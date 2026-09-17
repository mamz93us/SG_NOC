<?php

namespace App\Services\Itam\Oracle;

use App\Models\Itam\OracleAsset;

/**
 * Whether an Oracle asset line is a laptop, a desktop, or neither.
 *
 * Only laptops and desktops are imported. The register also carries software
 * licences ("OEM MS WINDOWS 11 PRO", "MS OFFICE HOME and BUSINESS 2019", a
 * Salesforce subscription) and the odd monitor; those return null and the
 * import counts them as left out.
 *
 * All-in-ones, iMacs and Mac minis are desktops. Anything that looks like a
 * computer but says nothing about its shape is a laptop, which is what nine
 * lines in ten of the register are.
 */
final class AssetLineKind
{
    /** Something is a computer when it names a CPU, memory or storage, or a computer family. */
    private const COMPUTER = '/\bI[3579]\b|\bC?I[3579]\s*-?\s*\d{3,5}|\bCORE\b|\bULTRA\s*[579]\b|\bU[579]\s*-|\bRYZEN\b|\bCELERON\b|\bXEON\b|\bDUO\b|\bGHZ\b|\d\s*GB\b|\d\s*TB\b|\bRAM\b|\bDDR\d?\b|\bSSD\b|\bHDD\b|\bLAPTOP\b|\bLABTOP\b|\bLA\[TOP\b|\bNOTE\s?BOOK\b|\bNOTBOOK\b|\bNBK?\b|\bDESKTOP\b|\bPC\b|\bALL[- ]?IN[- ]?ONE\b|\bAIO\b|\bTOSHI[BP]A\b|\bSATELLITE\b|\bTHINK\s?PAD\b|\bIDEA\s?PAD\b|\bTHINK\s?BOOK\b|\bYOGA\d?\b|\bLEGION\b|\bINSPI?R?I?ON\b|\bINS\b|\bLATI(?:TUDE)?\b|\bXPS\b|\bVOSTRO\b|\bOPTI|\bPAVIL|\bPAV\b|\bPROBOOK\b|\bELITEBOOK\b|\bZBOOK\b|\bENVY\b|\bSPECT|\bOMEN\b|\bMACBOOK\b|\bIMAC\b|\bMAC\s*MINI\b|\bSURFACE\b|\bMATEBOOK\b|\bVAIO\b|\bZENBOOK\b/';

    private const SOFTWARE = '/\bOFFICE\s+HOME\b|\bMS\s+OFFICE\b|\bMICROSOFT\s+OFFICE\b|\bOEM\s+MS\s+WINDOWS\b|\bWINDOWS\s+\d+\s+PRO\b|\bSALES\s+CLOUD\b|\bLICEN[CS]E\b|\bSUBSCRIPTION\b|\bENTERPRISE\s+EDITION\b/';

    private const NOT_A_COMPUTER = '/\bLCD\b|\bMONITOR\b|\bPRINTER\b|\bSCANNER\b|\bPROJECTOR\b|\bUPS\b|\bSWITCH\b|\bROUTER\b|\bCAMERA\b|\bPHONE\b|\bIPAD\b|\bTABLET\b|\bTELEVISION\b|\bSPEAKER\b|\bHEADSET\b|\bDOCKING\b|\bSTATION\b/';

    private const ALL_IN_ONE = '/\bALL[- ]?IN[- ]?ONE\b|\bAIO\b|\bA\/O\b|\bALO\b|\bIMAC\b|\b24-(?:CA|DP|F)\d/';

    private const DESKTOP = '/\bOPTI(?:PLEX|LEX|PLIX|POLEX)?\b|\bOPTPLEX\b|\bOPLTIPLEX\b|\bMFF\b|\bDESKTOP\b|\bDESK\/|\bPC\s+DELL\s+VOS\b|\bVOSTRO\s+3888\b|\bNEO\s+50T\b|\bTWR\b|\bPRE-3650\b|\bXEON\b|\bTHINK\s?CENTRE\b|\bIDEA\s?CENT(?:ER|RE)\b|\bMAC\s*MINI\b|\bDELL\s+990\b|\bHP\s+P3500\b|\bCOMPAQ\b.*\bDUO\b/';

