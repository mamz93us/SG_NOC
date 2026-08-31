<?php

namespace App\Services\Ticketing;

use App\Models\Setting;

/**
 * The category / subcategory / type / priority lookups for the Create Ticket
 * form.
 *
 * Categories and sub-categories come from the ticketing API's own lookup
 * endpoints (`getCategories` / `getSubCategories`, see {@see TicketCatalogApi}).
 * The JSON in Admin → Settings is the fallback for when that call fails, and
 * still the only source of **type** and **priority** labels — the API exposes
 * those as bare ids on each sub-category and publishes no list for them.
 *
 * Everything is defensive: a half-edited catalog or a dead lookup endpoint must
 * degrade to "no options" rather than throw on a page load.
 */
class TicketCatalog
{
    public const SOURCE_API = 'api';

    public const SOURCE_SETTINGS = 'settings';

    public const SOURCE_NONE = 'none';

    /**
     * @var array<int, array{
     *     id:int, name:string, name_ar:?string, department_id:?int,
     *     subcategories: array<int, array{id:int,name:string,name_ar:?string,type_id:?int,priority_id:?int}>
     * }>
     */
    public array $categories = [];

    /** @var array<int, array{id:int, name:string}> */
    public array $types = [];

    /** @var array<int, array{id:int, name:string}> */
    public array $priorities = [];

    public int $channelId = 1;

    /** Extra literal key/values merged into the `data` object on every submit. */
    public array $extra = [];

    /** Where `categories` came from — shown on the form and in Settings. */
    public string $source = self::SOURCE_NONE;

    public static function fromSettings(?Setting $settings = null): self
    {
        $settings ??= Setting::get();

        $catalog = self::fromArray($settings->noc_ticket_catalog ?? []);
        $catalog->source = $catalog->categories === [] ? self::SOURCE_NONE : self::SOURCE_SETTINGS;

        // The API is authoritative when it answers; the settings JSON keeps the
        // type/priority labels and the channel id either way.
        $fromApi = app(TicketCatalogApi::class)->categoriesOrFetch($settings);

        if (is_array($fromApi) && $fromApi !== []) {
            $catalog->categories = self::normalizeCategories($fromApi);
            $catalog->source = self::SOURCE_API;
        }

        $catalog->backfillTypesAndPriorities();

        return $catalog;
    }

    /** Parse the hand-maintained JSON only — no API call. */
    public static function fromArray(?array $raw): self
    {
        $c = new self;
        $raw ??= [];

        $c->categories = self::normalizeCategories($raw['categories'] ?? []);
        $c->types = self::flatList($raw['types'] ?? []);
        $c->priorities = self::flatList($raw['priorities'] ?? []);

        if ($c->categories !== []) {
            $c->source = self::SOURCE_SETTINGS;
        }

        if (isset($raw['channel_id']) && is_numeric($raw['channel_id'])) {
            $c->channelId = (int) $raw['channel_id'];
        }
        if (isset($raw['extra']) && is_array($raw['extra'])) {
            $c->extra = $raw['extra'];
        }

        return $c;
    }

    /**
     * Accepts both shapes — the settings JSON (`id`/`name`/`subcategories`) and
     * the already-normalized output of {@see TicketCatalogApi} — so one parser
     * covers both sources.
     */
    private static function normalizeCategories(mixed $rawCategories): array
    {
        $out = [];

        foreach (is_array($rawCategories) ? $rawCategories : [] as $cat) {
            if (! is_array($cat) || ! isset($cat['id'])) {
                continue;
            }

            $subs = [];
            foreach (is_array($cat['subcategories'] ?? null) ? $cat['subcategories'] : [] as $sub) {
                if (! is_array($sub) || ! isset($sub['id'])) {
                    continue;
                }
                $subs[] = [
                    'id' => (int) $sub['id'],
                    'name' => (string) ($sub['name'] ?? $sub['id']),
                    'name_ar' => isset($sub['name_ar']) ? (string) $sub['name_ar'] : null,
                    'type_id' => isset($sub['type_id']) ? (int) $sub['type_id'] : null,
                    'priority_id' => isset($sub['priority_id']) ? (int) $sub['priority_id'] : null,
                ];
            }

            $out[] = [
                'id' => (int) $cat['id'],
                'name' => (string) ($cat['name'] ?? $cat['id']),
                'name_ar' => isset($cat['name_ar']) ? (string) $cat['name_ar'] : null,
                'department_id' => isset($cat['department_id']) ? (int) $cat['department_id'] : null,
                'subcategories' => $subs,
            ];
        }

        return $out;
    }

