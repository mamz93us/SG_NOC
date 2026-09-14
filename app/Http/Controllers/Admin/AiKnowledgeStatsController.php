<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\KnowledgeStats;
use Illuminate\View\View;

/**
 * Admin → AI Assistant Knowledge → Statistics. Read-only: how big the
 * knowledge base is, how it is cut into chunks, and what it takes to store.
 */
class AiKnowledgeStatsController extends Controller
{
    public function index(KnowledgeStats $stats): View
    {
        return view('admin.ai-knowledge.stats', ['stats' => $stats->build()]);
    }
}
