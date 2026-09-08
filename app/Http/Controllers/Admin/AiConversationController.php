<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiKnowledgeGap;
use App\Models\AiMessage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → AI Assistant Conversations & Usage.
 *
 * A separate permission from manage-ai-assistant on purpose — a transcript
 * can contain whatever an employee typed into it, so reading it is a
 * narrower grant than authoring knowledge articles.
 *
 * The gaps list is the highest-value screen here: every search_knowledge
 * query that came back empty is exactly what IT should write next, which is
 * what keeps the assistant improving instead of plateauing.
 */
class AiConversationController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.ai-conversations.index', [
            'conversations' => AiConversation::with('user')
                ->orderByDesc('last_message_at')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function show(AiConversation $aiConversation): View
    {
        return view('admin.ai-conversations.show', [
            'conversation' => $aiConversation->load('user', 'employee'),
            'messages' => $aiConversation->messages()->get(),
        ]);
    }

    public function usage(): View
    {
        $since = now()->subDays(30);

        return view('admin.ai-conversations.usage', [
            'totalConversations' => AiConversation::where('created_at', '>=', $since)->count(),
            'totalTokens' => (int) AiMessage::where('created_at', '>=', $since)->sum('tokens_in')
                + (int) AiMessage::where('created_at', '>=', $since)->sum('tokens_out'),
            'dailyMessages' => AiMessage::where('role', AiMessage::ROLE_USER)
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->orderBy('day')
                ->get(),
            'thumbsDown' => AiMessage::where('rating', -1)
                ->with('conversation.user')
                ->latest()
                ->limit(20)
                ->get(),
            'gaps' => AiKnowledgeGap::whereNull('resolved_at')
                ->orderByDesc('hit_count')
                ->limit(30)
                ->get(),
        ]);
    }
}
