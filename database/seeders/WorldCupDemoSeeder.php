<?php

namespace Database\Seeders;

use App\Models\FormTemplate;
use App\Models\FormToken;
use App\Models\User;
use App\Services\WorldCup\ContestService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo World Cup "Guess the Score" contest, built exactly like production (via
 * ContestService → token_only). Emits a ready token link for QA/preview. Not part
 * of any default seed run.
 */
class WorldCupDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Replace any earlier demo so visibility/schema match the current code path.
        FormTemplate::where('name', 'World Cup Final — Guess the Score')->delete();

        $form = app(ContestService::class)->createForm([
            'name'       => 'World Cup Final — Guess the Score',
            'home'       => 'ar',
            'away'       => 'fr',
            'kickoff'    => '19 Jul 2026, 6:00 PM (Cairo)',
            'expires_at' => null,
            'created_by' => optional(User::first())->id ?? 1,
        ]);

        $token = FormToken::firstOrCreate(
            ['form_id' => $form->id, 'email' => 'demo@samirgroup.com'],
            ['token' => Str::random(48), 'label' => 'Demo Employee', 'uses_limit' => 1, 'expires_at' => null]
        );

        $this->command?->info('Contest ready: /forms/'.$form->slug.'?token='.$token->token);
    }
}
