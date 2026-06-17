<?php

namespace App\Services;

use App\Models\BranchLogCollector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the per-branch log query API
 * (deployment/branch-vm/api/public/index.php).
 *
 * Each branch VM exposes /api/logs/search and /api/logs/aggregate over
 * its IPsec tunnel IP, authenticated by a per-branch bearer token. This
 * service fans queries out to the selected branches in parallel and
 * merges the results.
 *
 * Branch endpoints are managed in the UI at
 * /admin/branches/log-collectors and persisted to branch_log_collectors.
 */
class BranchLogClient
{
    /**
     * All enabled, ready-to-query branches keyed by their `code`. Reads
     * from the branch_log_collectors table (UI-managed) — no config file
     * editing needed.
     *
     * @return array<string, array{name:string, host:string, port:int, token:string}>
     */
    public function enabledBranches(): array
    {
        return BranchLogCollector::ready()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (BranchLogCollector $c) => [
                $c->code => [
                    'name' => $c->name,
                    'host' => $c->host,
                    'port' => $c->port,
                    'token' => $c->api_token,
                ],
            ])
            ->all();
    }

    /**
     * Run /api/logs/search on each requested branch in parallel, merge
     * results, sort newest-first, return up to $limit globally.
     *
     * @param  array  $branchIds  subset of branch IDs to query, [] = all enabled
     * @param  array  $params  query params forwarded to each branch
     * @param  int  $limit  global cap on merged result count
     */
    public function search(array $branchIds, array $params, int $limit = 200, ?int $timeoutSec = null): array
    {
        $branches = $this->resolveBranches($branchIds);
        if (! $branches) {
            return ['ok' => true, 'results' => [], 'errors' => [], 'branches' => [], 'total' => 0];
        }

        // Tell each branch how many rows it should return — otherwise the
        // branch API defaults to 200 regardless of our global merge cap.
        // Capped at 50000 by the branch agent's Search() on the branch side
        // (interactive views ask for <= 5000; CSV export asks for more).
        if (! isset($params['limit'])) {
            $params['limit'] = min($limit, 50000);
        }

        $started = microtime(true);
        $responses = $this->fanOut($branches, '/api/logs/search', $params, $timeoutSec);

        $merged = [];
        $errors = [];
        $totals = [];
        foreach ($responses as $branchId => $resp) {
            if ($resp['ok']) {
                $totals[$branchId] = $resp['total'] ?? count($resp['results'] ?? []);
                foreach ($resp['results'] ?? [] as $row) {
                    $row['branch_id'] = $branchId;
                    $merged[] = $row;
                }
            } else {
                $errors[$branchId] = $resp['error'];
            }
        }

        usort($merged, fn ($a, $b) => strcmp($b['received_at'], $a['received_at']));
        $merged = array_slice($merged, 0, $limit);

        return [
            'ok' => true,
            'results' => $merged,
            'errors' => $errors,
            'totals' => $totals,
            'total' => array_sum($totals),
            'took_ms' => (int) ((microtime(true) - $started) * 1000),
            'branches' => array_keys($branches),
        ];
    }

    /**
     * Run /api/logs/aggregate on each branch in parallel, merge buckets
     * by key (sum counts).
     */
    public function aggregate(array $branchIds, array $params, int $limit = 30): array
    {
        $branches = $this->resolveBranches($branchIds);
        if (! $branches) {
            return ['ok' => true, 'buckets' => [], 'errors' => [], 'branches' => []];
        }

        $started = microtime(true);
        $responses = $this->fanOut($branches, '/api/logs/aggregate', $params);

        $bucketMap = [];
        $errors = [];
        foreach ($responses as $branchId => $resp) {
            if ($resp['ok']) {
                foreach ($resp['buckets'] ?? [] as $b) {
                    $key = (string) $b['key'];
                    $bucketMap[$key] = ($bucketMap[$key] ?? 0) + (int) $b['count'];
                }
            } else {
                $errors[$branchId] = $resp['error'];
            }
        }

        arsort($bucketMap);
        $buckets = [];
        foreach (array_slice($bucketMap, 0, $limit, true) as $k => $c) {
            $buckets[] = ['key' => $k, 'count' => $c];
        }

        return [
            'ok' => true,
            'buckets' => $buckets,
            'errors' => $errors,
            'took_ms' => (int) ((microtime(true) - $started) * 1000),
            'branches' => array_keys($branches),
        ];
    }

    private function resolveBranches(array $branchIds): array
    {
        $enabled = $this->enabledBranches();
        if (! $branchIds) {
            return $enabled;
        }

        return array_intersect_key($enabled, array_flip($branchIds));
    }

    /**
     * Fan out an HTTP GET to every branch in parallel using Laravel's
     * Http::pool. Returns ['branchId' => ['ok'=>true, ...payload]] or
     * ['branchId' => ['ok'=>false, 'error'=>'message']].
     */
    private function fanOut(array $branches, string $path, array $params, ?int $timeoutSec = null): array
    {
        $ids = array_keys($branches);
        $timeout = $timeoutSec ?? (int) config('branches.http.timeout', 10);
        $connectTimeout = (int) config('branches.http.connect_timeout', 3);
        $verifyTls = (bool) config('branches.http.verify_tls', false);

        $responses = Http::pool(function ($pool) use ($branches, $path, $params, $timeout, $connectTimeout, $verifyTls) {
            $jobs = [];
            foreach ($branches as $id => $info) {
                $url = sprintf('http://%s:%d%s', $info['host'], $info['port'], $path);
                $jobs[] = $pool->as($id)
                    ->withToken($info['token'])
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->withOptions(['verify' => $verifyTls])
                    ->get($url, $params);
            }

            return $jobs;
        });

        $out = [];
        foreach ($ids as $id) {
            $r = $responses[$id] ?? null;
            if ($r === null) {
                $out[$id] = ['ok' => false, 'error' => 'no response (pool error)'];

                continue;
            }
            if ($r instanceof \Throwable) {
                Log::warning('BranchLogClient transport error', [
                    'branch' => $id,
                    'error' => $r->getMessage(),
                ]);
                $out[$id] = ['ok' => false, 'error' => 'unreachable: '.$r->getMessage()];

                continue;
            }
            if (! $r->ok()) {
                $body = $r->json() ?? [];
                $out[$id] = ['ok' => false, 'error' => $body['error'] ?? "HTTP {$r->status()}"];

                continue;
            }
            $out[$id] = $r->json() ?? ['ok' => false, 'error' => 'invalid json'];
        }

        return $out;
    }
}
