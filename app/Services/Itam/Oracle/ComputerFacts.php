<?php

namespace App\Services\Itam\Oracle;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What can be compared between an Oracle asset line and an Intune device.
 *
 * Oracle's "Asset Name" is free text typed at purchase time — a supplier's
 * quote line cut at 80 characters, with the product number, CPU and storage
 * run together ("(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC …"). Intune knows
 * a manufacturer, a model string, a serial and, where the NOC's device script
 * has run, the CPU. Both are read into the same few facts:
 *
 * - brand, normalised ("Hewlett-Packard" and "HP" are HP);
 * - model keys, which are brand-specific and deliberately few: the Lenovo
 *   machine type (20TA from 20TA00C0AD), the HP series (15-FD0) or line and
 *   generation (250-G10), the Dell four-digit model number (3510). Two sets of
 *   model keys that do not overlap are taken as proof of a different model,
 *   so a key that appears by accident must be rare, not merely wrong sometimes;
 * - loose keys: other letter-and-digit model codes (GP78HX). They only ever
 *   add confidence, never rule a pair out;
 * - the CPU, normalised to I7-1355U / ULTRA 7 155H / RYZEN 5 5500U;
 * - serial-like tokens (Intune: the serial; Oracle: the few descriptions that
 *   carry one);
 * - a date: Oracle's purchase date, or Intune's enrollment date;
 * - the form, laptop or desktop, only where the text proves it: a ThinkPad
 *   P15v is never a ThinkCentre, even as its holder's only Lenovo.
 */
final class ComputerFacts
{
    /**
     * @param  list<string>  $modelKeys
     * @param  list<string>  $looseKeys
     * @param  list<string>  $serials
     */
    public function __construct(
        public readonly ?string $brand,
        public readonly array $modelKeys,
        public readonly array $looseKeys,
        public readonly ?string $cpu,
        public readonly array $serials,
        public readonly ?CarbonImmutable $date,
        public readonly ?string $form = null,
    ) {}

    public static function fromOracle(string $description, ?DateTimeInterface $purchaseDate): self
    {
        $brand = self::brandOfDescription($description);

        return new self(
            $brand,
            self::modelKeys($description, $brand),
            self::usesLooseKeys($brand) ? self::looseKeys($description) : [],
            self::cpuOf($description),
            self::serialTokens($description),
            $purchaseDate ? CarbonImmutable::instance($purchaseDate)->startOfDay() : null,
            AssetLineKind::formOf($description),
        );
    }

    public static function fromIntune(?string $manufacturer, ?string $model, ?string $cpuName, ?string $serial, ?DateTimeInterface $enrolledAt): self
    {
        $brand = self::normaliseManufacturer($manufacturer);
        $serial = self::cleanSerial($serial);

        return new self(
            $brand,
            self::modelKeys((string) $model, $brand),
            self::usesLooseKeys($brand) ? self::looseKeys((string) $model) : [],
            self::cpuOf($cpuName),
            $serial !== null ? [$serial] : [],
            $enrolledAt ? CarbonImmutable::instance($enrolledAt)->startOfDay() : null,
            self::intuneForm($brand, (string) $model),
        );
    }

    private const INTUNE_DESKTOP = '/ALL[- ]IN[- ]ONE|\bAIO\b|\bDESKTOP\b|\bTOWER\b|\bOPTIPLEX\b|\bPRODESK\b|\bELITEDESK\b|\bTHINK\s?CENTRE\b|\bIDEA\s?CENTRE\b|\bIMAC\b|\bMAC\s?MINI\b|\bMACMINI\b|\bSFF\b|\bMFF\b/';

