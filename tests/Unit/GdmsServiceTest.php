<?php

use App\Services\GdmsService;
use Illuminate\Support\Facades\Http;

// Bind the Laravel TestCase (boots the app so Http::fake + config work) but
// NOT RefreshDatabase — these tests never touch the DB. The GdmsService
// constructor falls back to config when the settings table is unavailable.
uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('services.gdms.base_url', 'https://gdms.test/oapi');
    config()->set('services.gdms.client_id', 'CID');
    config()->set('services.gdms.client_secret', 'SUPERSECRET');
    config()->set('services.gdms.org_id', 42);

    Http::fake([
        '*/oauth/token*' => Http::response(['access_token' => 'TESTTOKEN'], 200),
        '*/v1.0.0/*' => Http::response(['retCode' => 0, 'data' => []], 200),
    ]);
});

it('claims a device with a normalized MAC, serial and name', function () {
    (new GdmsService)->addDevice('ec74d7800474', 'SN123', 'Reception');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1.0.0/device/add')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return is_array($body)
            && count($body) === 1
            && $body[0]['mac'] === 'EC:74:D7:80:04:74'   // colon-formatted, upper
            && $body[0]['sn'] === 'SN123'
            && $body[0]['deviceName'] === 'Reception'
            && $body[0]['orgId'] === 42;
    });
});

it('sends a REBOOT task with taskType 1 and normalized macList', function () {
    (new GdmsService)->rebootDevices(['aabbccddeeff', 'AA:BB:CC:00:11:22']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1.0.0/task/add')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return $body['taskName'] === 'REBOOT'
            && $body['taskType'] === GdmsService::TASK_REBOOT
            && $body['execType'] === 1
            && $body['macList'] === ['AA:BB:CC:DD:EE:FF', 'AA:BB:CC:00:11:22'];
    });
});

it('uses the configured taskType for a factory reset', function () {
    config()->set('services.gdms.task_factory_reset', 7);

    (new GdmsService)->factoryResetDevices(['aabbccddeeff']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1.0.0/task/add')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return $body['taskName'] === 'FACTORY_RESET' && $body['taskType'] === 7;
    });
});

it('signs requests with a 64-char hex signature and never leaks the secret in the URL', function () {
    (new GdmsService)->rebootDevices(['aabbccddeeff']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1.0.0/task/add')) {
            return false;
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return isset($q['signature'])
            && preg_match('/^[0-9a-f]{64}$/', $q['signature']) === 1
            && $q['access_token'] === 'TESTTOKEN'
            && ! str_contains($request->url(), 'client_secret')
            && ! str_contains($request->url(), 'SUPERSECRET');
    });
});
