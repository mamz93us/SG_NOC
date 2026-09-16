<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiKnowledgeGap;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\DraftPlaceholders;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Identity\GraphService;
use App\Services\Ticketing\HomeTicketSubmissionException;
use App\Services\Ticketing\HomeTicketSubmitter;
use App\Services\Ticketing\TicketRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The AI IT Assistant on the home portal — a chat over the employee's own
 * data plus the admin-authored knowledge base, that drafts (never submits) a
 * ticket only once search_knowledge has been tried and failed.
 *
 * Every tool the model can call is scoped server-side to the identity
 * resolved here (see AssistantToolbox) — the model itself never sees or
 * passes an email, employee id, or Azure id.
 */
class AssistantController extends Controller
{
    /** Buttons under one answer. More than a handful is a list, not an answer. */
    private const MAX_DOCUMENT_BUTTONS = 5;

    public function __construct(
        private AssistantAgent $agent,
        private HomeTicketSubmitter $submitter,
        private GraphService $graph,
    ) {}

    public function index(Request $request): View
    {
        $settings = AiSetting::get();

        $conversation = AiConversation::where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->first();

        return view('home.assistant', [
            'configured' => $settings->isConfigured(),
            'conversation' => $conversation,
            'messages' => $conversation?->messages()
                ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
                ->get() ?? collect(),
        ]);
    }

