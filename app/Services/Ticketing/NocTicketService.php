<?php

namespace App\Services\Ticketing;

use App\Models\NocTicket;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Submits a ticket to the external ticketing system's NOC endpoint.
 *
 *   POST {url}   (multipart/form-data, X-API-Key header)
 *     data        JSON string — ticketTitle, ticketDescription, ticketCategory,
 *                 ticketSubCategory, ticketType, ticketPriotity, ticketChannelId
 *     file        optional attachment
 *     email       requester's email
 *     azureUserId requester's Azure AD object id
 *
 * Note `ticketPriotity` — that misspelling is the API's, confirmed in its own
 * response body. Do not "fix" it here or the priority silently goes unset.
 *
 * Every attempt is written to `noc_tickets`, successes and failures alike, so
 * a rejected submit leaves something to look at.
 */
class NocTicketService
{
    /** Files accepted per ticket. The form and both controllers enforce this too. */
    public const MAX_ATTACHMENTS = 3;

    /** Per-file ceiling in kilobytes, matching the ticketing system's own limit. */
    public const MAX_ATTACHMENT_KB = 20480;

    /** Fields the caller supplies, keyed as the form names them. */
    public function submit(
        string $title,
        string $description,
        int $categoryId,
        int $subCategoryId,
        int $typeId,
        int $priorityId,
        string $requesterEmail,
        ?string $requesterAzureId,
        ?string $requesterName = null,
        array $attachments = [],
        ?User $submittedBy = null,
    ): NocTicket {
        $settings = Setting::get();
        $catalog = TicketCatalog::fromSettings($settings);

        // Cap ONCE, here, so what gets logged is exactly what gets sent. The
        // controllers validate this too, but a caller reaching the service
        // directly must not be able to record files it never transmitted.
        $attachments = array_slice(array_values($attachments), 0, self::MAX_ATTACHMENTS);

        $ticket = new NocTicket([
            'title' => $title,
            'description' => $description,
            'category_id' => $categoryId,
            'category_name' => $catalog->categoryName($categoryId),
            'subcategory_id' => $subCategoryId,
            'subcategory_name' => $catalog->subcategoryName($categoryId, $subCategoryId),
            'type_id' => $typeId,
            'type_name' => $catalog->typeName($typeId),
            'priority_id' => $priorityId,
            'priority_name' => $catalog->priorityName($priorityId),
            'channel_id' => $catalog->channelId,
            'requester_email' => $requesterEmail,
            'requester_name' => $requesterName,
            'requester_azure_id' => $requesterAzureId,
            // First file keeps the legacy columns so existing views and the
            // submission log read unchanged; the full list goes in `attachments`.
            'attachment_name' => ($attachments[0] ?? null)?->getClientOriginalName(),
            'attachment_size' => ($attachments[0] ?? null)?->getSize(),
            'attachments' => array_map(fn ($f) => [
                'name' => $f->getClientOriginalName(),
                'size' => $f->getSize(),
                'type' => $f->getMimeType(),
            ], $attachments),
            'submitted_by_user_id' => $submittedBy?->id,
            'submitted_by_name' => $submittedBy?->name,
        ]);

        try {
            $response = $this->call($settings, $catalog, $ticket, $attachments);
        } catch (\Throwable $e) {
            // Connection refused, DNS, TLS, timeout — never reaches an HTTP status.
            $ticket->status = NocTicket::STATUS_FAILED;
            $ticket->error = $e->getMessage();
            $ticket->save();

            Log::warning('NocTicketService: submit failed', [
                'requester' => $requesterEmail,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $ticket->http_status = $response->status();
        $body = $response->json();
        $ticket->response = is_array($body) ? $body : ['raw' => $response->body()];

        if (! $response->successful()) {
            $ticket->status = NocTicket::STATUS_FAILED;
            $ticket->error = 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 2000);
            $ticket->save();

            throw new RuntimeException($ticket->error);
        }

        $ticket->ticket_id = is_array($body) && isset($body['ticketId']) ? (int) $body['ticketId'] : null;
        $ticket->status = NocTicket::STATUS_CREATED;
        $ticket->save();

        if (! $ticket->ticket_id) {
            // 2xx with no id: the ticket may or may not exist. Recorded, not thrown —
            // throwing here would tell the user it failed when it probably did not.
            Log::warning('NocTicketService: 2xx response carried no ticketId', [
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }

        return $ticket;
    }

    private function call(
        Setting $settings,
        TicketCatalog $catalog,
        NocTicket $ticket,
        array $attachments,
    ): Response {
        if (! $settings->noc_ticket_api_enabled) {
            throw new RuntimeException('The ticketing API is disabled in Admin → Settings.');
        }

        $url = $settings->noc_ticket_api_url;
        $key = $settings->nocTicketApiKey();

        if (! $url || ! $key) {
            throw new RuntimeException('The ticketing API URL or key is not configured in Admin → Settings.');
        }

        // `ticketPriotity` is the API's own spelling — see the class docblock.
        $data = array_merge($catalog->extra, [
            'ticketTitle' => $ticket->title,
            'ticketDescription' => $ticket->description ?? '',
            'ticketCategory' => $ticket->category_id,
            'ticketSubCategory' => $ticket->subcategory_id,
            'ticketType' => $ticket->type_id,
            'ticketPriotity' => $ticket->priority_id,
            'ticketChannelId' => $ticket->channel_id,
        ]);

        // No explicit Content-Type: Guzzle has to set the multipart boundary.
        $request = Http::withHeaders([
            'X-API-Key' => $key,
            'Accept' => 'application/json',
        ])
            ->timeout(60)
            ->asMultipart();

        // The API's part is named `file`. Multipart permits the same part name
        // more than once, which is how additional files are sent — Guzzle emits
        // one part per attach() call.
        //
        // UNVERIFIED UNTIL TESTED against a given deployment: if the server
        // reads only the first `file` part, extras are dropped SILENTLY while
        // the ticket still succeeds. Check a real submit lists every file back
        // via getTicketingRequestDetailsForNOC before trusting it.
        foreach ($attachments as $file) {
            $request = $request->attach(
                'file',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
            );
        }

        return $request->post($url, [
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'email' => $ticket->requester_email,
            'azureUserId' => (string) $ticket->requester_azure_id,
        ]);
    }
}
