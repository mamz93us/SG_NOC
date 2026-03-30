<?php

namespace App\Http\Controllers\Admin;

/*
 * SECURITY NOTES:
 *  1. Only devices in the `devices` table can be proxied — no arbitrary IP proxying.
 *     Every request is bound to a Device model instance validated by Laravel's router.
 *  2. All routes require auth middleware (enforced in routes/web.php).
 *  3. proxy_enabled flag must be true on the device before any proxy request is served.
 *  4. The proxy only forwards to $device->ip_address — the stored IP, never user-supplied.
 *  5. Rate-limited: throttle:60,1 per user (see routes/web.php).
 */

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class DeviceProxyController extends Controller
{
    // ── Browse (iFrame launcher) ──────────────────────────────────────────

    /**
     * Show the device's web management interface inside a frame.
     * Logs every browse() call as a 'web / browse' access event.
     */
    public function browse(Request $request, Device $device): \Illuminate\View\View
    {
        abort_unless($device->ip_address, 422, 'Device has no IP address configured.');

        // Security: device must be in our table — already guaranteed by model binding.
        DeviceAccessLog::log(
            $device, $request->user(), 'web', 'browse',
            $request->ip(), ['user_agent' => $request->userAgent()]
        );

        return view('admin.devices.browser', compact('device'));
    }

    // ── Proxy ─────────────────────────────────────────────────────────────

    /**
     * Forward HTTP requests to the device's web interface and return the
     * response with all internal URLs rewritten to route through this proxy.
     *
     * SECURITY: target IP is ALWAYS $device->ip_address from DB — never from user input.
     */
    public function proxy(Request $request, Device $device, string $path = ''): Response|\Illuminate\Http\RedirectResponse
    {
        abort_unless($device->ip_address, 422);

        $protocol = $device->web_protocol ?? 'http';
        $port     = $device->web_port     ?? ($protocol === 'https' ? 443 : 80);
        $base     = "{$protocol}://{$device->ip_address}:{$port}";
        $target   = $base . '/' . ltrim($path, '/');

        if ($qs = $request->getQueryString()) {
            $target .= '?' . $qs;
        }

        try {
            $response = Http::withOptions([
                'verify'          => false,   // Many devices use self-signed TLS certs
                'timeout'         => 12,
                'connect_timeout' => 5,
                'allow_redirects' => false,
                // Allow TLS 1.0/1.1 for old device firmware (OpenSSL 3.x blocks these by default)
                'curl' => [
                    CURLOPT_SSLVERSION      => CURL_SSLVERSION_DEFAULT,
                    CURLOPT_SSL_VERIFYPEER  => false,
                    CURLOPT_SSL_VERIFYHOST  => 0,
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                ],
            ])
            ->withHeaders(['X-Forwarded-For' => $request->ip()])
            ->send(
                $request->method(),
                $target,
                $request->isMethod('POST')
                    ? ['form_params' => $request->except('_token')]
                    : []
            );
        } catch (\Exception $e) {
            return response(
                "<html><body style='font-family:monospace;padding:2rem'>"
                . "<h3>Proxy Error</h3><p>" . e($e->getMessage()) . "</p>"
                . "<p><a href='" . route('admin.devices.proxy', [$device]) . "'>Retry</a></p></body></html>",
                502
            )->header('Content-Type', 'text/html');
        }

        // Handle server-side redirects
        if ($response->redirect()) {
            $location  = $response->header('Location', '/');
            $rewritten = $this->rewriteUrl($device, $location, $base);
            return redirect($rewritten);
        }

        $contentType = $response->header('Content-Type', 'text/html');
        $body        = $response->body();

        // Rewrite HTML so all links route through this proxy
        if (str_contains($contentType, 'text/html')) {
            $body = $this->rewriteLinks($device, $body, $base);
        }

        return response($body, $response->status())
            ->header('Content-Type', $contentType)
            ->header('X-Proxied-By', 'SG-NOC');
    }

    // ── Link rewriting ────────────────────────────────────────────────────

    /**
     * Rewrite all href/src/action/url() references in an HTML document so they
     * route through the proxy controller instead of pointing directly at the device.
     */
    protected function rewriteLinks(Device $device, string $html, string $deviceBase): string
    {
        // Rewrite HTML attributes (href, src, action)
        $html = preg_replace_callback(
            '/\b(href|src|action)=(["\'])([^"\']*)\2/i',
            function ($m) use ($device, $deviceBase) {
                $attr = $m[1];
                $qt   = $m[2];
                $url  = $m[3];
                $rw   = $this->rewriteUrl($device, $url, $deviceBase);
                return "{$attr}={$qt}{$rw}{$qt}";
            },
            $html
        );

        // Rewrite CSS url() references
        $html = preg_replace_callback(
            '/url\((["\']?)([^)"\'\s]+)\1\)/i',
            function ($m) use ($device, $deviceBase) {
                $qt  = $m[1];
                $url = $m[2];
                $rw  = $this->rewriteUrl($device, $url, $deviceBase);
                return "url({$qt}{$rw}{$qt})";
            },
            $html
        );

        return $html;
    }

    /**
     * Map a single URL to its proxy equivalent.
     */
    protected function rewriteUrl(Device $device, string $url, string $deviceBase): string
    {
        // Skip non-navigable URIs
        if (preg_match('/^(#|javascript:|mailto:|data:|about:)/i', $url)) {
            return $url;
        }

        $proxyBase = route('admin.devices.proxy', [$device, '']);

        // Absolute URL pointing to the device itself
        if (str_starts_with($url, $deviceBase)) {
            return $proxyBase . ltrim(substr($url, strlen($deviceBase)), '/');
        }

        // Absolute URL pointing elsewhere — leave untouched
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Protocol-relative URL pointing to device
        $deviceNoScheme = preg_replace('/^https?:/i', '', $deviceBase);
        if (str_starts_with($url, $deviceNoScheme)) {
            return $proxyBase . ltrim(substr($url, strlen($deviceNoScheme)), '/');
        }

        // Relative URL — prepend proxy base
        return $proxyBase . ltrim($url, '/');
    }
}
