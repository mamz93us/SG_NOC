<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Internal-only endpoint called by the Node.js Telnet proxy to exchange
 * a short-lived token for the connection details (host, port, credentials).
 *
 * Protected by:
 *  1. Caller must be 127.0.0.1 (enforced in routes/web.php middleware).
 *  2. X-Telnet-Secret header must match config('telnet.internal_secret').
 *
 * The token is consumed (deleted from cache) on the first successful read,
 * so it cannot be replayed.
 */
class TelnetTokenController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        // Validate shared secret
        $secret = config('telnet.internal_secret', 'changeme');
        if ($request->header('X-Telnet-Secret') !== $secret) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $key     = "telnet_token:{$token}";
        $session = Cache::get($key);

        if (!$session) {
            return response()->json(['error' => 'Token not found or expired'], 404);
        }

        // Consume token immediately — one-time use
        Cache::forget($key);

        return response()->json($session);
    }
}
