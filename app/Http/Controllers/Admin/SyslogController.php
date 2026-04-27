<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\MatchSyslogAlertsJob;
use App\Jobs\ParseSyslogPayloadsJob;
use App\Jobs\TagSyslogSourcesJob;
use App\Models\SyslogAlertRule;
use App\Models\SyslogMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SyslogController extends Controller
{
    /**
     * Main syslog viewer — paginated list with filters.
     * Filter inputs are kept stateful via query string so the user can
     * bookmark a search.
     */
    public function index(Request $request): View
    {
        $q = SyslogMessage::query();

        $filters = [
            'host'        => trim((string) $request->get('host', '')),
            'source_type' => (string) $request->get('source_type', ''),
            'severity'    => $request->get('severity'),
            'program'     => trim((string) $request->get('program', '')),
            'search'      => trim((string) $request->get('search', '')),
            'since'       => (string) $request->get('since', '24h'),
        ];

        if ($filters['host'] !== '') {
            $q->where('host', 'like', '%' . $filters['host'] . '%');
        }
        if ($filters['source_type'] !== '') {
            $q->where('source_type', $filters['source_type']);
        }
        if ($filters['severity'] !== null && $filters['severity'] !== '') {
            $q->where('severity', '<=', (int) $filters['severity']);
        }
        if ($filters['program'] !== '') {
            $q->where('program', 'like', '%' . $filters['program'] . '%');
        }
        if ($filters['search'] !== '') {
            $q->where('message', 'like', '%' . $filters['search'] . '%');
        }

        $since = $this->parseSince($filters['since']);
        if ($since) {
            $q->where('received_at', '>=', $since);
        }

        $messages = $q->orderByDesc('received_at')
            ->paginate(100)
            ->withQueryString();

        // Headline counters for the top of the page (cheap separate
        // queries — they all hit the same indexes).
        $stats = $this->stats($since);

        return view('admin.syslog.index', [
            'messages'      => $messages,
            'filters'       => $filters,
            'stats'         => $stats,
            'severityNames' => SyslogMessage::SEVERITIES,
            'sourceTypes'   => ['sophos', 'cisco', 'ucm', 'printer', 'vps', 'unknown'],
        ]);
    }

    /**
     * Detail view for a single syslog row — shows the raw packet,
     * parsed fields, and which rule (if any) matched.
     */
    public function show(int $id): View
    {
        $message = SyslogMessage::findOrFail($id);
        return view('admin.syslog.show', compact('message'));
    }

    /**
     * Sophos firewall log viewer — wide table that mirrors the columns
     * shown by the Sophos device's own Log Viewer (component, subtype,
     * fw_rule, src/dst, ports, protocol, …). Reads from the `parsed`
     * JSON column populated by ParseSyslogPayloadsJob.
     */
    public function sophos(Request $request): View
    {
        $q = SyslogMessage::query()->where('source_type', 'sophos');

        $filters = [
            'host'      => trim((string) $request->get('host', '')),
            'subtype'   => (string) $request->get('subtype', ''),
            'component' => (string) $request->get('component', ''),
            'src_ip'    => trim((string) $request->get('src_ip', '')),
            'dst_ip'    => trim((string) $request->get('dst_ip', '')),
            'fw_rule'   => trim((string) $request->get('fw_rule', '')),
            'search'    => trim((string) $request->get('search', '')),
            'since'     => (string) $request->get('since', '24h'),
        ];

        if ($filters['host'] !== '') {
            $q->where('host', 'like', '%' . $filters['host'] . '%');
        }
        if ($filters['subtype'] !== '') {
            $q->whereJsonContains('parsed->log_subtype', $filters['subtype']);
        }
        if ($filters['component'] !== '') {
            $q->whereJsonContains('parsed->log_component', $filters['component']);
        }
        if ($filters['src_ip'] !== '') {
            $q->where('parsed->src_ip', $filters['src_ip']);
        }
        if ($filters['dst_ip'] !== '') {
            $q->where('parsed->dst_ip', $filters['dst_ip']);
        }
        if ($filters['fw_rule'] !== '') {
            // fw_rule_id is stored as int, value here is a string from the
            // query string — compare loosely against either.
            $q->where(function ($sub) use ($filters) {
                $sub->where('parsed->fw_rule_id', (int) $filters['fw_rule'])
                    ->orWhere('parsed->fw_rule_id', $filters['fw_rule']);
            });
        }
        if ($filters['search'] !== '') {
            $q->where('message', 'like', '%' . $filters['search'] . '%');
        }

        $since = $this->parseSince($filters['since']);
        if ($since) {
            $q->where('received_at', '>=', $since);
        }

        $messages = $q->orderByDesc('received_at')->paginate(75)->withQueryString();

        // Pull distinct values for the subtype/component dropdowns —
        // limited to recent rows to keep the query fast.
        $distinct = $this->sophosDistincts($since);

        return view('admin.syslog.sophos', [
            'messages'   => $messages,
            'filters'    => $filters,
            'subtypes'   => $distinct['subtypes'],
            'components' => $distinct['components'],
        ]);
    }

    /**
     * UCM / Asterisk log viewer — single line per event with the
     * Asterisk-internal severity (NOTICE/WARNING/ERROR/SECURITY) shown
     * separately from the syslog severity, plus call_id / file:line /
     * function pulled out of the parsed JSON.
     */
    public function ucm(Request $request): View
    {
        $q = SyslogMessage::query()->where('source_type', 'ucm');

        $filters = [
            'host'              => trim((string) $request->get('host', '')),
            'asterisk_severity' => (string) $request->get('asterisk_severity', ''),
            'program'           => (string) $request->get('program', ''),
            'call_id'           => trim((string) $request->get('call_id', '')),
            'security'          => (string) $request->get('security', ''),
            'search'            => trim((string) $request->get('search', '')),
            'since'             => (string) $request->get('since', '24h'),
        ];

        if ($filters['host'] !== '') {
            $q->where('host', 'like', '%' . $filters['host'] . '%');
        }
        if ($filters['asterisk_severity'] !== '') {
            $q->whereJsonContains('parsed->asterisk_severity', $filters['asterisk_severity']);
        }
        if ($filters['program'] !== '') {
            $q->whereJsonContains('parsed->program', $filters['program']);
        }
        if ($filters['call_id'] !== '') {
            $q->whereJsonContains('parsed->call_id', $filters['call_id']);
        }
        if ($filters['security'] === '1') {
            // Only rows that carry a SecurityEvent tag.
            $q->whereRaw("JSON_EXTRACT(parsed, '$.security_event') IS NOT NULL");
        }
        if ($filters['search'] !== '') {
            $q->where('message', 'like', '%' . $filters['search'] . '%');
        }

        $since = $this->parseSince($filters['since']);
        if ($since) {
            $q->where('received_at', '>=', $since);
        }

        $messages = $q->orderByDesc('received_at')->paginate(75)->withQueryString();
        $distinct = $this->ucmDistincts($since);

        return view('admin.syslog.ucm', [
            'messages' => $messages,
            'filters'  => $filters,
            'severities' => $distinct['severities'],
            'programs'   => $distinct['programs'],
        ]);
    }

    private function ucmDistincts(?\Illuminate\Support\Carbon $since): array
    {
        $base = SyslogMessage::query()
            ->where('source_type', 'ucm')
            ->whereNotNull('parsed');

        if ($since) {
            $base->where('received_at', '>=', $since);
        }

        $severities = (clone $base)
            ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(parsed, '$.asterisk_severity')) AS v")
            ->whereRaw("JSON_EXTRACT(parsed, '$.asterisk_severity') IS NOT NULL")
            ->orderBy('v')
            ->pluck('v')
            ->filter()
            ->values()
            ->all();

        $programs = (clone $base)
            ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(parsed, '$.program')) AS v")
            ->whereRaw("JSON_EXTRACT(parsed, '$.program') IS NOT NULL")
            ->orderBy('v')
            ->pluck('v')
            ->filter()
            ->values()
            ->all();

        return ['severities' => $severities, 'programs' => $programs];
    }

    private function sophosDistincts(?\Illuminate\Support\Carbon $since): array
    {
        $base = SyslogMessage::query()
            ->where('source_type', 'sophos')
            ->whereNotNull('parsed');

        if ($since) {
            $base->where('received_at', '>=', $since);
        }

        // JSON_UNQUOTE keeps the values clean (no surrounding quotes).
        $components = (clone $base)
            ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(parsed, '$.log_component')) AS v")
            ->whereRaw("JSON_EXTRACT(parsed, '$.log_component') IS NOT NULL")
            ->orderBy('v')
            ->pluck('v')
            ->filter()
            ->values()
            ->all();

        $subtypes = (clone $base)
            ->selectRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(parsed, '$.log_subtype')) AS v")
            ->whereRaw("JSON_EXTRACT(parsed, '$.log_subtype') IS NOT NULL")
            ->orderBy('v')
            ->pluck('v')
            ->filter()
            ->values()
            ->all();

        return ['components' => $components, 'subtypes' => $subtypes];
    }

    /**
     * Polling endpoint for the live tail. Returns JSON of rows since
     * a given id (or the last 50 when no anchor given). Cheap enough
     * to call every 3-5 seconds from the browser.
     */
    public function tail(Request $request): JsonResponse
    {
        $sinceId = (int) $request->get('since_id', 0);
        $limit   = min(200, max(10, (int) $request->get('limit', 50)));

        $q = SyslogMessage::query();

        if ($sinceId > 0) {
            $q->where('id', '>', $sinceId);
        }

        $rows = $q->orderByDesc('id')
            ->limit($limit)
            ->get(['id','received_at','severity','source_type','source_ip','host','program','message'])
            ->map(fn ($m) => [
                'id'           => $m->id,
                'received_at'  => $m->received_at?->toIso8601String(),
                'severity'     => $m->severity,
                'severity_label' => $m->severityLabel(),
                'severity_class' => $m->severityBadgeClass(),
                'source_type'  => $m->source_type,
                'source_class' => $m->sourceTypeBadgeClass(),
                'source_ip'    => $m->source_ip,
                'host'         => $m->host,
                'program'      => $m->program,
                'message'      => mb_strimwidth((string) $m->message, 0, 500, '…'),
            ]);

        return response()->json([
            'rows'    => $rows,
            'last_id' => $rows->max('id') ?? $sinceId,
        ]);
    }

    // ─── Alert rules CRUD ────────────────────────────────────────────────

    public function rulesIndex(): View
    {
        $rules = SyslogAlertRule::orderBy('name')->get();
        return view('admin.syslog.rules.index', compact('rules'));
    }

    public function rulesCreate(): View
    {
        return view('admin.syslog.rules.form', [
            'rule' => new SyslogAlertRule([
                'enabled'          => true,
                'severity_max'     => 4,
                'event_severity'   => 'warning',
                'event_module'     => 'syslog',
                'cooldown_minutes' => 15,
            ]),
        ]);
    }

    public function rulesStore(Request $request): RedirectResponse
    {
        $data = $this->validateRule($request);
        SyslogAlertRule::create($data);
        return redirect()->route('admin.syslog.rules.index')
            ->with('success', 'Syslog alert rule created.');
    }

    public function rulesEdit(SyslogAlertRule $rule): View
    {
        return view('admin.syslog.rules.form', compact('rule'));
    }

    public function rulesUpdate(Request $request, SyslogAlertRule $rule): RedirectResponse
    {
        $data = $this->validateRule($request);
        $rule->update($data);
        return redirect()->route('admin.syslog.rules.index')
            ->with('success', 'Syslog alert rule updated.');
    }

    public function rulesDestroy(SyslogAlertRule $rule): RedirectResponse
    {
        $rule->delete();
        return redirect()->route('admin.syslog.rules.index')
            ->with('success', 'Syslog alert rule deleted.');
    }

    /**
     * Run the source-tagger and alert matcher on demand, e.g. after
     * adding a new device or rule. Returns a flash message.
     */
    public function runProcessors(): RedirectResponse
    {
        try {
            (new TagSyslogSourcesJob())->handle();
            (new ParseSyslogPayloadsJob())->handle();
            (new MatchSyslogAlertsJob())->handle();
            return back()->with('success', 'Source tagger, payload parser, and alert matcher ran successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Processor run failed: ' . $e->getMessage());
        }
    }

    /**
     * Wipe the syslog_messages table. Destructive — guarded by a typed
     * confirmation token from the form so it can't be hit accidentally.
     * Alert rules and their match counts are NOT touched.
     */
    public function clearAll(Request $request): RedirectResponse
    {
        $request->validate([
            'confirm' => 'required|in:CLEAR',
        ], [
            'confirm.in' => 'Type CLEAR to confirm — the wipe was not performed.',
        ]);

        try {
            $deleted = DB::table('syslog_messages')->count();
            DB::table('syslog_messages')->truncate();

            return redirect()->route('admin.syslog.index')
                ->with('success', "Cleared {$deleted} syslog messages.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Clear failed: ' . $e->getMessage());
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Parse a "since" filter:
     *   '15m','1h','24h','7d' → Carbon timestamp
     *   '' / 'all'             → null (no time filter)
     */
    private function parseSince(string $value): ?\Illuminate\Support\Carbon
    {
        if ($value === '' || $value === 'all') return null;

        if (preg_match('/^(\d+)([mhd])$/', $value, $m)) {
            return match ($m[2]) {
                'm' => now()->subMinutes((int) $m[1]),
                'h' => now()->subHours((int) $m[1]),
                'd' => now()->subDays((int) $m[1]),
            };
        }

        // Fall back to 24h on anything we don't recognize.
        return now()->subDay();
    }

    private function stats(?\Illuminate\Support\Carbon $since): array
    {
        $base = SyslogMessage::query();
        if ($since) {
            $base->where('received_at', '>=', $since);
        }

        $bySeverity = (clone $base)
            ->selectRaw('severity, COUNT(*) as c')
            ->groupBy('severity')
            ->pluck('c', 'severity');

        $bySource = (clone $base)
            ->selectRaw('COALESCE(source_type, \'unknown\') as st, COUNT(*) as c')
            ->groupBy('st')
            ->pluck('c', 'st');

        // Parser backlog — rows tagged with a parsable source_type but
        // not yet processed by ParseSyslogPayloadsJob.
        $parserPending = SyslogMessage::query()
            ->whereIn('source_type', ['sophos', 'ucm'])
            ->whereNull('parsed')
            ->count();

        return [
            'total'          => (clone $base)->count(),
            'critical'       => collect($bySeverity)->filter(fn($_, $sev) => (int)$sev <= 2)->sum(),
            'errors'         => (int) ($bySeverity[3] ?? 0),
            'warnings'       => (int) ($bySeverity[4] ?? 0),
            'by_severity'    => $bySeverity->toArray(),
            'by_source'      => $bySource->toArray(),
            'unique_hosts'   => (clone $base)->distinct('host')->count('host'),
            'parser_pending' => $parserPending,
        ];
    }

    private function validateRule(Request $request): array
    {
        $data = $request->validate([
            'name'             => 'required|string|max:191',
            'enabled'          => 'sometimes|boolean',
            'severity_max'     => 'required|integer|min:0|max:7',
            'source_type'      => 'nullable|in:sophos,cisco,ucm,printer,vps,unknown',
            'host_contains'    => 'nullable|string|max:191',
            'message_regex'    => 'nullable|string|max:500',
            'event_severity'   => 'required|in:info,warning,critical',
            'event_module'     => 'required|string|max:32',
            'cooldown_minutes' => 'required|integer|min:1|max:1440',
        ]);

        $data['enabled'] = $request->boolean('enabled');

        // Reject obviously broken regex up front so the matcher doesn't
        // silently skip it later.
        if (!empty($data['message_regex'])) {
            $pattern = $data['message_regex'];
            if (!preg_match('/^\/.*\/[a-z]*$/i', $pattern)
                && !preg_match('/^#.*#[a-z]*$/i', $pattern)) {
                $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
            }
            if (@preg_match($pattern, '') === false) {
                abort(422, 'Invalid regular expression in message_regex.');
            }
        }

        return $data;
    }
}
