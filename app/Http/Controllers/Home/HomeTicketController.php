<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\IdentityUser;
use App\Services\Ticketing\NocTicketService;
use App\Services\Ticketing\TicketCatalog;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Ticketing\TicketStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The IT Service Desk modal on the home portal.
 *
 * A thin JSON front end over the SAME services the admin page uses —
 * NocTicketService and TicketCatalog — because the admin routes 404 on this
 * host (EnforceHomePortalHostIsolation) and duplicating the submit logic would
 * mean two places to keep in step with the ticketing API.
 *
 * Unlike the admin form, this one never files on anyone else's behalf: the
 * requester is always the signed-in user. This host is opened unattended on
 * shared machines, so "raise a ticket as someone else" has no business here.
 */
class HomeTicketController extends Controller
{
    public function __construct(
        private NocTicketService $tickets,
        private TicketRequestService $requests,
    ) {}

    /**
     * "My Tickets" — what the ticketing system says about the signed-in
     * employee's own tickets, with a count of the ones still live.
     *
     * Always scoped to the session's own email. There is no id or address in
     * the URL to tamper with, which is the security model on this host.
     */
    public function index(Request $request): View
    {
        $email = (string) $request->user()->email;

        $status = (int) $request->input('status', TicketStatus::ALL);
        if (! in_array($status, TicketStatus::filterable(), true)) {
            $status = TicketStatus::ALL;
        }

        $error = null;
        $tickets = [];
        $summary = ['total' => 0, 'live' => 0, 'by_status' => [], 'error' => null];

        if (! $this->requests->isConfigured()) {
            $error = 'The ticketing system is not available right now.';
        } else {
            $summary = $this->requests->summaryFor($email);
            $error = $summary['error'] ? 'The ticketing system did not answer. Please try again shortly.' : null;

            if (! $error) {
                try {
                    $tickets = $this->requests->listFor($email, $status);
                } catch (\Throwable) {
                    $error = 'The ticketing system did not answer. Please try again shortly.';
                }
            }
        }

        return view('home.tickets', [
            'tickets' => $tickets,
            'summary' => $summary,
            'status' => $status,
            'error' => $error,
        ]);
    }

    /**
     * One ticket in full.
     *
     * The details endpoint takes a bare `requestId` and does not care who is
     * asking, so ownership is checked here against the employee's own list.
     * Without that this route would hand anyone's ticket to anyone.
     */
    public function show(Request $request, int $ticket): View
    {
        abort_unless($this->requests->isConfigured(), 503);

        $email = (string) $request->user()->email;

        $this->assertOwns($ticket, $email);

        try {
            $detail = $this->requests->details($ticket);
        } catch (\Throwable) {
            abort(503);
        }

        abort_if($detail === null, 404);

        return view('home.ticket', ['ticket' => $detail]);
    }

