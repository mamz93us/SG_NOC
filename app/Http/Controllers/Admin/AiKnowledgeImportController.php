<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiKnowledgeImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → AI Assistant Knowledge → Import PDFs.
 *
 * Stores the uploads and queues them, nothing more. Reading and translating
 * is `ai:import-pdfs`, in the background: a page is one gpt-4o call, and done
 * inline that is a PHP-FPM worker held for minutes and a 504.
 */
class AiKnowledgeImportController extends Controller
{
    /** max_file_uploads in the NOC's php.ini. */
    private const MAX_FILES = 20;

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'files' => 'required|array|max:'.self::MAX_FILES,
            'files.*' => 'required|file|mimes:pdf|max:51200',
            'category' => 'nullable|string|max:50',
            'audience' => ['required', Rule::in(['all', 'branch', 'department'])],
            'audience_branch_id' => 'nullable|required_if:audience,branch|integer|exists:branches,id',
            'audience_department_id' => 'nullable|required_if:audience,department|integer|exists:departments,id',
        ], [], [
            'files' => 'PDFs',
            'files.*' => 'PDF',
            'audience_branch_id' => 'branch',
            'audience_department_id' => 'department',
        ]);

        $queued = [];
        $skipped = [];
        $unstored = [];

        foreach ($request->file('files') as $file) {
            $name = mb_substr(basename($file->getClientOriginalName()), 0, 255);
            $hash = hash_file('sha256', $file->getRealPath());

            // The same bytes again would be read, paid for and published twice.
            if (AiKnowledgeImport::where('file_hash', $hash)->exists()) {
                $skipped[] = $name;

                continue;
            }

            // A generated name: two uploads of "policy.pdf" must not collide, and
            // a client filename is attacker-controlled.
            $path = $file->storeAs('ai-knowledge-imports', Str::uuid()->toString().'.pdf', 'private');

            if (! $path) {
                $unstored[] = $name;

                continue;
            }

            $this->letWorkerRead($path);

            $import = AiKnowledgeImport::create([
                'file_path' => $path,
                'file_name' => $name,
                'file_size' => $file->getSize(),
                'file_hash' => $hash,
                'status' => AiKnowledgeImport::QUEUED,
                'category' => $data['category'] ?? null,
                'audience' => $data['audience'],
                'audience_branch_id' => $data['audience'] === 'branch' ? $data['audience_branch_id'] : null,
                'audience_department_id' => $data['audience'] === 'department' ? $data['audience_department_id'] : null,
                'publish' => $request->boolean('publish'),
                'created_by' => Auth::id(),
            ]);

            $this->audit('ai_knowledge_import_queued', $import);
            $queued[] = $name;
        }

        $message = [];

        if ($queued) {
            $message[] = (count($queued) === 1 ? "“{$queued[0]}” is" : count($queued).' PDFs are')
                .' queued. Each page is read and translated in the background; the progress shows below.';
        }
        if ($skipped) {
            $message[] = 'Already imported, so skipped: '.implode(', ', $skipped).'.';
        }
        if ($unstored) {
            $message[] = 'Could not be stored: '.implode(', ', $unstored).'.';
        }

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with($queued ? 'success' : 'error', implode(' ', $message));
    }

    public function retry(AiKnowledgeImport $aiKnowledgeImport): RedirectResponse
    {
        if ($aiKnowledgeImport->status !== AiKnowledgeImport::FAILED) {
            return redirect()
                ->route('admin.ai-assistant.knowledge.index')
                ->with('error', 'Only a failed import can be tried again.');
        }

        // The pages already read are kept; it carries on from the one that failed.
        $aiKnowledgeImport->forceFill([
            'status' => AiKnowledgeImport::QUEUED,
            'attempts' => 0,
            'error' => null,
            'finished_at' => null,
        ])->save();

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', "“{$aiKnowledgeImport->file_name}” is queued again, from page ".($aiKnowledgeImport->pages_done + 1).'.');
    }

    public function destroy(AiKnowledgeImport $aiKnowledgeImport): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($aiKnowledgeImport) {
            // Locked, so an import finishing at this moment either keeps its PDF
            // with the article it just made, or is deleted before it makes one.
            $import = AiKnowledgeImport::whereKey($aiKnowledgeImport->id)->lockForUpdate()->first();

            if ($import?->article_id) {
                return false;
            }

            $import?->delete();

            return true;
        });

        if (! $deleted) {
            return redirect()
                ->route('admin.ai-assistant.knowledge.index')
                ->with('error', 'That import has already made its article. Delete the article instead; the PDF goes with it.');
        }

        $this->audit('ai_knowledge_import_deleted', $aiKnowledgeImport);

        return redirect()
            ->route('admin.ai-assistant.knowledge.index')
            ->with('success', "The import of “{$aiKnowledgeImport->file_name}” is deleted.");
    }

    /** The original PDF, to check a translation against. */
    public function file(AiKnowledgeImport $aiKnowledgeImport): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($aiKnowledgeImport->file_path), 404);

        return Storage::disk('private')->response(
            $aiKnowledgeImport->file_path,
            $aiKnowledgeImport->file_name,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** Polled by the Knowledge page while an import it shows is still being read. */
    public function status(Request $request): JsonResponse
    {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))), 0, 50);

        return response()->json([
            'imports' => AiKnowledgeImport::whereIn('id', $ids)->get()
                ->mapWithKeys(fn (AiKnowledgeImport $import) => [$import->id => [
                    'status' => $import->status,
                    'label' => $import->statusLabel(),
                    'percent' => $import->progressPercent(),
                    'error' => $import->error,
                ]]),
        ]);
    }

    /**
     * Uploads are written by PHP-FPM as www-data, but ai:import-pdfs runs in
     * the scheduler as azureuser, and Flysystem creates private-disk
     * directories 0700 — the first import on NOC2 failed with pdfinfo's
     * "Permission denied". 0711 lets the worker open a file it has the name
     * of, a UUID from the database, without letting anyone list the directory.
     */
    private function letWorkerRead(string $path): void
    {
        $file = Storage::disk('private')->path($path);

        try {
            chmod(dirname($file), 0711);
            chmod($file, 0644);
        } catch (\Throwable) {
            // Not ours to change (made by another user): the import then fails
            // with a message saying it is a permissions problem.
        }
    }

    /** By hand: the model is kept out of automatic auditing (config/audit.php). */
    private function audit(string $action, AiKnowledgeImport $import): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'AiKnowledgeImport',
                'model_id' => $import->id,
                'action' => $action,
                'changes' => [
                    'file_name' => $import->file_name,
                    'file_size' => $import->file_size,
                    'status' => $import->status,
                    'publish' => (bool) $import->publish,
                    'audience' => $import->audience,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging block an upload.
        }
    }
}
