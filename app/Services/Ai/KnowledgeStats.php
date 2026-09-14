<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind AI Assistant Knowledge ▸ Statistics: how much text the
 * assistant can search, how it is cut into chunks, and what it takes to store.
 *
 * Counted in SQL. The imported labor law alone is 428 chunks with a 6 KB
 * embedding each, and none of that needs to reach PHP to be summed.
 */
class KnowledgeStats
{
    /** Upper bounds of the chunk-size bands, in characters; AiChunker cuts at 1,500. */
    public const SIZE_BANDS = [250, 500, 1000, 1500];

    /** The tables the knowledge base lives in, for the storage figures. */
    public const TABLES = ['ai_knowledge_articles', 'ai_knowledge_chunks', 'ai_knowledge_imports', 'ai_knowledge_gaps'];

    public function build(): array
    {
        $settings = AiSetting::get();
        $chunks = $this->chunkGroups();
        $sources = $this->sources($chunks);

        return [
            'totals' => $this->totals($chunks, $sources),
            'languages' => $this->languages($chunks),
            'sources' => $sources,
            'sizes' => $this->sizeBands(),
            'imports' => $this->imports(),
            'storage' => $this->storage(),
            'settings' => [
                'relevance_floor' => $settings->knowledge_match_threshold ?? KnowledgeRetriever::DEFAULT_RELEVANCE_FLOOR,
                'embedding_deployment' => $settings->embedding_deployment,
                'chunk_max_characters' => AiChunker::MAX_CHARS,
                'batch_characters' => KnowledgeIndexer::BATCH_CHARS,
            ],
        ];
    }

