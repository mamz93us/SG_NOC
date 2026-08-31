<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IdentityUser;
use App\Models\NocTicket;
use App\Models\Setting;
use App\Services\Ticketing\NocTicketService;
use App\Services\Ticketing\TicketCatalog;
use App\Services\Ticketing\TicketCatalogApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Create Ticket.
 *
 * Raises a ticket in the external ticketing system (addTicketingRequestForNOC)
 * without leaving the NOC. The requester defaults to the signed-in user; the
 * `create-tickets-for-others` permission unlocks filing on someone else's
 * behalf, which is what makes this useful as a helpdesk console.
 *
 * The API identifies the requester by Azure object id, so the requester must
 * exist in `identity_users` — if identity sync has never seen them there is no
 * id to send, and the form says so rather than posting a blank one.
 */
class NocTicketController extends Controller
{
    public function __construct(
        private NocTicketService $tickets,
        private TicketCatalogApi $catalogApi,
    ) {}

    public function create(): View
    {
        $settings = Setting::get();
        $catalog = TicketCatalog::fromSettings($settings);

        $me = Auth::user();
        $identity = $this->findIdentity($me?->email);

        $canFileForOthers = (bool) $me?->can('create-tickets-for-others');

        // Only loaded when the user may file for someone else — no point
        // shipping the whole directory to a form that cannot use it.
        $directory = $canFileForOthers
            ? IdentityUser::where('account_enabled', true)
                ->orderBy('display_name')
                ->get(['display_name', 'user_principal_name', 'mail', 'department'])
            : collect();

        return view('admin.tickets.create', [
            'catalog' => $catalog,
            // Only worth surfacing when the form actually fell back to Settings.
            'catalogError' => $catalog->isFromApi() ? null : $this->catalogApi->lastError($settings),
            'settings' => $settings,
            'myEmail' => $me?->email,
            'myIdentity' => $identity,
            'directory' => $directory,
            'canFileForOthers' => $canFileForOthers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $catalog = TicketCatalog::fromSettings();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category_id' => ['required', 'integer', Rule::in($catalog->categoryIds())],
            'subcategory_id' => [
                'required',
                'integer',
                Rule::in($catalog->subcategoryIdsFor((int) $request->input('category_id'))),
            ],
            'type_id' => ['required', 'integer', Rule::in($catalog->typeIds())],
            'priority_id' => ['required', 'integer', Rule::in($catalog->priorityIds())],
            'requester_email' => 'required|email|max:255',
            'attachment' => 'nullable|file|max:20480', // 20 MB
        ], [
            'category_id.in' => 'Unknown category — check the ticket catalog in Admin → Settings.',
            'subcategory_id.in' => 'That sub-category does not belong to the selected category.',
        ]);

        $me = $request->user();

        // Filing for someone else is a separate permission; without it the
        // requester is forced back to the signed-in user regardless of what
        // the form posted.
        $requesterEmail = $me->can('create-tickets-for-others')
            ? $validated['requester_email']
            : ($me->email ?: $validated['requester_email']);

        $identity = $this->findIdentity($requesterEmail);

        if (! $identity) {
            return back()
                ->withInput()
                ->withErrors(['requester_email' => "No Azure identity found for {$requesterEmail}. The ticketing API needs the requester's Azure object id — run an identity sync, or pick a user from the directory."]);
        }

        try {
            $ticket = $this->tickets->submit(
                title: $validated['title'],
                description: $validated['description'],
                categoryId: (int) $validated['category_id'],
                subCategoryId: (int) $validated['subcategory_id'],
                typeId: (int) $validated['type_id'],
                priorityId: (int) $validated['priority_id'],
                requesterEmail: $identity->mail ?: $identity->user_principal_name,
                requesterAzureId: $identity->azure_id,
                requesterName: $identity->display_name,
                attachment: $request->file('attachment'),
                submittedBy: $me,
            );
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', 'Ticket was not created: '.$e->getMessage());
        }

        $number = $ticket->ticket_id ? "#{$ticket->ticket_id}" : '(no id returned)';

        return redirect()
            ->route('admin.tickets.index')
            ->with('success', "Ticket {$number} created for {$ticket->requester_email}.");
    }

    public function index(Request $request): View
    {
        $query = NocTicket::query()->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $search = trim((string) $request->input('q'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('requester_email', 'like', "%{$search}%")
                    ->orWhere('ticket_id', $search);
            });
        }

        // Without the helpdesk permission you see only what you raised — this
        // page is an audit trail for admins, not a directory of everyone's
        // problems.
        if (! $request->user()->can('create-tickets-for-others')) {
            $query->where('submitted_by_user_id', $request->user()->id);
        }

        return view('admin.tickets.index', [
            'tickets' => $query->paginate(30)->withQueryString(),
            'status' => $status,
            'q' => $search,
        ]);
    }

    public function show(Request $request, NocTicket $ticket): View
    {
        abort_unless(
            $request->user()->can('create-tickets-for-others')
                || $ticket->submitted_by_user_id === $request->user()->id,
            403
        );

        return view('admin.tickets.show', compact('ticket'));
    }

    /**
     * Azure identity for an email. Checks both the mail attribute and the UPN
     * because plenty of staff sign in as one and receive mail as the other.
     */
    private function findIdentity(?string $email): ?IdentityUser
    {
        if (! $email) {
            return null;
        }

        return IdentityUser::where('mail', $email)
            ->orWhere('user_principal_name', $email)
            ->first();
    }
}