    /**
     * Add a comment, with an optional attachment, to one of the employee's own
     * tickets.
     *
     * Ownership is re-checked here, not merely on the page that rendered the
     * form: the API takes a bare ticketId and does not care who is asking, so
     * a hand-made POST would otherwise comment on anyone's ticket.
     */
    public function comment(Request $request, int $ticket): RedirectResponse
    {
        abort_unless($this->requests->isConfigured(), 503);

        $validated = $request->validate([
            'comment' => 'required|string|max:5000',
            // One file: the proven API call takes a single `file` part.
            'attachment' => 'nullable|file|max:20480',
        ], [
            'comment.required' => 'Type something before sending.',
        ]);

        $email = (string) $request->user()->email;

        $this->assertOwns($ticket, $email);

        try {
            $this->requests->addComment(
                $ticket,
                $validated['comment'],
                $email,
                $request->file('attachment'),
            );
        } catch (\Throwable $e) {
            Log::warning('HomeTicketController: comment failed', [
                'ticket' => $ticket,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('ticketError',
                'Your reply could not be sent. Please try again shortly, or email IT directly.');
        }

        return back()->with('ticketSuccess', 'Your reply has been added to the ticket.');
    }

    /**
     * 404 unless this ticket appears in that person's own list. Shared by the
     * detail page and the comment form so the two cannot drift apart.
     */
    private function assertOwns(int $ticket, string $email): void
    {
        try {
            foreach ($this->requests->listFor($email, TicketStatus::ALL) as $t) {
                if ($t['id'] === $ticket) {
                    return;
                }
            }
        } catch (\Throwable) {
            // Cannot prove it is theirs -> do not act on it.
            abort(503);
        }

        abort(404);
    }

    /**
     * The category tree for the modal's two dropdowns.
     *
     * Sub-categories carry the type and priority the ticketing system pairs
     * with them, so the modal can fill both in without a second round trip.
     */
    public function catalog(): JsonResponse
    {
        $catalog = TicketCatalog::fromSettings();

        return response()->json([
            'configured' => $catalog->isConfigured(),
            'categories' => $catalog->categories,
            'types' => $catalog->types,
            'priorities' => $catalog->priorities,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $catalog = TicketCatalog::fromSettings();

        if (! $catalog->isConfigured()) {
            return response()->json([
                'message' => 'The ticketing system is not available right now. Please contact IT directly.',
            ], 503);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category_id' => ['required', 'integer', Rule::in($catalog->categoryIds())],
            'subcategory_id' => [
                'required',
                'integer',
                Rule::in($catalog->subcategoryIdsFor((int) $request->input('category_id'))),
            ],
            'attachments' => 'nullable|array|max:'.NocTicketService::MAX_ATTACHMENTS,
            'attachments.*' => 'file|max:'.NocTicketService::MAX_ATTACHMENT_KB,
        ], [
            'subcategory_id.in' => 'That sub-category does not belong to the selected category.',
            'attachments.max' => 'You can attach at most '.NocTicketService::MAX_ATTACHMENTS.' files.',
            'attachments.*.max' => 'Each file must be 20 MB or smaller.',
        ]);

        $user = $request->user();

        // The API identifies the requester by Azure object id, not email.
        $identity = IdentityUser::where('mail', $user->email)
            ->orWhere('user_principal_name', $user->email)
            ->first();

        if (! $identity) {
            return response()->json([
                'message' => 'We could not match your account in the directory, so the ticket cannot be raised from here. Please contact IT directly.',
            ], 422);
        }

        // The sub-category tells us which type and priority the ticketing
        // system expects; the modal deliberately does not ask the employee.
        $sub = $catalog->subcategory((int) $validated['category_id'], (int) $validated['subcategory_id']);
        $typeId = $sub['type_id'] ?? ($catalog->typeIds()[0] ?? null);
        $priorityId = $sub['priority_id'] ?? ($catalog->priorityIds()[0] ?? null);

        if (! $typeId || ! $priorityId) {
            return response()->json([
                'message' => 'The ticketing system did not supply a type or priority for that sub-category. Please contact IT directly.',
            ], 422);
        }

        try {
            $ticket = $this->tickets->submit(
                title: $validated['title'],
                description: $validated['description'],
                categoryId: (int) $validated['category_id'],
                subCategoryId: (int) $validated['subcategory_id'],
                typeId: (int) $typeId,
                priorityId: (int) $priorityId,
                requesterEmail: $identity->mail ?: $identity->user_principal_name,
                requesterAzureId: $identity->azure_id,
                requesterName: $identity->display_name,
                attachment: $request->file('attachment'),
                submittedBy: $user,
            );
        } catch (\Throwable $e) {
            // The attempt is already recorded as a failed row by the service,
            // so IT can see what happened. The employee gets something they can
            // act on rather than an API error string.
            return response()->json([
                'message' => 'The ticket could not be submitted. IT has a record of the attempt — please try again shortly.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'ticket_id' => $ticket->ticket_id,
            'reference' => $ticket->ticket_id ? '#'.$ticket->ticket_id : null,
        ], 201);
    }
}