    /** One row per source and language: how many chunks, how much text, how many bytes of embedding. */
    private function chunkGroups(): Collection
    {
        $length = $this->lengthFunction();

        return DB::table('ai_knowledge_chunks')
            ->selectRaw("article_id, portal_document_id, COALESCE(locale, '') AS locale,
                COUNT(*) AS chunks,
                COALESCE(SUM({$length}(content)), 0) AS characters,
                COALESCE(MAX({$length}(content)), 0) AS largest,
                COALESCE(SUM(LENGTH(embedding)), 0) AS embedding_bytes,
                SUM(CASE WHEN embedding IS NULL OR LENGTH(embedding) = 0 THEN 1 ELSE 0 END) AS unembedded,
                SUM(CASE WHEN source_page IS NULL THEN 0 ELSE 1 END) AS with_page,
                MAX(updated_at) AS last_indexed")
            ->groupByRaw("article_id, portal_document_id, COALESCE(locale, '')")
            ->get();
    }

    /** Every article, and every library PDF that has chunks, each with its own numbers. */
    private function sources(Collection $chunks): Collection
    {
        $length = $this->lengthFunction();
        $byArticle = $chunks->whereNotNull('article_id')->groupBy('article_id');
        $byDocument = $chunks->whereNotNull('portal_document_id')->groupBy('portal_document_id');

        $rows = DB::table('ai_knowledge_articles AS a')
            ->leftJoin('ai_knowledge_imports AS i', 'i.article_id', '=', 'a.id')
            ->selectRaw("a.id, a.title, a.category, a.is_published,
                {$length}(a.body) AS body_characters, {$length}(COALESCE(a.body_ar, '')) AS body_ar_characters,
                i.id AS import_id, i.file_name, i.page_count, i.source_language")
            ->orderBy('a.title')
            ->get()
            ->unique('id')
            ->map(fn (object $a) => $this->measure([
                'kind' => $a->import_id ? 'pdf' : 'written',
                'article_id' => (int) $a->id,
                'document_id' => null,
                'title' => (string) $a->title,
                'category' => $a->category,
                'published' => (bool) $a->is_published,
                'file_name' => $a->file_name,
                'import_id' => $a->import_id ? (int) $a->import_id : null,
                'page_count' => $a->page_count !== null ? (int) $a->page_count : null,
                'source_language' => $a->source_language,
                'body_characters' => (int) $a->body_characters + (int) $a->body_ar_characters,
            ], $byArticle->get($a->id, collect())));

        if ($byDocument->isNotEmpty()) {
            $documents = DB::table('portal_documents')
                ->whereIn('id', $byDocument->keys()->all())
                ->orderBy('title')
                ->get(['id', 'title', 'is_published', 'file_name']);

            foreach ($documents as $d) {
                $rows->push($this->measure([
                    'kind' => 'library',
                    'article_id' => null,
                    'document_id' => (int) $d->id,
                    'title' => (string) $d->title,
                    'category' => null,
                    'published' => (bool) $d->is_published,
                    'file_name' => $d->file_name,
                    'import_id' => null,
                    'page_count' => null,
                    'source_language' => null,
                    'body_characters' => null,
                ], $byDocument->get($d->id, collect())));
            }
        }

        return $rows->values();
    }

    /** @param  Collection<int, object>  $groups  this source's chunk rows, one per language */
    private function measure(array $source, Collection $groups): array
    {
        $count = (int) $groups->sum('chunks');
        $characters = (int) $groups->sum('characters');
        $unembedded = (int) $groups->sum('unembedded');
        $inLanguage = fn (string $locale, string $column) => (int) ($groups->firstWhere('locale', $locale)?->{$column} ?? 0);

        return $source + [
            'chunks' => $count,
            'chunks_en' => $inLanguage('en', 'chunks'),
            'chunks_ar' => $inLanguage('ar', 'chunks'),
            'characters' => $characters,
            'characters_en' => $inLanguage('en', 'characters'),
            'characters_ar' => $inLanguage('ar', 'characters'),
            'average_chunk' => $count > 0 ? (int) round($characters / $count) : 0,
            'largest_chunk' => (int) $groups->max('largest'),
            'embedding_bytes' => (int) $groups->sum('embedding_bytes'),
            'unembedded' => $unembedded,
            'with_page' => (int) $groups->sum('with_page'),
            'last_indexed' => $groups->max('last_indexed'),
            'searchable' => $source['published'] && $count > $unembedded,
        ];
    }

    private function totals(Collection $chunks, Collection $sources): array
    {
        $articles = $sources->whereNotNull('article_id');
        $count = (int) $chunks->sum('chunks');
        $characters = (int) $chunks->sum('characters');

        return [
            'articles' => $articles->count(),
            'published' => $articles->where('published', true)->count(),
            'drafts' => $articles->where('published', false)->count(),
            'written' => $articles->where('kind', 'written')->count(),
            'imported' => $articles->where('kind', 'pdf')->count(),
            'library_documents' => $sources->where('kind', 'library')->count(),
            'not_indexed' => $articles->filter(fn (array $s) => $s['published'] && $s['chunks'] === 0)->count(),
            'chunks' => $count,
            'characters' => $characters,
            'average_chunk' => $count > 0 ? (int) round($characters / $count) : 0,
            'largest_chunk' => (int) $chunks->max('largest'),
            'embedding_bytes' => (int) $chunks->sum('embedding_bytes'),
            'unembedded' => (int) $chunks->sum('unembedded'),
            'open_gaps' => DB::table('ai_knowledge_gaps')->whereNull('resolved_at')->count(),
        ];
    }

    /** @return array<string, array{chunks: int, characters: int}> keyed ar / en / other */
    private function languages(Collection $chunks): array
    {
        return $chunks
            ->groupBy(fn (object $row) => $row->locale !== '' ? $row->locale : 'other')
            ->map(fn (Collection $rows) => [
                'chunks' => (int) $rows->sum('chunks'),
                'characters' => (int) $rows->sum('characters'),
            ])
            ->sortKeys()
            ->all();
    }

    /** @return array<int, array{label: string, en: int, ar: int, other: int, total: int}> */
    private function sizeBands(): array
    {
        $length = $this->lengthFunction();
        $cases = [];
        $lower = -1;

        foreach (self::SIZE_BANDS as $n => $upper) {
            $cases[] = "SUM(CASE WHEN {$length}(content) > {$lower} AND {$length}(content) <= {$upper} THEN 1 ELSE 0 END) AS band{$n}";
            $lower = $upper;
        }
        $cases[] = "SUM(CASE WHEN {$length}(content) > {$lower} THEN 1 ELSE 0 END) AS band".count(self::SIZE_BANDS);

        $rows = DB::table('ai_knowledge_chunks')
            ->selectRaw("COALESCE(locale, '') AS locale, ".implode(', ', $cases))
            ->groupByRaw("COALESCE(locale, '')")
            ->get()
            ->keyBy(fn (object $row) => $row->locale !== '' ? $row->locale : 'other');

        $bands = [];
        $lower = 0;

        foreach ([...self::SIZE_BANDS, null] as $n => $upper) {
            $count = fn (string $key) => (int) ($rows->get($key)?->{"band{$n}"} ?? 0);

            $bands[] = [
                'label' => match (true) {
                    $upper === null => 'Over '.number_format($lower),
                    $lower === 0 => 'Up to '.number_format($upper),
                    default => number_format($lower + 1).'–'.number_format($upper),
                },
                'en' => $count('en'),
                'ar' => $count('ar'),
                'other' => $count('other'),
                'total' => $count('en') + $count('ar') + $count('other'),
            ];

            $lower = $upper ?? $lower;
        }

        return $bands;
    }

    /** @return array{files: int, bytes: int, pages: int, prompt_tokens: int, completion_tokens: int, failed: int, active: int} */
    private function imports(): array
    {
        $row = DB::table('ai_knowledge_imports')
            ->selectRaw("COUNT(*) AS files, COALESCE(SUM(file_size), 0) AS bytes, COALESCE(SUM(pages_done), 0) AS pages,
                COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens, COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status IN ('queued', 'processing') THEN 1 ELSE 0 END) AS active")
            ->first();

        return array_map('intval', (array) $row);
    }

    /**
     * Rows and bytes per table from information_schema: MySQL's own estimate,
     * refreshed when InnoDB updates its statistics, so approximate. Null on any
     * other database, such as the SQLite test suite.
     *
     * @return array<int, array{table: string, rows: int, data_bytes: int, index_bytes: int}>|null
     */
    private function storage(): ?array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $marks = implode(', ', array_fill(0, count(self::TABLES), '?'));

        return collect(DB::select(
            "SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$marks})",
            self::TABLES,
        ))
            ->map(fn (object $row) => [
                'table' => (string) $row->name,
                'rows' => (int) $row->row_estimate,
                'data_bytes' => (int) $row->data_bytes,
                'index_bytes' => (int) $row->index_bytes,
            ])
            ->sortBy('table')
            ->values()
            ->all();
    }

    /** Characters, not bytes: an Arabic letter is two bytes in UTF-8. SQLite's LENGTH() already counts characters. */
    private function lengthFunction(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
    }
}
