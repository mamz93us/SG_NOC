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
     * GET /admin/logs/branches/sophos
     *
     * Sophos-firewall-only columnar view that matches Sophos XGS Log
     * Viewer's layout (Time, Component, Subtype, User, Rule, Src/Dst,
     * Protocol). Same backend fan-out as index() but adds is_sophos=1
     * to filter to firewall messages and pre-parses extra KV fields
     * (interfaces, NAT rule, fw_rule_id) that aren't in the schema yet.
     */
    public function sophos(Request $request): View
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters  = $this->extractFilters($request);

        $results = null;
        if ($request->boolean('search')) {
            $apiParams = $this->toApiParams($filters) + ['is_sophos' => 1];

            // Sophos-specific filters that map to dedicated DB columns
            foreach (['sophos_dst_ip', 'sophos_src_ip'] as $f) {
                if (!empty($filters[$f])) {
                    $apiParams[$f] = $filters[$f];
                }
            }

            $results = $this->client->search($selected, $apiParams, limit: 500);

            // Pre-parse Sophos KV fields not stored as columns (interface,
            // rule_id, NAT). Done here once so the Blade view stays clean.
            $results['results'] = array_map(
                fn ($row) => $row + $this->extraSophosFields($row['message'] ?? ''),
                $results['results']
            );
        }

        return view('admin.logs.branches.sophos', [
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
            'sophos_dst_ip'  => trim((string) $request->get('sophos_dst_ip', '')),
            'sophos_src_ip'  => trim((string) $request->get('sophos_src_ip', '')),
        ];
    }

    /**
     * Parse the additional KV fields from a Sophos message that aren't
     * stored as columns yet — interfaces, fw_rule_id, NAT rule.
     */
    private function extraSophosFields(string $msg): array
    {
        $kv = [];
        if (preg_match_all('/(\w+)="([^"]*)"|(\w+)=(\S+)/', $msg, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $pair) {
                $k = $pair[1] !== '' ? $pair[1] : $pair[3];
                $v = $pair[2] !== '' ? $pair[2] : ($pair[4] ?? '');
                if ($k !== '') $kv[$k] = $v;
            }
        }
        return [
            'kv_fw_rule_id'      => $kv['fw_rule_id']      ?? '',
            'kv_in_interface'    => $kv['in_interface']    ?? '',
            'kv_out_interface'   => $kv['out_interface']   ?? '',
            'kv_nat_rule_id'     => $kv['nat_rule_id']     ?? '',
            'kv_nat_rule_name'   => $kv['nat_rule_name']   ?? '',
            'kv_dst_country'     => $kv['dst_country']     ?? '',
            'kv_src_country'     => $kv['src_country']     ?? '',
            'kv_app_resolved_by' => $kv['app_resolved_by'] ?? '',
            'kv_application'     => $kv['application']     ?? ($kv['app_name'] ?? ''),
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
