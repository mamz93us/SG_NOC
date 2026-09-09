<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin -> AI Assistant Instructions.
 *
 * The assistant's base system prompt is fixed in lang/{locale}/home_ai.php —
 * shipped with the app, not admin-editable, so behaviour like "always call
 * search_knowledge first" and "never invent a policy" can't be switched off
 * by accident. This page only edits system_prompt_extra, the text
 * AssistantAgent::systemPrompt() appends to it at request time — kept off the
 * connection-settings page (Admin -> Settings) so instructions can be tuned
 * without touching Azure OpenAI credentials, and a settings save can no
 * longer blank this field out from under it.
 */
class AiInstructionsController extends Controller
{
    public function edit(): View
    {
        return view('admin.ai-knowledge.instructions', [
            'settings' => AiSetting::get(),
            'basePromptEn' => trans('home_ai.system_prompt', [], 'en'),
            'basePromptAr' => trans('home_ai.system_prompt', [], 'ar'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'system_prompt_extra' => 'nullable|string|max:4000',
        ]);

        $settings = AiSetting::get();
        $before = $settings->system_prompt_extra;
        $settings->system_prompt_extra = $data['system_prompt_extra'] ?? null;
        $settings->save();

        ActivityLog::create([
            'model_type' => 'AiSetting',
            'model_id' => 1,
            'action' => 'ai_instructions_updated',
            'changes' => ['before' => $before, 'after' => $settings->system_prompt_extra],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.ai-assistant.instructions.edit')
            ->with('success', 'Assistant instructions updated.');
    }

    /**
     * How strict search_knowledge is before it treats a chunk as a real
     * answer versus reporting "not found" (see KnowledgeRetriever). Split
     * from update() above because it changes on a completely different
     * signal — measured retrieval scores as the knowledge base grows — not
     * on a whim the way the prompt text does.
     */
    public function updateMatchThreshold(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'knowledge_match_threshold' => 'required|numeric|min:0|max:1',
        ]);

        $settings = AiSetting::get();
        $before = $settings->knowledge_match_threshold;
        $settings->knowledge_match_threshold = $data['knowledge_match_threshold'];
        $settings->save();

        ActivityLog::create([
            'model_type' => 'AiSetting',
            'model_id' => 1,
            'action' => 'ai_match_threshold_updated',
            'changes' => ['before' => $before, 'after' => $settings->knowledge_match_threshold],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.ai-assistant.instructions.edit')
            ->with('success', 'Knowledge match threshold updated.');
    }
}