    /** A desktop word next to these is a typo on a laptop ("DELL OPTILEX N5520 … 15.6'"). */
    private const LAPTOP_DESPITE_DESKTOP_WORD = '/\bN55[12]0\b|\b15\.6\b/';

    /** Words only a laptop's description has: its kind, its family, or a laptop screen size. */
    private const LAPTOP_EVIDENCE = '/\bLAPTOP\b|\bLABTOP\b|\bLA\[TOP\b|\bNOTE\s?BOOK\b|\bNOTBOOK\b|\bNBK?\b|\bN\/B\b|\bTHINK\s?PAD\b|\bTHING\s+PAD\b|\bIDEA\s?PAD\b|\bTHINK\s?BOOK\b|\bYOGA\d?\b|\bLEGION\b|\bLOGION\b|\bLOQ\b|\bLATI(?:TUDE|TUDC)?\b|\bXPS\b|\bPROBOOK\b|\bELITEBOOK\b|\bZBOOK\b|\bOMNIBOOK\b|\bSPECT(?:RE|RA)?\b|\bENVY\b|\bOMEN\b|\bMACBOOK\b|\bSURFACE\b|\bMATEBOOK\b|\bZENBOOK\b|\bVAIO\b|\bTB\s?1[456]\b|\b(?:E1[456]|E4[789]0|E5[789]0|T14S?|T16|T4[89]0S?|T590|X1|X2[25]0|X390|L14|P1|P14S|P15[SV]?)\b|\b1[3456]\.\d"?|\b2-IN-1\b|\bX\s?360\b|\bCONVERTIBLE\b/';

    /** @return string|null OracleAsset::CATEGORY_LAPTOP / CATEGORY_DESKTOP, or null to leave the line out */
    public static function of(string $description): ?string
    {
        $u = ' '.strtoupper(preg_replace('/\s+/u', ' ', $description) ?? $description).' ';

        $computer = (bool) preg_match(self::COMPUTER, $u);

        if (! $computer && (preg_match(self::SOFTWARE, $u) || preg_match(self::NOT_A_COMPUTER, $u))) {
            return null;
        }

        // A licence line can name a PC it is for ("… Windows 11 Pro") without being one.
        if (preg_match(self::SOFTWARE, $u) && ! preg_match('/\bI[3579]\b|\bC?I[3579]\s*-?\s*\d{3,5}|\bULTRA\b|\bRYZEN\b|\d\s*GB\b|\bSSD\b|\bHDD\b/', $u)) {
            return null;
        }

        if (preg_match(self::ALL_IN_ONE, $u)) {
            return OracleAsset::CATEGORY_DESKTOP;
        }

        if (preg_match(self::DESKTOP, $u) && ! preg_match(self::LAPTOP_DESPITE_DESKTOP_WORD, $u)) {
            return OracleAsset::CATEGORY_DESKTOP;
        }

        return OracleAsset::CATEGORY_LAPTOP;
    }

    /**
     * The shape the description proves, for telling a laptop from a desktop
     * when pairing with Intune: a desktop, a laptop only when something in it
     * says so, and null when it says neither — a line imported as a laptop by
     * default is not evidence against a desktop.
     */
    public static function formOf(string $description): ?string
    {
        $kind = self::of($description);

        if ($kind !== OracleAsset::CATEGORY_LAPTOP) {
            return $kind;
        }

        $u = ' '.strtoupper(preg_replace('/\s+/u', ' ', $description) ?? $description).' ';

        return preg_match(self::LAPTOP_EVIDENCE, $u) ? OracleAsset::CATEGORY_LAPTOP : null;
    }
}