    private const INTUNE_LAPTOP = '/NOTEBOOK|LAPTOP|\bPROBOOK\b|\bELITEBOOK\b|\bZBOOK\b|\bOMNIBOOK\b|\bSPECTRE\b|\bENVY\b|CONVERTIBLE|\bLATITUDE\b|\bXPS\b|\bVOSTRO 1[45]\b|\bINSPIRON 1[3-6]\b|\bMACBOOK|\bSURFACE (?:LAPTOP|PRO|BOOK|GO)\b|\bMATEBOOK\b|\bTHINK\s?PAD\b|\bTHINK\s?BOOK\b|\bIDEA\s?PAD\b|\bYOGA\b|\bLEGION\b|\bG1[5-8] \d{4}\b/';

    /**
     * The form an Intune model string proves. Lenovo reports a machine type:
     * 10xx–12xx and F0xx are ThinkCentre and IdeaCentre desktops, 20xx–21xx
     * ThinkPads, 80xx–83xx IdeaPads, Yogas and Legions.
     */
    private static function intuneForm(?string $brand, string $model): ?string
    {
        $u = self::upper($model);

        if ($brand === 'LENOVO') {
            if (preg_match('/^(?:1[0-2]|F0)[0-9A-Z]{2}(?:[0-9A-Z]{4,6})?$/', $u)) {
                return 'desktop';
            }
            if (preg_match('/^(?:2[01]|8[0-3])[0-9A-Z]{2}(?:[0-9A-Z]{4,6})?$/', $u)) {
                return 'laptop';
            }
        }

        return match (true) {
            (bool) preg_match(self::INTUNE_DESKTOP, $u) => 'desktop',
            (bool) preg_match(self::INTUNE_LAPTOP, $u) => 'laptop',
            default => null,
        };
    }

    /**
     * HP, Dell and Lenovo are compared on their own model keys. Their loose
     * codes are family names shared across generations (E14, X360, G15), which
     * would pair a ThinkPad E14 Gen 2 with a Gen 5.
     */
    private static function usesLooseKeys(?string $brand): bool
    {
        return ! in_array($brand, ['HP', 'DELL', 'LENOVO'], true);
    }

    // ─── Brand ─────────────────────────────────────────────────────

    /** Brand names written out, checked before the family and model hints below. */
    private const BRAND_WORDS = [
        'ALIENWARE' => '/\bALIENWARE\b/',
        'APPLE' => '/\bAPPLE\b|\bMACBOOK|\bIMAC\b|\bMAC\s*MINI\b/',
        'MICROSOFT' => '/\bMICROSOFT\b|\bSURFACE\b/',
        'HUAWEI' => '/\bHUAWEI\b/',
        'ASUS' => '/\bASUS\b/',
        'ACER' => '/\bACER\b/',
        'MSI' => '/\bMSI\b/',
        'TOSHIBA' => '/\bTOSHI[BP]A/',
        'SONY' => '/\bSONY\b/',
        'SAMSUNG' => '/\bSAMSUNG\b/',
        'LENOVO' => '/\bLENOVO\b/',
        'DELL' => '/\bDELL\b|\bDEL\b/',
        'HP' => '/\bHP\d*\b|\bHP-/',
    ];

