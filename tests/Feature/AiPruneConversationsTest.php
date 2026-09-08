<?php

use App\Models\AiConversation;
use App\Models\AiSetting;
use App\Models\User;

// Feature tests auto-use RefreshDatabase (see tests/Pest.php).
//
// Same caveat as tests/Feature/AiAssistantTest.php: this Feature suite errors
// under the SQLite :memory: connection in phpunit.xml (unrelated historical
// MySQL-only migrations) — see tests/Feature/Api/HrApiTest.php. Written
// against the same conventions as the rest of the suite; expected to pass
// against MySQL.

it('deletes conversations (and their messages) older than the retention window', function () {
    AiSetting::create(['retention_days' => 30]);
    $user = User::factory()->create();

    $old = AiConversation::create([
        'user_id' => $user->id,
        'locale' => 'en',
        'last_message_at' => now()->subDays(45),
    ]);
    $recent = AiConversation::create([
        'user_id' => $user->id,
        'locale' => 'en',
        'last_message_at' => now()->subDays(2),
    ]);
    $neverMessaged = AiConversation::create([
        'user_id' => $user->id,
        'locale' => 'en',
        'created_at' => now()->subDays(60),
    ]);

    $this->artisan('ai:prune-conversations')->assertSuccessful();

    expect(AiConversation::find($old->id))->toBeNull();
    expect(AiConversation::find($neverMessaged->id))->toBeNull();
    expect(AiConversation::find($recent->id))->not->toBeNull();
});

it('does nothing when retention is disabled (days <= 0)', function () {
    AiSetting::create(['retention_days' => 0]);
    $user = User::factory()->create();

    AiConversation::create([
        'user_id' => $user->id,
        'locale' => 'en',
        'last_message_at' => now()->subYears(5),
    ]);

    $this->artisan('ai:prune-conversations')->assertSuccessful();

    expect(AiConversation::count())->toBe(1);
});
