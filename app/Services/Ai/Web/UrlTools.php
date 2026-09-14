<?php

namespace App\Services\Ai\Web;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/** URLs the way the website crawler compares them. */
class UrlTools
{
    /** Never read as pages. PDFs have their own importer. */
    private const NOT_PAGES = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tif', 'tiff',
        'zip', 'rar', '7z', 'gz', 'tar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv',
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'webm', 'css', 'js', 'json', 'xml', 'rss',
        'woff', 'woff2', 'ttf', 'eot', 'exe', 'msi', 'dmg', 'apk',
    ];

    /** $href resolved against $base and normalized; null when it is not an http(s) link. */
    public static function resolve(string $href, string $base): ?string
    {
        $href = trim($href);

        if ($href === '' || preg_match('/^(mailto|tel|javascript|data|ftp|file):/i', $href)) {
            return null;
        }

        try {
            return self::normalize((string) UriResolver::resolve(new Uri($base), new Uri($href)));
        } catch (Throwable) {
            return null;
        }
    }

    /** Scheme and host lower-cased, default port, dot segments and fragment removed; null if not http(s). */
    public static function normalize(string $url): ?string
    {
        try {
            $uri = new Uri(trim($url));
        } catch (Throwable) {
            return null;
        }

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            return null;
        }

        $uri = UriNormalizer::normalize($uri->withFragment(''), UriNormalizer::PRESERVING_NORMALIZATIONS);

        return (string) ($uri->getPath() === '' ? $uri->withPath('/') : $uri);
    }

    /** The folder a URL is in, for a default scope: https://site/hr/leave.html → https://site/hr/ */
    public static function folder(string $url): string
    {
        $uri = new Uri($url);
        $path = $uri->getPath();
        $folder = str_ends_with($path, '/') ? $path : substr($path, 0, (int) strrpos($path, '/') + 1);

        return (string) $uri->withPath($folder !== '' ? $folder : '/')->withQuery('')->withFragment('');
    }

    /**
     * Whether $url is inside $scope: the same host and port, and a path under
     * the scope's. The scheme is not compared, so a site that moved from http
     * to https stays in scope.
     */
    public static function inScope(string $url, string $scope): bool
    {
        $page = parse_url($url);
        $allowed = parse_url($scope);

        return strtolower($page['host'] ?? '') === strtolower($allowed['host'] ?? '')
            && ($page['port'] ?? null) === ($allowed['port'] ?? null)
            && str_starts_with($page['path'] ?? '/', $allowed['path'] ?? '/');
    }

    public static function looksLikePage(string $url): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return ! in_array($extension, self::NOT_PAGES, true);
    }
}
