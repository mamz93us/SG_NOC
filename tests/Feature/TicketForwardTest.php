<?php

use App\Models\TicketVisit;
use App\Services\Ticketing\TicketVisitRecorder;

// Feature tests auto-use RefreshDatabase (see tests/Pest.php).

beforeEach(function () {
    config()->set('ticket_tracking.destination_url', 'https://tickets.example.test/login');
    config()->set('ticket_tracking.forward_mode', 'redirect');
    config()->set('ticket_tracking.async_logging', false);
});

it('records a visit and redirects to the ticketing app', function () {
    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'])
        ->get('/go');

    $response->assertRedirect('https://tickets.example.test/login');

    expect(TicketVisit::count())->toBe(1);

    $visit = TicketVisit::first();
    expect($visit->browser)->toBe('Chrome');
    expect($visit->platform)->toBe('Windows');
    expect($visit->is_unique_today)->toBeTrue();
});

it('still forwards the visitor when analytics logging throws', function () {
    // Force the recorder to blow up; the user must STILL be forwarded.
    $this->mock(TicketVisitRecorder::class, function ($mock) {
        $mock->shouldReceive('record')->andThrow(new RuntimeException('db is down'));
    });

    $this->get('/go')
        ->assertRedirect('https://tickets.example.test/login');

    expect(TicketVisit::count())->toBe(0);
});

it('forwards bots but does not record them', function () {
    config()->set('ticket_tracking.ignore_bots', true);

    $this->withHeaders(['User-Agent' => 'Pingdom.com_bot_version_1.4'])
        ->get('/go')
        ->assertRedirect('https://tickets.example.test/login');

    expect(TicketVisit::count())->toBe(0);
});

it('sets a session cookie so repeat visitors can be distinguished', function () {
    $response = $this->get('/go');

    $cookieName = config('ticket_tracking.session_cookie');
    $response->assertCookie($cookieName);
});

it('never takes the destination from the request (no open redirect)', function () {
    $this->get('/go?url=https://evil.example.com&destination=https://evil.example.com')
        ->assertRedirect('https://tickets.example.test/login');
});
