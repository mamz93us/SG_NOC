<?php

use App\Jobs\CheckExpiryAlertsJob;
use App\Models\License;
use App\Models\NocEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('raises a license_expiring NocEvent when expiry is within 60 days', function () {
    $license = License::create([
        'license_name' => 'M365 E3',
        'license_type' => 'subscription',
        'seats' => 5,
        'expiry_date' => now()->addDays(50),
    ]);

    (new CheckExpiryAlertsJob)->handle();

    $event = NocEvent::where('source_type', 'license_expiring')->where('source_id', $license->id)->first();
    expect($event)->not()->toBeNull();
    expect($event->severity)->toBe('warning');
});

it('does NOT raise a license_expiring event when expiry is 65 days out', function () {
    $license = License::create([
        'license_name' => 'Adobe CC',
        'license_type' => 'subscription',
        'seats' => 1,
        'expiry_date' => now()->addDays(65),
    ]);

    (new CheckExpiryAlertsJob)->handle();

    expect(NocEvent::where('source_type', 'license_expiring')->where('source_id', $license->id)->exists())->toBeFalse();
});

it('marks severity critical when expiry is within 14 days', function () {
    $license = License::create([
        'license_name' => 'AutoCAD',
        'license_type' => 'subscription',
        'seats' => 2,
        'expiry_date' => now()->addDays(10),
    ]);

    (new CheckExpiryAlertsJob)->handle();

    $event = NocEvent::where('source_type', 'license_expiring')->where('source_id', $license->id)->first();
    expect($event->severity)->toBe('critical');
});

it('still raises a license_expired event for past-due licenses', function () {
    $license = License::create([
        'license_name' => 'Old CRM',
        'license_type' => 'subscription',
        'seats' => 1,
        'expiry_date' => now()->subDays(5),
    ]);

    (new CheckExpiryAlertsJob)->handle();

    expect(NocEvent::where('source_type', 'license_expired')->where('source_id', $license->id)->exists())->toBeTrue();
});
