<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Carries one AiKnowledgeImport forward: page by page through
 * PdfPageTranslator, then into an AiKnowledgeArticle that KnowledgeIndexer
 * indexes in both languages.
 *
 * Each page is saved before the next one starts, so the next run of
 * ai:import-pdfs resumes at the first page not yet in `pages` — after the time
 * budget, a deploy, or Azure throttling — rather than paying to read the
 * document again.
 */
class PdfKnowledgeImporter
{
    /** A cost guard. A page is roughly two US cents, so this caps one upload near six dollars. */
    public const MAX_PAGES = 300;

    /** Failures in a row on one page before the import is marked failed. The worker tries again a minute later each time. */
    public const MAX_ATTEMPTS = 4;

    public function __construct(
        private PdfPages $pdf,
        private PdfPageTranslator $translator,
        private KnowledgeIndexer $indexer,
    ) {}

    /**
     * Works on the import until it is done or failed, a page fails (tried again
     * on the next run), or $deadline — a microtime — passes.
     *
     * @return bool true once the import has finished, either way; false when
     *              there is more to do on a later run
     */
    public function work(AiKnowledgeImport $import, float $deadline): bool
    {
        if (! $import->isActive()) {
            return true;
        }

        $path = Storage::disk('private')->path($import->file_path);

        if ($import->page_count === null) {
            try {
                $count = $this->pdf->count($path);
            } catch (Throwable $e) {
                return $this->fail($import, $e->getMessage());
            }

            if ($count < 1) {
                return $this->fail($import, 'The PDF has no pages.');
            }

            if ($count > self::MAX_PAGES) {
                return $this->fail($import, "The PDF has {$count} pages, and an import can have at most ".self::MAX_PAGES.'. Split it and upload the parts.');
            }

            $import->page_count = $count;
        }

        $import->forceFill([
            'status' => AiKnowledgeImport::PROCESSING,
            'started_at' => $import->started_at ?? now(),
        ])->save();

        $pages = $import->pages ?? [];

        while (count($pages) < $import->page_count) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            $number = count($pages) + 1;

            try {
                $result = $this->translator->translate(
                    $this->pdf->image($path, $number),
                    $this->pdf->text($path, $number),
                    $number,
                    $import->page_count,
                );
            } catch (Throwable $e) {
                return $this->pageFailed($import, $number, $e);
            }

            // Deleted from the Knowledge page while this page was being read.
            if (! AiKnowledgeImport::whereKey($import->id)->exists()) {
                return true;
            }

            $pages[] = $result['page'];

            $import->forceFill([
                'pages' => $pages,
                'pages_done' => count($pages),
                'attempts' => 0,
                'error' => null,
                'prompt_tokens' => $import->prompt_tokens + $result['prompt_tokens'],
                'completion_tokens' => $import->completion_tokens + $result['completion_tokens'],
            ])->save();
        }

        return $this->finish($import, $pages);
    }

    /**
     * The finished pages as one article. Pure, so it is tested on its own.
     *
     * English is the body. The original is kept as the Arabic body when most
     * of the document is Arabic, so KnowledgeIndexer indexes both and an Arabic
     * question matches Arabic text. Any other original language stays only in
     * `pages`: the article has nowhere to put it.
     *
     * @param  array<int, array{language:string, title_original:string, title_english:string, original:string, english:string}>  $pages
     * @return array{title:string, title_ar:?string, body:string, body_ar:?string, language:?string}
     */
    public static function assemble(array $pages, string $fileName): array
    {
        $pages = array_values(array_filter($pages, fn (array $page) => $page['original'] !== ''));

        // The language most pages are in; a tie goes to the one met first (PHP's sort is stable).
        $counts = array_count_values(array_filter(array_column($pages, 'language')));
        arsort($counts);
        $language = array_key_first($counts);

        $titled = null;
        foreach ($pages as $page) {
            if ($page['title_english'] !== '' || $page['title_original'] !== '') {
                $titled = $page;
                break;
            }
        }

        $title = $titled['title_english'] ?? '';
        if ($title === '' && $language === 'en') {
            $title = $titled['title_original'] ?? '';
        }
        if ($title === '') {
            $title = trim((string) preg_replace('/[\s_]+/u', ' ', pathinfo($fileName, PATHINFO_FILENAME))) ?: 'Imported document';
        }

        $arabic = $language === 'ar';
        $titleAr = $arabic && ($titled['title_original'] ?? '') !== '' ? $titled['title_original'] : null;

        return [
            'title' => mb_substr($title, 0, 200),
            'title_ar' => $titleAr === null ? null : mb_substr($titleAr, 0, 200),
            'body' => implode("\n\n", array_column($pages, 'english')),
            'body_ar' => $arabic ? implode("\n\n", array_column($pages, 'original')) : null,
            'language' => $language,
        ];
    }

    private function finish(AiKnowledgeImport $import, array $pages): bool
    {
        $document = self::assemble($pages, $import->file_name);

        if ($document['body'] === '') {
            return $this->fail($import, 'No text was found on any page.');
        }

        $article = DB::transaction(function () use ($import, $document) {
            // Locked, so a delete from the Knowledge page either lands first
            // (and no article is made) or waits and finds the article made.
            if (AiKnowledgeImport::whereKey($import->id)->lockForUpdate()->value('id') === null) {
                return null;
            }

            $article = AiKnowledgeArticle::create([
                'title' => $document['title'],
                'title_ar' => $document['title_ar'],
                'body' => $document['body'],
                'body_ar' => $document['body_ar'],
                'category' => $import->category,
                'tags' => [],
                'audience' => $import->audience,
                'audience_branch_id' => $import->audience_branch_id,
                'audience_department_id' => $import->audience_department_id,
                'is_published' => $import->publish,
                'created_by' => $import->created_by,
            ]);

            $import->forceFill([
                'status' => AiKnowledgeImport::DONE,
                'article_id' => $article->id,
                'source_language' => $document['language'],
                'attempts' => 0,
                'error' => null,
                'finished_at' => now(),
            ])->save();

            return $article;
        });

        if ($article?->is_published && ! $this->indexer->indexArticle($article)) {
            $import->forceFill(['error' => 'Published, but indexing failed — use Reindex All once Azure OpenAI is reachable.'])->save();
        }

        return true;
    }

    private function pageFailed(AiKnowledgeImport $import, int $number, Throwable $e): bool
    {
        $attempts = $import->attempts + 1;
        $reason = "Page {$number}: ".self::reason($e);

        Log::warning('ai:import-pdfs: page failed', [
            'import_id' => $import->id,
            'page' => $number,
            'attempt' => $attempts,
            'error' => $e->getMessage(),
        ]);

        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->fail($import, $reason, $attempts);
        }

        $import->forceFill(['attempts' => $attempts, 'error' => $reason])->save();

        return false;
    }

    private function fail(AiKnowledgeImport $import, string $reason, ?int $attempts = null): bool
    {
        $import->forceFill([
            'status' => AiKnowledgeImport::FAILED,
            'error' => mb_substr($reason, 0, 1000),
            'attempts' => $attempts ?? $import->attempts,
            'finished_at' => now(),
        ])->save();

        return true;
    }

    /** Azure's usual failures, said the way the person fixing them needs to read them. */
    private static function reason(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'HTTP 429')) {
            return 'Azure OpenAI is throttling requests (HTTP 429).';
        }

        if (str_contains($message, 'content_filter')) {
            return "Azure OpenAI's content filter blocked this page.";
        }

        return mb_substr($message, 0, 400);
    }
}
