<?php

use App\Models\BranchAgent;
use App\Models\BranchLogCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// NOTE: the wider suite is known-broken under SQLite :memory: (MySQL-only
// MODIFY COLUMN migrations). Run these against a real MySQL DB to verify.

function pendingAgent(array $overrides = []): BranchAgent
{
    return BranchAgent::create(array_merge([
        'code'                  => 'jed',
        'name'                  => 'Jeddah',
        'port'                  => 8080,
        'enabled'               => true,
        'status'                => 'pending',
        'enrollment_code'       => 'ABCD1234',
        'enrollment_expires_at' => now()->addMinutes(30),
        'dns_domain'            => 'branch.samirgroup.net',
        'dns_subdomain'         => 'jed',
    ], $overrides));
}

describe('Branch agent enrollment', function () {

    it('issues a token and provisions a log-collector for a valid code', function () {
        pendingAgent();

        $res = $this->postJson('/api/branch-agents/enroll', [
            'code'          => 'ABCD1234',
            'hostname'      => '10.3.0.5',
            'agent_version' => '1.0.0',
        ])->assertOk()->assertJson(['ok' => true]);

        $token = $res->json('token');
        expect($token)->toBeString()->and(strlen($token))->toBe(64);

        $agent = BranchAgent::where('code', 'jed')->first();
        expect($agent->api_token)->toBe($token)
            ->and($agent->enrollment_code)->toBeNull()
            ->and($agent->hostname)->toBe('10.3.0.5');

        // Auto-provisioned log-collector row with the same token.
        $collector = BranchLogCollector::where('code', 'jed')->first();
        expect($collector)->not->toBeNull()
            ->and($collector->api_token)->toBe($token)
            ->and($collector->host)->toBe('10.3.0.5');
    });

    it('rejects an invalid enrollment code', function () {
        pendingAgent();

        $this->postJson('/api/branch-agents/enroll', ['code' => 'WRONGONE'])
            ->assertStatus(401)
            ->assertJson(['ok' => false]);
    });

    it('rejects an expired enrollment code', function () {
        pendingAgent(['enrollment_expires_at' => now()->subMinute()]);

        $this->postJson('/api/branch-agents/enroll', ['code' => 'ABCD1234'])
            ->assertStatus(401);
    });
});

describe('Branch agent heartbeat & config', function () {

    it('accepts a heartbeat with a valid bearer token and updates health', function () {
        $agent = pendingAgent([
            'enrollment_code'       => null,
            'enrollment_expires_at' => null,
            'api_token'             => 'tok_'.str_repeat('a', 60),
        ]);

        $this->withToken($agent->api_token)
            ->postJson('/api/branch-agents/heartbeat', [
                'agent_version' => '1.2.3',
                'health'        => ['disk_pct' => 41, 'devices_up' => 5],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $agent->refresh();
        expect($agent->agent_version)->toBe('1.2.3')
            ->and($agent->last_heartbeat_at)->not->toBeNull()
            ->and($agent->status)->toBe('healthy')
            ->and($agent->last_health['disk_pct'])->toBe(41);
    });

    it('rejects heartbeat without a valid token', function () {
        pendingAgent([
            'enrollment_code'       => null,
            'enrollment_expires_at' => null,
            'api_token'             => 'tok_'.str_repeat('a', 60),
        ]);

        $this->withToken('not-the-token')
            ->postJson('/api/branch-agents/heartbeat', [])
            ->assertStatus(401);
    });

    it('serves runtime config to an enrolled agent', function () {
        $agent = pendingAgent([
            'enrollment_code'       => null,
            'enrollment_expires_at' => null,
            'api_token'             => 'tok_'.str_repeat('b', 60),
        ]);

        $this->withToken($agent->api_token)
            ->getJson('/api/branch-agents/config')
            ->assertOk()
            ->assertJson([
                'ok'     => true,
                'config' => ['branch_code' => 'jed', 'ddns_enabled' => true],
            ]);
    });
});