    /**
     * The API hands out type/priority ids without labels. Any id referenced by
     * a sub-category but missing from the settings lists is added under a
     * placeholder name, so the form can still offer a valid option instead of
     * refusing to submit.
     */
    private function backfillTypesAndPriorities(): void
    {
        $knownTypes = array_column($this->types, 'id');
        $knownPriorities = array_column($this->priorities, 'id');

        foreach ($this->categories as $cat) {
            foreach ($cat['subcategories'] as $sub) {
                if ($sub['type_id'] !== null && ! in_array($sub['type_id'], $knownTypes, true)) {
                    $this->types[] = ['id' => $sub['type_id'], 'name' => 'Type '.$sub['type_id']];
                    $knownTypes[] = $sub['type_id'];
                }
                if ($sub['priority_id'] !== null && ! in_array($sub['priority_id'], $knownPriorities, true)) {
                    $this->priorities[] = ['id' => $sub['priority_id'], 'name' => 'Priority '.$sub['priority_id']];
                    $knownPriorities[] = $sub['priority_id'];
                }
            }
        }

        usort($this->types, fn ($a, $b) => $a['id'] <=> $b['id']);
        usort($this->priorities, fn ($a, $b) => $a['id'] <=> $b['id']);
    }

    /** @return array<int, array{id:int,name:string}> */
    private static function flatList(mixed $items): array
    {
        $out = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }
            $out[] = ['id' => (int) $item['id'], 'name' => (string) ($item['name'] ?? $item['id'])];
        }

        return $out;
    }

    public function isConfigured(): bool
    {
        return $this->categories !== [] && $this->types !== [] && $this->priorities !== [];
    }

    public function isFromApi(): bool
    {
        return $this->source === self::SOURCE_API;
    }

    public function categoryName(?int $id): ?string
    {
        foreach ($this->categories as $cat) {
            if ($cat['id'] === $id) {
                return $cat['name'];
            }
        }

        return null;
    }

    public function subcategoryName(?int $categoryId, ?int $subId): ?string
    {
        return $this->subcategory($categoryId, $subId)['name'] ?? null;
    }

    /** The full sub-category row, including the type/priority the API pairs with it. */
    public function subcategory(?int $categoryId, ?int $subId): ?array
    {
        foreach ($this->categories as $cat) {
            if ($categoryId !== null && $cat['id'] !== $categoryId) {
                continue;
            }
            foreach ($cat['subcategories'] as $sub) {
                if ($sub['id'] === $subId) {
                    return $sub;
                }
            }
        }

        return null;
    }

    public function typeName(?int $id): ?string
    {
        return self::lookup($this->types, $id);
    }

    public function priorityName(?int $id): ?string
    {
        return self::lookup($this->priorities, $id);
    }

    private static function lookup(array $list, ?int $id): ?string
    {
        foreach ($list as $item) {
            if ($item['id'] === $id) {
                return $item['name'];
            }
        }

        return null;
    }

    /** Valid subcategory IDs for one category — used to validate the form. */
    public function subcategoryIdsFor(?int $categoryId): array
    {
        foreach ($this->categories as $cat) {
            if ($cat['id'] === $categoryId) {
                return array_column($cat['subcategories'], 'id');
            }
        }

        return [];
    }

    public function categoryIds(): array
    {
        return array_column($this->categories, 'id');
    }

    public function typeIds(): array
    {
        return array_column($this->types, 'id');
    }

    public function priorityIds(): array
    {
        return array_column($this->priorities, 'id');
    }

    /** Starter catalog offered in the Settings editor. */
    public static function example(): array
    {
        return [
            'categories' => [
                [
                    'id' => 8,
                    'name' => 'IT Support',
                    'subcategories' => [
                        ['id' => 40, 'name' => 'Hardware'],
                        ['id' => 41, 'name' => 'Software'],
                    ],
                ],
            ],
            'types' => [
                ['id' => 1, 'name' => 'Service Request'],
                ['id' => 2, 'name' => 'Incident'],
            ],
            'priorities' => [
                ['id' => 1, 'name' => 'Critical'],
                ['id' => 2, 'name' => 'High'],
                ['id' => 3, 'name' => 'Medium'],
                ['id' => 4, 'name' => 'Low'],
            ],
            'channel_id' => 1,
        ];
    }
}
