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
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationBalance;
use App\Models\Vacation\VacationEmployee;
use App\Services\Attendance\MonthlyDay;
use App\Services\Attendance\MonthlySheet;
use App\Services\Attendance\MonthlyTotals;
use App\Services\Attendance\ShiftResolver;
use App\Services\Home\PaydayCalculator;
use App\Services\Recruitment\RecruitmentToolbox;
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
 * The one place the model names another person is the team attendance and
 * leave tools, and even there it only picks from a list the server built:
 * team() is read from the signed-in employee's HR reporting lines and their
 * row on the attendance owner list, so a name that is not on it is "not
 * found" — never looked up anywhere else. Attendance and leave share that one
 * list, so whoever sees a person's attendance sees their leave, and nobody else.
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

    /** The longest from–to span one attendance call may ask for. */
    private const MAX_RANGE_DAYS = 62;

    /** Leave is booked ahead, so get_team_vacation looks forward as well as back. */
    private const LEAVE_PERIODS = ['today', 'tomorrow', 'this_week', 'next_week', 'last_week', 'this_month', 'next_month', 'last_month'];

    /** The longest span get_team_vacation lists leave records for: a quarter. */
    private const MAX_LEAVE_RANGE_DAYS = 92;

    /** What get_team_vacation's `only` can ask for, and how the result says what it listed. */
    private const LEAVE_ONLY = [
        'on_leave' => 'only people with leave in the period',
        'on_business_trip' => 'only people on a business trip in the period',
        'negative_balance' => 'only people with a negative balance, the most negative first',
    ];

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

    /** see recruitment() */
    private ?RecruitmentToolbox $recruitment = null;

    /** see archive() */
    private ?\App\Services\Archive\Ai\ArchiveToolbox $archive = null;

    public function __construct(
        private User $user,
        private ?Employee $employee,
        private ?IdentityUser $identity,
        private KnowledgeRetriever $retriever,
        private TicketRequestService $tickets,
    ) {}

    /**
     * OpenAI-shaped function tool definitions, offered on every chat turn. The
     * recruitment tools are added only for someone who may use Recruitment AI,
     * and the archive tools only for someone who may use archive AI AND belongs
     * to at least one archive — both toolboxes return [] otherwise, so a person
     * is never told about documents they cannot reach.
     */
    public function definitions(): array
    {
        return array_merge(
            $this->baseDefinitions(),
            $this->recruitment()->definitions(),
            $this->archive()->definitions(),
        );
    }

    private function baseDefinitions(): array
    {
        return [
            $this->def('search_knowledge',
                'Search the company\'s IT and HR knowledge base for an answer. Always try this before answering an IT/HR/policy question, and before drafting a ticket.',
                ['query' => ['type' => 'string', 'description' => 'The employee\'s question, in their own words.']],
                ['query']),
            $this->def('report_knowledge_gap',
                'Record a question the knowledge base does not answer. Call it when search_knowledge returned results but none of them answers the question, before telling the employee so. The people who maintain the knowledge base see it and write the answer; nothing is shown to the employee.',
                ['question' => ['type' => 'string', 'description' => 'The employee\'s question, in their own words.']],
                ['question']),
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
            $this->def('get_my_vacation',
                'Get the signed-in employee\'s OWN annual leave from Oracle: their balance (carried over from last year, earned this year so far, used, remaining, as of the date Oracle reported it) and their leave records for the year — type, dates, days, and whether taken, ongoing or upcoming. Only ever their own — for anyone else, use get_team_member_vacation / get_team_vacation.',
                ['year' => ['type' => 'integer', 'description' => 'Optional — a year such as 2026. Defaults to the newest year Oracle has a balance for.']],
                []),
            $this->def('get_team_vacation',
                'Leave of a group of other people — exactly those get_team_attendance covers: the signed-in employee\'s direct reports (HR records) and, if they are on the attendance owner list (e.g. a general manager), everyone in their branches or the whole company. Returns counts for the group plus one entry per person: their newest Oracle balance (remaining, used, as of) and their leave records in the period. Call it straight away for questions about many people ("who is on vacation today?", "who in Jeddah has a negative balance?"); it checks access itself, so never refuse or ask about the employee\'s role first.',
                $this->periodParameters(self::LEAVE_PERIODS, self::MAX_LEAVE_RANGE_DAYS, 'today') + [
                    'branch' => ['type' => 'string', 'description' => 'Optional — only people in this branch: its code (JED) or its city (Jeddah).'],
                    'department' => ['type' => 'string', 'description' => 'Optional — only people in this department.'],
                    'only' => ['type' => 'string', 'enum' => array_keys(self::LEAVE_ONLY), 'description' => 'Optional — list only people on leave or on a business trip in the period, or with a negative balance. The counts still cover everyone.'],
                ],
                []),
            $this->def('get_team_member_vacation',
                'Annual leave of ONE other person, in the same detail as get_my_vacation: their Oracle balance and leave records. Call it straight away whenever the employee asks about someone else\'s leave or balance: it checks access itself against the HR records and the attendance owner list, so never refuse or ask about the employee\'s role first. A person outside their access comes back as an error.',
                [
                    'member' => ['type' => 'string', 'description' => 'The person\'s name, email or employee number.'],
                    'year' => ['type' => 'integer', 'description' => 'Optional — a year such as 2026. Defaults to the newest year Oracle has a balance for.'],
                    'branch' => ['type' => 'string', 'description' => 'Optional — the person\'s branch code (CAI) or city (Cairo). Only when the employee names that person\'s branch in this request; never carry one over from an earlier question.'],
                ],
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
            // AssistantAgent records it, knowing the conversation; this only tells the model what to say next.
            'report_knowledge_gap' => [
                'recorded' => true,
                'message' => 'Recorded for the people who maintain the knowledge base. Tell the employee the company documentation does not cover this yet; do not present general knowledge as company policy.',
            ],
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
            'get_my_vacation' => $this->getMyVacation($args),
            'get_team_vacation' => $this->getTeamVacation(
                $args,
                (string) ($args['branch'] ?? ''),
                (string) ($args['department'] ?? ''),
                (string) ($args['only'] ?? ''),
            ),
            'get_team_member_vacation' => $this->getTeamMemberVacation(
                (string) ($args['member'] ?? ''),
                $args,
                (string) ($args['branch'] ?? ''),
            ),
            'list_ticket_categories' => $this->listTicketCategories(),
            'draft_ticket' => $this->draftTicket($args),
            'draft_email' => $this->draftEmail($args),
            'draft_calendar_event' => $this->draftCalendarEvent($args),
            default => match (true) {
                $this->recruitment()->handles($name) => $this->recruitment()->call($name, $args),
                $this->archive()->handles($name) => $this->archive()->call($name, $args),
                default => ['error' => "Unknown tool: {$name}"],
            },
        };
    }

    /** Recruitment AI's tools, for this same signed-in user; empty and refused without the permission. */
    private function recruitment(): RecruitmentToolbox
    {
        return $this->recruitment ??= new RecruitmentToolbox($this->user);
    }

    /**
     * The document archive's tools, for this same signed-in user.
     *
     * Constructed with the user and nothing else, so no tool argument can point
     * at somebody else's documents; the toolbox re-checks per-archive
     * membership on every call, not just here.
     */
    private function archive(): \App\Services\Archive\Ai\ArchiveToolbox
    {
        return $this->archive ??= new \App\Services\Archive\Ai\ArchiveToolbox($this->user);
    }

    /** The system prompt's recruitment rules, for someone who may use Recruitment AI; '' for everyone else. */
    public function recruitmentNote(): string
    {
        return $this->recruitment()->enabled() ? (string) __('home_ai.recruitment_note') : '';
    }

    /** The system prompt's archive rules, for someone who may search it; '' for everyone else. */
    public function archiveNote(): string
    {
        return $this->archive()->promptNote();
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

    /**
     * The range arguments every attendance tool shares — and get_team_vacation,
     * with its own periods and span; dateRange() reads them.
     *
     * @param  list<string>  $periods
     */
    private function periodParameters(array $periods = self::PERIODS, int $maxDays = self::MAX_RANGE_DAYS, string $default = 'this_month'): array
    {
        return [
            'period' => ['type' => 'string', 'enum' => $periods, 'description' => "Which days. Weeks run Sunday to Saturday. Defaults to {$default}."],
            'month' => ['type' => 'string', 'description' => 'Optional — a specific month as YYYY-MM (e.g. 2026-08), when a month is named. Overrides period.'],
            'from' => ['type' => 'string', 'description' => 'Optional — first day as YYYY-MM-DD, together with to, for any other span ("since the 1st", "the last 10 days"). Overrides month and period. At most '.$maxDays.' days.'],
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
            'if_none_answer' => 'If none of these results answers the question, call report_knowledge_gap before you reply.',
            // document, page and heading_in_document come only with an imported
            // PDF: what the employee can find in the original (PdfSourceLocator);
            // url only with a page read from a website.
            'results' => $results->map(fn ($r) => [
                'title' => $r['title'],
                'heading' => $r['heading'],
                'content' => mb_substr($r['content'], 0, 1200),
            ] + array_filter([
                'heading_in_document' => $r['heading_in_document'] ?? null,
                'document' => $r['document'] ?? null,
                'page' => $r['page'] ?? null,
                'url' => $r['url'] ?? null,
            ], fn ($value) => $value !== null))->values()->all(),
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
            return ['error' => $this->noTeam('attendance')];
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
            return ['error' => $this->noTeam('attendance')];
        }

        $range = $this->attendanceRange($args);

        if (is_string($range)) {
            return ['error' => $range];
        }

        $employee = $this->teamMember($member, $branch, 'attendance');

        if (is_array($employee)) {
            return $employee;
        }

        if ($this->fingerprintLinked(collect([$employee])) === []) {
            return ['error' => "{$employee->name}'s fingerprint code is not linked to their HR record yet, so no attendance is recorded for them. HR can link it on the attendance page."];
        }

        return [
            'employee' => $this->memberIdentity($employee),
            'visible_because' => $this->visibleBecause($employee),
        ] + $this->attendanceOf($employee, $args);
    }

    /**
     * The one person in team() whom $member names — in $branch, when given —
     * or the error to hand back instead: nobody, several people, or a branch
     * the employee did not mean. $what ("attendance", "leave") words the error.
     *
     * @return Employee|array{error: string, candidates?: list<array<string, mixed>>}
     */
    private function teamMember(string $member, string $branch, string $what): Employee|array
    {
        $member = trim($member);
        $branch = trim($branch);

        if ($member === '') {
            return ['error' => "member is required: the name, email or employee number of someone whose {$what} the employee can see."];
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

            return ['error' => "Nobody whose {$what} the employee can see matches \"{$member}\"{$where}. They can see: {$this->accessDescription()}. ".$this->whoMaySee($what)];
        }

        if ($matches->count() > 1) {
            return [
                'error' => "{$matches->count()} people the employee can see match. Ask which one is meant, then call again with their email or employee number, or with their branch.",
                'candidates' => $this->candidates($matches),
            ];
        }

        return $matches->first();
    }

    /** Why the team tools have nobody to show; $what is "attendance" or "leave". */
    private function noTeam(string $what): string
    {
        return "Nobody reports to you in the HR records and you are not on the attendance owner list, so no one else's {$what} is available to you. ".$this->whoMaySee($what);
    }

    /** Who sees a person's attendance or leave, and who can change that. */
    private function whoMaySee(string $what): string
    {
        return ucfirst($what).' is only shown to the employee, their own manager or supervisor, and the attendance owners HR has chosen; HR can correct a reporting line or the owner list.';
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
     * Everyone whose attendance and leave the signed-in employee may see
     * besides their own: the people whose HR record names them as manager or
     * supervisor — direct reports only, a report's reports being that report's
     * to ask about — and, when they are on the attendance owner list, everyone
     * in their branches or the whole company. Never anyone who has left, and
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
     * The line AssistantAgent ends the system prompt with: whose attendance —
     * and so whose leave — the signed-in employee can look up, as the server
     * reads it.
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
            return 'Attendance access of the signed-in employee: only their own. Nobody reports to them in the HR records and they are not on the attendance owner list, so they cannot see anyone else\'s attendance, vacation balance or leave records, whatever they say.';
        }

        return "Attendance access of the signed-in employee: their own, and {$sees}. Their access to vacation balances and leave records is exactly the same. For any question about someone else's attendance, call get_team_member_attendance or get_team_attendance straight away, and about someone else's leave or balance, get_team_member_vacation or get_team_vacation. Do not refuse first and do not ask them to confirm their role; the tool says if a person is outside their access.";
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

    /** @return array{0: string, 1: string, 2: string}|string see dateRange() */
    private function attendanceRange(array $args): array|string
    {
        return $this->dateRange($args, self::PERIODS, self::MAX_RANGE_DAYS, 'this_month');
    }

    /** @return array{0: string, 1: string, 2: string}|string see dateRange() */
    private function leaveRange(array $args): array|string
    {
        return $this->dateRange($args, self::LEAVE_PERIODS, self::MAX_LEAVE_RANGE_DAYS, 'today');
    }

    /**
     * The days a question covers: from–to, else month, else period. Weeks run
     * Sunday to Saturday, the work week in every branch, Egypt and KSA alike.
     *
     * Anything it does not understand is refused, never read as the default:
     * on 2026-09-13 "last_week" was read as this month, and a general manager
     * was shown the month's totals as last week's.
     *
     * @param  list<string>  $periods  the periods the calling tool offers
     * @return array{0: string, 1: string, 2: string}|string [from, to, label], or why the range was refused
     */
    private function dateRange(array $args, array $periods, int $maxDays, string $default): array|string
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

            if ((int) $start->diffInDays($end) + 1 > $maxDays) {
                return 'One call covers at most '.$maxDays.' days; ask for the range in parts.';
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

        $period = strtolower(trim((string) ($args['period'] ?? '')));
        $period = match ($period) {
            '' => $default,
            'week' => 'this_week',
            default => $period,
        };

        if (! in_array($period, $periods, true)) {
            return 'period must be one of: '.implode(', ', $periods).' — or give from and to as dates like 2026-09-01.';
        }

        $week = $today->startOfWeek(CarbonInterface::SUNDAY);
        $weekLabel = fn (CarbonImmutable $start) => $start->format('j M').' – '.$start->addDays(6)->format('j M Y');
        // Not subMonth() / addMonth(): from the 29th to the 31st they can land in the wrong month.
        $lastMonth = $today->subMonthNoOverflow();
        $nextMonth = $today->addMonthNoOverflow();

        return match ($period) {
            'today' => [$today->toDateString(), $today->toDateString(), 'Today'],
            'yesterday' => [$today->subDay()->toDateString(), $today->subDay()->toDateString(), 'Yesterday'],
            'tomorrow' => [$today->addDay()->toDateString(), $today->addDay()->toDateString(), 'Tomorrow'],
            'this_week' => [$week->toDateString(), $week->addDays(6)->toDateString(), 'This week, '.$weekLabel($week)],
            'last_week' => [$week->subWeek()->toDateString(), $week->subDay()->toDateString(), 'Last week, '.$weekLabel($week->subWeek())],
            'next_week' => [$week->addWeek()->toDateString(), $week->addDays(13)->toDateString(), 'Next week, '.$weekLabel($week->addWeek())],
            'this_month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString(), $today->format('F Y')],
            'last_month' => [$lastMonth->startOfMonth()->toDateString(), $lastMonth->endOfMonth()->toDateString(), $lastMonth->format('F Y')],
            'next_month' => [$nextMonth->startOfMonth()->toDateString(), $nextMonth->endOfMonth()->toDateString(), $nextMonth->format('F Y')],
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

    /**
     * The signed-in employee's own annual leave, as Oracle holds it: the rows
     * Vacations ▸ Balances shows, never recomputed here.
     *
     * Scoped by $this->employee, like get_my_attendance: there is no employee
     * argument, so no prompt can point it at a colleague's balance.
     */
    private function getMyVacation(array $args): array
    {
        if (! $this->employee) {
            return ['error' => 'No HR record is linked to your account yet — contact IT to be added to the directory.'];
        }

        $year = $this->leaveYear($args);

        if (is_string($year)) {
            return ['error' => $year];
        }

        // A linked secondary mailbox reads its primary record's, where links are made.
        $people = VacationEmployee::forEmployee($this->employee);

        if ($people->isEmpty()) {
            return ['error' => 'Your HR record is not linked to your Oracle leave record yet, so no vacation balance is available for you. HR can link your Oracle number on the Vacations page.'];
        }

        return $this->vacationOf($people, $year);
    }

    /**
     * One person from team(), in the same detail as get_my_vacation. $member
     * and $branch only ever select from that list — the one the attendance
     * tools read.
     */
    private function getTeamMemberVacation(string $member, array $args, string $branch): array
    {
        if ($this->team()->isEmpty()) {
            return ['error' => $this->noTeam('leave')];
        }

        $year = $this->leaveYear($args);

        if (is_string($year)) {
            return ['error' => $year];
        }

        $employee = $this->teamMember($member, $branch, 'leave');

        if (is_array($employee)) {
            return $employee;
        }

        $people = VacationEmployee::forEmployee($employee);

        if ($people->isEmpty()) {
            return ['error' => "{$employee->name}'s HR record is not linked to an Oracle leave record yet, so no vacation balance is available for them. HR can link their Oracle number on the Vacations page."];
        }

        return [
            'employee' => $this->memberIdentity($employee),
            'visible_because' => $this->visibleBecause($employee),
        ] + $this->vacationOf($people, $year);
    }

    /**
     * The people in team(), narrowed by branch, department and `only`: counts
     * for the whole narrowed group, plus one entry per person with their newest
     * Oracle balance and their leave records in the period.
     */
    private function getTeamVacation(array $args, string $branch, string $department, string $only): array
    {
        $team = $this->team();

        if ($team->isEmpty()) {
            return ['error' => $this->noTeam('leave')];
        }

        $range = $this->leaveRange($args);

        if (is_string($range)) {
            return ['error' => $range];
        }

        $only = strtolower(trim($only));

        if ($only !== '' && ! isset(self::LEAVE_ONLY[$only])) {
            return ['error' => 'only must be one of: '.implode(', ', array_keys(self::LEAVE_ONLY)).'.'];
        }

        $group = $this->inBranch($team, $branch)
            ->filter(fn (Employee $e) => $this->nameContains($e->department?->name, $department))
            ->values();

        if ($group->isEmpty()) {
            return [
                'error' => 'Nobody whose leave you can see is in that branch or department.',
                'branches' => $this->namesOf($team, 'branch'),
                'departments' => $this->namesOf($team, 'department'),
            ];
        }

        [$from, $to, $label] = $range;

        // A few queries per 500 people, however large the group: a
        // whole-company owner sees everyone.
        $peopleByEmployee = $this->oraclePeople($group);
        $recordsByPerson = $peopleByEmployee->flatten(1)->pluck('id')->chunk(500)
            ->flatMap(fn (Collection $ids) => VacationAbsence::query()
                ->active()
                ->whereIn('vacation_employee_id', $ids->values()->all())
                ->overlapping($from, $to)
                ->orderBy('start_date')
                ->orderBy('id')
                ->get())
            ->groupBy('vacation_employee_id');

        $counts = ['on_leave' => 0, 'on_business_trip' => 0, 'negative_balance' => 0, 'no_balance_in_oracle' => 0];
        $members = [];
        $unlinked = [];
        $stale = 0;

        foreach ($group as $employee) {
            $people = $peopleByEmployee->get($employee->id);

            if (! $people) {
                $unlinked[] = $employee->name;

                continue;
            }

            $balance = $this->newestBalance($people);
            $hasBalance = (bool) $balance?->hasBalance();
            $records = $people->flatMap(fn (VacationEmployee $person) => $recordsByPerson->get($person->id, collect()))
                ->sortBy(fn (VacationAbsence $record) => $record->start_date->toDateString())
                ->values();

            $onLeave = $records->contains(fn (VacationAbsence $record) => ! $record->isBusinessTrip());
            $onTrip = $records->contains(fn (VacationAbsence $record) => $record->isBusinessTrip());
            $negative = $hasBalance && $balance->balance < 0;

            $counts['on_leave'] += (int) $onLeave;
            $counts['on_business_trip'] += (int) $onTrip;
            $counts['negative_balance'] += (int) $negative;
            $counts['no_balance_in_oracle'] += (int) (! $hasBalance);
            $stale += (int) ($hasBalance && $balance->isStale());

            $listed = match ($only) {
                'on_leave' => $onLeave,
                'on_business_trip' => $onTrip,
                'negative_balance' => $negative,
                default => true,
            };

            if (! $listed) {
                continue;
            }

            $members[] = [
                'remaining' => $hasBalance ? $balance->balance : null,
                'entry' => $this->memberIdentity($employee) + array_filter([
                    // The figures one line per person needs; get_team_member_vacation has them all.
                    'balance' => $hasBalance
                        ? array_intersect_key($this->leaveBalance($balance), array_flip(['as_of', 'used_this_year', 'other_adjustments', 'remaining']))
                        : 'none in Oracle',
                    'leave' => $records->map(fn (VacationAbsence $record) => $this->leaveRecord($record))->all() ?: null,
                ]),
            ];
        }

        // Asking for negative balances: the most negative first. Otherwise, name order.
        if ($only === 'negative_balance') {
            usort($members, fn (array $a, array $b) => $a['remaining'] <=> $b['remaining']);
        }

        $notes = ['Each balance is Oracle\'s own figure as of its as_of date: never add balances up or work one out again. leave lists each person\'s records in the period; a business trip is not leave.'];

        if ($stale > 0) {
            $notes[] = "{$stale} of these balances are more than ".(int) config('vacations.stale_after_days', 35).' days old, so leave has been earned since; say they are out of date.';
        }

        if (count($members) > self::TEAM_OVERVIEW_LIMIT) {
            $notes[] = 'Only '.self::TEAM_OVERVIEW_LIMIT.' of '.count($members).' people are listed; the counts cover everyone. Narrow it with branch, department or only, or ask about one person with get_team_member_vacation.';
        }

        return array_filter([
            'period' => $label,
            'from' => $from,
            'to' => $to,
            'can_see' => $this->accessDescription(),
            'people' => $group->count(),
            'counts' => $counts + ['not_linked_to_oracle' => count($unlinked)],
            'counts_are' => 'people',
            'listed' => $only !== '' ? self::LEAVE_ONLY[$only] : 'everyone',
            'members' => array_column(array_slice($members, 0, self::TEAM_OVERVIEW_LIMIT), 'entry'),
            // Named, not dropped: missing from the list would read as nothing to report.
            'not_linked_to_oracle' => array_slice($unlinked, 0, 50) ?: null,
            'notes' => $notes,
        ], fn ($value) => $value !== null);
    }

    /**
     * One person's leave for a year: Oracle's balance and the leave records in
     * that year. Shared by get_my_vacation and get_team_member_vacation so the
     * two can never describe it differently; whose leave it is, the caller
     * decided. The same figures as the person's page on Vacations ▸ Balances.
     *
     * @param  Collection<int, VacationEmployee>  $people  the person's Oracle numbers, balances loaded
     * @param  int|null  $year  null for the newest year Oracle has a balance for
     */
    private function vacationOf(Collection $people, ?int $year): array
    {
        $balances = $people->flatMap(fn (VacationEmployee $person) => $person->balances
            ->map(fn (VacationBalance $balance) => $balance->setRelation('vacationEmployee', $person)));
        $year ??= $balances->max('year') ?? CarbonImmutable::today()->year;

        // One balance a year per Oracle number; should there be two, the newest leads.
        $yearBalances = $balances->where('year', $year)
            ->sortByDesc(fn (VacationBalance $balance) => $balance->as_of->toDateString())
            ->values();
        $balance = $yearBalances->first();

        $records = VacationAbsence::query()
            ->whereIn('vacation_employee_id', $people->pluck('id')->all())
            ->overlapping("{$year}-01-01", "{$year}-12-31")
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
        $listed = $records->whereNull('removed_at')->values();
        $withdrawn = $records->count() - $listed->count();

        return array_filter([
            'year' => $year,
            'balance' => $balance?->hasBalance() ? $this->leaveBalance($balance) : null,
            'balance_note' => match (true) {
                $balance === null => "Oracle's balance sheet has no {$year} balance for this person; only leave records came through.",
                ! $balance->hasBalance() => 'Oracle has no leave plan for this person yet: every figure in its balance sheet is empty.',
                default => null,
            },
            // Named, not dropped: which Oracle number is the right one is HR's to say.
            'other_balances' => $yearBalances->slice(1)
                ->map(fn (VacationBalance $other) => ['oracle_number' => $other->vacationEmployee->oracle_emp_no]
                    + ($other->hasBalance() ? $this->leaveBalance($other) : ['as_of' => $other->as_of->toDateString()]))
                ->values()
                ->all() ?: null,
            'other_years_with_a_balance' => $balances->map(fn (VacationBalance $b) => (int) $b->year)
                ->reject(fn (int $y) => $y === $year)
                ->unique()
                ->sortDesc()
                ->values()
                ->all() ?: null,
            'records_by_type' => $this->leaveByType($listed),
            'records' => $listed->map(fn (VacationAbsence $record) => $this->leaveRecord($record))->all(),
            'no_longer_in_oracle' => $withdrawn > 0
                ? "{$withdrawn} leave record(s) in {$year} are no longer listed by Oracle — withdrawn or changed there — and are left out above."
                : null,
            'notes' => $this->leaveNotes($balance, $yearBalances->count(), $listed->isNotEmpty(), (string) $people->first()->book) ?: null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, float|string> Oracle's figures as imported; a part it left empty is left out */
    private function leaveBalance(VacationBalance $balance): array
    {
        return array_filter([
            'as_of' => $balance->as_of->toDateString(),
            'carried_over_from_last_year' => $this->leaveDays($balance->carryover),
            'earned_this_year_so_far' => $this->leaveDays($balance->accrued),
            'used_this_year' => $this->leaveDays($balance->used),
            // What remaining holds beyond the three figures before it; only there when there is some.
            'other_adjustments' => $balance->otherAdjustments() ?: null,
            'remaining' => $this->leaveDays($balance->balance),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> one leave record, as the person's page on Vacations ▸ Balances lists it */
    private function leaveRecord(VacationAbsence $record): array
    {
        return array_filter([
            'type' => $record->absence_type,
            'from' => $record->start_date->toDateString(),
            'from_weekday' => $record->start_date->format('D'),
            'to' => $record->end_date->toDateString(),
            'to_weekday' => $record->end_date->format('D'),
            'days' => $this->leaveDays($record->days()),
            'calendar_days' => $record->calendar_days,
            'status' => $record->status(), // taken, ongoing or upcoming
            'business_trip' => $record->isBusinessTrip() ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * A year's records added up per type, leave before business trips — the
     * "by type" figures of the person's page on Vacations ▸ Balances.
     *
     * @param  Collection<int, VacationAbsence>  $records  still listed by Oracle
     * @return list<array<string, mixed>>
     */
    private function leaveByType(Collection $records): array
    {
        return $records->groupBy('absence_type')
            ->map(fn (Collection $group, $type) => array_filter([
                'type' => (string) $type,
                'records' => $group->count(),
                'days' => $this->leaveDays($group->sum(fn (VacationAbsence $record) => $record->days())),
                'business_trip' => VacationAbsence::isTripType((string) $type) ?: null,
            ], fn ($value) => $value !== null))
            ->sortBy(fn (array $row) => [isset($row['business_trip']) ? 1 : 0, -$row['days']])
            ->values()
            ->all();
    }

    /**
     * What has to be said beside the figures, so the model never quietly turns
     * Oracle's balance into a number of its own.
     *
     * @return list<string>
     */
    private function leaveNotes(?VacationBalance $balance, int $balancesThatYear, bool $hasRecords, string $book): array
    {
        $notes = [];

        if ($balance?->hasBalance()) {
            $notes[] = 'remaining is Oracle\'s own figure as of '.$balance->as_of->format('j M Y').'. Oracle adds leave every month and deducts booked leave only once it is taken, so never work out a different remaining.';

            if ($balance->isStale()) {
                $notes[] = 'This balance is '.(int) $balance->as_of->diffInDays(CarbonImmutable::today()).' days old: the leave earned, and the days left, have grown since. Say so.';
            }

            if ($adjustment = $balance->otherAdjustments()) {
                $notes[] = 'remaining includes '.($adjustment > 0 ? '+' : '').VacationBalance::days($adjustment).' days of other_adjustments: an adjustment recorded in Oracle outside its carried-over, earned and used figures. HR can explain it.';
            }
        }

        if ($balancesThatYear > 1) {
            $notes[] = 'Oracle holds more than one balance for this person, under different Oracle numbers; balance is the newest. HR can say which one applies.';
        }

        if ($hasRecords) {
            $notes[] = 'A record\'s days leave out the weekend ('.$this->weekendOf($book).') but not public holidays, so a record over a holiday can show more days than Oracle deducted. records_by_type adds up every record in the year, upcoming ones included. A business trip is not leave.';
        }

        return $notes;
    }

    /** "Friday and Saturday": the days a book's leave records do not count. */
    private function weekendOf(string $book): string
    {
        $sunday = CarbonImmutable::today()->startOfWeek(CarbonInterface::SUNDAY);

        return collect(VacationEmployee::books()[$book]['weekend'] ?? [])
            ->map(fn ($day) => $sunday->addDays((int) $day)->format('l'))
            ->implode(' and ') ?: 'none';
    }

    /** @return int|string|null the year asked for, null when none was, or why it was refused */
    private function leaveYear(array $args): int|string|null
    {
        $year = trim((string) ($args['year'] ?? ''));

        if ($year === '') {
            return null;
        }

        return preg_match('/^20\d{2}$/', $year) ? (int) $year : 'year must be a year like 2026.';
    }

    /**
     * Each employee's Oracle numbers, balances loaded: VacationEmployee::forEmployee()
     * for a whole group at once. team() holds primary records only, which is
     * where links are made.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Collection<int, VacationEmployee>> keyed by employee id
     */
    private function oraclePeople(Collection $employees): Collection
    {
        return $employees->pluck('id')->chunk(500)
            ->flatMap(fn (Collection $ids) => VacationEmployee::query()
                ->whereIn('employee_id', $ids->values()->all())
                ->with('balances')
                ->orderBy('id')
                ->get())
            ->groupBy('employee_id');
    }

    /**
     * @param  Collection<int, VacationEmployee>  $people
     * @return VacationBalance|null the newest balance Oracle reported for one person, across their Oracle numbers
     */
    private function newestBalance(Collection $people): ?VacationBalance
    {
        return $people->flatMap(fn (VacationEmployee $person) => $person->balances)
            ->sortByDesc(fn (VacationBalance $balance) => sprintf('%04d %s', $balance->year, $balance->as_of->toDateString()))
            ->first();
    }

    private function leaveDays(?float $days): ?float
    {
        return $days === null ? null : round($days, 2);
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
        $placeholders = DraftPlaceholders::find($args['title'] ?? null, $args['description'] ?? null);
        if ($placeholders !== []) {
            return $this->placeholderError('draft_ticket', $placeholders);
        }

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
        $placeholders = DraftPlaceholders::find($args['subject'] ?? null, $args['body'] ?? null);
        if ($placeholders !== []) {
            return $this->placeholderError('draft_email', $placeholders);
        }

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
        $placeholders = DraftPlaceholders::find($args['subject'] ?? null, $args['body'] ?? null);
        if ($placeholders !== []) {
            return $this->placeholderError('draft_calendar_event', $placeholders);
        }

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

    /**
     * Refuses a draft that still holds a placeholder, and says how to fill it.
     * The chat's draft cards have Send and no Edit, so "[Your Name]" in a
     * draft is "[Your Name]" in the recipient's inbox — see DraftPlaceholders.
     */
    private function placeholderError(string $tool, array $placeholders): array
    {
        return [
            'error' => 'The draft still contains a placeholder: '.implode(', ', $placeholders).'. '
                ."Fill it in and call {$tool} again: the employee is ".$this->displayName()
                .', lookup_colleague gives anyone else\'s name, and ask the employee for anything you still do not know. '
                .'Never leave a placeholder for them to fill in.',
        ];
    }

    /**
     * Appended to the system prompt: who the assistant is talking to.
     *
     * With no name to sign with, 7 of the first 9 emails the assistant drafted
     * on NOC2 ended "[Your Name]" or "[اسمك]" — and a draft goes out exactly as
     * shown when the employee presses Send.
     */
    public function identityNote(): string
    {
        $role = collect([$this->employee?->job_title, $this->employee?->department?->name])->filter()->implode(', ');

        return 'The signed-in employee is '.$this->displayName().($role !== '' ? " ({$role})" : '').'. '
            .'Anything you draft for them (an email, a meeting invitation, a ticket) is written as them and signed with that name.';
    }

    private function displayName(): string
    {
        return trim((string) ($this->employee?->name ?: $this->user->name)) ?: 'the employee';
    }
}
