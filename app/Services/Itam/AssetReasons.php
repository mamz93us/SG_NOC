<?php

namespace App\Services\Itam;

/**
 * Why an asset was scrapped or retired, as a fixed list rather than free text.
 *
 * Finance sorts a month's movements by reason, so "damaged" and "Damaged!!"
 * typed into a box cannot be counted together. The list is what a person
 * picks; the box beside it is only for the detail (a ticket number, what
 * happened), and both are kept: `reason_code` on the record, and the reason
 * text the pages already show, composed as "Label — detail".
 */
final class AssetReasons
{
    public const SCRAP = [
        'damaged' => 'Damaged beyond repair',
        'not_economical' => 'Repair not economical',
        'end_of_life' => 'End of life — too old to use',
        'replaced' => 'Replaced by a new asset',
        'water_fire' => 'Water or fire damage',
        'lost' => 'Lost',
        'stolen' => 'Stolen',
        'other' => 'Other',
    ];

    public const RETIRE = [
        'not_with_holder' => 'No longer with the employee',
        'end_of_life' => 'End of life — too old to use',
        'replaced' => 'Replaced by a new asset',
        'damaged' => 'Damaged beyond repair',
        'kept_for_parts' => 'Kept for parts',
        'lost' => 'Lost',
        'stolen' => 'Stolen',
        'left_company' => 'Holder left and did not return it',
        'other' => 'Other',
    ];

    public static function scrapLabel(?string $code): ?string
    {
        return self::SCRAP[$code] ?? null;
    }

    public static function retireLabel(?string $code): ?string
    {
        return self::RETIRE[$code] ?? null;
    }

    /** The label from either list, for a report that shows both. */
    public static function label(?string $code): ?string
    {
        return self::SCRAP[$code] ?? self::RETIRE[$code] ?? null;
    }

    /** The reason as it is stored and shown: the label, and the detail after it. */
    public static function compose(?string $label, ?string $detail): string
    {
        $detail = trim((string) $detail);

        return trim(implode(' — ', array_filter([$label, $detail !== '' ? $detail : null])));
    }
}
