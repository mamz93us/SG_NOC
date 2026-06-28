<?php

// Feature tests auto-use RefreshDatabase (see tests/Pest.php).

it('redirects guests away from the analytics dashboard', function () {
    $this->get('/admin/ticket-stats')
        ->assertRedirect(route('login'));
});

it('requires auth for the JSON stats API', function () {
    $this->getJson('/api/ticket-stats?range=7d')
        ->assertStatus(401); // unauthenticated JSON request
});
