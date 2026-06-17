<?php

namespace App\Services\WorldCup;

use App\Models\FormTemplate;
use Illuminate\Support\Arr;

/**
 * Shared World Cup "Guess the Score" contest logic, used by both the admin Form
 * Builder and the marketing-portal contest page. A contest is just a FormTemplate
 * themed `worldcup` with two auto-injected score fields, so it flows through the
 * existing submission storage / CSV export with no special-casing.
 */
class ContestService
{
    /** @return array<int,array{code:string,name:string}> */
    public function teams(): array
    {
        return config('worldcup.teams', []);
    }

    /** Resolve a team code to ['code'=>..,'name'=>..] or null. */
    public function resolveTeam(?string $code): ?array
    {
        $team = Arr::first($this->teams(), fn ($t) => ($t['code'] ?? null) === $code);

        return $team ? ['code' => $team['code'], 'name' => $team['name']] : null;
    }

    /** The `settings.worldcup` block. */
    public function worldcupSettings(?array $home, ?array $away, ?string $kickoff): array
    {
        return [
            'enabled' => true,
            'home'    => $home,
            'away'    => $away,
            'kickoff' => $kickoff,
        ];
    }

    /** The two score fields injected into the form schema. */
    public function scoreFields(?array $home, ?array $away): array
    {
        return [
            $this->scoreField('home_score', ($home['name'] ?? 'Home').' — goals'),
            $this->scoreField('away_score', ($away['name'] ?? 'Away').' — goals'),
        ];
    }

    public function scoreField(string $name, string $label): array
    {
        return [
            'id'          => $name,
            'type'        => 'number',
            'name'        => $name,
            'label'       => $label,
            'required'    => true,
            'width'       => 'half',
            'min'         => 0,
            'max'         => 20,
            'help_text'   => '',
            'conditional' => null,
        ];
    }

    /**
     * Create a contest FormTemplate from simple inputs (marketing portal path).
     *
     * @param  array{name:string,home:?string,away:?string,kickoff:?string,expires_at:?string,created_by:?int}  $input
     */
    public function createForm(array $input): FormTemplate
    {
        $home = $this->resolveTeam($input['home'] ?? null);
        $away = $this->resolveTeam($input['away'] ?? null);

        return FormTemplate::create([
            'name'        => $input['name'],
            'slug'        => FormTemplate::generateSlug($input['name']),
            'description' => trim(($home['name'] ?? 'Home').' vs '.($away['name'] ?? 'Away')),
            'type'        => 'survey',
            'visibility'  => 'private', // employee signs in via SSO → guess tied to them
            'is_active'   => true,
            'expires_at'  => $input['expires_at'] ?? null,
            'created_by'  => $input['created_by'] ?? null,
            'schema'      => $this->scoreFields($home, $away),
            'settings'    => array_merge(FormTemplate::defaultSettings(), [
                'theme'        => 'worldcup',
                'submit_label' => 'Submit my guess',
                'worldcup'     => $this->worldcupSettings($home, $away, $input['kickoff'] ?? null),
            ]),
        ]);
    }
}
