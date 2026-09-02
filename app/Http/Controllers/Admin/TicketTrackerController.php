<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IdentityUser;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Ticketing\TicketStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin → My Tickets: what the ticketing system says about a person's tickets,
 * read live. Distinct from Ticket Submissions (`/admin/tickets`), which is the
 * NOC's own log of submit *attempts* and knows nothing about what happened
 * afterwards.
 *
 * The requester defaults to the signed-in user. `create-tickets-for-others`
 * unlocks looking someone else up, which is what makes this useful on a
 * helpdesk call.
 *
 * The details endpoint takes a bare `requestId` and does not check who is
 * asking, so **ownership is enforced here**: without the helpdesk permission a
 * ticket is only shown if it appears in that person's own list. Dropping that
 * check would turn the page into an enumeration of everyone's tickets.
 */
class TicketTrackerController extends Controller
{
    public function __construct(private TicketRequestService $tickets) {}

    public function index(Request $request): View
    {
        $me = $request->user();
        $canViewOthers = (bool) $me->can('create-tickets-for-others');

        $email = $canViewOthers
            ? trim((string) $request->input('email', $me->email)) ?: $me->email
            : $me->email;

        $status = (int) $request->input('status', TicketStatus::ALL);
        if (! in_array($status, TicketStatus::filterable(), true)) {
            $status = TicketStatus::ALL;
        }

        $error = null;
        $tickets = [];
        $summary = ['total' => 0, 'live' => 0, 'by_status' => [], 'error' => null];

        if (! $this->tickets->isConfigured()) {
            $error = 'The ticketing API is not enabled or not fully configured in Admin → Settings.';
        } else {
            // The summary is always the full set; the table below it is the
            // filtered view. Both come out of the same 60-second cache, so the
            // second call is free.
            $summary = $this->tickets->summaryFor($email);
            $error = $summary['error'];

            if (! $error) {
                try {
                    $tickets = $status === TicketStatus::ALL
                        ? $this->tickets->listFor($email, TicketStatus::ALL)
                        : $this->tickets->listFor($email, $status);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return view('admin.tickets.tracker', [
            'tickets' => $tickets,
            'summary' => $summary,
            'status' => $status,
            'email' => $email,
            'error' => $error,
            'canViewOthers' => $canViewOthers,
            'directory' => $canViewOthers
                ? IdentityUser::where('account_enabled', true)
                    ->orderBy('display_name')
                    ->get(['display_name', 'user_principal_name', 'mail', 'department'])
                : collect(),
        ]);
    }

    public function show(Request $request, int $ticket): View
    {
        $me = $request->user();
        $canViewOthers = (bool) $me->can('create-tickets-for-others');

        abort_unless($this->tickets->isConfigured(), 503, 'The ticketing API is not configured.');

        // Ownership before disclosure — see the class docblock.
        if (! $canViewOthers && ! $this->belongsTo($ticket, $me->email)) {
            abort(404);
        }

        try {
            $detail = $this->tickets->details($ticket);
        } catch (\Throwable $e) {
            abort(502, 'The ticketing system did not answer: '.$e->getMessage());
        }

        abort_if($detail === null, 404);

        return view('admin.tickets.tracker-show', [
            'ticket' => $detail,
            'backUrl' => route('admin.tickets.tracker', $request->only('email', 'status')),
        ]);
    }

    /**
     * Add a comment, with an optional attachment, to a ticket.
     *
     * Ownership is re-checked here rather than trusted from the page that
     * rendered the form — the API takes a bare ticketId and does not check who
     * is asking. Helpdesk users (create-tickets-for-others) may comment on
     * anyone's; everyone else only on their own.
     */
    public function comment(Request $request, int $ticket): RedirectResponse
    {
        abort_unless($this->tickets->isConfigured(), 503, 'The ticketing API is not configured.');

        $me = $request->user();
        $canViewOthers = (bool) $me->can('create-tickets-for-others');

        $validated = $request->validate([
            'comment' => 'required|string|max:5000',
            'attachment' => 'nullable|file|max:20480',
        ]);

        if (! $canViewOthers && ! $this->belongsTo($ticket, $me->email)) {
            abort(404);
        }

        // The comment is attributed to the signed-in user, not to whoever the
        // page happens to be showing: this is their reply, not the requester's.
        try {
            $this->tickets->addComment(
                $ticket,
                $validated['comment'],
                (string) $me->email,
                $request->file('attachment'),
            );
        } catch (\Throwable $e) {
            Log::warning('TicketTrackerController: comment failed', [
                'ticket' => $ticket,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'The comment was not added: '.$e->getMessage());
        }

        return back()->with('success', 'Comment added to ticket #'.$ticket.'.');
    }

    private function belongsTo(int $ticketId, ?string $email): bool
    {
        if (! $email) {
            return false;
        }

        try {
            foreach ($this->tickets->listFor($email, TicketStatus::ALL) as $t) {
                if ($t['id'] === $ticketId) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Cannot prove ownership -> do not disclose.
            return false;
        }

        return false;
    }
}
