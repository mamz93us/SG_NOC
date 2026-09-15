<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\ArchiveAccessLog;
use App\Services\Archive\Ai\ArchiveAgent;
use App\Services\Archive\ArchiveAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asking the archive a question, from the portal.
 *
 * Two endpoints, and the difference between them is the whole security story:
 *
 *  - ask a DOCUMENT: the document is resolved here, through ArchiveAccess,
 *    before the agent sees anything. The agent is handed the document object,
 *    so there is no id in the prompt for a question to change.
 *  - ask the ARCHIVE: no id is resolved at all; the agent works through
 *    ArchiveToolbox, which re-checks membership on every tool call.
 *
 * Both return JSON, because these are panels beside a document rather than
 * pages of their own — a question about page 3 should not lose the page you
 * were looking at.
 *
 * Every question is recorded against the document, the same as opening it.
 * Somebody asking an assistant what an invoice says has read that invoice.
 */
class AskController extends Controller
{
    /** A question longer than this is not a question. */
    private const MAX_QUESTION = 1000;

    public function __construct(private ArchiveAgent $agent) {}

    /** Ask about one document, answered only from its own pages. */
    public function document(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:'.self::MAX_QUESTION],
        ]);

        $access = ArchiveAccess::for($request->user());

        if (! $access->mayUseAi()) {
            return response()->json(['error' => 'You do not have access to archive AI.'], 403);
        }

        $document = $access->findDocument($id);

        if (! $document) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        // Per archive, not just per person: an archive with AI switched off
        // stays off even for somebody who may use AI elsewhere. HR is the
        // reason that switch exists.
        if (! $document->archive?->ai_chat) {
            return response()->json([
                'error' => 'AI is switched off for this archive.',
            ], 403);
        }

        $document->load('files');

        ArchiveAccessLog::record(
            $request->user()?->getKey(),
            $document,
            ArchiveAccessLog::ACTION_ASK,
            null,
            $request->ip(),
            $request->userAgent(),
        );

        $result = $this->agent->askDocument($request->user(), $document, $data['question']);

        return response()->json([
            'answer' => $result['answer'],
            'pages' => $result['pages'],
            'pages_read_now' => $result['read']['pages_read'] ?? 0,
            'error' => $result['error'],
        ], $result['error'] && $result['answer'] === '' ? 422 : 200);
    }

    /** Ask across the archives this person may search. */
    public function archive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:'.self::MAX_QUESTION],
        ]);

        $access = ArchiveAccess::for($request->user());

        if (! $access->mayUseAi()) {
            return response()->json(['error' => 'You do not have access to archive AI.'], 403);
        }

        $result = $this->agent->askArchive($request->user(), $data['question']);

        return response()->json([
            'answer' => $result['answer'],
            // What it actually did, so an answer that used no tool is visibly
            // an answer from nothing — the model talking rather than searching.
            'used_tools' => $result['used_tools'],
            'error' => $result['error'],
        ], $result['error'] && $result['answer'] === '' ? 422 : 200);
    }
}
