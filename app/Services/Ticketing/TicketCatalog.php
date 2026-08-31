<?php

namespace App\Services\Ticketing;

use App\Models\Setting;

/**
 * The category / subcategory / type / priority lookups for the Create Ticket
 * form.
 *
 * The ticketing API accepts bare numeric IDs (ticketCategory: 8,
 * ticketSubCategory: 40, …) and publishes no endpoint to list them, so the
 * ID→label map is maintained by hand in Admin → Settings and parsed here.
 * Everything is defensive: a half-edited catalog must degrade to "no options"
 * rather than throw on a page load.
 */
class TicketCatalog
{
    /** @var array<int, array{id:int, name:string, subcategories: array<int, array{id:int,name:string}>}> */
    public array $categories = [];

    /** @var array<int, array{id:int, name:string}> */
    public array $types = [];

    /** @var array<int, array{id:int, name:string}> */
    public array $priorities = [];

    public int $channelId = 1;

    /** Extra literal key/values merged into the `data` object on every submit. */
    public array $extra = [];

    public static function fromSettings(?Setting $settings = null): self
    {
        return self::fromArray(($settings ?? Setting::get())->noc_ticket_catalog ?? []);
    }

    public static function fromArray(?array $raw): self
    {
        $c = new self;
        $raw ??= [];

        $rawCategories = $raw['categories'] ?? [];

        foreach (is_array($rawCategories) ? $rawCategories : [] as $cat) {
            if (! is_array($cat) || ! isset($cat['id'])) {
                continue;
            }
            $subs = [];
            $rawSubs = $cat['subcategories'] ?? [];
            foreach (is_array($rawSubs) ? $rawSubs : [] as $sub) {
                if (! is_array($sub) || ! isset($sub['id'])) {
                    continue;
                }
                $subs[] = ['id' => (int) $sub['id'], 'name' => (string) ($sub['name'] ?? $sub['id'])];
            }
            $c->categories[] = [
                'id' => (int) $cat['id'],
                'name' => (string) ($cat['name'] ?? $cat['id']),
                'subcategories' => $subs,
            ];
        }

        $c->types = self::flatList($raw['types'] ?? []);
        $c->priorities = self::flatList($raw['priorities'] ?? []);

        if (isset($raw['channel_id']) && is_numeric($raw['channel_id'])) {
            $c->channelId = (int) $raw['channel_id'];
        }
        if (isset($raw['extra']) && is_array($raw['extra'])) {
            $c->extra = $raw['extra'];
        }

        return $c;
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
        foreach ($this->categories as $cat) {
            if ($categoryId !== null && $cat['id'] !== $categoryId) {
                continue;
            }
            foreach ($cat['subcategories'] as $sub) {
                if ($sub['id'] === $subId) {
                    return $sub['name'];
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
