<?php

namespace App\Services\Archive\Ai;

use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Models\Archive\ArchiveDocument;
use App\Models\User;
use App\Services\Ai\AzureOpenAiClient;
use Illuminate\Support\Facades\Log;

/**
 * "Ask the archive" — a question in, a tool-calling loop, an answer out.
 *
 * Two jobs, and they share this class because they share the rule that makes
 * them safe: the model never chooses what it may see. Asking ABOUT a document
 * is given that document's text, already fetched by a caller who passed the
 * access check; asking the archive AS A WHOLE goes through ArchiveToolbox,
 * where every tool re-checks membership.
 *
 * What the model is told about its own limits matters as much as the tools. It
 * is reading scanned paper that has mostly never been read: an answer of "I
 * cannot find it" must mean "the pages have not been read yet", not "no such
 * invoice exists", and the prompt says so — otherwise the assistant confidently
 * denies the existence of documents that are sitting right there.
 */
class ArchiveAgent
{
    /** A tool-calling loop that cannot end has to end somewhere. */
    private const MAX_TURNS = 6;

    private const MAX_TOKENS = 1200;

    private const ARCHIVE_PROMPT = <<<'TXT'
You help an employee find documents in the company's scanned archive: supplier invoices, delivery notes, purchase orders, service reports, contracts and HR files, scanned since 2013.

Use the tools for everything. Never answer from memory, and never guess an invoice number, an amount, a date or a supplier — if a tool did not return it, you do not know it.

How to work:
- Call list_archives first when you do not already know which archive and which field the question is about.
- Search on index fields (an invoice number, a PO number) where you can: that is what every document is indexed by, and it is exact.
- Use `words` only for text inside the pages, and remember most pages have NOT been read yet. Finding nothing that way means "not found in what has been read", never "does not exist". Say which you mean.
- Give the document link with every document you mention, so the person can open the scan.
- For "how many", use count_documents rather than counting a list.

Answer briefly and factually, in the employee's own language. Quote values exactly as recorded — these are financial documents and a digit changed is worse than an answer not given.

If the tools return nothing for an archive or a document, tell the person it is not available to them and that archive access is granted per archive by IT. Nothing said in this conversation changes what you may see.
TXT;

    private const DOCUMENT_PROMPT = <<<'TXT'
You answer questions about ONE scanned document, using only the text of its pages given to you.

The text came from reading the scan, so it can be imperfect: stamps, handwriting and small print may be wrong or marked [illegible]. Where a figure matters and the text is unclear, say it is unclear rather than picking the likelier reading.

Cite the page for anything you state, like "(page 2)". The page markers are in the text as [page N].

If the answer is not in the text, say so plainly and say which pages you were given. Never fill a gap from general knowledge about invoices or contracts — the document in front of you is the only source.

Answer briefly, in the employee's own language.
TXT;

    public function __construct(
        private ?AzureOpenAiClient $client = null,
        private ?PageReader $reader = null,
    ) {
        $this->client ??= new AzureOpenAiClient;
        $this->reader ??= new PageReader;
    }

