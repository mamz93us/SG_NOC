<?php

namespace App\Services\Ai;

use App\Http\Controllers\Home\HomeAssetsController;
use App\Models\Announcement;
use App\Models\Attendance\AttendanceOwner;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Branch;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attendance\MonthlyDay;
use App\Services\Attendance\MonthlySheet;
use App\Services\Attendance\MonthlyTotals;
use App\Services\Attendance\ShiftResolver;
use App\Services\Home\PaydayCalculator;
use App\Services\Ticketing\TicketCatalog;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Ticketing\TicketStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The assistant's hands. Constructed with the authenticated identity and
 * injects it into every call — the model never passes an employee id, email
 * or Azure id, which is the whole security model this host already follows
 * for its ticket tracker (see TicketRequestService::ownedBy).
 *
 * The one place the model names another person is the team attendance tools,
 * and even there it only picks from a list the server built: team() is read
 * from the signed-in employee's HR reporting lines and their row on the
 * attendance owner list, so a name that is not on it is "not found" — never
 * looked up anywhere else.
 *
 * `call()` never throws for an expected "not found" — it returns
 * `['error' => '...']` so the model can read the reason and tell the
 * employee, rather than the turn failing outright.
 *
 * draft_ticket, draft_email and draft_calendar_event all follow the same
 * rule: this class only ever *shapes* a draft (`'draft' => true`) — nothing
 * is submitted, sent or created here. The real write happens in
 * AssistantController (ticket()/email()/calendarEvent()), only once the
 * employee explicitly confirms in the chat UI, and always against the
 * signed-in employee's own identity resolved server-side — never an id or
 * address the model supplied.
 */
class AssistantToolbox
{
    /**
     * A team overview lists at most this many people. The largest team in the
     * HR records was 64 on 2026-09-13, but a whole-company owner sees everyone;
     * past this the list is cut and says how to narrow it. The counts always
     * cover the whole group.
     */
    private const TEAM_OVERVIEW_LIMIT = 100;

    /** A "which one did you mean?" answer names at most this many people. */
    private const CANDIDATE_LIMIT = 10;

    /** The periods the attendance tools understand. Weeks run Sunday to Saturday. */
    private const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month'];

    /** The longest from–to span one call may ask for. */
    private const MAX_RANGE_DAYS = 62;

    private const NO_TEAM = 'Nobody reports to you in the HR records and you are not on the attendance owner list, so no one else\'s attendance is available to you. Attendance is only shown to the employee, their own manager or supervisor, and the attendance owners HR has chosen; HR can correct a reporting line or the owner list.';

    /** What get_team_attendance's `only` can ask for, and the MonthlyTotals figure that counts it. */
    private const ONLY = [
        'absent' => 'absentDays',
        'late' => 'lateDays',
        'early_leave' => 'earlyLeaveDays',
        'missing_check_out' => 'missingCheckOuts',
        'not_recorded' => 'unrecordedDays',
        'present' => 'presentDays',
    ];

    /** @var Collection<int, Employee>|null see team() */
    private ?Collection $team = null;

    /** @var array{company: bool, branch_ids: list<int>}|null see ownerAccess() */
    private ?array $ownerAccess = null;

    public function __construct(
        private User $user,
        private ?Employee $employee,
        private ?IdentityUser $identity,
        private KnowledgeRetriever $retriever,
        private TicketRequestService $tickets,
    ) {}

