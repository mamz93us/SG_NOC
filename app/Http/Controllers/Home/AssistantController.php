<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\KnowledgeRetriever;
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
    public function __construct(
        private AssistantAgent $agent,
        private HomeTicketSubmitter $submitter,
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

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $reply->id,
                'role' => $reply->role,
                'content' => $reply->content,
            ],
            'draft_ticket' => $this->extractDraft($conversation, $beforeId),
        ]);
    }

    /** The most recent draft_ticket result produced by this turn, if any. */
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

        $message->update([
            'rating' => $validated['rating'],
            'rating_reason' => $validated['reason'] ?? null,
        ]);

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
}