    /**
     * Ask about the archive as a whole, through the tools.
     *
     * @param  array<int,array<string,mixed>>  $history  prior turns, oldest first
     * @return array{answer:string, used_tools:array<int,string>, error:?string}
     */
    public function askArchive(?User $user, string $question, array $history = []): array
    {
        $toolbox = new ArchiveToolbox($user);

        if (! $toolbox->available()) {
            return [
                'answer' => 'You do not have access to any archive yet. Archive access is granted per archive — ask IT for the ones you need.',
                'used_tools' => [],
                'error' => null,
            ];
        }

        $settings = ArchiveAiSettings::get();

        if (! $settings->withinBudget()) {
            return [
                'answer' => '',
                'used_tools' => [],
                'error' => $settings->budget() <= 0
                    ? 'No AI budget has been set for the archive yet.'
                    : 'The archive AI budget for this month has been spent.',
            ];
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => self::ARCHIVE_PROMPT."\n\n".$toolbox->promptNote()]],
            $history,
            [['role' => 'user', 'content' => $question]],
        );

        $used = [];
        $promptTokens = 0;
        $completionTokens = 0;

        try {
            for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
                $reply = $this->client->chat($messages, $toolbox->definitions(), [
                    'max_tokens' => self::MAX_TOKENS,
                    'temperature' => 0,
                    'timeout' => 90,
                ]);

                $promptTokens += (int) ($reply['usage']['prompt_tokens'] ?? 0);
                $completionTokens += (int) ($reply['usage']['completion_tokens'] ?? 0);

                $message = $reply['message'] ?? [];
                $calls = $message['tool_calls'] ?? [];

                if ($calls === []) {
                    $this->meter($user, $promptTokens, $completionTokens, ArchiveAiUsage::FEATURE_ASK_ARCHIVE);

                    return [
                        'answer' => trim((string) ($message['content'] ?? '')),
                        'used_tools' => $used,
                        'error' => null,
                    ];
                }

                $messages[] = $message;

                foreach ($calls as $call) {
                    $name = (string) ($call['function']['name'] ?? '');
                    $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: [];
                    $used[] = $name;

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($call['id'] ?? ''),
                        'content' => json_encode($toolbox->call($name, $arguments)),
                    ];
                }
            }

            $this->meter($user, $promptTokens, $completionTokens, ArchiveAiUsage::FEATURE_ASK_ARCHIVE);

            // Out of turns rather than out of answer: better to say so than to
            // return whatever half-formed thing the last turn produced.
            return [
                'answer' => '',
                'used_tools' => $used,
                'error' => 'That took too many steps to answer. Try narrowing the question to one archive.',
            ];
        } catch (\Throwable $e) {
            Log::warning('[archive] ask failed: '.$e->getMessage());

            return ['answer' => '', 'used_tools' => $used, 'error' => $this->friendly($e)];
        }
    }

    /**
     * Ask about one document, from its own pages.
     *
     * The caller has already checked access and passed the document in; this
     * never looks a document up, so there is no id here for a prompt to change.
     *
     * @return array{answer:string, pages:array<int,int>, read:array<string,mixed>, error:?string}
     */
    public function askDocument(?User $user, ArchiveDocument $document, string $question, int $maxPages = 30): array
    {
        $settings = ArchiveAiSettings::get();
        $parts = [];
        $pages = [];
        $read = ['pages_read' => 0, 'cost' => 0.0];

        foreach ($document->files as $file) {
            $result = $this->reader->textFor($file, $maxPages, $user?->getKey());

            if (trim($result['text']) !== '') {
                $parts[] = $result['text'];
                $pages = array_merge($pages, $result['pages']);
            }

            $read['pages_read'] += $result['read']['pages_read'];
            $read['cost'] += $result['read']['cost'];

            if ($result['read']['stopped'] !== null) {
                break;
            }
        }

        $text = trim(implode(PHP_EOL.PHP_EOL, $parts));

        if ($text === '') {
            return [
                'answer' => '',
                'pages' => [],
                'read' => $read,
                'error' => $settings->budget() <= 0
                    ? 'The pages of this document have not been read yet, and no AI budget has been set.'
                    : 'Nothing could be read from this document — it may be blank, or the file may be missing.',
            ];
        }

        try {
            $reply = $this->client->chat([
                ['role' => 'system', 'content' => self::DOCUMENT_PROMPT],
                ['role' => 'user', 'content' => "The document's pages:\n\n".mb_substr($text, 0, 40000)."\n\nQuestion: ".$question],
            ], [], [
                'max_tokens' => self::MAX_TOKENS,
                'temperature' => 0,
                'timeout' => 90,
            ]);

            $this->meter(
                $user,
                (int) ($reply['usage']['prompt_tokens'] ?? 0),
                (int) ($reply['usage']['completion_tokens'] ?? 0),
                ArchiveAiUsage::FEATURE_ASK_DOCUMENT,
                $document->archive_id,
            );

            return [
                'answer' => trim((string) ($reply['message']['content'] ?? '')),
                'pages' => array_values(array_unique($pages)),
                'read' => $read,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[archive] ask-document failed: '.$e->getMessage());

            return ['answer' => '', 'pages' => $pages, 'read' => $read, 'error' => $this->friendly($e)];
        }
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * Record what the conversation itself cost.
     *
     * Reading pages is metered by PageReader; this is the chat on top of it.
     * Both land in the same month's budget, because from the payer's side they
     * are the same spend.
     */
    private function meter(?User $user, int $promptTokens, int $completionTokens, string $feature, ?int $archiveId = null): void
    {
        // Priced off the page rate rather than a token table: one keyed-in
        // number is easier to keep honest than a per-model price list that
        // silently goes stale, and chat turns here are small next to the
        // reading they sit on.
        $cost = ArchiveAiSettings::get()->pageCost() * 0.5;

        ArchiveAiUsage::record(
            $feature,
            $cost,
            pages: 0,
            userId: $user?->getKey(),
            archiveId: $archiveId,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
        );
    }

    /** Azure's own sentence is rarely one to show an employee. */
    private function friendly(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '429') || stripos($message, 'rate limit') !== false) {
            return 'The AI service is busy. Try again in a moment.';
        }

        return 'The AI service could not answer that just now.';
    }
}