    /**
     * Families and model shapes only one brand uses. Lenovo comes last: its
     * hints include bare ThinkPad model names (X1, P1, E14) that are the
     * likeliest to turn up by accident.
     */
    private const BRAND_HINTS = [
        'MICROSOFT' => '/\bMS\s+PRO\s*\d|\bKJT-?\d/',
        'HUAWEI' => '/\bMATEBOOK\b/',
        'ASUS' => '/\bZENBOOK\b|\bVIVOBOOK\b|\bROG\b/',
        'ACER' => '/\bNITRO\b|\bASPIRE\b/',
        'MSI' => '/\bGF6\d|\bGP\d{2}|\bGS\d{2}\b/',
        'SONY' => '/\bVAIO\b/',
        'DELL' => '/\bOPTI(?:PLEX|LEX|PLIX|POLEX)?\b|\bOPTPLEX\b|\bOPLTIPLEX\b|\bLATI(?:TUDE|TUDC)?\b|\bXPS\b|\bINSPI?R?I?ON\b|\bINSPRON\b|\bINSPITON\b|\bINSP\b|\bINS\b|\bVOSTRO\b|\bVOS\b|\bN5[015][12]0\b|\bPRE-3650\b/',
        'HP' => '/\bPAVIL+I?O?I?ON\b|\bPAV\d*\b|\bPROBOOK\b|\bZBOOK\b|\bELITEBOOK\b|\bOMNIBOOK\b|\bSPECT(?:RE|RA)?\b|\bSPEC\b|\bENVY\b|\bOMEN\b|\bCOMPAQ\b|\bDV6\b|\b250\s*-?\s*R?\s*G1?\d\b|\bUMA\s+I[3579]\b/',
        'LENOVO' => '/\bTHINK\s?PAD\b|\bTHING\s+PAD\b|\bIDEA\s?PAD\b|\bIDEA\s?CENT(?:ER|RE)\b|\bTHINK\s?CENTRE\b|\bTHINK\s?BOOK\b|\bLEGION\b|\bLOGION\b|\bYOGA\b|\bLOQ\b|\bNEO\s+50T\b|\bTB\s?1[456]\b|\b(?:E1[456]|E4[789]0|E5[789]0|T4[89]0S?|T590|X1|X2[25]0|X390|L14|P1|P14S|P15[SV]?|T14|T16)\b/',
    ];

    public static function brandOfDescription(string $text): ?string
    {
        $u = self::upper($text);

        foreach (self::BRAND_WORDS as $brand => $pattern) {
            if (preg_match($pattern, $u)) {
                return $brand;
            }
        }

        foreach (self::BRAND_HINTS as $brand => $pattern) {
            if (preg_match($pattern, $u)) {
                return $brand;
            }
        }

        return self::lenovoTypes($u) !== [] ? 'LENOVO' : null;
    }

    /** Intune's manufacturer string, as the same brand names the descriptions are read into. */
    public static function normaliseManufacturer(?string $manufacturer): ?string
    {
        $u = self::upper((string) $manufacturer);

        if ($u === '' || in_array($u, ['UNKNOWN', 'SYSTEM MANUFACTURER', 'TO BE FILLED BY O.E.M.', 'DEFAULT STRING'], true)) {
            return null;
        }

        return match (true) {
            str_contains($u, 'LENOVO') => 'LENOVO',
            str_contains($u, 'HEWLETT') || $u === 'HP' || str_starts_with($u, 'HP ') => 'HP',
            str_contains($u, 'ALIENWARE') => 'ALIENWARE',
            str_contains($u, 'DELL') => 'DELL',
            str_contains($u, 'MICRO-STAR') || $u === 'MSI' => 'MSI',
            str_contains($u, 'MICROSOFT') => 'MICROSOFT',
            str_contains($u, 'ASUS') => 'ASUS',
            str_contains($u, 'HUAWEI') => 'HUAWEI',
            str_contains($u, 'ACER') => 'ACER',
            str_contains($u, 'APPLE') => 'APPLE',
            str_contains($u, 'TOSHIBA') || str_contains($u, 'DYNABOOK') => 'TOSHIBA',
            str_contains($u, 'SAMSUNG') => 'SAMSUNG',
            str_contains($u, 'GIGABYTE') => 'GIGABYTE',
            default => $u,
        };
    }

    /** True / false when both brands are known, null when either is not. Alienware is Dell's. */
    public static function brandsAgree(?string $a, ?string $b): ?bool
    {
        if ($a === null || $b === null) {
            return null;
        }

        $family = fn (string $brand) => $brand === 'ALIENWARE' ? 'DELL' : $brand;

        return $family($a) === $family($b);
    }

    /** How a brand reads on an asset created from a description. */
    public static function brandLabel(?string $brand): ?string
    {
        return match ($brand) {
            null => null,
            'HP', 'MSI' => $brand,
            default => ucfirst(strtolower($brand)),
        };
    }

    // ─── Model keys ────────────────────────────────────────────────

