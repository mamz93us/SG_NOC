<?php

namespace App\Services\Archive;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Finding a document, the way the people leaving ArcMate already look for one.
 *
 * The form is the archive's own fields, so an invoice is still found by invoice
 * number or PO number — the same two boxes, backed by the same two columns.
 *
 * Every query starts from ArchiveAccess::documents(), never from
 * ArchiveDocument directly. That is the rule that keeps a search from becoming
 * the way around per-archive membership: there is no code path here that can
 * return a document the asker may not open, including when a filter matches
 * nothing and the query degenerates.
 *
 * Values are matched against archive_document_values with a whereExists per
 * filter rather than a join per filter. Two filters over 513,381 invoices would
 * otherwise multiply rows before they narrowed them; whereExists stops at the
 * first match and rides the (field, value) index.
 */
class DocumentSearch
{
    /** Text matching is a prefix search by default — it can use the index. */
    private const MAX_TERM = 250;

    public function __construct(private ArchiveAccess $access) {}

    /**
     * What the person asked for, cleaned up.
     *
     * @return array{filters:array<string,mixed>, from:?string, to:?string, words:?string}
     */
    public function criteriaFrom(Request $request, Archive $archive): array
    {
        $filters = [];
        $raw = (array) $request->query('f', []);

        foreach ($archive->fields as $field) {
            $value = $raw[$field->key] ?? null;

            if (is_array($value)) {
                $from = trim((string) ($value['from'] ?? ''));
                $to = trim((string) ($value['to'] ?? ''));

                if ($from !== '' || $to !== '') {
                    $filters[$field->key] = ['from' => $from ?: null, 'to' => $to ?: null];
                }

                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $filters[$field->key] = mb_substr($value, 0, self::MAX_TERM);
            }
        }

        return [
            'filters' => $filters,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'words' => mb_substr(trim((string) $request->query('q', '')), 0, self::MAX_TERM) ?: null,
            // 'date', or a field key. Checked against the archive's own fields
            // before it reaches a query, so a column name cannot arrive in a URL.
            'sort' => mb_substr(trim((string) $request->query('sort', 'date')), 0, 60),
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @param array<string,mixed> $criteria */
    public function hasFilters(array $criteria): bool
    {
        return $criteria['filters'] !== [] || $criteria['from'] || $criteria['to'] || $criteria['words'];
    }

    /**
     * The query, newest first.
     *
     * `ai_readings` (off unless asked for) lets a field filter match a value AI
     * read off the paper that nobody has reviewed yet, as well as the document's
     * recorded value. The assistant asks for it, labelling each such reading as
     * unreviewed; the search page does not, because a list of results there
     * reads as the archive's own index.
     *
     * @param  array{filters:array<string,mixed>, from:?string, to:?string, words:?string, ai_readings?:bool}  $criteria
     */
    public function run(Archive $archive, array $criteria): Builder
    {
        $withReadings = (bool) ($criteria['ai_readings'] ?? false);

        $query = $this->access->documents()
            ->where('archive_id', $archive->getKey())
            ->with(['values.field']);

        $fields = $archive->fields->keyBy('key');

        foreach ($criteria['filters'] as $key => $value) {
            $field = $fields->get($key);

            if ($field) {
                $this->applyField($query, $field, $value, $withReadings);
            }
        }

        // The capture date, which for a mirrored archive is the moment ArcMate
        // named the file — the only date it recorded at all.
        if ($criteria['from']) {
            $query->where('captured_at', '>=', $criteria['from'].' 00:00:00');
        }

        if ($criteria['to']) {
            $query->where('captured_at', '<=', $criteria['to'].' 23:59:59');
        }

        if ($criteria['words']) {
            $this->applyWords($query, $criteria['words']);
        }

        return $this->applySort($query, $archive, $criteria);
    }

    /**
     * Order by the capture date, or by one of the archive's own fields.
     *
     * A field's value lives in another table, so sorting by one is a left join:
     * left, because a document whose invoice number was never filled in should
     * still appear rather than vanish from a sorted list. Blanks go last in both
     * directions, for the same reason undated documents do — an empty value is
     * not what anybody is looking for.
     *
     * The sort key is matched against the archive's fields, so nothing from the
     * URL reaches the query as a column name.
     */
    private function applySort(Builder $query, Archive $archive, array $criteria): Builder
    {
        $dir = ($criteria['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = (string) ($criteria['sort'] ?? 'date');

        $field = $sort === 'date' ? null : $archive->fields->firstWhere('key', $sort);

        if ($field) {
            return $query
                ->leftJoin('archive_document_values as sort_value', function ($join) use ($field) {
                    $join->on('sort_value.archive_document_id', '=', 'archive_documents.id')
                        ->where('sort_value.archive_field_id', '=', $field->getKey());
                })
                // The join duplicates `id`, so say which table the rows come from
                // or the paginator counts and hydrates the wrong thing.
                ->select('archive_documents.*')
                ->orderByRaw("sort_value.value_text IS NULL OR sort_value.value_text = ''")
                ->orderBy('sort_value.value_text', $dir)
                ->orderByDesc('archive_documents.id');
        }

        // Undated documents sort last rather than first: a null capture date
        // means the file names were unreadable, which is rare and not what
        // anybody is looking for when they open an archive.
        return $query->orderByRaw('captured_at IS NULL')
            ->orderBy('captured_at', $dir)
            ->orderByDesc('id');
    }

    /**
     * One field's filter.
     *
     * Dates and numbers compare on their typed columns; everything else is a
     * prefix match on the text, which is what an invoice or PO number search
     * actually is and what the index can serve.
     *
     * With AI readings, a document matches on its recorded value OR a pending
     * reading. That is a UNION of the two index lookups joined in as a
     * derived table, never `EXISTS … OR EXISTS …`: MySQL cannot turn an OR of
     * two EXISTS into a semi-join, so it walks every document in the archive
     * and probes both tables for each — measured on NOC2's 515,000 invoices,
     * an exact invoice number took 6.5 s that way against 3 ms without the
     * readings. A field no reading is pending for skips the union entirely.
     */
    private function applyField(Builder $query, ArchiveField $field, mixed $value, bool $withReadings = false): void
    {
        if ($withReadings && $this->hasPendingReadings($field)) {
            $values = DB::table('archive_document_values')
                ->select('archive_document_values.archive_document_id')
                ->where('archive_document_values.archive_field_id', $field->getKey());
            $this->valueConditions($values, $field, $value);

            $readings = DB::table('archive_ai_proposals')
                ->select('archive_ai_proposals.archive_document_id')
                ->where('archive_ai_proposals.archive_field_id', $field->getKey())
                ->where('archive_ai_proposals.status', ArchiveAiProposal::STATUS_PENDING);
            $this->readingConditions($readings, $field, $value);

            $alias = 'field_match_'.$field->getKey();

            $query->joinSub($values->union($readings), $alias, "{$alias}.archive_document_id", '=', 'archive_documents.id')
                // The derived table adds a column; the rows are still documents.
                ->select('archive_documents.*');

            return;
        }

        $query->whereExists(function ($sub) use ($field, $value) {
            $sub->select(DB::raw(1))
                ->from('archive_document_values')
                ->whereColumn('archive_document_values.archive_document_id', 'archive_documents.id')
                ->where('archive_document_values.archive_field_id', $field->getKey());

            $this->valueConditions($sub, $field, $value);
        });
    }

    /** $value's condition on archive_document_values, for either shape of query. */
    private function valueConditions($query, ArchiveField $field, mixed $value): void
    {
        if (is_array($value)) {
            $column = $field->isNumber() ? 'value_number' : 'value_date';

            if ($value['from'] ?? null) {
                $query->where("archive_document_values.{$column}", '>=', $value['from']);
            }

            if ($value['to'] ?? null) {
                $query->where("archive_document_values.{$column}", '<=', $value['to']);
            }

            return;
        }

        if ($field->isList()) {
            $query->where('archive_document_values.value_text', $value);

            return;
        }

        $query->where('archive_document_values.value_text', 'like', $this->escapeLike($value).'%');
    }

    /**
     * $value's condition on a pending AI reading.
     *
     * Pending only (the caller's query says so): an approved or edited
     * reading is already the document's value, a rejected one was judged
     * wrong, and not_found holds nothing. FieldExtractor writes dates as Y-m-d
     * and numbers without separators, so a date range compares as text and a
     * number range as a cast.
     */
    private function readingConditions($query, ArchiveField $field, mixed $value): void
    {
        if (is_array($value)) {
            $column = $field->isNumber()
                ? DB::raw('CAST(archive_ai_proposals.value AS DECIMAL(20,4))')
                : 'archive_ai_proposals.value';

            if ($value['from'] ?? null) {
                $query->where($column, '>=', $value['from']);
            }

            if ($value['to'] ?? null) {
                $query->where($column, '<=', $value['to']);
            }

            return;
        }

        if ($field->isList()) {
            $query->where('archive_ai_proposals.value', $value);

            return;
        }

        $query->where('archive_ai_proposals.value', 'like', $this->escapeLike($value).'%');
    }

    /** Whether AI has read this field anywhere that nobody has reviewed yet. */
    private function hasPendingReadings(ArchiveField $field): bool
    {
        return ArchiveAiProposal::query()
            ->where('archive_field_id', $field->getKey())
            ->pending()
            ->exists();
    }

    /**
     * The pages of these documents whose text holds $words, with a snippet
     * around the first word found: what the assistant quotes when a word
     * search is what found a document. The same match as applyWords(), so a
     * document found by its words always has a page to show.
     *
     * @param  array<int,int>  $documentIds  already scoped by the caller
     * @return array<int,list<array{file:string, page:int, snippet:string}>> keyed by document id
     */
    public function matchingPages(array $documentIds, string $words, int $perDocument = 3): array
    {
        $words = mb_substr(trim($words), 0, self::MAX_TERM);

        if ($documentIds === [] || $words === '') {
            return [];
        }

        $query = DB::table('archive_file_texts')
            ->join('archive_files', 'archive_files.id', '=', 'archive_file_texts.archive_file_id')
            ->whereIn('archive_files.archive_document_id', $documentIds)
            ->orderBy('archive_files.archive_document_id')
            ->orderBy('archive_files.position')
            ->orderBy('archive_file_texts.page')
            ->select([
                'archive_files.archive_document_id as document_id',
                'archive_files.original_name as file',
                'archive_file_texts.page',
                'archive_file_texts.text',
            ]);

        if ($query->getConnection()->getDriverName() === 'mysql') {
            $query->whereRaw('MATCH(archive_file_texts.text) AGAINST (? IN BOOLEAN MODE)', [$words]);
        } else {
            $query->where('archive_file_texts.text', 'like', '%'.$this->escapeLike($words).'%');
        }

        $pages = [];

        foreach ($query->limit(count($documentIds) * $perDocument * 4)->get() as $row) {
            $id = (int) $row->document_id;

            if (count($pages[$id] ?? []) >= $perDocument) {
                continue;
            }

            $pages[$id][] = [
                'file' => (string) $row->file,
                'page' => (int) $row->page,
                'snippet' => self::snippet((string) $row->text, $words),
            ];
        }

        return $pages;
    }

    /**
     * About 300 characters of $text around the first of $words it holds — the
     * start of the page when none is found as written (a FULLTEXT match can
     * rest on a stem or a boolean operator).
     */
    public static function snippet(string $text, string $words, int $width = 300): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $at = null;

        foreach (preg_split('/\s+/u', $words, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = trim($word, '+-~<>()"*');

            if (mb_strlen($word) < 2) {
                continue;
            }

            $position = mb_stripos($text, $word);

            if ($position !== false && ($at === null || $position < $at)) {
                $at = $position;
            }
        }

        $start = max(0, ($at ?? 0) - intdiv($width, 3));
        $snippet = mb_substr($text, $start, $width);

        return ($start > 0 ? '…' : '').$snippet.($start + $width < mb_strlen($text) ? '…' : '');
    }

    /**
     * Words anywhere in the document's readable text.
     *
     * Almost nothing has readable text yet: of 93 files probed across the live
     * archives only two PDFs carried a text layer, and ArcMate's own OCR column
     * came back null. This fills up as AI reading runs, so the box is honest
     * from day one and simply finds more over time.
     *
     * MySQL gets its FULLTEXT index; anything else (the test database) falls
     * back to LIKE, which is slow but correct and never runs in production.
     */
    private function applyWords(Builder $query, string $words): void
    {
        $driver = $query->getConnection()->getDriverName();

        $query->whereExists(function ($sub) use ($words, $driver) {
            $sub->select(DB::raw(1))
                ->from('archive_file_texts')
                ->join('archive_files', 'archive_files.id', '=', 'archive_file_texts.archive_file_id')
                ->whereColumn('archive_files.archive_document_id', 'archive_documents.id');

            if ($driver === 'mysql') {
                $sub->whereRaw('MATCH(archive_file_texts.text) AGAINST (? IN BOOLEAN MODE)', [$words]);
            } else {
                $sub->where('archive_file_texts.text', 'like', '%'.$this->escapeLike($words).'%');
            }
        });
    }

    /**
     * The distinct values a list field actually holds.
     *
     * ArcMate leaves most of its list fields with no choices defined, so what
     * people pick from has to come from the documents themselves.
     *
     * @return array<int,string>
     */
    public function choices(ArchiveField $field): array
    {
        if (($declared = $field->optionList()) !== []) {
            return $declared;
        }

        return DB::table('archive_document_values')
            ->where('archive_field_id', $field->getKey())
            ->whereNotNull('value_text')
            ->distinct()
            ->orderBy('value_text')
            ->limit(200)
            ->pluck('value_text')
            ->all();
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** `%` and `_` are wildcards; an invoice number containing one is not. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
