<?php

namespace Database\Seeders;

use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo World Cup "Guess the Score" contest form (PUBLIC visibility) used for
 * previewing/QA of the branded form layout. Not part of any default seed run.
 */
class WorldCupDemoSeeder extends Seeder
{
    public function run(): void
    {
        $home = ['code' => 'ar', 'name' => 'Argentina'];
        $away = ['code' => 'fr', 'name' => 'France'];

        $form = FormTemplate::updateOrCreate(
            ['slug' => 'worldcup-demo'],
            [
                'name'        => 'World Cup Final — Guess the Score',
                'description' => 'Predict the final score and win!',
                'type'        => 'survey',
                'visibility'  => 'public',
                'is_active'   => true,
                'created_by'  => optional(User::first())->id ?? 1,
                'schema'      => [
                    ['id' => 'home_score', 'type' => 'number', 'name' => 'home_score', 'label' => 'Argentina — goals', 'required' => true, 'width' => 'half', 'min' => 0, 'max' => 20, 'help_text' => '', 'conditional' => null],
                    ['id' => 'away_score', 'type' => 'number', 'name' => 'away_score', 'label' => 'France — goals', 'required' => true, 'width' => 'half', 'min' => 0, 'max' => 20, 'help_text' => '', 'conditional' => null],
                ],
                'settings'    => array_merge(FormTemplate::defaultSettings(), [
                    'theme'        => 'worldcup',
                    'submit_label' => 'Submit my guess',
                    'worldcup'     => ['enabled' => true, 'home' => $home, 'away' => $away, 'kickoff' => '19 Jul 2026, 6:00 PM (Cairo)'],
                ]),
            ]
        );

        $this->command?->info('World Cup demo form ready: /forms/'.$form->slug);
    }
}
