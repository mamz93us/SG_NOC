<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class WebBrowserController extends Controller
{
    // ── Browser UI ─────────────────────────────────────────────────────────

    /**
     * Show the custom URL browser page.
     * Accepts ?url= to pre-load a URL in the address bar.
     */
    public function index(Request $request): \Illuminate\View\View
    {
        $url = trim($request->query('url', ''));
        return view('admin.browser.index', compact('url'));
    }

    // ── Proxy Fetch ────────────────────────────────────────────────────────

    /**
     * Fetch any URL and return it with links rewritten through this proxy.
     * The URL comes from a query parameter — validated server-side.
     *
     * SECURITY:
     *  - Requires auth + view-noc permission (enforced in routes/web.php).
     *  - URL is validated as a proper http/https URL.
     *  - NOC admins are trusted to access internal management addresses.
     */
    public function fetch(Request $request): Response
    {
        $url = trim($request->query('url', ''));

        // Validate: must be a proper http/https URL
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            return response(
                $this->errorPage('Invalid URL', 'Only http:// and https:// URLs are supported.'),
                400
            )->header('Content-Type', 'text/html');
        }

        // Parse base for relative-URL rewriting
        $parsed = parse_url($url);
        $base   = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }

        try {
            $response = Http::withOptions([
                'verify'          => false,   // Many devices use self-signed TLS certs
                'timeout'         => 15,
                'connect_timeout' => 6,
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
            ])
            ->withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (SG-NOC Web Browser)',
                'X-Forwarded-For' => $request->ip(),
            ])
            ->send($request->method(), $url,
                $request->isMethod('POST')
                    ? ['form_params' => $request->except(['_token', 'url'])]
                    : []
            );
        } catch (\Exception $e) {
            return response(
                $this->errorPage('Connection Failed', e($e->getMessage())),
                502
            )->header('Content-Type', 'text/html');
        }

        // Follow redirects manually if needed
        if ($response->redirect()) {
            $location = $response->header('Location', '/');
            $location = $this->resolveUrl($location, $base, $url);
            $proxied  = route('admin.browser.fetch', ['url' => $location]);
            return response(
                "<html><head><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($proxied) . "'></head></html>",
                200
            )->header('Content-Type', 'text/html');
        }

        $contentType = $response->header('Content-Type', 'text/html');
        $body        = $response->body();

        // Rewrite HTML links to route through this proxy
        if (str_contains($contentType, 'text/html')) {
            $body = $this->rewriteHtml($body, $base, $url);
        }

        // Rewrite CSS
        if (str_contains($contentType, 'text/css')) {
            $body = $this->rewriteCss($body, $base, $url);
        }

        return response($body, $response->status())
            ->header('Content-Type', $contentType)
            ->header('X-Proxied-By', 'SG-NOC');
    }

    // ── Link rewriting ─────────────────────────────────────────────────────

    protected function rewriteHtml(string $html, string $base, string $currentUrl): string
    {
        // Rewrite href, src, action attributes
        $html = preg_replace_callback(
            '/\b(href|src|action)=(["\'])([^"\']*)\2/i',
            function ($m) use ($base, $currentUrl) {
                $attr     = $m[1];
                $quote    = $m[2];
                $original = $m[3];
                $resolved = $this->toProxyUrl($original, $base, $currentUrl);
                return "{$attr}={$quote}{$resolved}{$quote}";
            },
            $html
        );

        // Rewrite CSS url() inside <style> blocks and inline styles
        $html = preg_replace_callback(
            '/url\((["\']?)([^)"\'\s]+)\1\)/i',
            function ($m) use ($base, $currentUrl) {
                $quote    = $m[1];
                $original = $m[2];
                $resolved = $this->toProxyUrl($original, $base, $currentUrl);
                return "url({$quote}{$resolved}{$quote})";
            },
            $html
        );

        // Inject a <base> suppressor so relative URLs don't escape the proxy
        $html = preg_replace('/<base[^>]*>/i', '', $html);

        return $html;
    }

    protected function rewriteCss(string $css, string $base, string $currentUrl): string
    {
        return preg_replace_callback(
            '/url\((["\']?)([^)"\'\s]+)\1\)/i',
            function ($m) use ($base, $currentUrl) {
                $quote    = $m[1];
                $original = $m[2];
                $resolved = $this->toProxyUrl($original, $base, $currentUrl);
                return "url({$quote}{$resolved}{$quote})";
            },
            $css
        );
    }

    /**
     * Convert any URL found in the page to its proxied equivalent.
     */
    protected function toProxyUrl(string $url, string $base, string $currentUrl): string
    {
        // Leave non-navigable URIs untouched
        if (preg_match('/^(#|javascript:|mailto:|data:|about:|tel:)/i', $url)) {
            return $url;
        }

        $resolved = $this->resolveUrl($url, $base, $currentUrl);

        return route('admin.browser.fetch', ['url' => $resolved]);
    }

    /**
     * Resolve a possibly-relative URL against the base and current page URL.
     */
    protected function resolveUrl(string $url, string $base, string $currentUrl): string
    {
        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $scheme = str_starts_with($base, 'https') ? 'https' : 'http';
            return "{$scheme}:{$url}";
        }

        // Root-relative
        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        // Relative — resolve against current page directory
        $dir = rtrim(dirname(parse_url($currentUrl, PHP_URL_PATH) ?? '/'), '/');
        return $base . $dir . '/' . $url;
    }

    // ── Error page helper ──────────────────────────────────────────────────

    protected function errorPage(string $title, string $message): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><title>{$title}</title>
        <style>
            body { font-family: system-ui, sans-serif; background:#0d1117; color:#e6edf3;
                   display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
            .box { text-align:center; max-width:480px; }
            h2 { font-size:1.4rem; margin-bottom:.5rem; color:#f85149; }
            p  { color:#8b949e; font-size:.9rem; }
        </style>
        </head>
        <body>
        <div class="box">
            <h2>⚠ {$title}</h2>
            <p>{$message}</p>
        </div>
        </body>
        </html>
        HTML;
    }
}
