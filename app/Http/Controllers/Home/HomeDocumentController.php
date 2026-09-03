<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PortalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * "Documentation & Manuals" and "IT Policy" on the employee home portal.
 *
 * One page and one download route serve both cards; the cards differ only by
 * the `category` they arrive with. Everything is scoped by the employee
 * resolved from the session, so there is no id in the URL to widen.
 *
 * The download route re-checks publication AND audience on every hit rather
 * than trusting that the link came from the list — a URL is shared, forwarded
 * and bookmarked, and a document restricted to one branch has to stay that way.
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
     * Stream one document. Links are redirected; uploads are served from the
     * `private` disk, which nginx cannot reach on its own.
     */
    public function download(Request $request, PortalDocument $document)
    {
        $employee = $this->employee($request);

        // Re-run the exact visibility query for this one row. Cheaper and far
        // safer than re-implementing the audience rules here, where they could
        // drift from the list page.
        $visible = PortalDocument::liveFor($employee)
            ->reorder()
            ->whereKey($document->getKey())
            ->exists();

        abort_unless($visible, 404);

        if (! $document->isFile()) {
            abort_unless($document->link_url, 404);

            return redirect()->away($document->link_url);
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
