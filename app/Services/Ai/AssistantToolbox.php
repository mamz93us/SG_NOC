<?php

namespace App\Services\Ai;

use App\Http\Controllers\Home\HomeAssetsController;
use App\Models\Announcement;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attendance\MonthlyDay;
use App\Services\Attendance\MonthlySheet;
use App\Services\Attendance\MonthlyTotals;
use App\Services\Home\PaydayCalculator;
use App\Services\Ticketing\TicketCatalog;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Ticketing\TicketStatus;
use Carbon\CarbonImmutable;

/**
 * The assistant's hands. Constructed with the authenticated identity and
 * injects it into every call — the model never passes an employee id, email
 * or Azure id, which is the whole security model this host already follows
 * for its ticket tracker (see TicketRequestService::ownedBy).
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
                'Get the signed-in employee\'s OWN fingerprint attendance: check-in and check-out times per day, hours worked, lateness, early leaves, absences and missing check-outs. Only ever their own — there is no way to see anyone else\'s, and no colleague\'s attendance may be discussed.',
                [
                    'period' => ['type' => 'string', 'description' => 'One of: today, yesterday, this_week, this_month, last_month. Defaults to this_month.'],
                    'month' => ['type' => 'string', 'description' => 'Optional specific month as YYYY-MM (e.g. 2026-08). Overrides period. Use only when the employee names a month.'],
                ],
                []),
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
            'get_my_attendance' => $this->getMyAttendance(
                (string) ($args['period'] ?? 'this_month'),
                isset($args['month']) ? (string) $args['month'] : null,
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
                $q->whereHas('branch', fn ($b) => $b->where('name', 'like', "%{$branch}%"));
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
    private function getMyAttendance(string $period, ?string $month): array
    {
        if (! $this->employee) {
            return ['error' => 'No HR record is linked to your account yet — contact IT to be added to the directory.'];
        }

        if (! BiotimeEmployee::where('employee_id', $this->employee->id)->exists()) {
            return ['error' => 'Your fingerprint code is not linked to your HR record yet, so no attendance is recorded for you. HR can link it on the attendance page.'];
        }

        [$from, $to, $label] = $this->attendanceRange($period, $month);

        $sheet = app(MonthlySheet::class);
        $days = [];

        // A week can straddle two months; a month never more than itself.
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->toDateString() <= $to; $m = $m->addMonth()) {
            foreach ($sheet->build($this->employee, $m->format('Y-m')) as $day) {
                if ($day->date >= $from && $day->date <= $to) {
                    $days[] = $day;
                }
            }
        }

        $totals = MonthlyTotals::fromDays($days);

        return [
            'period' => $label,
            'from' => $from,
            'to' => $to,
            'summary' => [
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
            ],
            // Worked hours are check-in to check-out, so a day they forgot to
            // punch out adds nothing. Say so rather than let them read a total
            // that is quietly short.
            'note' => $totals->missingCheckOuts > 0
                ? "{$totals->missingCheckOuts} day(s) have no check-out, so those hours are not counted in the total. HR can correct a day on request."
                : null,
            'days' => array_map(fn (MonthlyDay $day) => $this->attendanceDay($day), $days),
        ];
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
     * @return array{0: string, 1: string, 2: string} [from, to, label]
     */
    private function attendanceRange(string $period, ?string $month): array
    {
        $today = CarbonImmutable::today();

        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($month))) {
            $start = CarbonImmutable::parse(trim($month).'-01');

            return [$start->toDateString(), $start->endOfMonth()->toDateString(), $start->format('F Y')];
        }

        return match (strtolower(trim($period))) {
            'today' => [$today->toDateString(), $today->toDateString(), 'Today'],
            'yesterday' => [$today->subDay()->toDateString(), $today->subDay()->toDateString(), 'Yesterday'],
            'this_week', 'week' => [
                $today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString(), 'This week',
            ],
            'last_month' => [
                $today->subMonth()->startOfMonth()->toDateString(),
                $today->subMonth()->endOfMonth()->toDateString(),
                $today->subMonth()->format('F Y'),
            ],
            default => [
                $today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString(), $today->format('F Y'),
            ],
        };
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
