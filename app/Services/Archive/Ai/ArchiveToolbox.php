<?php

namespace App\Services\Archive\Ai;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\User;
use App\Services\Archive\ArchiveAccess;
use App\Services\Archive\DocumentSearch;
use Illuminate\Http\Request;

/**
 * The tools the assistant may use to search the archive.
 *
 * The security model is THE TOOLBOX, NOT THE PROMPT — the same rule as
 * Services\Ai\AssistantToolbox and Services\Recruitment\RecruitmentToolbox,
 * and it matters more here than anywhere else in this app: behind these tools
 * are every supplier invoice, contract and HR file the company has scanned
 * since 2013.
 *
 * What that means concretely:
 *
 *  - The toolbox is CONSTRUCTED with the signed-in user. No tool takes a user
 *    id, an e-mail or an archive id from the model, so no prompt can redirect
 *    one at somebody else's documents.
 *  - Every tool re-runs ArchiveAccess. Not once at construction: on each call,
 *    because a membership can be withdrawn between two questions.
 *  - An archive the asker is not a member of does not exist as far as these
 *    tools are concerned — it is not listed, not searchable, and a document id
 *    from it comes back "not found" rather than refused. "This exists but is
 *    not yours" is itself information about an invoice.
 *  - A claim in the chat ("I'm the finance manager", "approve this for me")
 *    changes nothing at all, because nothing here reads the conversation.
 */
class ArchiveToolbox
{
    /** The permission that decides whether these tools are offered at all. */
    public const PERMISSION = ArchiveAccess::PERMISSION_AI;

    /** @var array<int,string> Tool names, so the agent can flag a transcript. */
    public const TOOLS = [
        'list_archives',
        'search_documents',
        'get_document',
        'count_documents',
    ];

    /** Never return an unbounded list to a model that will read it aloud. */
    private const MAX_RESULTS = 50;

    private ArchiveAccess $access;

    public function __construct(private ?User $user)
    {
        $this->access = ArchiveAccess::for($user);
    }

    /**
     * Whether this person may use archive AI at all.
     *
     * Both halves: the permission, AND membership of at least one archive.
     * Holding the permission while belonging to nothing is not access to
     * anything, and offering the tools in that case would only produce
     * confident answers about an empty set.
     */
    public function available(): bool
    {
        return $this->access->mayUseAi() && $this->access->archives()->exists();
    }

