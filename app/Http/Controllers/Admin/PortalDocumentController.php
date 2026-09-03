<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\PortalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Employee Documents.
 *
 * Authoring for the manuals, guides, forms and IT policies the whole company
 * reads on the home portal, so every write is audited and publishing is an
 * explicit act rather than a side effect of saving.
 *
 * Uploads go to the `private` disk. They are served only by
 * Home\HomeDocumentController::download, which re-checks publication and
 * audience — putting them on the public disk would make every IT policy a
 * world-readable URL.
 */
class PortalDocumentController extends Controller
{
    /** Extensions people actually publish here. Anything executable is refused. */
    private const ALLOWED = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,zip';

    public function index(Request $request): View
    {
        $category = (string) $request->query('category', '');

        return view('admin.portal-documents.index', [
            'documents' => PortalDocument::with(['branch', 'department'])
                ->when(
                    array_key_exists($category, PortalDocument::CATEGORIES),
                    fn ($q) => $q->where('category', $category)
                )
                ->orderByDesc('pinned')
                ->orderBy('category')
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'category' => array_key_exists($category, PortalDocument::CATEGORIES) ? $category : null,
        ]);
    }

    public function create(): View
    {
        return view('admin.portal-documents.form', [
            'document' => new PortalDocument(['category' => 'manual', 'audience' => 'all', 'sort_order' => 0]),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function edit(PortalDocument $portalDocument): View
    {
        return view('admin.portal-documents.form', [
            'document' => $portalDocument,
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $data['created_by'] = Auth::id();
        $data['created_by_name'] = Auth::user()?->name;

        $document = PortalDocument::create($data);

        $this->audit('portal_document_created', $document);
        $this->flushCache();

        return redirect()
            ->route('admin.portal-documents.index')
            ->with('success', 'Document created.');
    }

    public function update(Request $request, PortalDocument $portalDocument): RedirectResponse
    {
        $data = $this->validated($request, $portalDocument);

        // A replaced upload leaves its predecessor behind otherwise, and these
        // are whole documents rather than thumbnails.
        $oldPath = $portalDocument->file_path;
        $replacing = array_key_exists('file_path', $data) && $data['file_path'] !== $oldPath;

        $portalDocument->update($data);

        if ($replacing && $oldPath) {
            $this->deleteFile($oldPath);
        }

        $this->audit('portal_document_updated', $portalDocument);
        $this->flushCache();

        return redirect()
            ->route('admin.portal-documents.index')
            ->with('success', 'Document updated.');
    }

    public function destroy(PortalDocument $portalDocument): RedirectResponse
    {
        $this->audit('portal_document_deleted', $portalDocument);

        $path = $portalDocument->file_path;
        $portalDocument->delete();
        $this->deleteFile($path);

        $this->flushCache();

        return redirect()
            ->route('admin.portal-documents.index')
            ->with('success', 'Document deleted.');
    }

    /** The admin's own copy, so a draft can be checked before publishing it. */
    public function download(PortalDocument $portalDocument)
    {
        abort_unless(
            $portalDocument->file_path && Storage::disk('private')->exists($portalDocument->file_path),
            404
        );

        return Storage::disk('private')->download(
            $portalDocument->file_path,
            $portalDocument->file_name ?: basename($portalDocument->file_path),
        );
    }

    private function validated(Request $request, ?PortalDocument $existing): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'title_ar' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:500',
            'category' => ['required', Rule::in(array_keys(PortalDocument::CATEGORIES))],
            'link_url' => 'nullable|url|max:500',
            'video_url' => 'nullable|url|max:500',
            'file' => 'nullable|file|mimes:'.self::ALLOWED.'|max:51200',
            'version' => 'nullable|string|max:32',
            'effective_date' => 'nullable|date',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'audience' => ['required', Rule::in(PortalDocument::AUDIENCES)],
            'audience_branch_id' => 'nullable|integer|exists:branches,id',
            'audience_department_id' => 'nullable|integer|exists:departments,id',
        ]);

        $data['is_published'] = $request->boolean('is_published');
        $data['pinned'] = $request->boolean('pinned');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        // A document is a file, a video or a link. None of the three means a
        // card that goes nowhere, which is worse than a validation error.
        $hasUpload = $request->hasFile('file');
        $hasExistingFile = (bool) $existing?->file_path;
        $hasLink = ! empty($data['link_url']);
        $hasVideo = ! empty($data['video_url']);

        // Checked here rather than with a regex rule so the message can say what
        // is wrong: a Vimeo or SharePoint link belongs in the Link field, and
        // silently storing it would render an empty player.
        if ($hasVideo && (new PortalDocument(['video_url' => $data['video_url']]))->youtubeId() === null) {
            throw ValidationException::withMessages([
                'video_url' => 'That is not a YouTube link. Paste a youtube.com or youtu.be URL, '
                    .'or use the Link field for video hosted anywhere else.',
            ]);
        }

        if (! $hasUpload && ! $hasExistingFile && ! $hasLink && ! $hasVideo) {
            throw ValidationException::withMessages([
                'file' => 'Upload a file, paste a YouTube link, or give a link — a document needs one of the three.',
            ]);
        }

        if ($hasUpload) {
            $file = $request->file('file');
            $original = $file->getClientOriginalName();

            // Stored under a generated name (the original is kept for the
            // download filename): two people uploading "policy.pdf" must not
            // collide, and a client filename is attacker-controlled.
            $stored = $file->storeAs(
                'portal-documents',
                Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension()),
                'private'
            );

            $data['file_path'] = $stored;
            $data['file_name'] = mb_substr(basename($original), 0, 255);
            $data['file_mime'] = $file->getClientMimeType();
            $data['file_size'] = $file->getSize();

            // An upload wins — a card can only do one thing.
            $data['link_url'] = null;
            $data['video_url'] = null;
        } elseif ($hasVideo) {
            // Filling in a video on a document that had a file converts it;
            // update() then deletes the orphaned blob.
            $data = array_merge($data, $this->clearedFileFields());
            $data['link_url'] = null;
        } elseif ($hasLink) {
            $data = array_merge($data, $this->clearedFileFields());
            $data['video_url'] = null;
        } else {
            // Neither given, so an existing upload is being kept as-is.
            $data['link_url'] = null;
            $data['video_url'] = null;
        }

        // Clear the audience id that no longer applies, so switching from
        // "branch" to "all" cannot leave a stale filter behind.
        if ($data['audience'] !== 'branch') {
            $data['audience_branch_id'] = null;
        }
        if ($data['audience'] !== 'department') {
            $data['audience_department_id'] = null;
        }

        unset($data['file']);

        return $data;
    }

    /** @return array{file_path:null, file_name:null, file_mime:null, file_size:null} */
    private function clearedFileFields(): array
    {
        return [
            'file_path' => null,
            'file_name' => null,
            'file_mime' => null,
            'file_size' => null,
        ];
    }

    private function deleteFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk('private')->delete($path);
        } catch (\Throwable) {
            // An orphaned blob is not worth failing the save over.
        }
    }

    /**
     * The portal caches per-audience tile counts for 5 minutes. Without this,
     * publishing a policy would leave the IT card claiming the old number.
     */
    private function flushCache(): void
    {
        try {
            Cache::forget('home.documents.counts.nb.nd');

            foreach (Branch::pluck('id') as $branchId) {
                Cache::forget("home.documents.counts.{$branchId}.nd");

                foreach (Department::pluck('id') as $deptId) {
                    Cache::forget("home.documents.counts.{$branchId}.{$deptId}");
                    Cache::forget("home.documents.counts.nb.{$deptId}");
                }
            }
        } catch (\Throwable) {
            // Entries expire in five minutes regardless.
        }
    }

    private function audit(string $action, PortalDocument $document): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'PortalDocument',
                'model_id' => $document->id,
                'action' => $action,
                'changes' => [
                    'title' => $document->title,
                    'category' => $document->category,
                    'audience' => $document->audience,
                    'is_published' => (bool) $document->is_published,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging block publishing.
        }
    }
}
