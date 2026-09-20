<?php

use App\Services\Identity\GraphService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Where a Graph call actually goes.
 *
 * Intune lives on the beta endpoint, so those callers pass a whole URL rather
 * than a path. `delete()` used to paste the v1.0 base in front of it whatever
 * it was given, which sent every Intune device delete to
 * `https://graph.microsoft.com/v1.0https://graph.microsoft.com/beta/…`. Graph
 * answers that 404, the offboarding job read 404 as "already gone", and so no
 * leaver's laptop was ever unenrolled while the run reported success.
 *
 * Tables are built directly: the full migration set does not run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'settings'] as $table) {
        Schema::dropIfExists($table);
    }

    // GraphService reads Settings when it is constructed; auditing writes here.
    Schema::create('settings', function (Blueprint $t) {
        $t->id();
        $t->string('company_name')->nullable();
        $t->string('company_logo')->nullable();
        $t->boolean('sso_enabled')->default(false);
        $t->string('sso_default_role')->nullable();
        $t->string('graph_tenant_id')->nullable();
        $t->string('graph_client_id')->nullable();
        $t->text('graph_client_secret')->nullable();
        $t->timestamps();
    });
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('model_type')->nullable();
        $t->unsignedBigInteger('model_id')->nullable();
        $t->string('model_label')->nullable();
        $t->string('action')->nullable();
        $t->json('changes')->nullable();
        $t->string('actor_label')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->timestamps();
    });
});

function graphWithToken(): GraphService
{
    // Skips the OAuth round trip: the token is cached under the client id.
    Cache::put('graph_token_client-1', 'test-token', 60);

    return new GraphService('tenant-1', 'client-1', 'secret-1');
}

it('deletes an Intune device at the beta endpoint, not underneath v1.0', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $deleted = graphWithToken()->deleteIntuneDevice('abc-123');

    expect($deleted)->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://graph.microsoft.com/beta/deviceManagement/managedDevices/abc-123');
});

it('deletes an Intune script at the beta endpoint too', function () {
    Http::fake(['*' => Http::response('', 204)]);

    graphWithToken()->deleteIntuneScript('script-9');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://graph.microsoft.com/beta/deviceManagement/deviceManagementScripts/script-9');
});

it('still puts a plain path under v1.0', function () {
    Http::fake(['*' => Http::response('', 204)]);

    graphWithToken()->deleteUser('user-7');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://graph.microsoft.com/v1.0/users/user-7');
});

it('reports a device Intune no longer holds as gone, without throwing', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'ResourceNotFound']], 404)]);

    expect(graphWithToken()->deleteIntuneDevice('vanished'))->toBeFalse();
});

it('throws on any other failure, so a broken delete cannot pass for a missing device', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'ServiceUnavailable']], 503)]);

    expect(fn () => graphWithToken()->deleteIntuneDevice('abc-123'))
        ->toThrow(RuntimeException::class, '503');
});

it('refuses a URL that is not Graph rather than calling it', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $delete = new ReflectionMethod(GraphService::class, 'delete');

    expect(fn () => $delete->invoke(graphWithToken(), 'https://graph.microsoft.com.evil.example/v1.0/users/1'))
        ->toThrow(InvalidArgumentException::class)
        // The shape the old code produced: base pasted in front of a whole URL.
        ->and(fn () => $delete->invoke(graphWithToken(), 'https://graph.microsoft.com/v1.0https://graph.microsoft.com/beta/deviceManagement/managedDevices/x'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});