    public function show(Request $request, AiConversation $conversation): View
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);

        return view('home.assistant', [
            'configured' => AiSetting::get()->isConfigured(),
            'conversation' => $conversation,
            'messages' => $conversation->messages()
                ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
                ->get(),
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        $settings = AiSetting::get();

        if (! $settings->isConfigured()) {
            return response()->json(['message' => __('home_ai.errors.system_unavailable')], 503);
        }

        $validated = $request->validate([
            'conversation_id' => 'nullable|integer',
            'message' => 'required|string|max:4000',
        ]);

        $user = $request->user();

        // One conversation per lock, so a double-click cannot spend two
        // completions on the same turn.
        $lockKey = 'ai:conv:'.($validated['conversation_id'] ?? 'new:'.$user->id);

        $result = Cache::lock($lockKey, 120)->get(function () use ($request, $validated, $user, $settings) {
            return $this->handleMessage($request, $validated, $user, $settings);
        });

        // Lock::get() returns false (never a JsonResponse) when it could not
        // acquire the lock — a double-submit of the same conversation.
        if ($result === false) {
            return response()->json(['message' => __('home_ai.errors.rate_limited')], 429);
        }

        return $result;
    }

    private function handleMessage(Request $request, array $validated, $user, AiSetting $settings): JsonResponse
    {
        $conversation = null;
        if (! empty($validated['conversation_id'])) {
            $conversation = AiConversation::where('id', $validated['conversation_id'])
                ->where('user_id', $user->id)
                ->first();
        }

        $employee = Employee::with(['branch', 'department'])->where('email', $user->email)->first();

        if (! $conversation) {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'employee_id' => $employee?->id,
                'locale' => app()->getLocale(),
            ]);
        }

        // Daily cap: count this user's own user-role messages sent today,
        // across all their conversations.
        $sentToday = AiMessage::where('role', AiMessage::ROLE_USER)
            ->whereIn('conversation_id', AiConversation::where('user_id', $user->id)->pluck('id'))
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($sentToday >= $settings->daily_message_cap) {
            return response()->json(['message' => __('home_ai.errors.daily_cap')], 429);
        }

        $identity = IdentityUser::where('mail', $user->email)
            ->orWhere('user_principal_name', $user->email)
            ->first();

        $toolbox = new AssistantToolbox(
            $user,
            $employee,
            $identity,
            app(KnowledgeRetriever::class),
            app(TicketRequestService::class),
        );

        // Watermark: draft_ticket can execute on an earlier tool turn than the
        // one that produces the final reply — the model typically calls it,
        // then closes with a plain-text turn presenting the draft in prose.
        // So the draft is found by scanning every tool result THIS turn
        // produced, not just the ones attached to the final message.
        $beforeId = (int) ($conversation->messages()->max('id') ?? 0);

        try {
            $reply = $this->agent->respond($conversation, $validated['message'], $toolbox);
        } catch (\Throwable $e) {
            Log::warning('AssistantController: turn failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => __('home_ai.errors.system_unavailable')], 503);
        }

        // A document link is useless as text: the chat renders replies as plain
        // text, so a URL arrives as a URL. Take them out of the sentence and hand
        // the documents over as data, for the widget to draw buttons from.
        $documents = $this->archiveDocuments($request, $reply, $conversation, $beforeId);

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $reply->id,
                'role' => $reply->role,
                'content' => $reply->content,
            ],
            'documents' => $documents,
            // Generic — carries a 'type' of ticket/email/calendar_event so the
            // widget can render and confirm the right kind. See AssistantToolbox:
            // every draft_* tool only ever shapes this, never submits it.
            'draft' => $this->extractDraft($conversation, $beforeId),
        ]);
    }

    /**
     * Archive documents the answer pointed at, as things to open.
     *
     * The links are stripped from the reply (and the stored message, so the
     * history reads the same way) and returned as data instead.
     *
     * Access is re-checked here per document rather than trusted from the answer.
     * The toolbox already refuses to return a document this person cannot open, but
     * a button is a second chance to get that wrong, and the thing on the other
     * side of it is a scanned invoice or an HR file.
     *
     * @return array<int,array<string,mixed>>
     */
    private function archiveDocuments(Request $request, AiMessage $reply, AiConversation $conversation, int $beforeId): array
    {
        if (! \App\Support\ArchivePortal::enabled() || trim((string) $reply->content) === '') {
            return [];
        }

        [$clean, $ids] = \App\Services\Archive\Ai\ArchiveLinks::strip(
            (string) $reply->content,
            \App\Support\ArchivePortal::domain(),
        );

        // The model is told NOT to write a URL, so the documents it actually
        // looked at come from the tool results. Anything it pasted anyway is
        // merged in first, because naming a document explicitly is a stronger
        // signal than having searched for it.
        $ids = array_values(array_unique(array_merge($ids, $this->archiveToolDocumentIds($conversation, $beforeId))));

        if ($ids === []) {
            return [];
        }

        if ($clean !== $reply->content) {
            $reply->forceFill(['content' => $clean])->save();
        }

        $access = \App\Services\Archive\ArchiveAccess::for($request->user());
        $documents = [];

        foreach (array_slice($ids, 0, self::MAX_DOCUMENT_BUTTONS) as $id) {
            $document = $access->findDocument($id);

            if (! $document) {
                continue;
            }

            $document->loadMissing(['values.field', 'files', 'archive']);
            $file = $document->files->first();

            $documents[] = [
                'id' => $document->getKey(),
                'title' => $document->title(),
                'archive' => $document->archive?->displayName(),
                'scanned' => $document->captured_at?->format('Y-m-d'),
                'view' => route('archive.document', $document->getKey()),
                // Only with a file to send: a document whose scan has not been
                // copied yet would give a download button that 404s.
                'download' => $file
                    ? route('archive.document.download', [$document->getKey(), $file->getKey()])
                    : null,
            ];
        }

        return $documents;
    }

    /**
     * Document ids the archive tools returned during this turn.
     *
     * A broad search can return fifty, and fifty buttons is not an answer, so a
     * long list is narrowed to the documents the reply actually talks about —
     * matched on their own index values, which the model is told to quote exactly.
     *
     * @return array<int,int>
     */
    private function archiveToolDocumentIds(AiConversation $conversation, int $sinceMessageId): array
    {
        $found = [];

        $toolMessages = $conversation->messages()
            ->where('id', '>', $sinceMessageId)
            ->where('role', AiMessage::ROLE_TOOL)
            ->orderBy('id')
            ->get();

        foreach ($toolMessages as $toolMessage) {
            $data = json_decode((string) $toolMessage->content, true);

            if (! is_array($data)) {
                continue;
            }

            // get_document: one document, looked at deliberately.
            if (isset($data['id']) && is_numeric($data['id']) && isset($data['fields'])) {
                $found[(int) $data['id']] = $data['fields'];
            }

            // search_documents / count: a list.
            foreach ((array) ($data['documents'] ?? []) as $row) {
                if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                    $found[(int) $row['id']] = $row['fields'] ?? [];
                }
            }
        }

        if (count($found) <= self::MAX_DOCUMENT_BUTTONS) {
            return array_keys($found);
        }

        $answer = mb_strtolower((string) $conversation->messages()->latest('id')->value('content'));
        $mentioned = [];

        foreach ($found as $id => $fields) {
            foreach ((array) $fields as $value) {
                $value = trim((string) $value);

                // Two characters would match half the archive by accident.
                if (mb_strlen($value) >= 3 && str_contains($answer, mb_strtolower($value))) {
                    $mentioned[] = $id;
                    break;
                }
            }
        }

        return $mentioned;
    }

    /** The most recent draft_* tool result produced by this turn, if any. */
    private function extractDraft(AiConversation $conversation, int $sinceMessageId): ?array
    {
        $toolMessages = $conversation->messages()
            ->where('id', '>', $sinceMessageId)
            ->where('role', AiMessage::ROLE_TOOL)
            ->orderByDesc('id')
            ->get();

        foreach ($toolMessages as $toolMessage) {
            $data = json_decode((string) $toolMessage->content, true);

            if (is_array($data) && ($data['draft'] ?? false) === true) {
                return $data;
            }
        }

        return null;
    }

    public function rate(Request $request, AiMessage $message): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|integer|in:-1,1',
            'reason' => 'nullable|string|max:255',
        ]);

        abort_unless(
            $message->role === AiMessage::ROLE_ASSISTANT
                && $message->conversation?->user_id === $request->user()->id,
            404
        );

        $wasNotHelpful = $message->rating === -1;

        $message->update([
            'rating' => $validated['rating'],
            'rating_reason' => $validated['reason'] ?? null,
        ]);

        // "Not helpful" on an answer from the knowledge base puts its question
        // on AI Assistant ▸ Knowledge gaps — once, not on every click.
        if ((int) $validated['rating'] === -1 && ! $wasNotHelpful) {
            AiKnowledgeGap::recordNotHelpful($message);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Confirms and sends a ticket the assistant drafted. Runs the SAME
     * validation and submit path as the IT Service Desk modal
     * (HomeTicketSubmitter) — see its docblock for why there is one path.
     */
    public function ticket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category_id' => 'required|integer',
            'subcategory_id' => 'required|integer',
        ]);

        try {
            $ticket = $this->submitter->submit(
                user: $request->user(),
                title: $validated['title'],
                description: $validated['description'],
                categoryId: (int) $validated['category_id'],
                subcategoryId: (int) $validated['subcategory_id'],
            );
        } catch (HomeTicketSubmissionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'ticket_id' => $ticket->ticket_id,
            'reference' => $ticket->ticket_id ? '#'.$ticket->ticket_id : null,
        ], 201);
    }

    /**
     * Confirms and sends an email the assistant drafted (draft_email) —
     * always as the signed-in employee's own mailbox. $request->user()
     * resolves that; the client never gets to say whose mailbox to use.
     */
    public function email(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|array|min:1',
            'to.*' => 'required|email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ]);

        // The draft card has no Edit, so a placeholder the model left in would
        // go out as written. AssistantToolbox refuses such a draft; this stops
        // one that reached the card anyway.
        $placeholders = DraftPlaceholders::find($validated['subject'], $validated['body']);
        if ($placeholders !== []) {
            return response()->json(['message' => __('home_ai.email_draft.has_placeholder', ['placeholder' => implode(', ', $placeholders)])], 422);
        }

        try {
            $this->graph->sendMailAsUser(
                mailbox: (string) $request->user()->email,
                subject: $validated['subject'],
                body: $validated['body'],
                to: $validated['to'],
            );
        } catch (\Throwable $e) {
            Log::warning('AssistantController: sendMailAsUser failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => __('home_ai.email_draft.send_failed')], 502);
        }

        return response()->json(['ok' => true], 201);
    }

    /**
     * Confirms and creates a calendar event (draft_calendar_event) — a plain
     * reminder, or a Teams meeting when is_teams_meeting is true — always on
     * the signed-in employee's own calendar, as organiser.
     */
    public function calendarEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'attendees' => 'nullable|array',
            'attendees.*' => 'email',
            'body' => 'nullable|string|max:5000',
            'is_teams_meeting' => 'nullable|boolean',
        ]);

        // An invitation goes to other people too: same stop as email().
        $placeholders = DraftPlaceholders::find($validated['subject'], $validated['body'] ?? null);
        if ($placeholders !== []) {
            return response()->json(['message' => __('home_ai.calendar_draft.has_placeholder', ['placeholder' => implode(', ', $placeholders)])], 422);
        }

        try {
            $event = $this->graph->createCalendarEvent(
                mailbox: (string) $request->user()->email,
                subject: $validated['subject'],
                start: new \DateTimeImmutable($validated['start']),
                end: new \DateTimeImmutable($validated['end']),
                attendeeEmails: $validated['attendees'] ?? [],
                body: $validated['body'] ?? '',
                isOnlineMeeting: (bool) ($validated['is_teams_meeting'] ?? false),
            );
        } catch (\Throwable $e) {
            Log::warning('AssistantController: createCalendarEvent failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => __('home_ai.calendar_draft.create_failed')], 502);
        }

        return response()->json([
            'join_url' => $event['onlineMeeting']['joinUrl'] ?? null,
            'web_link' => $event['webLink'] ?? null,
        ], 201);
    }
}
