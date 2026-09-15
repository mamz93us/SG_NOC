<?php

namespace App\Services\Archive\ArcMate;

/**
 * Where an ArcMate file actually is on the share.
 *
 * tblFiles.arcFileName is RELATIVE and partial — `D2026\0915\1440\2026…msg` —
 * and the rest has to be reconstructed:
 *
 *     <mount>/<project folder>/Documents/<Images|EDocs>/<arcFileName>
 *
 * Two things make that harder than it looks, both confirmed against the live
 * database rather than assumed:
 *
 *  - **tblMedia is empty.** ArcMate's own storage-root table, which the file
 *    rows point at through arcMediaId, has no rows at all and every arcMediaId
 *    is 0. So there is no recorded root anywhere; the folder layout IS the
 *    record, and this class is the only place that knows it.
 *
 *  - **Images or EDocs is not stored either.** A scan goes under `Images` and an
 *    attached e-mail or office file under `EDocs`, but nothing in the row says
 *    which. The extension decides, and because that is a guess about a
 *    twenty-year-old convention rather than a fact, resolve() tries the other
 *    directory too instead of declaring a file missing.
 *
 * Path building is pure and separately testable; resolve() is the only part
 * that touches the filesystem, and it takes the existence check as an argument
 * so it can be tested without a mounted share.
 */
class ArcMatePaths
{
    public const DIR_IMAGES = 'Images';

    public const DIR_EDOCS = 'EDocs';

    /**
     * Extensions ArcMate files under `Images` — the page formats a scanner or
     * its PDF writer produces. Everything else is an "electronic document" and
     * goes under `EDocs`: .msg, .doc(x), .xls(x), .htm, .zip.
     */
    private const IMAGE_EXTENSIONS = ['pdf', 'tif', 'tiff', 'jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp'];

    /**
     * The stored name reduced to its `DYYYY/MMDD/HHMM/file` part.
     *
     * Handles the three shapes seen in the wild: the relative form the live
     * rows use, a form that already names Images/EDocs, and an absolute Windows
     * path left over from the server this data lived on before the 2026 move
     * (`E:\ArcRepositories\…`), which is cut at `Documents` when present and at
     * `ArcRepositories\<folder>` otherwise.
     */
    public static function relative(?string $arcFileName): string
    {
        $path = trim(str_replace('\\', '/', (string) $arcFileName));

        if ($path === '') {
            return '';
        }

        // Absolute or share path: drop everything up to and including Documents/.
        if (preg_match('~^(?:[A-Za-z]:|//)?.*?/Documents/(.*)$~i', $path, $m)) {
            $path = $m[1];
        } elseif (preg_match('~^(?:[A-Za-z]:|//)?.*?/ArcRepositories/[^/]+/(.*)$~i', $path, $m)) {
            $path = $m[1];
        }

        // A leading Images/ or EDocs/ is the directory, not part of the name —
        // subdirectory() reads it back off, so it is stripped here to keep one
        // canonical relative form.
        $path = preg_replace('~^(Images|EDocs|Thumbs)/~i', '', ltrim($path, '/')) ?? '';

        return trim($path, '/');
    }

    /**
     * Whether the stored name itself names a directory (Images/EDocs/Thumbs),
     * which is the only certain answer available.
     */
    public static function declaredSubdirectory(?string $arcFileName): ?string
    {
        $path = ltrim(trim(str_replace('\\', '/', (string) $arcFileName)), '/');

        if (preg_match('~(?:^|/)(Images|EDocs|Thumbs)/~i', $path, $m)) {
            return ucfirst(strtolower($m[1])) === 'Edocs' ? self::DIR_EDOCS : ucfirst(strtolower($m[1]));
        }

        return null;
    }

    /** The directory this file most likely sits in, from its extension. */
    public static function subdirectory(?string $arcFileName): string
    {
        if ($declared = self::declaredSubdirectory($arcFileName)) {
            return $declared;
        }

        $extension = strtolower(pathinfo(self::relative($arcFileName), PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true) ? self::DIR_IMAGES : self::DIR_EDOCS;
    }

    /**
     * Every place this file could be, best guess first.
     *
     * @return array<int,string>
     */
    public static function candidates(string $mountPath, ?string $projectFolder, ?string $arcFileName): array
    {
        $relative = self::relative($arcFileName);
        $folder = trim((string) $projectFolder, '/\\');

        if ($relative === '' || $folder === '') {
            return [];
        }

        $base = rtrim($mountPath, '/').'/'.$folder.'/Documents';
        $first = self::subdirectory($arcFileName);
        $second = $first === self::DIR_IMAGES ? self::DIR_EDOCS : self::DIR_IMAGES;

        return [
            $base.'/'.$first.'/'.$relative,
            $base.'/'.$second.'/'.$relative,
        ];
    }

    /**
     * The first candidate that exists, or null.
     *
     * $exists defaults to is_file(); tests pass their own so the whole class
     * can be checked without a share.
     */
    public static function resolve(
        string $mountPath,
        ?string $projectFolder,
        ?string $arcFileName,
        ?callable $exists = null,
    ): ?string {
        $exists ??= static fn (string $path): bool => is_file($path);

        foreach (self::candidates($mountPath, $projectFolder, $arcFileName) as $candidate) {
            if ($exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The path to store on the file row when nothing on disk matched.
     *
     * A file that cannot be found still gets a row — the document it belongs to
     * is real, and a missing file is a fact worth showing rather than a reason
     * to drop the document — so the best guess is recorded and the viewer
     * reports it as missing.
     */
    public static function bestGuess(string $mountPath, ?string $projectFolder, ?string $arcFileName): ?string
    {
        return self::candidates($mountPath, $projectFolder, $arcFileName)[0] ?? null;
    }
}
