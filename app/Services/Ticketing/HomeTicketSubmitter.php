<?php

namespace App\Services\Ticketing;

use App\Models\IdentityUser;
use App\Models\NocTicket;
use App\Models\User;
use RuntimeException;

/**
 * The identity-lookup + catalog + submit sequence shared by every path on the
 * home portal that files a ticket as the signed-in employee: the IT Service
 * Desk modal (HomeTicketController::store) and the AI assistant's confirm
 * card (Home\AssistantController::confirmTicket). One implementation so the
 * two cannot drift — the docblock on NocTicketService already warns against
 * duplicating this.
 *
 * Never files on anyone else's behalf — the requester is always the given
 * $user, same rule as HomeTicketController.
 */
class HomeTicketSubmitter
{
    public function __construct(private NocTicketService $tickets) {}

    /**
     * @param  array<int,\Illuminate\Http\UploadedFile>  $attachments
     *
     * @throws HomeTicketSubmissionException a user-facing message + HTTP status
     */
    public function submit(
        User $user,
        string $title,
        string $description,
        int $categoryId,
        int $subcategoryId,
        array $attachments = [],
    ): NocTicket {
        $catalog = TicketCatalog::fromSettings();

        if (! $catalog->isConfigured()) {
            throw new HomeTicketSubmissionException(
                'The ticketing system is not available right now. Please contact IT directly.', 503
            );
        }

        if (! in_array($categoryId, $catalog->categoryIds(), true)) {
            throw new HomeTicketSubmissionException('Unknown ticket category.');
        }

        if (! in_array($subcategoryId, $catalog->subcategoryIdsFor($categoryId), true)) {
            throw new HomeTicketSubmissionException('That sub-category does not belong to the selected category.');
        }

        $identity = IdentityUser::where('mail', $user->email)
            ->orWhere('user_principal_name', $user->email)
            ->first();

        if (! $identity) {
            throw new HomeTicketSubmissionException(
                'We could not match your account in the directory, so the ticket cannot be raised from here. Please contact IT directly.'
            );
        }

        $sub = $catalog->subcategory($categoryId, $subcategoryId);
        $typeId = $sub['type_id'] ?? ($catalog->typeIds()[0] ?? null);
        $priorityId = $sub['priority_id'] ?? ($catalog->priorityIds()[0] ?? null);

        if (! $typeId || ! $priorityId) {
            throw new HomeTicketSubmissionException(
                'The ticketing system did not supply a type or priority for that sub-category. Please contact IT directly.'
            );
        }

        try {
            return $this->tickets->submit(
                title: $title,
                description: $description,
                categoryId: $categoryId,
                subCategoryId: $subcategoryId,
                typeId: (int) $typeId,
                priorityId: (int) $priorityId,
                requesterEmail: $identity->mail ?: $identity->user_principal_name,
                requesterAzureId: $identity->azure_id,
                requesterName: $identity->display_name,
                attachments: $attachments,
                submittedBy: $user,
            );
        } catch (RuntimeException $e) {
            // Already recorded as a failed row by NocTicketService, so IT can
            // see the attempt. Give the employee something actionable rather
            // than the raw API error string.
            throw new HomeTicketSubmissionException(
                'The ticket could not be submitted. IT has a record of the attempt — please try again shortly.', 502
            );
        }
    }
}
