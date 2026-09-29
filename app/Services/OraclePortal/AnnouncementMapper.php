<?php

namespace App\Services\OraclePortal;

use Carbon\CarbonImmutable;

/**
 * One Oracle announcement turned into announcement columns.
 *
 * Pure: no database, no clock beyond what it is handed, no HTTP. It is where
 * the judgements about this feed live, all of which would be invisible bugs
 * rather than loud ones.
 *
 * **The description is taken apart, never stored.** It is base64-encoded HTML
 * with the designed picture inlined ({@see AnnouncementContent}): the text
 * becomes `body`, the first link `link_url`, and the pictures are handed back
 * for the sync to keep beside the row. Written into `body` as it stands, it
 * overflowed the TEXT column on every hourly pull from 2026-09-28 — and had it
 * fitted, every company PC would have shown a screen of base64.
 *
 * **The subject is not split into title and title_ar.** It is tempting: 39 of
 * 61 subjects carry both scripts. But no rule survives the real data —
 * 'Condolences Jeddah   <arabic> | Qamar Uddin Moinuddin' splits on the pipe
 * into an Arabic-tailed title and a person's Latin name as the "Arabic" one,
 * one subject leads with Arabic and another has no separator at all. And the
 * decisive part: the home portal never renders an announcement's title_ar.
 * Both views print `$ann->title`, and the model has no language fallback —
 * only PortalDocument renders its Arabic title. So a split would move half of
 * every bilingual subject into a column nothing displays. Showing the subject
 * whole is what Oracle's own portal does.
 *
 * **expires_at is the end of the expiry day, not its start.** Announcement's
 * live scope compares `expires_at > now()`, so mapping a date to midnight
 * would pull each notice a day early. Oracle agrees the day is inclusive: on
 * 2026-09-20 a row expiring 2026-09-13 reported isExpired=Y while rows
 * expiring the 23rd and 24th reported N.
 */
class AnnouncementMapper
{
    /** announcements.title is varchar(200); the longest subject measured is 111. */
    private const TITLE_LIMIT = 200;

    /**
     * The columns the sync owns. Anything not in here — severity, pinned,
     * audience, link_url — is the admin's alone and is never written after
     * the row is created.
     *
     * @var list<string>
     */
    public const MANAGED = ['title', 'body', 'link_url', 'published_at', 'expires_at'];

    /**
     * @param  array<string,mixed>  $row  one row of /announcements
     * @param  CarbonImmutable|null  $now  for the isExpired clamp; defaults to the real clock
     * @return array<string,mixed>|null null when the row has no usable id;
     *                                  `images` is not a column, it is for the sync
     */
    public static function map(array $row, ?CarbonImmutable $now = null): ?array
    {
        $id = self::text($row['announcementId'] ?? null);

        if ($id === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        $published = self::date($row['startDate'] ?? null)?->startOfDay();
        $expires = self::date($row['expireDate'] ?? null)?->endOfDay();

        // Oracle precomputes isExpired, and it is the portal's own answer. When
        // it says a notice is over, believe it over an expiry date that is
        // missing or still ahead — otherwise a notice Oracle retired early
        // stays on every company PC until its printed date passes.
        if (self::text($row['isExpired'] ?? null) === 'Y' && ($expires === null || $expires->gt($now))) {
            $expires = $now;
        }

        $content = AnnouncementContent::parse($row['description'] ?? null);

        return [
            'external_id' => $id,
            'title' => mb_substr(self::text($row['subject'] ?? null) ?? 'Announcement '.$id, 0, self::TITLE_LIMIT),
            // announcements.body is NOT NULL, so a notice that is only a
            // picture — 57 of 61 — has ''.
            'body' => $content['text'],
            'link_url' => $content['link'],
            'external_image_name' => self::text($row['imageName'] ?? null),
            'published_at' => $published,
            'expires_at' => $expires,
            'images' => $content['images'],
        ];
    }

    /**
     * Which managed columns may this pull overwrite?
     *
     * A column is the sync's to refresh only while it still holds exactly what
     * the sync last wrote. The moment somebody edits it here, it is theirs —
     * and if they later put Oracle's own text back, it returns to being
     * synced, which is the behaviour people expect without being told.
     *
     * The same rule as an Oracle asset undo, which restores a field only where
     * it still holds what the import put there.
     *
     * @param  array<string,mixed>  $held  the row as it stands now
     * @param  array<string,mixed>  $lastWrote  synced_fields from the last pull
     * @return list<string>
     */
    public static function writableColumns(array $held, array $lastWrote): array
    {
        $writable = [];

        foreach (self::MANAGED as $column) {
            // Never written before (a row created before this feature, or a
            // column the sync took on later, as it did link_url): the sync's
            // to fill only while it is empty. A value already there was put
            // there by somebody, and silence is not permission to replace it.
            if (! array_key_exists($column, $lastWrote)) {
                if (self::blank($held[$column] ?? null)) {
                    $writable[] = $column;
                }

                continue;
            }

            if (self::same($held[$column] ?? null, $lastWrote[$column])) {
                $writable[] = $column;
            }
        }

        return $writable;
    }

    /**
     * The snapshot to store, as plain scalars so a JSON round trip cannot
     * change what a later comparison sees.
     *
     * @param  array<string,mixed>  $mapped
     * @return array<string,mixed>
     */
    public static function snapshot(array $mapped): array
    {
        $snapshot = [];

        foreach (self::MANAGED as $column) {
            $snapshot[$column] = self::scalar($mapped[$column] ?? null);
        }

        return $snapshot;
    }

    /**
     * Do these two mean the same thing?
     *
     * Dates arrive as Carbon from the model and as strings from JSON, so both
     * sides are flattened to a comparable scalar first.
     */
    private static function same(mixed $held, mixed $wrote): bool
    {
        return self::scalar($held) === self::scalar($wrote);
    }

    /**
     * One comparable form for a value that may arrive as a Carbon from the
     * model, a string from JSON, or null from either.
     */
    public static function scalarOf(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_array($value) ? null : $value;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        return self::scalarOf($value);
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Oracle writes every date as yyyy-MM-dd. Anything else is treated as
     * absent rather than guessed at.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            // Carbon runs in strict mode here, so an unreadable date throws
            // rather than returning false. One odd row must not abort a pull
            // of the whole noticeboard.
            return CarbonImmutable::createFromFormat('!Y-m-d', mb_substr($text, 0, 10)) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
