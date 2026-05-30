<?php

namespace App\Http\Controllers\Admin\Teamtailor;

use App\Http\Controllers\Controller;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class CandidateController extends Controller
{
    /** Page size for the candidate listing (Teamtailor caps page[size] at 30). */
    private const PER_PAGE = 25;

    public function index(Request $request, TeamtailorApiService $teamtailor)
    {
        $page = max(1, (int) $request->query('page', 1));
        $sort = $request->query('sort') === 'oldest' ? 'created-at' : '-created-at';

        $filters = $this->buildFilters($request);

        $candidates = collect();
        $total = 0;
        $error = null;
        $configured = $teamtailor->isConfigured();

        if ($configured) {
            try {
                $body = $teamtailor->listCandidates($filters, $page, self::PER_PAGE, $sort);
                $total = (int) Arr::get($body, 'meta.record-count', 0);
                $candidates = collect(Arr::get($body, 'data', []))
                    ->map(fn ($row) => $this->mapCandidate($row));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $paginator = new LengthAwarePaginator(
            $candidates,
            $total,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.teamtailor.candidates.index', [
            'candidates' => $candidates,
            'paginator' => $paginator,
            'total' => $total,
            'error' => $error,
            'configured' => $configured,
        ]);
    }

    /**
     * Translate request inputs into Teamtailor JSON:API filter keys.
     *
     * @return array<string,string>
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        if ($request->filled('email')) {
            $filters['filter[email]'] = trim((string) $request->query('email'));
        }
        if ($request->filled('phone')) {
            $filters['filter[phone]'] = trim((string) $request->query('phone'));
        }
        if (in_array($request->query('connected'), ['true', 'false'], true)) {
            $filters['filter[connected]'] = $request->query('connected');
        }
        if ($request->filled('created_from')) {
            $filters['filter[created-at][from]'] = (string) $request->query('created_from');
        }
        if ($request->filled('created_to')) {
            $filters['filter[created-at][to]'] = (string) $request->query('created_to');
        }

        return $filters;
    }

    /**
     * Flatten a JSON:API candidate resource into a view-friendly row.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function mapCandidate(array $row): array
    {
        $a = $row['attributes'] ?? [];
        $first = $a['first-name'] ?? '';
        $last = $a['last-name'] ?? '';

        return [
            'id' => $row['id'] ?? null,
            'name' => trim("{$first} {$last}") ?: '—',
            'email' => $a['email'] ?? null,
            'phone' => $a['phone'] ?? null,
            'connected' => (bool) ($a['connected'] ?? false),
            'sourced' => (bool) ($a['sourced'] ?? false),
            'tags' => is_array($a['tags'] ?? null) ? $a['tags'] : [],
            'linkedin' => $a['linkedin-url'] ?? null,
            'resume' => $a['resume'] ?? null,
            'created_at' => $a['created-at'] ?? null,
        ];
    }
}
