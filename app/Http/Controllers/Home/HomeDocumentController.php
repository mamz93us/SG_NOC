<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PortalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * "Documentation & Manuals" and "IT Policy" on the employee home portal.
 *
 * One library serves both cards; they differ only by the `category` they arrive
 * with. Everything is scoped by the employee resolved from the session, so
 * there is no id in the URL to widen.
 *
 * Four routes: the list, the in-app viewer (`show`), the inline bytes the
 * viewer's iframe loads (`stream`), and the download. EVERY one of them
 * re-checks publication AND audience rather than trusting that the link came
 * from the list — a URL is shared, forwarded and bookmarked, and a document
 * restricted to one branch has to stay that way.
 */
class HomeDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $employee = $this->employee($request);

        $category = (string) $request->query('category', '');
        $search = trim((string) $request->query('q', ''));

        $documents = PortalDocument::liveFor($employee)
            ->when(
                array_key_exists($category, PortalDocument::CATEGORIES),
                fn ($q) => $q->where('category', $category)
            )
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(function ($w) use ($like) {
                    $w->where('title', 'like', $like)
                        ->orWhere('title_ar', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->get();

        return view('home.documents', [
            'user' => $request->user(),
            'employee' => $employee,
            'documents' => $documents,
            'category' => array_key_exists($category, PortalDocument::CATEGORIES) ? $category : null,
            'search' => $search,
            // Per-category totals for the filter chips — of everything this
            // person may see, not of the filtered result.
            'counts' => PortalDocument::liveFor($employee)
                ->reorder()
                ->select('category', DB::raw('COUNT(*) as total'))
                ->groupBy('category')
                ->pluck('total', 'category'),
        ]);
    }

    /**
     * The in-app viewer: a PDF, an image or an embedded video, inside the
     * portal's own chrome.
     *
     * A link, a Word file or a zip has nothing to render, so those leave from
     * here rather than being shown a viewer with an empty frame in it. The
     * cards already send people straight to the right place; this only catches
     * a bookmarked or typed URL.
     */
    public function show(Request $request, PortalDocument $document): View|RedirectResponse
    {
        $this->authorizeDocument($request, $document);

        if ($document->sourceType() === 'link') {
            return redirect()->away($document->link_url);
        }

        if (! $document->isPreviewable()) {
            return redirect()->route('home.documents.download', $document);
        }

        return view('home.document', [
            'user' => $request->user(),
            'doc' => $document,
        ]);
    }

    /**
     * The bytes, served INLINE so the browser renders them in the viewer's
     * iframe instead of saving them.
     *
     * Same gate as every other route here; the iframe is same-origin, so this
     * is a normal authenticated request and not a hole around it.
     */
    public function stream(Request $request, PortalDocument $document)
    {
        $this->authorizeDocument($request, $document);

        abort_unless($document->isFile() && Storage::disk('private')->exists($document->file_path), 404);

        return Storage::disk('private')->response(
            $document->file_path,
            $document->file_name ?: basename($document->file_path),
            // Explicit rather than sniffed: the app sends X-Content-Type-Options
            // nosniff, so a wrong or missing type means the browser refuses to
            // render the PDF instead of quietly guessing.
            ['Content-Type' => $document->file_mime ?: 'application/octet-stream'],
        );
    }

    /**
     * Stream one document as a download. Links are redirected; uploads are
     * served from the `private` disk, which nginx cannot reach on its own.
     */
    public function download(Request $request, PortalDocument $document)
    {
        $this->authorizeDocument($request, $document);

        if (! $document->isFile()) {
            $away = $document->link_url ?: $document->youtubeWatchUrl();

            abort_unless($away, 404);

            return redirect()->away($away);
        }

        abort_unless(Storage::disk('private')->exists($document->file_path), 404);

        // A counter, not an audit trail — never let it fail the download.
        try {
            PortalDocument::whereKey($document->getKey())->increment('download_count');
        } catch (\Throwable) {
        }

        return Storage::disk('private')->download(
            $document->file_path,
            $document->file_name ?: basename($document->file_path),
        );
    }

    /**
     * Re-run the exact visibility query for one row.
     *
     * Cheaper and far safer than re-implementing the audience rules per route,
     * where they could drift from the list page — and it has to happen on every
     * hit, because these URLs get shared, forwarded and bookmarked.
     */
    private function authorizeDocument(Request $request, PortalDocument $document): void
    {
        $visible = PortalDocument::liveFor($this->employee($request))
            ->reorder()
            ->whereKey($document->getKey())
            ->exists();

        abort_unless($visible, 404);
    }

    private function employee(Request $request): ?Employee
    {
        return Employee::where('email', $request->user()->email)->first();
    }

    /**
     * Tile counts for the home page, cached per audience for five minutes.
     *
     * The whole company loads the start page within minutes of 9am, so this is
     * one query per audience triple rather than one per person, and it never
     * throws — a missing table hides the numbers, not the cards.
     *
     * @return array{documents:int, policies:int}
     */
    public static function tileCounts(?Employee $employee): array
    {
        $key = sprintf(
            'home.documents.counts.%s.%s',
            $employee?->branch_id ?? 'nb',
            $employee?->department_id ?? 'nd'
        );

        try {
            return Cache::remember($key, now()->addMinutes(5), function () use ($employee) {
                $byCategory = PortalDocument::liveFor($employee)
                    ->reorder()
                    ->select('category', DB::raw('COUNT(*) as total'))
                    ->groupBy('category')
                    ->pluck('total', 'category');

                return [
                    'documents' => (int) collect(PortalDocument::DOC_CATEGORIES)
                        ->sum(fn ($c) => (int) ($byCategory[$c] ?? 0)),
                    'policies' => (int) ($byCategory['policy'] ?? 0),
                ];
            });
        } catch (\Throwable) {
            return ['documents' => 0, 'policies' => 0];
        }
    }
}
