<?php

namespace App\Http\Middleware;

use App\Services\Access\AccessVisitRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a deduplicated "still active" heartbeat for authenticated users on
 * the NOC / EM / Portal apps. At most one row per (user, app) per 5 minutes, so
 * background polling and assets don't flood access_visits. Login events are
 * recorded separately in MicrosoftController.
 *
 * Best-effort: any failure is swallowed (logged) and never affects the response.
 */
class LogAccessVisit
{
    private const DEDUP_MINUTES = 5;

    /** Paths that are polling/asset/health noise, not real "page" navigations. */
    private const SKIP_PATTERNS = [
        'up',
        'build/*',
        'livewire/*',
        '*/unread-count',
        '*/heartbeat',
        'admin/toggle-dark-mode',
        'admin/notifications/unread-count',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->shouldLog($request)) {
                $app = AccessVisitRecorder::appFor($request);
                $key = "access_hb:{$app}:".Auth::id();

                if (! Cache::has($key)) {
                    Cache::put($key, 1, now()->addMinutes(self::DEDUP_MINUTES));

                    $user = Auth::user();
                    app(AccessVisitRecorder::class)->record([
                        'user_id' => $user->getKey(),
                        'user_name' => $user->name ?? null,
                        'user_email' => $user->email ?? null,
                        'app' => $app,
                        'event' => 'access',
                        'path' => '/'.ltrim($request->path(), '/'),
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[LogAccessVisit] failed to record heartbeat: '.$e->getMessage());
        }

        return $response;
    }

    private function shouldLog(Request $request): bool
    {
        return Auth::check()
            && $request->isMethod('GET')
            && ! $request->ajax()
            && ! $request->expectsJson()
            && ! $request->is(...self::SKIP_PATTERNS);
    }
}
