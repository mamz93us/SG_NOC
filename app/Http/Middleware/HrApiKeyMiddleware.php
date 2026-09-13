<?php
namespace App\Http\Middleware;

use App\Models\HrApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HrApiKeyMiddleware
{
    /**
     * @param  string  $scope  the API being called: `hr` (the default, /api/hr) or
     *                         `attendance`. A key scoped to another API is refused;
     *                         a General key (no scope) works on every API.
     */
    public function handle(Request $request, Closure $next, string $scope = 'hr'): Response
    {
        $provided = $request->header('X-HR-Api-Key')
            ?? $request->bearerToken();

        if (empty($provided)) {
            return response()->json(['error' => 'API key required.'], 401);
        }

        $apiKey = HrApiKey::findByRawKey($provided, $scope);

        if (! $apiKey) {
            // Legacy config key fallback (migration period) — the HR API's only.
            $legacy = $scope === 'hr' ? config('services.hr_api.key') : null;
            if ($legacy && hash_equals((string) $legacy, (string) $provided)) {
                Log::warning('HR API: Legacy config key used. Please migrate to database keys.');
                return $next($request);
            }

            // A live key issued for a different API: say so rather than "invalid".
            if (HrApiKey::findByRawKey($provided)) {
                return response()->json(['error' => "This API key is not scoped for this API. Use a key with the '{$scope}' scope."], 403);
            }

            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }

        // Record usage (non-blocking)
        try {
            $apiKey->recordUsage($request->ip());
        } catch (\Throwable) {}

        // Attach to request for downstream use
        $request->attributes->set('hr_api_key', $apiKey);

        return $next($request);
    }
}
