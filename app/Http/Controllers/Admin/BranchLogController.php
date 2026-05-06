<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BranchLogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin viewer for distributed branch logs.
 *
 * Logs themselves live on each branch VM's MariaDB. This controller
 * fans out search/aggregate queries to the branches and merges results.
 * Nothing is stored centrally beyond an audit row in branch_log_searches
 * (optional — see migration in deployment/branch-vm/docs/).
 */
class BranchLogController extends Controller
{
    public function __construct(private readonly BranchLogClient $client) {}

    /**
     * GET /admin/logs/branches
     */
    public function index(Request $request): View
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters  = $this->extractFilters($request);

        $results = null;
        if ($request->boolean('search')) {
            $results = $this->client->search(
                $selected,
                $this->toApiParams($filters),
                limit: 500
            );
        }

        return view('admin.logs.branches.index', [
            'branches'         => $branches,
            'selectedBranches' => $selected,
            'filters'          => $filters,
            'results'          => $results,
        ]);
    }

    /**
     * GET /admin/logs/branches/aggregate.json
     * Returns top-N grouped counts (used by the in-page chart).
     */
    public function aggregate(Request $request): JsonResponse
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters  = $this->extractFilters($request);

        $field = (string) $request->get('field', 'source');
        $resp  = $this->client->aggregate(
            $selected,
            $this->toApiParams($filters) + ['field' => $field, 'limit' => 25],
            limit: 25
        );
        return response()->json($resp);
    }

    /** Standard filter shape used in form, query string, and forwarded to branches. */
    private function extractFilters(Request $request): array
    {
        return [
            'from'           => trim((string) $request->get('from', '')),
            'to'             => trim((string) $request->get('to',   '')),
            'source'         => trim((string) $request->get('source', '')),
            'q'              => trim((string) $request->get('q', '')),
            'severity'       => $request->get('severity', ''),
            'program'        => trim((string) $request->get('program', '')),
            'sophos_subtype' => trim((string) $request->get('sophos_subtype', '')),
        ];
    }

    /** Drop empties so the branch API uses its defaults for missing keys. */
    private function toApiParams(array $filters): array
    {
        return array_filter($filters, static fn ($v) => $v !== '' && $v !== null);
    }

    /** Parse comma-separated branch list, default to all enabled. */
    private function parseSelectedBranches(Request $request, array $allBranches): array
    {
        $raw = (string) $request->get('branches', '');
        if ($raw === '') return array_keys($allBranches);

        $picked = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_intersect($picked, array_keys($allBranches)));
    }
}
