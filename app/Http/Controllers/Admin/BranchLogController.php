<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BranchLogClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function index(Request $request)
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters = $this->extractFilters($request);

        // CSV export bypasses HTML rendering
        if ($request->get('export') === 'csv') {
            $rows = max(50, min(10000, (int) $request->get('rows', 5000)));
            $results = $this->client->search($selected, $this->toApiParams($filters, $this->displayTz($request)), limit: $rows);

            return $this->streamCsv(
                'branch-logs-'.date('Ymd-His').'.csv',
                ['time_utc', 'branch', 'severity', 'source', 'source_ip', 'program', 'message'],
                function ($fh) use ($results) {
                    foreach ($results['results'] as $r) {
                        fputcsv($fh, [
                            $r['received_at'] ?? '',
                            $r['branch_id'] ?? '',
                            $r['severity'] ?? '',
                            $r['source'] ?? '',
                            $r['source_ip'] ?? '',
                            $r['program'] ?? '',
                            $r['message'] ?? '',
                        ]);
                    }
                }
            );
        }

        $results = null;
        if ($request->boolean('search')) {
            $results = $this->client->search(
                $selected,
                $this->toApiParams($filters, $this->displayTz($request)),
                limit: 500
            );
        }

        return view('admin.logs.branches.index', [
            'branches' => $branches,
            'selectedBranches' => $selected,
            'filters' => $filters,
            'results' => $results,
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
    public function sophos(Request $request)
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters = $this->extractFilters($request);

        $apiParams = $this->toApiParams($filters, $this->displayTz($request)) + ['is_sophos' => 1];
        foreach (['sophos_dst_ip', 'sophos_src_ip'] as $f) {
            if (! empty($filters[$f])) {
                $apiParams[$f] = $filters[$f];
            }
        }

        // CSV export — streamed download, sized for big pulls (up to 50k/branch).
        // Bigger limit, longer branch timeout, and raised PHP limits so a large
        // export doesn't OOM or time out the way the rendered table would.
        if ($request->get('export') === 'csv') {
            $rows = max(50, min(50000, (int) $request->get('rows', 5000)));
            @set_time_limit(300);
            @ini_set('memory_limit', '1024M');
            $results = $this->client->search($selected, $apiParams, limit: $rows, timeoutSec: 120);
            $results['results'] = array_map(
                fn ($row) => $row + $this->extraSophosFields($row['message'] ?? ''),
                $results['results']
            );

            return $this->streamCsv(
                'branch-logs-sophos-'.date('Ymd-His').'.csv',
                ['time_utc', 'branch', 'component', 'subtype', 'user',
                    'rule_id', 'rule_name', 'in_interface', 'out_interface',
                    'src_ip', 'src_port', 'dst_ip', 'dst_port', 'protocol',
                    'src_country', 'dst_country', 'message'],
                function ($fh) use ($results) {
                    foreach ($results['results'] as $r) {
                        fputcsv($fh, [
                            $r['received_at'] ?? '',
                            $r['branch_id'] ?? '',
                            $r['sophos_log_component'] ?? '',
                            $r['sophos_log_subtype'] ?? '',
                            $r['sophos_user_name'] ?? '',
                            $r['kv_fw_rule_id'] ?? '',
                            $r['sophos_fw_rule_name'] ?? '',
                            $r['kv_in_interface'] ?? '',
                            $r['kv_out_interface'] ?? '',
                            $r['sophos_src_ip'] ?? '',
                            $r['sophos_src_port'] ?? '',
                            $r['sophos_dst_ip'] ?? '',
                            $r['sophos_dst_port'] ?? '',
                            $r['sophos_protocol'] ?? '',
                            $r['kv_src_country'] ?? '',
                            $r['kv_dst_country'] ?? '',
                            $r['message'] ?? '',
                        ]);
                    }
                }
            );
        }

        $results = null;
        if ($request->boolean('search')) {
            // Per-branch row cap. Default 500; user can pick up to 5000 in the form
            // (the branch agent's Search() honours up to 5000).
            $rows = (int) $request->get('rows', 500);
            $rows = max(50, min(5000, $rows));

            $results = $this->client->search($selected, $apiParams, limit: $rows);

            // Pre-parse Sophos KV fields not stored as columns (interface,
            // rule_id, NAT). Done here once so the Blade view stays clean.
            $results['results'] = array_map(
                fn ($row) => $row + $this->extraSophosFields($row['message'] ?? ''),
                $results['results']
            );
        }

        return view('admin.logs.branches.sophos', [
            'branches' => $branches,
            'selectedBranches' => $selected,
            'filters' => $filters,
            'results' => $results,
        ]);
    }

    /**
     * GET /admin/logs/branches/ucm
     *
     * Per-IP log viewer aimed at UCM (Asterisk) servers. Primary filter
     * is source_ip — once the operator types the UCM's IP, every line
     * that device sent shows up, with Asterisk-format messages broken
     * out into severity / call_id / file:line / function / body columns
     * (parsed from the raw message at render time).
     */
    public function ucm(Request $request)
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters = $this->extractFilters($request);
        $sourceIp = trim((string) $request->get('source_ip', ''));

        $apiParams = $this->toApiParams($filters, $this->displayTz($request));
        if ($sourceIp !== '') {
            $apiParams['source_ip'] = $sourceIp;
        }

        // CSV export — streamed download, sized for big pulls (up to 50k/branch).
        // Bigger limit, longer branch timeout, and raised PHP limits so a large
        // export doesn't OOM or time out the way the rendered table would.
        if ($request->get('export') === 'csv') {
            $rows = max(50, min(50000, (int) $request->get('rows', 5000)));
            @set_time_limit(300);
            @ini_set('memory_limit', '1024M');
            $results = $this->client->search($selected, $apiParams, limit: $rows, timeoutSec: 120);
            $results['results'] = array_map(
                fn ($row) => $row + $this->extraAsteriskFields($row['message'] ?? ''),
                $results['results']
            );

            return $this->streamCsv(
                'branch-logs-ucm-'.date('Ymd-His').'.csv',
                ['time_utc', 'branch', 'source_ip', 'severity_text',
                    'asterisk_severity', 'call_id', 'pid', 'task_id',
                    'file', 'line', 'function', 'body', 'message'],
                function ($fh) use ($results) {
                    foreach ($results['results'] as $r) {
                        fputcsv($fh, [
                            $r['received_at'] ?? '',
                            $r['branch_id'] ?? '',
                            $r['source_ip'] ?? '',
                            $r['severity'] ?? '',
                            $r['a_severity'] ?? '',
                            $r['a_call_id'] ?? '',
                            $r['a_pid'] ?? '',
                            $r['a_task'] ?? '',
                            $r['a_file'] ?? '',
                            $r['a_line'] ?? '',
                            $r['a_func'] ?? '',
                            $r['a_body'] ?? '',
                            $r['message'] ?? '',
                        ]);
                    }
                }
            );
        }

        $results = null;
        if ($request->boolean('search')) {
            $rows = (int) $request->get('rows', 500);
            $rows = max(50, min(1000, $rows));

            $results = $this->client->search($selected, $apiParams, limit: $rows);

            $results['results'] = array_map(
                fn ($row) => $row + $this->extraAsteriskFields($row['message'] ?? ''),
                $results['results']
            );
        }

        return view('admin.logs.branches.ucm', [
            'branches' => $branches,
            'selectedBranches' => $selected,
            'filters' => $filters,
            'sourceIp' => $sourceIp,
            'results' => $results,
        ]);
    }

    /**
     * Stream a CSV download. UTF-8 BOM up front so Excel renders Arabic
     * / accented chars correctly when the file is double-clicked.
     */
    private function streamCsv(string $filename, array $headers, \Closure $rowsCallback): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rowsCallback) {
            $fh = fopen('php://output', 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers);
            $rowsCallback($fh);
            fclose($fh);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Parse the Asterisk message shape:
     *   [HOST_MAC] asterisk[PID]: SEVERITY[task][C-callid]: file:line in func: body
     */
    private function extractAsteriskFields(string $msg): array
    {
        $out = [
            'a_severity' => '', 'a_pid' => '', 'a_task' => '', 'a_call_id' => '',
            'a_file' => '', 'a_line' => '', 'a_func' => '', 'a_body' => '',
        ];
        if (preg_match(
            '/asterisk\[(?<pid>\d+)\]:\s+(?<sev>[A-Z]+)\[(?<task>\d+)\](?:\[C-(?<call>[A-Fa-f0-9]+)\])?:\s+(?<file>[^:\s]+):(?<line>\d+)\s+in\s+(?<func>\w+):\s*(?<body>.*)/',
            $msg,
            $m
        )) {
            $out = [
                'a_severity' => $m['sev'] ?? '',
                'a_pid' => $m['pid'] ?? '',
                'a_task' => $m['task'] ?? '',
                'a_call_id' => $m['call'] ?? '',
                'a_file' => $m['file'] ?? '',
                'a_line' => $m['line'] ?? '',
                'a_func' => $m['func'] ?? '',
                'a_body' => $m['body'] ?? '',
            ];
        }

        return $out;
    }

    private function extraAsteriskFields(string $msg): array
    {
        return $this->extractAsteriskFields($msg);
    }

    /**
     * GET /admin/logs/branches/aggregate.json
     * Returns top-N grouped counts (used by the in-page chart).
     */
    public function aggregate(Request $request): JsonResponse
    {
        $branches = $this->client->enabledBranches();
        $selected = $this->parseSelectedBranches($request, $branches);
        $filters = $this->extractFilters($request);

        $field = (string) $request->get('field', 'source');
        $resp = $this->client->aggregate(
            $selected,
            $this->toApiParams($filters, $this->displayTz($request)) + ['field' => $field, 'limit' => 25],
            limit: 25
        );

        return response()->json($resp);
    }

    /** Standard filter shape used in form, query string, and forwarded to branches. */
    private function extractFilters(Request $request): array
    {
        return [
            'from' => trim((string) $request->get('from', '')),
            'to' => trim((string) $request->get('to', '')),
            'source' => trim((string) $request->get('source', '')),
            'q' => trim((string) $request->get('q', '')),
            'severity' => $request->get('severity', ''),
            'program' => trim((string) $request->get('program', '')),
            'sophos_subtype' => trim((string) $request->get('sophos_subtype', '')),
            'sophos_dst_ip' => trim((string) $request->get('sophos_dst_ip', '')),
            'sophos_src_ip' => trim((string) $request->get('sophos_src_ip', '')),
            'sophos_src_port' => trim((string) $request->get('sophos_src_port', '')),
            'sophos_dst_port' => trim((string) $request->get('sophos_dst_port', '')),
        ];
    }

    /**
     * Parse Sophos KV fields from the raw message.
     *
     * The `sophos_*` keys used to be pre-parsed and returned by the branch-vm
     * search API. The consolidated branch agent returns the raw message
     * instead (which carries everything as KV), so we derive those columns
     * here. The caller merges via `$row + extra`, so any value a branch *does*
     * supply still wins — this only fills the gaps.
     */
    private function extraSophosFields(string $msg): array
    {
        $kv = [];
        if (preg_match_all('/(\w+)="([^"]*)"|(\w+)=(\S+)/', $msg, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $pair) {
                $k = $pair[1] !== '' ? $pair[1] : $pair[3];
                $v = $pair[2] !== '' ? $pair[2] : ($pair[4] ?? '');
                if ($k !== '') {
                    $kv[$k] = $v;
                }
            }
        }

        return [
            // Extra KV columns (interfaces, rule id, NAT, country, app).
            'kv_fw_rule_id' => $kv['fw_rule_id'] ?? '',
            'kv_in_interface' => $kv['in_interface'] ?? '',
            'kv_out_interface' => $kv['out_interface'] ?? '',
            'kv_nat_rule_id' => $kv['nat_rule_id'] ?? '',
            'kv_nat_rule_name' => $kv['nat_rule_name'] ?? '',
            'kv_dst_country' => $kv['dst_country'] ?? '',
            'kv_src_country' => $kv['src_country'] ?? '',
            'kv_app_resolved_by' => $kv['app_resolved_by'] ?? '',
            'kv_application' => $kv['application'] ?? ($kv['app_name'] ?? ''),

            // Primary columns the Sophos view expects (derived from the raw
            // message when the branch didn't pre-parse them).
            'sophos_log_type' => $kv['log_type'] ?? '',
            'sophos_log_component' => $kv['log_component'] ?? '',
            'sophos_log_subtype' => $kv['log_subtype'] ?? '',
            'sophos_fw_rule_name' => $kv['fw_rule_name'] ?? '',
            'sophos_user_name' => $kv['user_name'] ?? ($kv['user'] ?? ''),
            'sophos_src_ip' => $kv['src_ip'] ?? '',
            'sophos_src_port' => $kv['src_port'] ?? '',
            'sophos_dst_ip' => $kv['dst_ip'] ?? '',
            'sophos_dst_port' => $kv['dst_port'] ?? '',
            'sophos_protocol' => $kv['protocol'] ?? '',
        ];
    }

    /**
     * Drop empties so the branch API uses its defaults for missing keys, and
     * convert the from/to window from the display timezone (what the user typed
     * in the datetime-local fields) to UTC — branch agents store received_at in
     * UTC, so sending local time would shift the window by the TZ offset and
     * return nothing.
     */
    private function toApiParams(array $filters, string $tz = 'UTC'): array
    {
        $params = array_filter($filters, static fn ($v) => $v !== '' && $v !== null);

        foreach (['from', 'to'] as $k) {
            if (! empty($params[$k]) && $tz !== 'UTC') {
                try {
                    $params[$k] = \Carbon\CarbonImmutable::parse($params[$k], $tz)
                        ->utc()->format('Y-m-d\TH:i:s');
                } catch (\Throwable) {
                    // Leave the raw value; the agent will parse what it can.
                }
            }
        }

        return $params;
    }

    /** Timezone the user's from/to inputs are expressed in (matches the view). */
    private function displayTz(Request $request): string
    {
        return $request->get('tz') ?: config('app.timezone', 'UTC');
    }

    /** Parse comma-separated branch list, default to all enabled. */
    private function parseSelectedBranches(Request $request, array $allBranches): array
    {
        $raw = (string) $request->get('branches', '');
        if ($raw === '') {
            return array_keys($allBranches);
        }

        $picked = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_intersect($picked, array_keys($allBranches)));
    }
}
