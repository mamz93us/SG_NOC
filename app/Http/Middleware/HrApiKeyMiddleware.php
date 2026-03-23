<?php
namespace App\Http\Middleware;

use App\Models\HrApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HrApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-HR-Api-Key')
            ?? $request->bearerToken();

        if (empty($provided)) {
            return response()->json(['error' => 'API key required.'], 401);
        }

        $apiKey = HrApiKey::findByRawKey($provided);

        if (! $apiKey) {
            // Legacy config key fallback (migration period)
            $legacy = config('services.hr_api.key');
            if ($legacy && hash_equals((string) $legacy, (string) $provided)) {
                Log::warning('HR API: Legacy config key used. Please migrate to database keys.');
                return $next($request);
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