    /**
     * @return list<string> LEN:20TA, HP:15-FD0, HPG:250-G10, DELL:3510
     */
    public static function modelKeys(string $text, ?string $brand): array
    {
        $u = self::upper($text);
        $keys = [];

        if ($brand === null || $brand === 'LENOVO') {
            foreach (self::lenovoTypes($u) as $type) {
                $keys[] = 'LEN:'.$type;
            }
        }

        if ($brand === 'HP') {
            // Consumer series: 15-fd0027nx, "15 fd0027nx", Intune "HP Laptop 15-fd0xxx". Not "14- CI7-4500U".
            if (preg_match_all('/(?<![0-9A-Z])(1[3-7]|2[2-7])S?\s*-\s*(?!CI\d)([A-Z]{2})\s*(\d)/', $u, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $keys[] = 'HP:'.$hit[1].'-'.$hit[2].$hit[3];
                }
            }
            if (preg_match_all('/(?<![0-9A-Z])(1[3-7]|2[2-7])\s+([A-Z]{2})(\d)\d{2,3}[A-Z]{2}(?![0-9A-Z])/', $u, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $keys[] = 'HP:'.$hit[1].'-'.$hit[2].$hit[3];
                }
            }
            // Business line and generation: "250 G10", "250G10", "HP-250-RG10", Intune "HP 250 15.6 inch G10 Notebook PC".
            if (preg_match_all('/(?<![0-9A-Z.])(\d{3})\s*-?\s*R?\s*-?\s*(?:\d{2}(?:\.\d)?\s*INCH\s+)?G(\d{1,2})(?![0-9A-Z])/', $u, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $keys[] = 'HPG:'.$hit[1].'-G'.(int) $hit[2];
                }
            }
        }

        if ($brand === 'DELL') {
            // Four-digit model numbers, optionally letter-prefixed (E3500, N5520), that are not
            // disk speeds, memory speeds, M.2 sizes, years or a CPU's own number.
            if (preg_match_all('/(?<![0-9A-Z.])([A-Z]?)([134579]\d{3})(?![0-9A-Z.])/', $u, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $hit) {
                    $number = $hit[2][0];
                    $before = substr($u, max(0, $hit[0][1] - 8), min(8, $hit[0][1]));
                    $after = substr($u, $hit[0][1] + strlen($hit[0][0]), 8);

                    if ((int) $number >= 1990 && (int) $number <= 2035) {
                        continue;
                    }
                    if (preg_match('/^\s*(?:RPM|MHZ|GHZ|MB|GB|TB|MT\/S)/', $after)
                        || preg_match('/(?:DDR\d|M\.2|I[3579]|RTX|GTX|MX|RX)\s*-?\s*$|(?<![A-Z])W\s*-\s*$/', $before)) {
                        continue;
                    }

                    $keys[] = 'DELL:'.$number;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Lenovo machine types: the first four characters of a ten-character
     * machine-type-model (20TA00C0AD, 21KE005DAD, 82K1013PAD, 12JD0040AX,
     * F0EU00B9KS), or an Intune model that is only the type (83EM).
     *
     * @return list<string>
     */
    private static function lenovoTypes(string $upper): array
    {
        $types = [];
        $prefix = '(?:1[0-2]|2[01]|8[0-3])[0-9A-Z]{2}|F0[0-9A-Z]{2}';

        if (preg_match('/^\s*('.$prefix.')(?:[0-9A-Z]{4,6})?\s*$/', $upper, $m) && preg_match('/[A-Z]/', $m[1].$upper)) {
            $types[] = $m[1];
        }

        if (preg_match_all('/(?<![0-9A-Z])('.$prefix.')([0-9A-Z]{5,6})/', $upper, $all, PREG_SET_ORDER)) {
            foreach ($all as $hit) {
                // A machine-type-model mixes letters and digits; a run of digits is a number.
                if (preg_match_all('/[A-Z]/', $hit[0]) >= 2) {
                    $types[] = $hit[1];
                }
            }
        }

        return array_values(array_unique($types));
    }

    private const LOOSE_KEY_NOISE = '/^(?:DDR\d|LPDDR\d|MX\d+|RTX\d+|GTX\d+|RX\d+|GT\d+|WIN\d+\w*|W\d+P?|SSD\d+|HDD\d+|USB\d*|WIFI\d*|AX\d+|BT\d*|PCIE\d*|NVME\d*|FHD\d*|UHD\d*|QHD\d*|IPS\d*|TLC\w*|G\d|U\d+|H\d+|M\d+|I\d+|CI\d+|K\d+)$/';

    /** @return list<string> letter-and-digit model codes such as GP78HX or G15 */
    public static function looseKeys(string $text): array
    {
        $keys = [];

        foreach (preg_split('/[^0-9A-Z]+/', self::upper($text), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (strlen($token) < 3 || strlen($token) > 10) {
                continue;
            }
            if (! preg_match('/^[A-Z]{1,4}\d{2,5}[A-Z]{0,4}\d{0,2}$/', $token)) {
                continue;
            }
            if (preg_match(self::LOOSE_KEY_NOISE, $token)) {
                continue;
            }
            $keys[] = $token;
        }

        return array_values(array_unique($keys));
    }

    // ─── CPU ───────────────────────────────────────────────────────

    public static function cpuOf(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $u = self::upper(str_replace(['(R)', '(TM)', '®', '™'], ' ', strtoupper($text)));

        if (preg_match('/(?<![0-9A-Z])C?I([3579])\s*-?\s*(\d{4,5})([A-Z]{1,2}\d?)?(?![0-9])/', $u, $m)) {
            return 'I'.$m[1].'-'.$m[2].($m[3] ?? '');
        }
        if (preg_match('/\bULTRA\s*([579])\s*-?\s*(\d{3}[A-Z]?)\b/', $u, $m)
            || preg_match('/(?<![0-9A-Z])(?:U|ULT)([579])\s*-\s*(\d{3}[A-Z]?)\b/', $u, $m)) {
            return 'ULTRA '.$m[1].' '.$m[2];
        }
        if (preg_match('/\bCORE\s+([357])\s*-?\s*(\d{3}[A-Z])\b/', $u, $m)) {
            return 'CORE '.$m[1].' '.$m[2];
        }
        if (preg_match('/\bRYZEN\s*([3579])\s*(?:PRO\s*)?(\d{4}[A-Z]{0,2})\b/', $u, $m)) {
            return 'RYZEN '.$m[1].' '.$m[2];
        }

        return null;
    }

    /**
     * True when two CPUs are the same, false when both are known and differ,
     * null when either is unknown. A truncated description ("i7-1165") agrees
     * with the full name it starts.
     */
    public static function cpusAgree(?string $a, ?string $b): ?bool
    {
        if ($a === null || $b === null) {
            return null;
        }
        if ($a === $b) {
            return true;
        }

        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return strlen($short) >= 7 && str_starts_with($long, $short);
    }

    // ─── Serials ───────────────────────────────────────────────────

    /** @return list<string> tokens in a description that could be a serial number */
    public static function serialTokens(string $text): array
    {
        $tokens = [];

        foreach (preg_split('/[^0-9A-Z]+/', self::upper($text), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (strlen($token) >= 7 && strlen($token) <= 14 && preg_match('/[A-Z]/', $token) && preg_match('/\d.*\d/', $token)) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    public static function cleanSerial(?string $serial): ?string
    {
        $serial = strtoupper(trim((string) $serial));

        // Placeholders firmware reports when nobody set a serial.
        if (strlen($serial) < 5 || preg_match('/^(?:0+|DEFAULT STRING|TO BE FILLED BY O\.E\.M\.|SYSTEM SERIAL NUMBER|NONE|N\/A|123456789)$/', $serial)) {
            return null;
        }

        return $serial;
    }

    private static function upper(string $text): string
    {
        return strtoupper(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