    /** OpenAI-shaped function tool definitions, offered on every chat turn. */
    public function definitions(): array
    {
        return [
            $this->def('search_knowledge',
                'Search the company\'s IT and HR knowledge base for an answer. Always try this before answering an IT/HR/policy question, and before drafting a ticket.',
                ['query' => ['type' => 'string', 'description' => 'The employee\'s question, in their own words.']],
                ['query']),
            $this->def('get_my_profile',
                'Get the signed-in employee\'s own profile: department, branch, job title, manager, extension.',
                [], []),
            $this->def('get_my_assets',
                'Get the signed-in employee\'s own assigned IT assets, accessories and licenses.',
                [], []),
            $this->def('get_my_tickets',
                'Get the signed-in employee\'s own IT support tickets, optionally filtered by status.',
                ['status' => ['type' => 'string', 'description' => 'One of: all, open, closed. Defaults to all.']],
                []),
            $this->def('get_ticket_details',
                'Get full details (timeline, attachments) of one of the signed-in employee\'s own tickets, by id.',
                ['ticket_id' => ['type' => 'integer', 'description' => 'The ticket id, from get_my_tickets.']],
                ['ticket_id']),
            $this->def('lookup_colleague',
                'Search the employee directory for a colleague\'s work contact details — name, job title, department, branch, extension, work/mobile phone, email. Matches on name, email, phone or extension; can also filter to one branch.',
                [
                    'query' => ['type' => 'string', 'description' => 'Name, email, phone number or extension to search for. Leave blank (empty string) to only filter by branch.'],
                    'branch' => ['type' => 'string', 'description' => 'Optional — a branch name to narrow the search to (e.g. "Cairo", "ABH").'],
                ],
                []),
            $this->def('get_company_info',
                'Get company info: upcoming events/holidays, recent announcements, or the next payday date.',
                ['topic' => ['type' => 'string', 'description' => 'One of: events, announcements, payday.']],
                ['topic']),
            $this->def('get_my_security_score',
                'Get the signed-in employee\'s own KnowBe4 security-awareness score: phishing test results and outstanding security training.',
                [], []),
            $this->def('get_my_attendance',
                'Get the signed-in employee\'s OWN fingerprint attendance: check-in and check-out times per day, hours worked, lateness, early leaves, absences and missing check-outs. Only ever their own — for anyone else, use get_team_member_attendance / get_team_attendance.',
                $this->periodParameters(),
                []),
            $this->def('get_team_attendance',
                'Attendance of a group of other people: those the signed-in employee may see among their direct reports (HR records) and, if they are on the attendance owner list (e.g. a general manager), everyone in their branches or the whole company. Returns counts for the group plus one entry per person — status, check-in and check-out for a single day, or totals for a longer period. Call it straight away for questions about many people ("who is absent today?", "how many were late in Jeddah last week?"); it checks access itself, so never refuse or ask about the employee\'s role first.',
                $this->periodParameters() + [
                    'branch' => ['type' => 'string', 'description' => 'Optional — only people in this branch: its code (JED) or its city (Jeddah).'],
                    'department' => ['type' => 'string', 'description' => 'Optional — only people in this department.'],
                    'only' => ['type' => 'string', 'enum' => array_keys(self::ONLY), 'description' => 'Optional — list only people with at least one such day in the period. The counts still cover everyone.'],
                ],
                []),
            $this->def('get_team_member_attendance',
                'Day-by-day attendance of ONE other person, in the same detail as get_my_attendance. Call it straight away whenever the employee asks about someone else\'s attendance: it checks access itself against the HR records and the attendance owner list, so never refuse or ask about the employee\'s role first. A person outside their access comes back as an error.',
                ['member' => ['type' => 'string', 'description' => 'The person\'s name, email or employee number.']]
                    + $this->periodParameters()
                    + ['branch' => ['type' => 'string', 'description' => 'Optional — the person\'s branch code (CAI) or city (Cairo). Only when the employee names that person\'s branch in this request; never carry one over from an earlier question.']],
                ['member']),
            $this->def('list_ticket_categories',
                'List the IT ticketing system\'s categories and sub-categories, for drafting a ticket.',
                [], []),
            $this->def('draft_ticket',
                'Draft an IT support ticket for the employee to review and send. Only call this AFTER search_knowledge has failed to answer the question — reason_not_solved must explain what was checked and why it did not resolve the problem. This does NOT submit anything.',
                [
                    'title' => ['type' => 'string', 'description' => 'Short ticket title.'],
                    'description' => ['type' => 'string', 'description' => 'Full description of the problem, including what troubleshooting was already tried.'],
                    'category_id' => ['type' => 'integer', 'description' => 'From list_ticket_categories.'],
                    'subcategory_id' => ['type' => 'integer', 'description' => 'From list_ticket_categories, must belong to category_id.'],
                    'reason_not_solved' => ['type' => 'string', 'description' => 'Why this could not be resolved from search_knowledge / the employee\'s own data.'],
                ],
                ['title', 'description', 'category_id', 'subcategory_id', 'reason_not_solved']),
            $this->def('draft_email',
                'Draft an email to be sent from the employee\'s own mailbox. This does NOT send anything — the employee reviews and confirms in the chat before it goes out. Use lookup_colleague first to resolve a colleague\'s name to their exact email address; never guess an address.',
                [
                    'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Recipient email addresses.'],
                    'subject' => ['type' => 'string', 'description' => 'Email subject line.'],
                    'body' => ['type' => 'string', 'description' => 'Plain-text email body.'],
                ],
                ['to', 'subject', 'body']),
            $this->def('draft_calendar_event',
                'Draft a calendar event on the employee\'s own calendar — a meeting, a Teams meeting, or a plain reminder with no attendees. This does NOT create anything — the employee reviews and confirms in the chat before it\'s added. Use lookup_colleague first to resolve any attendee\'s name to their exact email address. Ask the employee for a specific date and time if they were not given — never guess one.',
                [
                    'subject' => ['type' => 'string', 'description' => 'Event title.'],
                    'start' => ['type' => 'string', 'description' => 'Start date/time, ISO 8601 (e.g. 2026-09-10T14:00:00), in the employee\'s own Africa/Cairo timezone.'],
                    'end' => ['type' => 'string', 'description' => 'End date/time, ISO 8601, same timezone. For a reminder with no real duration, use start + 15 minutes.'],
                    'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Attendee email addresses. Leave empty for a personal reminder.'],
                    'body' => ['type' => 'string', 'description' => 'Optional description/agenda.'],
                    'is_teams_meeting' => ['type' => 'boolean', 'description' => 'True to make this a Teams meeting with a join link. False for a plain calendar entry/reminder.'],
                ],
                ['subject', 'start', 'end']),
        ];
    }

    /** @return array<string,mixed> JSON-serializable tool result */
    public function call(string $name, array $args): array
    {
        return match ($name) {
            'search_knowledge' => $this->searchKnowledge((string) ($args['query'] ?? '')),
            'get_my_profile' => $this->getMyProfile(),
            'get_my_assets' => $this->getMyAssets(),
            'get_my_tickets' => $this->getMyTickets((string) ($args['status'] ?? 'all')),
            'get_ticket_details' => $this->getTicketDetails((int) ($args['ticket_id'] ?? 0)),
            'lookup_colleague' => $this->lookupColleague(
                (string) ($args['query'] ?? $args['name'] ?? ''),
                $args['branch'] ?? null,
            ),
            'get_company_info' => $this->getCompanyInfo((string) ($args['topic'] ?? '')),
            'get_my_security_score' => $this->getMySecurityScore(),
            'get_my_attendance' => $this->getMyAttendance($args),
            'get_team_attendance' => $this->getTeamAttendance(
                $args,
                (string) ($args['branch'] ?? ''),
                (string) ($args['department'] ?? ''),
                (string) ($args['only'] ?? ''),
            ),
            'get_team_member_attendance' => $this->getTeamMemberAttendance(
                (string) ($args['member'] ?? ''),
                $args,
                (string) ($args['branch'] ?? ''),
            ),
            'list_ticket_categories' => $this->listTicketCategories(),
            'draft_ticket' => $this->draftTicket($args),
            'draft_email' => $this->draftEmail($args),
            'draft_calendar_event' => $this->draftCalendarEvent($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function def(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    // Cast, not just pass through: PHP's json_encode cannot
                    // tell an empty associative array from an empty indexed
                    // one, so a tool with no parameters (get_my_profile, etc.)
                    // would serialize `properties` as `[]` — Azure's function
                    // schema validation requires a JSON object here even when
                    // empty, and rejects `[]` with a 400.
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    /** The range arguments every attendance tool shares; attendanceRange() reads them. */
    private function periodParameters(): array
    {
        return [
            'period' => ['type' => 'string', 'enum' => self::PERIODS, 'description' => 'Which days. Weeks run Sunday to Saturday. Defaults to this_month.'],
            'month' => ['type' => 'string', 'description' => 'Optional — a specific month as YYYY-MM (e.g. 2026-08), when a month is named. Overrides period.'],
            'from' => ['type' => 'string', 'description' => 'Optional — first day as YYYY-MM-DD, together with to, for any other span ("since the 1st", "the last 10 days"). Overrides month and period. At most '.self::MAX_RANGE_DAYS.' days.'],
            'to' => ['type' => 'string', 'description' => 'Optional — last day as YYYY-MM-DD, together with from.'],
        ];
    }

    private function searchKnowledge(string $query): array
    {
        if (trim($query) === '') {
            return ['error' => 'query is required'];
        }

        $results = $this->retriever->search($query, $this->employee, 6);

        if ($results->isEmpty()) {
            return [
                'found' => false,
                'message' => 'No knowledge base article answers this. You may now troubleshoot from general IT knowledge, and if that fails, draft a ticket via draft_ticket (with reason_not_solved explaining what was checked).',
            ];
        }

        return [
            'found' => true,
            'results' => $results->map(fn ($r) => [
                'title' => $r['title'],
                'heading' => $r['heading'],
                'content' => mb_substr($r['content'], 0, 1200),
            ])->values()->all(),
        ];
    }

    private function getMyProfile(): array
    {
        if (! $this->employee) {
            return ['error' => 'No HR record is linked to your account yet — contact IT to be added to the directory.'];
        }

        $e = $this->employee->loadMissing(['branch', 'department', 'manager']);

        return [
            'name' => $e->name,
            'job_title' => $e->job_title,
            'department' => $e->department?->name,
            'branch' => $e->branch?->name,
            'manager' => $e->manager?->name,
            'extension' => $e->extension_number,
            'work_phone' => $e->work_phone,
            'email' => $e->email,
            'status' => $e->status,
        ];
    }

    private function getMyAssets(): array
    {
        $data = app(HomeAssetsController::class)->assetsFor($this->employee);

        return [
            'it_assets' => $data['itAssets']->map(fn ($a) => [
                'device' => trim(($a->device?->manufacturer ?? '').' '.($a->device?->model ?? '')),
                'serial' => $a->device?->serial_number,
                'assigned_date' => (string) $a->assigned_date,
                'returned' => (bool) $a->returned_date,
            ])->values()->all(),
            'accessories' => $data['accessories']->map(fn ($a) => [
                'accessory' => $a->accessory?->name,
                'assigned_date' => (string) $a->assigned_date,
                'returned' => (bool) $a->returned_date,
            ])->values()->all(),
            'licenses' => $data['licenses']->map(fn ($a) => [
                'license' => $a->license?->license_name,
            ])->values()->all(),
            'active_counts' => $data['activeCounts'],
        ];
    }

    private function getMyTickets(string $status): array
    {
        if (! $this->tickets->isConfigured()) {
            return ['error' => 'The ticketing system is not available right now.'];
        }

        $statusId = match (strtolower($status)) {
            'open', 'live' => TicketStatus::ALL, // filtered client-side below via isLive
            default => TicketStatus::ALL,
        };

        try {
            $tickets = $this->tickets->listFor((string) $this->user->email, $statusId);
        } catch (\Throwable) {
            return ['error' => 'The ticketing system did not answer. Try again shortly.'];
        }

        if (strtolower($status) === 'open' || strtolower($status) === 'live') {
            $tickets = array_values(array_filter($tickets, fn ($t) => TicketStatus::isLive($t['status_id'])));
        }

        return [
            'tickets' => array_map(fn ($t) => [
                'id' => $t['id'],
                'title' => $t['title'],
                'status' => $t['status_name'],
                'created_at' => $t['created_at'],
            ], $tickets),
        ];
    }

    private function getTicketDetails(int $ticketId): array
    {
        if ($ticketId <= 0) {
            return ['error' => 'ticket_id is required'];
        }

        $email = (string) $this->user->email;

        try {
            if (! $this->tickets->ownedBy($ticketId, $email)) {
                return ['error' => 'That ticket does not belong to you.'];
            }

            $detail = $this->tickets->details($ticketId);
        } catch (\Throwable) {
            return ['error' => 'The ticketing system did not answer. Try again shortly.'];
        }

        if ($detail === null) {
            return ['error' => 'Ticket not found.'];
        }

        return [
            'id' => $detail['id'],
            'title' => $detail['title'],
            'description' => $detail['description'],
            'status' => $detail['status_name'],
            'engineer' => $detail['engineer_name'],
            'timeline' => array_map(fn ($t) => [
                'status' => $t['status_name'],
                'comment' => $t['comments'],
                'by' => $t['created_by'],
                'at' => $t['created_at'],
            ], $detail['timeline']),
        ];
    }

    /**
     * Searches the HR directory (Employee), not the public Contact table —
     * this is the source get_my_profile itself reads from, so it is the one
     * place extension/department/branch actually live. Work contact fields
     * only: nothing here is more sensitive than what the printed phonebook
     * already put on every desk.
     */
    private function lookupColleague(string $query, ?string $branch = null): array
    {
        $query = trim($query);
        $branch = trim((string) $branch);

        if ($query === '' && $branch === '') {
            return ['error' => 'query or branch is required'];
        }

        $employees = Employee::query()
            ->with(['branch', 'department'])
            ->where('status', 'active')
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($w) use ($query) {
                    $w->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('work_phone', 'like', "%{$query}%")
                        ->orWhere('mobile_phone', 'like', "%{$query}%")
                        ->orWhere('extension_number', 'like', "%{$query}%");
                });
            })
            ->when($branch !== '', function ($q) use ($branch) {
                // Branches are named by code (CAI); people say the city (Cairo).
                $q->whereHas('branch', fn ($b) => $b->where(
                    fn ($w) => $w->where('name', 'like', "%{$branch}%")->orWhere('city', 'like', "%{$branch}%")
                ));
            })
            ->limit(10)
            ->get();

        if ($employees->isEmpty()) {
            return ['colleagues' => [], 'message' => 'No matching colleague found in the directory.'];
        }

        return [
            'colleagues' => $employees->map(fn (Employee $e) => [
                'name' => $e->name,
                'job_title' => $e->job_title,
                'department' => $e->department?->name,
                'branch' => $e->branch?->name,
                'extension' => $e->extension_number,
                'work_phone' => $e->work_phone,
                'mobile_phone' => $e->mobile_phone,
                'email' => $e->email,
            ])->values()->all(),
        ];
    }

    private function getCompanyInfo(string $topic): array
    {
        return match (strtolower($topic)) {
            'events' => [
                'events' => CompanyEvent::upcoming()->limit(5)->get()->map(fn ($e) => [
                    'subject' => $e->subject,
                    'when' => $e->whenLabel(),
                    'location' => $e->location,
                ])->values()->all(),
            ],
            'announcements' => [
                'announcements' => Announcement::liveFor($this->employee)->limit(5)->get()->map(fn ($a) => [
                    'title' => $a->title,
                    'excerpt' => $a->excerpt(200),
                ])->values()->all(),
            ],
            'payday' => (function () {
                $next = app(PaydayCalculator::class)->next();

                return ['payday' => $next['date']->toDateString(), 'days_left' => $next['days_left']];
            })(),
            default => ['error' => 'topic must be one of: events, announcements, payday'],
        };
    }

    /**
     * The signed-in employee's own KnowBe4 figures, and only ever their own —
     * same query shape as HomeController::securityScore(), which is the one
     * other place this table is ever read from a request.
     */
    private function getMySecurityScore(): array
    {
        if (! Setting::get()->knowbe4_enabled) {
            return ['error' => 'Security awareness scoring is not enabled for this company.'];
        }

        $email = (string) $this->user->email;

        $score = Knowbe4Score::query()
            ->where(function ($q) use ($email) {
                $q->where('email', $email);

                if ($this->employee) {
                    $q->orWhere('employee_id', $this->employee->id);
                }
            })
            ->first();

        if (! $score) {
            return ['error' => 'No security awareness score has synced for you yet.'];
        }

        return [
            'phish_prone_percentage' => $score->phish_prone_percentage,
            'phish_prone_band' => $score->pppBand(), // low | medium | high | unknown
            'has_been_phished' => $score->hasBeenPhished(),
            'phishing_emails_failed' => $score->phish_fail_count,
            'phishing_emails_sent' => $score->phish_sent_count,
            'trainings_outstanding' => $score->trainings_outstanding,
            'trainings_completed' => $score->trainings_completed,
        ];
    }

    /**
     * The signed-in employee's own attendance, read from `attendance_days` —
     * the same rows HR sees on Attendance ▸ Monthly sheet, never recomputed
     * here, so the answer in the chat and the answer in the admin agree.
     *
     * Scoped by $this->employee, resolved from the session in
     * AssistantController. There is deliberately no employee argument: with no
     * way to name somebody else, no prompt can talk this tool into fetching a
     * colleague's punches.
     */
    private function getMyAttendance(array $args): array
    {
        if (! $this->employee) {
            return ['error' => 'No HR record is linked to your account yet — contact IT to be added to the directory.'];
        }

        if (! BiotimeEmployee::where('employee_id', $this->employee->id)->exists()) {
            return ['error' => 'Your fingerprint code is not linked to your HR record yet, so no attendance is recorded for you. HR can link it on the attendance page.'];
        }

        return $this->attendanceOf($this->employee, $args);
    }

    /**
     * One person's attendance over a period, day by day. Shared by
     * get_my_attendance and get_team_member_attendance so the two can never
     * describe a day differently; whose attendance it is, the caller decided.
     */
    private function attendanceOf(Employee $employee, array $args): array
    {
        $range = $this->attendanceRange($args);

        if (is_string($range)) {
            return ['error' => $range];
        }

        [$from, $to, $label] = $range;

        $days = $this->sheetDays(app(MonthlySheet::class), $employee, $from, $to);
        $totals = MonthlyTotals::fromDays($days);

        return array_filter([
            'period' => $label,
            'from' => $from,
            'to' => $to,
            'summary' => $this->attendanceSummary($totals),
            // Worked hours are check-in to check-out, so a day with no
            // punch-out adds nothing. Say so rather than let a total read as
            // quietly short.
            'note' => $totals->missingCheckOuts > 0
                ? "{$totals->missingCheckOuts} day(s) have no check-out, so those hours are not counted in the total. HR can correct a day on request."
                : null,
            'today' => $this->todayNote($from, $to),
            'days' => array_map(fn (MonthlyDay $day) => $this->attendanceDay($day), $days),
        ], fn ($value) => $value !== null);
    }

    /** @return list<MonthlyDay> every date from $from to $to, in order */
    private function sheetDays(MonthlySheet $sheet, Employee $employee, string $from, string $to): array
    {
        $days = [];

        // A week can straddle two months; a month never more than itself.
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->toDateString() <= $to; $m = $m->addMonth()) {
            foreach ($sheet->build($employee, $m->format('Y-m')) as $day) {
                if ($day->date >= $from && $day->date <= $to) {
                    $days[] = $day;
                }
            }
        }

        return $days;
    }

    /** @return array<string, int|string> */
    private function attendanceSummary(MonthlyTotals $totals): array
    {
        return [
            'work_days' => $totals->workDays,
            'present' => $totals->presentDays,
            'absent' => $totals->absentDays,
            'excused' => $totals->excusedDays,
            'days_off' => $totals->offDays,
            'holidays' => $totals->holidayDays,
            'total_worked' => $totals->workedLabel().' (h:mm)',
            'overtime' => $totals->overtimeLabel().' (h:mm)',
            'late_days' => $totals->lateDays,
            'late_total_minutes' => $totals->lateMinutes,
            'early_leave_days' => $totals->earlyLeaveDays,
            'early_leave_total_minutes' => $totals->earlyLeaveMinutes,
            'missing_check_outs' => $totals->missingCheckOuts,
            'not_recorded_yet' => $totals->unrecordedDays,
        ];
    }

    /** The figures one line per person needs; get_team_member_attendance has the full set. */
    private function memberSummary(MonthlyTotals $totals): array
    {
        return [
            'present' => $totals->presentDays,
            'absent' => $totals->absentDays,
            'excused' => $totals->excusedDays,
            'late_days' => $totals->lateDays,
            'late_total_minutes' => $totals->lateMinutes,
            'early_leave_days' => $totals->earlyLeaveDays,
            'missing_check_outs' => $totals->missingCheckOuts,
            'not_recorded_yet' => $totals->unrecordedDays,
            'total_worked' => $totals->workedLabel().' (h:mm)',
        ];
    }

    /** Two sets of totals added together, figure by figure. */
    private function plus(MonthlyTotals $a, MonthlyTotals $b): MonthlyTotals
    {
        $sum = [];

        foreach (get_object_vars($a) as $field => $value) {
            $sum[$field] = $value + $b->{$field};
        }

        return new MonthlyTotals(...$sum);
    }

    /**
     * The people in team(), narrowed by branch, department and `only`: counts
     * for the whole narrowed group, plus one entry per person — the day itself
     * when the period is one day ("who is absent today?"), the period's totals
     * otherwise. The same rows and totals as each person's own sheet.
     */
    private function getTeamAttendance(array $args, string $branch, string $department, string $only): array
    {
        $team = $this->team();

        if ($team->isEmpty()) {
            return ['error' => self::NO_TEAM];
        }

        $range = $this->attendanceRange($args);

        if (is_string($range)) {
            return ['error' => $range];
        }

        $only = strtolower(trim($only));

        if ($only !== '' && ! isset(self::ONLY[$only])) {
            return ['error' => 'only must be one of: '.implode(', ', array_keys(self::ONLY)).'.'];
        }

        $group = $this->inBranch($team, $branch)
            ->filter(fn (Employee $e) => $this->nameContains($e->department?->name, $department))
            ->values();

        if ($group->isEmpty()) {
            return [
                'error' => 'Nobody whose attendance you can see is in that branch or department.',
                'branches' => $this->namesOf($team, 'branch'),
                'departments' => $this->namesOf($team, 'department'),
            ];
        }

        [$from, $to, $label] = $range;
        $singleDay = $from === $to;

        $linked = $this->fingerprintLinked($group);
        $unlinked = $group->reject(fn (Employee $e) => isset($linked[$e->id]))->pluck('name')->all();

        // One resolver for everyone, so shifts, holidays and the people load
        // once; and a hundred people's day rows per query, without punches.
        // A whole-company owner sees some 560 people with a fingerprint code.
        $resolver = app(ShiftResolver::class);
        $resolver->preloadEmployees(array_keys($linked));
        $sheet = new MonthlySheet($resolver);

        $sum = new MonthlyTotals;
        $members = [];

        foreach ($group->filter(fn (Employee $e) => isset($linked[$e->id]))->chunk(100) as $chunk) {
            $daysById = $sheet->daysWithoutPunches($chunk, $from, $to);

            foreach ($chunk as $employee) {
                $days = $daysById[(int) $employee->id];
                $totals = MonthlyTotals::fromDays($days);
                $sum = $this->plus($sum, $totals);

                $count = $only !== '' ? $totals->{self::ONLY[$only]} : null;

                if ($count === 0) {
                    continue;
                }

                $members[] = [
                    'count' => (int) $count,
                    'entry' => $this->memberIdentity($employee) + ($singleDay && $days !== []
                        ? ['day' => $this->attendanceDay($days[0])]
                        : ['summary' => $this->memberSummary($totals)]),
                ];
            }
        }

        // Asking for one kind of day over a period: the most such days first.
        // Otherwise, and on ties, the list stays in name order.
        if ($only !== '' && ! $singleDay) {
            usort($members, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        }

        return array_filter([
            'period' => $label,
            'from' => $from,
            'to' => $to,
            'can_see' => $this->accessDescription(),
            'people' => $group->count(),
            'counts' => $this->attendanceSummary($sum),
            // On one day every figure is a number of people; over a period it
            // is days, added up over everyone.
            'counts_are' => $singleDay ? 'people' : 'days, added up over everyone',
            'listed' => $only !== '' ? "only people with at least one {$only} day" : 'everyone',
            'today' => $this->todayNote($from, $to),
            'members' => array_column(array_slice($members, 0, self::TEAM_OVERVIEW_LIMIT), 'entry'),
            // Named, not dropped: missing from the list would read as "not
            // visible to you" or, worse, as nothing to report.
            'no_fingerprint_code' => array_slice($unlinked, 0, 50),
            'no_fingerprint_code_count' => count($unlinked),
            'note' => count($members) > self::TEAM_OVERVIEW_LIMIT
                ? 'Only '.self::TEAM_OVERVIEW_LIMIT.' of '.count($members).' people are listed; the counts cover everyone. Narrow it with branch, department or only, or ask about one person with get_team_member_attendance.'
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * One person from team(), in the same detail as get_my_attendance.
     * $member and $branch only ever select from that list.
     */
    private function getTeamMemberAttendance(string $member, array $args, string $branch): array
    {
        if ($this->team()->isEmpty()) {
            return ['error' => self::NO_TEAM];
        }

        $range = $this->attendanceRange($args);

        if (is_string($range)) {
            return ['error' => $range];
        }

        $member = trim($member);
        $branch = trim($branch);

        if ($member === '') {
            return ['error' => 'member is required: the name, email or employee number of someone whose attendance the employee can see.'];
        }

        $matches = $this->matchTeamMember($member, $branch);

        // A branch they did not mean — carried over from an earlier question,
        // or simply wrong — must not hide the person they did mean.
        if ($matches->isEmpty() && $branch !== '' && ($elsewhere = $this->matchTeamMember($member))->isNotEmpty()) {
            return [
                'error' => "Nobody matching \"{$member}\" is in \"{$branch}\", but the people below match in other branches. Tell the employee which branch they are in, then call again with that branch.",
                'candidates' => $this->candidates($elsewhere),
            ];
        }

        if ($matches->isEmpty()) {
            $where = $branch !== '' ? " in \"{$branch}\"" : '';

            return ['error' => "Nobody whose attendance the employee can see matches \"{$member}\"{$where}. They can see: {$this->accessDescription()}. Attendance is only shown to the employee, their own manager or supervisor, and the attendance owners HR has chosen; HR can correct a reporting line or the owner list."];
        }

        if ($matches->count() > 1) {
            return [
                'error' => "{$matches->count()} people the employee can see match. Ask which one is meant, then call again with their email or employee number, or with their branch.",
                'candidates' => $this->candidates($matches),
            ];
        }

        $employee = $matches->first();

        if ($this->fingerprintLinked(collect([$employee])) === []) {
            return ['error' => "{$employee->name}'s fingerprint code is not linked to their HR record yet, so no attendance is recorded for them. HR can link it on the attendance page."];
        }

        return [
            'employee' => $this->memberIdentity($employee),
            'visible_because' => $this->visibleBecause($employee),
        ] + $this->attendanceOf($employee, $args);
    }

    /**
     * @param  Collection<int, Employee>  $people
     * @return list<array<string, mixed>> enough to tell them apart, and no more
     */
    private function candidates(Collection $people): array
    {
        return $people->take(self::CANDIDATE_LIMIT)->map(fn (Employee $e) => $this->memberIdentity($e) + array_filter([
            'email' => $e->email,
            'employee_number' => $e->oracle_emp_no,
        ]))->values()->all();
    }

    /**
     * Everyone whose attendance the signed-in employee may see besides their
     * own: the people whose HR record names them as manager or supervisor —
     * direct reports only, a report's reports being that report's to ask
     * about — and, when they are on the attendance owner list, everyone in
     * their branches or the whole company. Never anyone who has left, and
     * nobody at all for an asker who has left.
     *
     * Read from the session's employee, never from anything the model sent.
     * A linked secondary mailbox is the same person as its primary record,
     * where reporting lines and owner rows live, so both ids count as the
     * asker. The people seen are primary records only: a secondary never
     * holds punches (see EmployeeLinker).
     *
     * @return Collection<int, Employee>
     */
    private function team(): Collection
    {
        if ($this->team !== null) {
            return $this->team;
        }

        $me = $this->myEmployeeIds();

        if ($me === [] || $this->employee->status === 'terminated') {
            return $this->team = collect();
        }

        $access = $this->ownerAccess();

        return $this->team = Employee::query()
            ->with(['branch', 'department'])
            ->when(! $access['company'], fn ($query) => $query->where(function ($q) use ($me, $access) {
                $q->whereIn('manager_id', $me)->orWhereIn('supervisor_id', $me);

                if ($access['branch_ids'] !== []) {
                    $q->orWhereIn('branch_id', $access['branch_ids']);
                }
            }))
            ->whereNotIn('id', $me)
            ->whereNull('linked_primary_employee_id')
            ->where('status', '!=', 'terminated')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return array{company: bool, branch_ids: list<int>} the signed-in employee's owner rows, added up */
    private function ownerAccess(): array
    {
        return $this->ownerAccess ??= AttendanceOwner::accessFor($this->myEmployeeIds());
    }

    /** @return list<int> the session's employee, and the primary record it mirrors */
    private function myEmployeeIds(): array
    {
        if (! $this->employee) {
            return [];
        }

        return array_values(array_unique(array_filter([
            (int) $this->employee->id,
            (int) $this->employee->linked_primary_employee_id,
        ])));
    }

    /**
     * The people in team() — in $branch, when given — that $query names: an
     * exact email, employee number or full name first; failing that, everyone
     * whose name holds every word.
     *
     * @return Collection<int, Employee>
     */
    private function matchTeamMember(string $query, string $branch = ''): Collection
    {
        $people = $this->inBranch($this->team(), $branch);
        $needle = mb_strtolower(trim($query));

        $exact = $people->filter(fn (Employee $e) => in_array($needle, [
            mb_strtolower(trim((string) $e->email)),
            mb_strtolower(trim((string) $e->oracle_emp_no)),
            mb_strtolower(trim((string) $e->name)),
        ], true));

        if ($exact->isNotEmpty()) {
            return $exact->values();
        }

        $words = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $people
            ->filter(fn (Employee $e) => $words !== [] && collect($words)->every(
                fn (string $word) => str_contains(mb_strtolower((string) $e->name), $word)
            ))
            ->values();
    }

    /**
     * Directory fields only — lookup_colleague shows the same to anyone — plus
     * the reporting line when the person reports to the one asking.
     */
    private function memberIdentity(Employee $employee): array
    {
        return array_filter([
            'name' => $employee->name,
            'job_title' => $employee->job_title,
            'department' => $employee->department?->name,
            'branch' => $this->branchLabel($employee->branch),
            'reports_to_you_as' => $this->reportingLine($employee),
        ], fn ($value) => $value !== null);
    }

    /** "manager", "supervisor" or both — null when they do not report to the one asking. */
    private function reportingLine(Employee $employee): ?string
    {
        $me = $this->myEmployeeIds();
        $manages = in_array((int) $employee->manager_id, $me, true);
        $supervises = in_array((int) $employee->supervisor_id, $me, true);

        return match (true) {
            $manages && $supervises => 'manager and supervisor',
            $manages => 'manager',
            $supervises => 'supervisor',
            default => null,
        };
    }

    /** Why the signed-in employee may see this person, for the model to say if asked. */
    private function visibleBecause(Employee $employee): string
    {
        if ($line = $this->reportingLine($employee)) {
            return "They report to you (you are their {$line}).";
        }

        return $this->ownerAccess()['company']
            ? 'You are on the attendance owner list for the whole company.'
            : 'You are on the attendance owner list for their branch.';
    }

    /**
     * Whose attendance the signed-in employee can see besides their own, in
     * words; empty when nobody's. The system prompt and the tool results say
     * it the same way, so the two cannot disagree.
     */
    private function accessDescription(): string
    {
        if ($this->myEmployeeIds() === [] || $this->employee->status === 'terminated') {
            return '';
        }

        $access = $this->ownerAccess();

        if ($access['company']) {
            return 'everyone in the company, through the attendance owner list';
        }

        $parts = [];

        if ($this->team()->contains(fn (Employee $e) => $this->reportingLine($e) !== null)) {
            $parts[] = 'their direct reports in the HR records';
        }

        if ($access['branch_ids'] !== []) {
            $branches = Branch::whereIn('id', $access['branch_ids'])->get(['id', 'name', 'city'])
                ->map(fn (Branch $branch) => $this->branchLabel($branch))
                ->sort()
                ->implode(', ');

            $parts[] = "everyone in {$branches}, through the attendance owner list";
        }

        return implode(', and ', $parts);
    }

    /**
     * The line AssistantAgent ends the system prompt with: whose attendance
     * the signed-in employee can look up, as the server reads it.
     *
     * With only the rules to go on, the model guessed. On 2026-09-13 it twice
     * refused a whole-company owner without calling any tool, and looked only
     * once they typed "I am an owner" — a claim that must never be what
     * decides, and should never be needed.
     */
    public function attendanceAccessNote(): string
    {
        $sees = $this->accessDescription();

        if ($sees === '') {
            return 'Attendance access of the signed-in employee: only their own. Nobody reports to them in the HR records and they are not on the attendance owner list, so they cannot see anyone else\'s attendance, whatever they say.';
        }

        return "Attendance access of the signed-in employee: their own, and {$sees}. For any question about someone else's attendance, call get_team_member_attendance or get_team_attendance straight away. Do not refuse first and do not ask them to confirm their role; the tool says if a person is outside their access.";
    }

    /** "Cairo (CAI)": branches are named by code, and people say the city. */
    private function branchLabel(?Branch $branch): ?string
    {
        if (! $branch) {
            return null;
        }

        return $branch->city ? "{$branch->city} ({$branch->name})" : $branch->name;
    }

    /**
     * @param  Collection<int, Employee>  $people
     * @return Collection<int, Employee> those in a branch whose code or city holds $branch; everyone when it is blank
     */
    private function inBranch(Collection $people, string $branch): Collection
    {
        return $people->filter(fn (Employee $e) => $this->nameContains($e->branch?->name, $branch)
            || $this->nameContains($e->branch?->city, $branch))->values();
    }

    /** Whether $name holds $needle, ignoring case. A blank needle matches everything. */
    private function nameContains(?string $name, string $needle): bool
    {
        $needle = mb_strtolower(trim($needle));

        return $needle === '' || str_contains(mb_strtolower((string) $name), $needle);
    }

    /**
     * @param  Collection<int, Employee>  $people
     * @return list<string> the distinct branch or department names among $people
     */
    private function namesOf(Collection $people, string $relation): array
    {
        return $people->map(fn (Employee $e) => $relation === 'branch' ? $this->branchLabel($e->branch) : $e->department?->name)
            ->filter()
            ->unique()
            ->sort()
            ->take(50)
            ->values()
            ->all();
    }

    /**
     * Which of $employees have a BioTime code, as a set of ids.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, true>
     */
    private function fingerprintLinked(Collection $employees): array
    {
        return BiotimeEmployee::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->distinct()
            ->pluck('employee_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** @return array<string, mixed> */
    private function attendanceDay(MonthlyDay $day): array
    {
        $row = $day->day;
        $date = CarbonImmutable::parse($day->date);

        $status = match (true) {
            $row !== null => $row->statusLabel(),
            $day->kind === MonthlyDay::KIND_HOLIDAY => 'Holiday: '.$day->kindLabel(),
            default => $day->emptyLabel(),
        };

        return array_filter([
            'date' => $day->date,
            'weekday' => $date->format('D'),
            'status' => $status,
            'check_in' => $row?->first_in?->format('H:i'),
            'check_out' => $row?->last_out?->format('H:i'),
            'worked' => $row && $row->worked_minutes !== null ? $row->workedLabel() : null,
            'due' => $row?->scheduled_start && $row->scheduled_end
                ? $row->scheduled_start->format('H:i').'–'.$row->scheduled_end->format('H:i')
                : null,
            'late_minutes' => (int) $row?->late_minutes ?: null,
            'early_leave_minutes' => (int) $row?->early_leave_minutes ?: null,
            'overtime_minutes' => (int) $row?->overtime_minutes ?: null,
            'problem' => $row?->first_in && ! $row->last_out ? 'No check-out recorded' : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The days a question covers: from–to, else month, else period. Weeks run
     * Sunday to Saturday, the work week in every branch, Egypt and KSA alike.
     *
     * Anything it does not understand is refused, never read as this month:
     * on 2026-09-13 "last_week" was, and a general manager was shown the
     * month's totals as last week's.
     *
     * @return array{0: string, 1: string, 2: string}|string [from, to, label], or why the range was refused
     */
    private function attendanceRange(array $args): array|string
    {
        $today = CarbonImmutable::today();
        $from = trim((string) ($args['from'] ?? ''));
        $to = trim((string) ($args['to'] ?? ''));

        if ($from !== '' || $to !== '') {
            $start = $this->isoDate($from);
            $end = $this->isoDate($to);

            if (! $start || ! $end) {
                return 'from and to must both be given, as dates like 2026-09-01.';
            }

            if ($start->greaterThan($end)) {
                return 'from must not be after to.';
            }

            if ((int) $start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
                return 'One call covers at most '.self::MAX_RANGE_DAYS.' days; ask for the range in parts.';
            }

            return [$start->toDateString(), $end->toDateString(), $start->format('j M Y').' – '.$end->format('j M Y')];
        }

        $month = trim((string) ($args['month'] ?? ''));

        if ($month !== '') {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                return 'month must be a month like 2026-08.';
            }

            $start = CarbonImmutable::parse($month.'-01');

            return [$start->toDateString(), $start->endOfMonth()->toDateString(), $start->format('F Y')];
        }

        $week = $today->startOfWeek(CarbonInterface::SUNDAY);
        $weekLabel = fn (CarbonImmutable $start) => $start->format('j M').' – '.$start->addDays(6)->format('j M Y');
        // Not subMonth(): from the 29th to the 31st it can land back in this month.
        $lastMonth = $today->subMonthNoOverflow();

        return match (strtolower(trim((string) ($args['period'] ?? '')))) {
            'today' => [$today->toDateString(), $today->toDateString(), 'Today'],
            'yesterday' => [$today->subDay()->toDateString(), $today->subDay()->toDateString(), 'Yesterday'],
            'this_week', 'week' => [$week->toDateString(), $week->addDays(6)->toDateString(), 'This week, '.$weekLabel($week)],
            'last_week' => [$week->subWeek()->toDateString(), $week->subDay()->toDateString(), 'Last week, '.$weekLabel($week->subWeek())],
            '', 'this_month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString(), $today->format('F Y')],
            'last_month' => [$lastMonth->startOfMonth()->toDateString(), $lastMonth->endOfMonth()->toDateString(), $lastMonth->format('F Y')],
            default => 'period must be one of: '.implode(', ', self::PERIODS).' — or give from and to as dates like 2026-09-01.',
        };
    }

    /** A YYYY-MM-DD date that exists, or null. */
    private function isoDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Said when the range includes today, whose figures can still change. */
    private function todayNote(string $from, string $to): ?string
    {
        $today = CarbonImmutable::today()->toDateString();

        return $from <= $today && $today <= $to
            ? 'Today is not over: its check-out is only the latest punch so far, so an early leave or a missing check-out today can still change.'
            : null;
    }

    private function listTicketCategories(): array
    {
        $catalog = TicketCatalog::fromSettings();

        if (! $catalog->isConfigured()) {
            return ['error' => 'The ticketing system is not available right now.'];
        }

        return [
            'categories' => array_map(fn ($cat) => [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'subcategories' => array_map(fn ($sub) => [
                    'id' => $sub['id'],
                    'name' => $sub['name'],
                ], $cat['subcategories']),
            ], $catalog->categories),
        ];
    }

    /**
     * Returns a draft only — nothing is submitted here. AssistantAgent is
     * responsible for refusing this call when search_knowledge has not run
     * yet in this conversation; by the time it reaches here the guard has
     * already passed.
     */
    private function draftTicket(array $args): array
    {
        $catalog = TicketCatalog::fromSettings();

        $categoryId = (int) ($args['category_id'] ?? 0);
        $subcategoryId = (int) ($args['subcategory_id'] ?? 0);

        return [
            'draft' => true,
            'type' => 'ticket',
            'title' => (string) ($args['title'] ?? ''),
            'description' => (string) ($args['description'] ?? ''),
            'category_id' => $categoryId,
            'category_name' => $catalog->categoryName($categoryId),
            'subcategory_id' => $subcategoryId,
            'subcategory_name' => $catalog->subcategoryName($categoryId, $subcategoryId),
            'reason_not_solved' => (string) ($args['reason_not_solved'] ?? ''),
            'message' => 'Draft ready. Show this to the employee for review; nothing is submitted until they confirm.',
        ];
    }

    /**
     * Draft only — see the class docblock. AssistantController::email() does
     * the real Graph call, strictly as the signed-in employee's own mailbox,
     * only once they confirm.
     */
    private function draftEmail(array $args): array
    {
        $to = array_values(array_filter(array_map(
            fn ($e) => trim((string) $e),
            is_array($args['to'] ?? null) ? $args['to'] : [],
        )));

        return [
            'draft' => true,
            'type' => 'email',
            'to' => $to,
            'subject' => (string) ($args['subject'] ?? ''),
            'body' => (string) ($args['body'] ?? ''),
            'message' => 'Draft ready. Show this to the employee for review; nothing is sent until they confirm.',
        ];
    }

    /**
     * Draft only — see the class docblock. AssistantController::calendarEvent()
     * does the real Graph call, strictly on the signed-in employee's own
     * calendar, only once they confirm.
     */
    private function draftCalendarEvent(array $args): array
    {
        $attendees = array_values(array_filter(array_map(
            fn ($e) => trim((string) $e),
            is_array($args['attendees'] ?? null) ? $args['attendees'] : [],
        )));

        return [
            'draft' => true,
            'type' => 'calendar_event',
            'subject' => (string) ($args['subject'] ?? ''),
            'start' => (string) ($args['start'] ?? ''),
            'end' => (string) ($args['end'] ?? ''),
            'attendees' => $attendees,
            'body' => (string) ($args['body'] ?? ''),
            'is_teams_meeting' => (bool) ($args['is_teams_meeting'] ?? false),
            'message' => 'Draft ready. Show this to the employee for review; nothing is created until they confirm.',
        ];
    }
}
