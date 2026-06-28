<?php

use App\Models\AccessVisit;
use App\Models\User;

// Feature tests auto-use RefreshDatabase (see tests/Pest.php).

it('records a heartbeat for an authenticated user and deduplicates within the window', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'])
        ->get('/admin')
        ->assertStatus(200);

    expect(AccessVisit::where('event', 'access')->count())->toBe(1);

    $v = AccessVisit::first();
    expect($v->user_id)->toBe($user->id);
    expect($v->app)->toBe('noc');
    expect($v->browser)->toBe('Chrome');

    // Second hit within 5 min → deduped, still one heartbeat row.
    $this->actingAs($user)->get('/admin')->assertStatus(200);
    expect(AccessVisit::where('event', 'access')->count())->toBe(1);
});

it('does not record heartbeats for guests', function () {
    $this->get('/admin'); // guest → redirected to login, no auth
    expect(AccessVisit::count())->toBe(0);
});

it('requires view-activity-logs for the dashboard', function () {
    $this->get('/admin/access-stats')->assertRedirect(route('login'));
});

it('requires auth for the JSON access API', function () {
    $this->getJson('/api/access-stats?range=7d')->assertStatus(401);
});
