<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class WebBrowserController extends Controller
{
    // ── Browser UI ─────────────────────────────────────────────────────────

    public function index(Request $request): \Illuminate\View\View
    {
        $url = trim($request->query('url', ''));
        return view('admin.browser.index', compact('url'));
    }

    // ── Proxy Fetch ────────────────────────────────────────────────────────

    public function fetch(Request $request): Response
    {
        $url = trim($request->query('url', ''));

        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            return response($this->errorPage('Invalid URL', 'Only http:// and https:// URLs are supported.'), 400)
                ->header('Content-Type', 'text/html');
        }

        $parsed = parse_url($url);
        $base   = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $base .= ':' . $parsed['port'];
        }

        // Forward cookies the browser sent (captured from previous proxied requests)
        $forwardCookies = collect($request->cookies->all())
            ->except(['XSRF-TOKEN', session()->getName()])
            ->map(fn($v, $k) => "{$k}={$v}")
            ->implode('; ');

        try {
            $http = Http::withOptions([
                'verify'          => false,
                'timeout'         => 12,       // total response time — keeps PHP-FPM alive
                'connect_timeout' => 5,        // fail fast on unreachable hosts
                'allow_redirects' => false,    // Handle redirects ourselves
                // Legacy SSL compatibility for old network device firmware:
                // SECLEVEL=0  → allows MD5/SHA1 signature algorithms (cURL error:0A00014D)
                // TLSv1       → allows TLS 1.0/1.1 (cURL error:0A000102)
                // verify=false→ ignores self-signed / expired certificates
                'curl' => [
                    CURLOPT_SSLVERSION      => CURL_SSLVERSION_TLSv1,
                    CURLOPT_SSL_VERIFYPEER  => false,
                    CURLOPT_SSL_VERIFYHOST  => 0,
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=0',
                ],
            ])
            ->withHeaders(array_filter([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept'          => $request->header('Accept', 'text/html,application/xhtml+xml,*/*'),
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cookie'          => $forwardCookies ?: null,
                'X-Forwarded-For' => $request->ip(),
                'Referer'         => $base . '/',
            ]));

            if ($request->isMethod('POST')) {
                $postData = $request->except(['_token', 'url']);
                $response = $http->asForm()->post($url, $postData);
            } else {
                $response = $http->get($url);
            }
        } catch (\Exception $e) {
            return response($this->errorPage('Connection Failed', e($e->getMessage())), 502)
                ->header('Content-Type', 'text/html');
        }

        // ── Handle redirects ──────────────────────────────────────────────
        if (in_array($response->status(), [301, 302, 303, 307, 308])) {
            $location = $response->header('Location', '/');
            $location = $this->resolveUrl($location, $base, $url);
            $proxied  = route('admin.browser.fetch', ['url' => $location]);
            return response(
                "<html><head><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($proxied) . "'></head></html>",
                200
            )->header('Content-Type', 'text/html');
        }

        $contentType = $response->header('Content-Type', 'text/html; charset=utf-8');
        $body        = $response->body();

        // ── Safety: skip rewriting for large responses (> 2 MB) ──────────
        // Regex link-rewriting on huge pages (Google, heavy SPAs) will exhaust
        // PHP memory and cause nginx 502. Pass them through untouched.
        $tooBig = strlen($body) > 2 * 1024 * 1024;

        // ── Rewrite & inject for HTML ─────────────────────────────────────
        if (!$tooBig && str_contains($contentType, 'text/html')) {
            $body = $this->rewriteHtml($body, $base, $url);
        }

        if (!$tooBig && str_contains($contentType, 'text/css')) {
            $body = $this->rewriteCss($body, $base);
        }

        // ── Build response — pass through Set-Cookie from device ──────────
        // Always return 200: a real 401/403/5xx from the device makes the
        // browser refuse to render the iframe body (which is the login page we need to show).
        $deviceStatus  = $response->status();
        $renderStatus  = ($deviceStatus >= 400) ? 200 : $deviceStatus;

        $resp = response($body, $renderStatus)
            ->header('Content-Type', $contentType)
            ->header('X-Proxied-By', 'SG-NOC')
            ->header('X-Device-Status', (string) $deviceStatus)
            ->header('X-Frame-Options', 'SAMEORIGIN');

        // Strip headers that would break the iframe
        // (X-Frame-Options / CSP from the DEVICE are NOT forwarded — we set our own)
        foreach ($response->headers() as $name => $values) {
            $skip = ['x-frame-options', 'content-security-policy', 'strict-transport-security',
                     'content-encoding', 'transfer-encoding', 'content-length', 'connection'];
            if (in_array(strtolower($name), $skip)) continue;
            if (strtolower($name) === 'set-cookie') {
                foreach ($values as $cookie) {
                    // Rewrite cookie path/domain so browser stores it for our domain
                    $cookie = preg_replace('/;\s*domain=[^;]*/i', '', $cookie);
                    $cookie = preg_replace('/;\s*secure/i', '', $cookie);
                    $resp->headers->set('Set-Cookie', $cookie, false);
                }
                continue;
            }
        }

        return $resp;
    }

    // ── HTML rewriting ─────────────────────────────────────────────────────

    protected function rewriteHtml(string $html, string $base, string $currentUrl): string
    {
        $proxyFetch = route('admin.browser.fetch');
        $csrf       = csrf_token();

        // ── 1. Remove existing <base> tags ──────────────────────────────
        $html = preg_replace('/<base[^>]*>/i', '', $html);

        // ── 2. Rewrite href / src / action / data-src attributes ────────
        $html = preg_replace_callback(
            '/\b(href|src|action|data-src|data-href)=(["\'])([^"\']*)\2/i',
            function ($m) use ($base, $currentUrl) {
                $attr     = $m[1];
                $quote    = $m[2];
                $original = $m[3];
                $resolved = $this->toProxyUrl($original, $base, $currentUrl);
                return "{$attr}={$quote}{$resolved}{$quote}";
            },
            $html
        );

        // ── 3. Rewrite CSS url() references ─────────────────────────────
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

        // ── 4. Inject fetch/XHR interceptor BEFORE any other scripts ────
        //    This patches window.fetch and XMLHttpRequest so that all
        //    dynamic API calls made by the SPA are also routed through
        //    our proxy server.
        $escapedBase  = json_encode($base);
        $escapedProxy = json_encode($proxyFetch);
        $escapedCsrf  = json_encode($csrf);

        $escapedOrigin = json_encode(url('/'));

        $interceptor = <<<SCRIPT
<script>
(function() {
  var DEVICE_BASE  = {$escapedBase};
  var PROXY_FETCH  = {$escapedProxy};
  var CSRF         = {$escapedCsrf};
  var NOC_ORIGIN   = {$escapedOrigin};   // e.g. https://noc.samirgroup.net

  // Convert any URL to a proxied version
  function proxyUrl(url) {
    if (!url || typeof url !== 'string') return url;
    // Already our proxy — leave alone
    if (url.indexOf(PROXY_FETCH) === 0) return url;
    // Non-navigable
    if (/^(#|javascript:|mailto:|data:|blob:|about:)/i.test(url)) return url;
    // Resolve to absolute
    var abs = url;
    if (url.startsWith('//')) {
      abs = (DEVICE_BASE.startsWith('https') ? 'https:' : 'http:') + url;
    } else if (url.startsWith('/')) {
      // Root-relative URL: resolve against DEVICE_BASE (not NOC origin)
      abs = DEVICE_BASE + url;
    } else if (!/^https?:\/\//i.test(url)) {
      abs = DEVICE_BASE + '/' + url;
    }
    // If the resolved URL points to our OWN server (not the device), it means
    // the SPA used a relative URL that resolved against the iframe origin.
    // Re-resolve it against the device base instead.
    if (abs.indexOf(NOC_ORIGIN) === 0) {
      var path = abs.slice(NOC_ORIGIN.length);
      abs = DEVICE_BASE + path;
    }
    // Don't proxy external CDNs / 3rd-party URLs (not device, not NOC)
    if (/^https?:\/\//i.test(abs) && abs.indexOf(DEVICE_BASE) !== 0) {
      return abs;  // pass through as-is (CDN fonts, etc.)
    }
    return PROXY_FETCH + '?url=' + encodeURIComponent(abs);
  }

  // ── Patch window.fetch ──────────────────────────────────────────
  var _fetch = window.fetch.bind(window);
  window.fetch = function(input, init) {
    try {
      if (typeof input === 'string') {
        input = proxyUrl(input);
      } else if (input && input.url) {
        input = new Request(proxyUrl(input.url), input);
      }
      // Add CSRF header for non-GET requests through our proxy
      if (init && init.method && init.method.toUpperCase() !== 'GET') {
        init.headers = Object.assign({}, init.headers || {}, {'X-CSRF-TOKEN': CSRF});
      }
    } catch(e) {}
    return _fetch(input, init);
  };

  // ── Patch XMLHttpRequest ────────────────────────────────────────
  var _open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(method, url, async, user, pass) {
    try { url = proxyUrl(url); } catch(e) {}
    return _open.call(this, method, url, async !== false, user, pass);
  };
  var _setHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
    // Always ensure CSRF is sent for state-changing requests
    return _setHeader.call(this, name, value);
  };

  // ── Patch window.location assignment ───────────────────────────
  // Intercept JS redirects like location.href = '/login'
  try {
    var _loc = window.location;
    Object.defineProperty(window, 'location', {
      get: function() { return _loc; },
      set: function(v) {
        try { _loc.href = proxyUrl(String(v)); } catch(e) { _loc.href = v; }
      },
      configurable: true,
    });
  } catch(e) {}

})();
</script>
SCRIPT;

        // Inject right after <head> or at the very start if no <head>
        if (preg_match('/<head[^>]*>/i', $html)) {
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . $interceptor, $html, 1);
        } else {
            $html = $interceptor . $html;
        }

        return $html;
    }

    protected function rewriteCss(string $css, string $base): string
    {
        return preg_replace_callback(
            '/url\((["\']?)([^)"\'\s]+)\1\)/i',
            function ($m) use ($base) {
                $quote    = $m[1];
                $original = $m[2];
                if (preg_match('/^(data:|#)/i', $original)) return $m[0];
                $abs = $original;
                if (str_starts_with($original, '/')) $abs = $base . $original;
                elseif (!preg_match('/^https?:\/\//i', $original)) $abs = $base . '/' . $original;
                return "url({$quote}" . route('admin.browser.fetch', ['url' => $abs]) . "{$quote})";
            },
            $css
        );
    }

    // ── URL helpers ────────────────────────────────────────────────────────

    protected function toProxyUrl(string $url, string $base, string $currentUrl): string
    {
        if (preg_match('/^(#|javascript:|mailto:|data:|about:|tel:|blob:)/i', $url)) {
            return $url;
        }
        $resolved = $this->resolveUrl($url, $base, $currentUrl);
        return route('admin.browser.fetch', ['url' => $resolved]);
    }

    protected function resolveUrl(string $url, string $base, string $currentUrl): string
    {
        if (preg_match('/^https?:\/\//i', $url)) return $url;
        if (str_starts_with($url, '//')) {
            $scheme = str_starts_with($base, 'https') ? 'https' : 'http';
            return "{$scheme}:{$url}";
        }
        if (str_starts_with($url, '/')) return $base . $url;
        $dir = rtrim(dirname(parse_url($currentUrl, PHP_URL_PATH) ?? '/'), '/');
        return $base . $dir . '/' . $url;
    }

    // ── Error page ─────────────────────────────────────────────────────────

    protected function errorPage(string $title, string $message): string
    {
        return <<<HTML
        <!DOCTYPE html><html>
        <head><meta charset="UTF-8"><title>{$title}</title>
        <style>
          body { font-family:system-ui,sans-serif; background:#0d1117; color:#e6edf3;
                 display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
          .box { text-align:center; max-width:500px; padding:2rem; }
          h2 { color:#f85149; font-size:1.4rem; margin-bottom:.5rem; }
          p  { color:#8b949e; font-size:.9rem; word-break:break-all; }
        </style></head>
        <body><div class="box">
          <h2>⚠ {$title}</h2><p>{$message}</p>
        </div></body></html>
        HTML;
    }
}