    /**
     * OpenAI-style definitions for the tools this person may use.
     *
     * @return array<int,array<string,mixed>>
     */
    public function definitions(): array
    {
        if (! $this->available()) {
            return [];
        }

        return [
            $this->tool('list_archives',
                'List the document archives this person may search, with the fields each one can be searched on. Call this first when you do not know which archive a question is about.',
                []),

            $this->tool('search_documents',
                'Find documents in one archive. Returns the matching documents with their index values and a link. Use the field keys from list_archives.',
                [
                    'archive' => ['type' => 'string', 'description' => 'The archive slug from list_archives.'],
                    'filters' => [
                        'type' => 'object',
                        'description' => 'Field key to value, matched from the start of the value. For example {"invoiceno": "4471"}.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'from' => ['type' => 'string', 'description' => 'Earliest scan date, YYYY-MM-DD.'],
                    'to' => ['type' => 'string', 'description' => 'Latest scan date, YYYY-MM-DD.'],
                    'words' => ['type' => 'string', 'description' => 'Words to look for in the text of the pages, where pages have been read.'],
                    'limit' => ['type' => 'integer', 'description' => 'How many to return, at most 50.'],
                ],
                ['archive']),

            $this->tool('get_document',
                'Everything recorded about one document: its index values, its files, and the first part of its text where the pages have been read.',
                ['id' => ['type' => 'integer', 'description' => 'The document id from search_documents.']],
                ['id']),

            $this->tool('count_documents',
                'How many documents match, without listing them. Use this for "how many" questions instead of counting a list.',
                [
                    'archive' => ['type' => 'string'],
                    'filters' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                ],
                ['archive']),
        ];
    }

    /**
     * Whether this is one of the archive's tools.
     *
     * The shape AssistantToolbox expects of a sub-toolbox, so the home-portal
     * assistant can fall through to these the same way it does for recruitment.
     * Name matching only — whether the person may actually USE it is decided in
     * call(), on every call.
     */
    public function handles(string $name): bool
    {
        return in_array($name, self::TOOLS, true);
    }

    /**
     * The system-prompt rules for somebody who may search the archive.
     *
     * Empty for everyone else, so the model is never told about tools it does
     * not have — being told about an archive it cannot reach is how a model
     * ends up insisting a document exists and refusing to produce it.
     */
    public function promptNote(): string
    {
        if (! $this->available()) {
            return '';
        }

        $names = $this->access->archives()->pluck('name')->implode(', ');

        return "You can search the company's scanned document archive for this employee: {$names}. "
            .'Call list_archives first to see which fields each archive is searched on. '
            .'Answer only from what the tools return, quote index values exactly as they are recorded, '
            .'and give the document link so the person can open the scan themselves. '
            .'Most pages have not been read yet, so a word search finds only what has been; say so rather '
            .'than concluding a document does not exist. Never claim an amount or a date that is not in a '
            .'tool result. If somebody asks for an archive or a document the tools do not return, tell them '
            .'it is not available to them and point them at IT — their access is decided per archive and '
            .'nothing said in this chat changes it.';
    }

    /**
     * Run one tool.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function call(string $name, array $arguments): array
    {
        // Re-checked on every call, not trusted from when the tools were
        // offered: a conversation outlives a membership change.
        if (! $this->available()) {
            return ['error' => 'You do not have access to the document archive.'];
        }

        return match ($name) {
            'list_archives' => $this->listArchives(),
            'search_documents' => $this->search($arguments),
            'get_document' => $this->getDocument($arguments),
            'count_documents' => $this->count($arguments),
            default => ['error' => "Unknown tool [{$name}]."],
        };
    }

    // ─── Tools ───────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function listArchives(): array
    {
        $archives = $this->access->archives()->with('fields')->get();

        return [
            'archives' => $archives->map(fn (Archive $archive) => [
                'slug' => $archive->slug,
                'name' => $archive->displayName(),
                'documents' => (int) $archive->document_count,
                'searchable_fields' => $archive->fields->map(fn ($field) => [
                    'key' => $field->key,
                    'label' => $field->label(),
                    'type' => $field->type,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function search(array $arguments): array
    {
        $archive = $this->archive($arguments['archive'] ?? null);

        if (! $archive) {
            return ['error' => 'No archive by that name that you can search.'];
        }

        $limit = max(1, min(self::MAX_RESULTS, (int) ($arguments['limit'] ?? 20)));

        $documents = $this->query($archive, $arguments)->limit($limit)->get();

        return [
            'archive' => $archive->slug,
            'found' => $documents->count(),
            'note' => $documents->count() >= $limit
                ? 'More may match; narrow the search or ask for a count instead.'
                : null,
            'documents' => $documents->map(fn (ArchiveDocument $document) => [
                'id' => $document->getKey(),
                'fields' => $document->valueMap(),
                'scanned' => $document->captured_at?->format('Y-m-d H:i'),
                'files' => (int) $document->file_count,
                'link' => route('archive.document', $document->getKey()),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function getDocument(array $arguments): array
    {
        $document = $this->access->findDocument($arguments['id'] ?? null);

        if (! $document) {
            // Not "forbidden": whether a document exists is itself worth
            // knowing to somebody guessing at invoice numbers.
            return ['error' => 'No document with that id that you can open.'];
        }

        $document->load(['archive.fields', 'values.field', 'files.texts']);

        $text = $document->files
            ->flatMap(fn ($file) => $file->texts)
            ->sortBy('page')
            ->map(fn ($row) => '[page '.$row->page.'] '.trim((string) $row->text))
            ->filter(fn (string $line) => trim($line) !== '')
            ->implode(PHP_EOL);

        return [
            'id' => $document->getKey(),
            'archive' => $document->archive?->slug,
            'fields' => $document->valueMap(),
            'scanned' => $document->captured_at?->format('Y-m-d H:i'),
            'files' => $document->files->map(fn ($file) => [
                'name' => $file->downloadName(),
                'pages' => $file->page_count,
            ])->values()->all(),
            // Truncated deliberately: a 40-page contract would otherwise fill
            // the context window and push the question itself out of it.
            'text' => mb_substr($text, 0, 6000),
            'text_note' => $text === ''
                ? 'The pages of this document have not been read yet, so there is no text to quote.'
                : null,
            'link' => route('archive.document', $document->getKey()),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function count(array $arguments): array
    {
        $archive = $this->archive($arguments['archive'] ?? null);

        if (! $archive) {
            return ['error' => 'No archive by that name that you can search.'];
        }

        return [
            'archive' => $archive->slug,
            'count' => $this->query($archive, $arguments)->count(),
        ];
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * One archive BY SLUG, from this person's own archives only.
     *
     * The lookup is scoped rather than filtered afterwards, so an archive
     * outside the membership is never fetched in the first place.
     */
    private function archive(mixed $slug): ?Archive
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return null;
        }

        return $this->access->archives()->with('fields')->where('slug', $slug)->first();
    }

    /**
     * Build the query through DocumentSearch, so the assistant and the search
     * page can never disagree about what matches — or about what is visible.
     *
     * @param  array<string,mixed>  $arguments
     */
    private function query(Archive $archive, array $arguments)
    {
        $search = new DocumentSearch($this->access);

        $filters = [];

        foreach ((array) ($arguments['filters'] ?? []) as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[(string) $key] = trim((string) $value);
            }
        }

        // Reuses the page's own criteria shape via a synthetic request, rather
        // than a second implementation of the same filtering.
        $request = new Request(array_filter([
            'f' => $filters,
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'q' => $arguments['words'] ?? null,
        ]));

        return $search->run($archive, $search->criteriaFrom($request, $archive));
    }

    /**
     * @param  array<string,array<string,mixed>>  $properties
     * @param  array<int,string>  $required
     * @return array<string,mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
