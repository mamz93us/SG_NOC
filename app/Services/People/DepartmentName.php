<?php

namespace App\Services\People;

/**
 * One department out of the several names Oracle gives it.
 *
 * Oracle names a department once per branch — "Accounting - Jeddah",
 * "Accounting - Khobar", "Accounting - Riyadh" — and once more for the people
 * at its head: "Information System Division" beside "Information System
 * Division - Jeddah", "Board Of Directors Division" beside "Board Of Directors
 * - Jeddah". 113 names on 2026-10-06 for what the company calls about fifty
 * departments. This reads them as one:
 *
 *  - the branch at the end goes ("… - Riyadh");
 *  - so does a unit number before it ("… - Unit 1 - Riyadh", "… - UNIT 3 -
 *    Jeddah"): the units of Visualization & Integrated Projects are that
 *    department's teams by region, not departments of their own;
 *  - and a closing "Division" or "Department" is not part of the name when
 *    matching, which is what joins the head office row to its branches and
 *    "Marketing Department" to "Marketing Division".
 *
 * **It never joins two names that differ in their words.** "Accounting" and
 * "Finance & Accounting Division", "Warehouses" and "Warehouses & Distribution
 * Division", "Medical IT" and "Medical IT Services" stay apart: they may well
 * be one department to the people in them, but nothing in the name says so,
 * and a page that merged on a guess would count one department's people under
 * another. The page lists the Oracle names behind each row, so a wrong split
 * or a wrong join is visible rather than buried in a total.
 *
 * Pure: no database, no config.
 */
final class DepartmentName
{
    /**
     * How Oracle writes the branch at the end of a department's name. A new
     * branch is one more entry; until it is added its departments simply stay
     * as rows of their own, named with their city.
     */
    public const CITIES = ['Jeddah', 'Riyadh', 'Khobar', 'Al-Khobar', 'Al Khobar', 'Abha'];

    /** The name without its branch and unit: "Accounting - Riyadh" is "Accounting". */
    public static function base(?string $name): string
    {
        $name = self::tidy($name);
        $cities = implode('|', array_map(fn (string $city) => preg_quote($city, '/'), self::CITIES));

        $name = preg_replace('/^(.*\S)\s*[-–—]\s*(?:'.$cities.')$/iu', '$1', $name) ?? $name;

        return preg_replace('/^(.*\S)\s*[-–—]\s*unit\s*\d+$/iu', '$1', $name) ?? $name;
    }

    /** The branch Oracle wrote at the end of the name, or null where it wrote none. */
    public static function city(?string $name): ?string
    {
        $cities = implode('|', array_map(fn (string $city) => preg_quote($city, '/'), self::CITIES));

        return preg_match('/\S\s*[-–—]\s*('.$cities.')$/iu', self::tidy($name), $m) ? $m[1] : null;
    }

    /**
     * What two names must share to be one department: the base, without a
     * closing "Division" or "Department", compared without case. '' for no name.
     */
    public static function key(?string $name): string
    {
        $base = preg_replace('/\s+(?:division|department|dept\.?)$/iu', '', self::base($name)) ?? '';

        // One kind of dash, so "A – B" and "A - B" are the same name.
        return mb_strtolower(trim((string) preg_replace('/\s*[-–—]\s*/u', ' - ', $base)));
    }

    private static function tidy(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }
}
