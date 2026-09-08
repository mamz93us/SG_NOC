<?php

namespace App\Services\Ai;

use App\Http\Controllers\Home\HomeAssetsController;
use App\Models\Announcement;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Models\User;
use App\Services\Home\PaydayCalculator;
use App\Services\Ticketing\TicketCatalog;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Ticketing\TicketStatus;

/**
 * The assistant's hands. Constructed with the authenticated identity and
 * injects it into every call — the model never passes an employee id, email
 * or Azure id, which is the whole security model this host already follows
 * for its ticket tracker (see TicketRequestService::ownedBy).
 *
 * `call()` never throws for an expected "not found" — it returns
 * `['error' => '...']` so the model can read the reason and tell the
 * employee, rather than the turn failing outright.
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
            'list_ticket_categories' => $this->listTicketCategories(),
            'draft_ticket' => $this->draftTicket($args),
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
}
