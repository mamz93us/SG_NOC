<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\IdentityUser;
use App\Services\Ticketing\NocTicketService;
use App\Services\Ticketing\TicketCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    public function __construct(private NocTicketService $tickets) {}

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
            // One file: the proven API call takes a single `file` part. Whether
            // it accepts a repeated part is an open question with the ticketing
            // team — until they answer, accepting more here would silently drop
            // everything after the first while the ticket still succeeds.
            'attachment' => 'nullable|file|max:20480',
        ], [
            'subcategory_id.in' => 'That sub-category does not belong to the selected category.',
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
