<?php
/**
 * SG_NOC Branch VM — Query API.
 *
 * Tiny single-file PHP app (no framework). nginx + PHP-FPM front this.
 * Three endpoints:
 *
 *   GET /api/health                  → liveness probe (no auth)
 *   GET /api/logs/search             → paginated search (auth required)
 *   GET /api/logs/aggregate          → group-by counts (auth required)
 *
 * Auth: Bearer token in Authorization header. Token lives in
 * /etc/sg-noc-branch.env as API_TOKEN. nginx ALSO restricts the source IP
 * to NOC_ALLOWED_CIDR before requests reach PHP — so this is defence in
 * depth.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/Env.php';
require __DIR__ . '/../lib/Db.php';
require __DIR__ . '/../lib/SearchService.php';
require __DIR__ . '/../lib/StatsService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Routing
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET' && $path === '/api/health') {
        echo json_encode(['ok' => true, 'service' => 'sg-noc-branch-api', 'time' => gmdate('c')]);
        exit;
    }

    // Everything else needs auth.
    require_auth();

    $svc = new SearchService(Db::pdo());

    if ($method === 'GET' && $path === '/api/logs/search') {
        $r = $svc->search($_GET);
        echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $path === '/api/logs/aggregate') {
        $r = $svc->aggregate($_GET);
        echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $path === '/api/stats') {
        $stats = (new StatsService(Db::pdo()))->collect();
        echo json_encode(['ok' => true] + $stats, JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'route not found']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[sg-noc-api] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal error']);
}

function require_auth(): void {
    $expected = Env::get('API_TOKEN');
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($hdr, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'missing bearer token']);
        exit;
    }
    $given = substr($hdr, 7);
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid token']);
        exit;
    }
}
