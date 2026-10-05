<?php

namespace App\Services\People;

use App\Models\Branch;
use App\Models\User;

/**
 * The Samir AI Assistant's tools over the workforce figures: how many people
 * there are by job category, profession or nationality, and where each
 * professional group stands against its Saudization percentage.
 *
 * **Offered to exactly the people who can open those pages** — holders of
 * view-attendance or view-vacations, the two permissions that open Workforce
 * Breakdown and Saudization — and checked again on every call, so a stale
 * conversation cannot keep using them after access is taken away. Everybody
 * else is not told the tools exist.
 *
 * **Counts only, never names.** The pages list who is in a group; the
 * assistant does not, and no argument here can ask it to. Somebody's
 * nationality is theirs: a head count per nationality is a company figure, a
 * list of the non-Saudis in Sales is not something to hand to a chat.
 *
 * The figures come from the same two services as the pages (WorkforceBreakdown
 * and Saudization), so the assistant and the screen cannot disagree.
 */
class WorkforceToolbox
{
    /** Either one opens the pages, so either one opens the tools. */
    public const PERMISSIONS = ['view-attendance', 'view-vacations'];

    public const TOOLS = ['get_workforce_breakdown', 'get_saudization'];

    /** There are 144 professions; a chat turn does not need the tail. */
    private const GROUPS_MAX = 40;

    public function __construct(private User $user) {}

    public function enabled(): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if ($this->user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function handles(string $name): bool
    {
        return in_array($name, self::TOOLS, true);
    }

    /** The system prompt's rules for these tools; '' for everyone else. */
    public function promptNote(): string
    {
        return $this->enabled() ? (string) __('home_ai.workforce_note') : '';
    }

    /** @return list<array<string,mixed>> OpenAI-shaped function tools; none without the permission */
    public function definitions(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return [
            $this->def('get_workforce_breakdown',
                'How many employees there are, counted by job category, profession or nationality - each group with its head count and share of the total. Counts only: it never names who is in a group. Use it for "how many Saudis / Indians do we have", "how many engineers", "which nationalities work in Jeddah", "what is our biggest profession".',
                [
                    'by' => ['type' => 'string', 'enum' => array_keys(WorkforceBreakdown::DIMENSIONS), 'description' => 'What to count by.'],
                    'branch' => ['type' => 'string', 'description' => 'Optional - only this branch, by its name (e.g. JED, RYD, CAI). Leave out for the whole company.'],
                    'status' => ['type' => 'string', 'enum' => array_keys(WorkforceBreakdown::STATUS), 'description' => 'Optional - "active" (current staff, the default), "left" or "all".'],
                ],
                ['by']),
            $this->def('get_saudization',
                'Where each professional group stands against the Saudization (توطين) percentage it is required to reach: employees, Saudis, the Saudi share, the percentage required now, whether it is compliant, how many more Saudis it needs, and the percentages announced for later with whether today\'s staff already meet them. Also the company-wide Saudi share. Use it for "are we compliant with Saudization", "which groups are below their percentage", "how many Saudis does Sales need".',
                [], []),
        ];
    }

    /** @return array<string,mixed> */
    public function call(string $name, array $args): array
    {
        if (! $this->enabled()) {
            return ['error' => 'This employee may not see workforce figures. They are for people who can open Workforce Breakdown in the NOC.'];
        }

        return match ($name) {
            'get_workforce_breakdown' => $this->breakdown($args),
            'get_saudization' => $this->saudization(),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /** @return array<string,mixed> */
    private function breakdown(array $args): array
    {
        $by = (string) ($args['by'] ?? '');

        if (! isset(WorkforceBreakdown::DIMENSIONS[$by])) {
            return ['error' => 'Unknown way of counting. Use one of: '.implode(', ', array_keys(WorkforceBreakdown::DIMENSIONS)).'.'];
        }

        $status = (string) ($args['status'] ?? 'active');
        if (! isset(WorkforceBreakdown::STATUS[$status])) {
            return ['error' => 'Unknown status. Use one of: '.implode(', ', array_keys(WorkforceBreakdown::STATUS)).'.'];
        }

        $filters = ['status' => $status, 'branch' => null];
        $branchName = trim((string) ($args['branch'] ?? ''));

        if ($branchName !== '') {
            $branches = Branch::query()->orderBy('name')->get(['id', 'name']);
            $branch = $branches->first(fn (Branch $b) => mb_strtolower($b->name) === mb_strtolower($branchName))
                ?? $branches->filter(fn (Branch $b) => str_contains(mb_strtolower($b->name), mb_strtolower($branchName)))->pipe(
                    fn ($found) => $found->count() === 1 ? $found->first() : null
                );

            if (! $branch) {
                return ['error' => "No single branch matches \"{$branchName}\".", 'branches' => $branches->pluck('name')->all()];
            }

            $filters['branch'] = $branch->id;
            $branchName = $branch->name;
        }

        $groups = app(WorkforceBreakdown::class)->groups($by, $filters);
        $shown = $groups->take(self::GROUPS_MAX);

        return [
            'counted_by' => WorkforceBreakdown::DIMENSIONS[$by]['label'],
            'branch' => $branchName !== '' ? $branchName : 'all branches',
            'people' => WorkforceBreakdown::STATUS[$status],
            'total_employees' => (int) $groups->sum('count'),
            'number_of_groups' => $groups->where('named', true)->count(),
            'groups' => $shown->map(fn (array $group) => [
                'name' => $group['label'],
                'employees' => $group['count'],
                'share_percent' => round($group['share'] * 100, 1),
            ])->values()->all(),
            'groups_not_shown' => max(0, $groups->count() - $shown->count()),
            'note' => 'A head count from the job category, profession and nationality Oracle holds for each employee. '
                .'"Not in Oracle\'s employee list" is people Oracle\'s list does not cover (SSS Egypt, and anyone not yet matched), not people without a value. '
                .'Counts only: who is in a group is on Workforce Breakdown in the NOC.',
        ];
    }

    /** @return array<string,mixed> */
    private function saudization(): array
    {
        $report = app(Saudization::class)->report();
        $percent = fn (?float $value) => $value === null ? null : round($value, 1);
        $month = fn ($date) => $date?->format('F Y');

        return [
            'as_of' => now()->toDateString(),
            'company' => [
                'employees_in_oracle_list' => $report['overall']['people'],
                'saudis' => $report['overall']['saudis'],
                'saudi_share_percent' => $percent($report['overall']['share']),
                'no_nationality_on_record' => $report['overall']['unknown'],
            ],
            'groups_compliant' => $report['compliant'],
            'groups_not_compliant' => $report['not_compliant'],
            'groups' => $report['groups']->map(fn (array $row) => array_filter([
                'group' => $row['group']->name_ar,
                'group_english' => $row['group']->name_en,
                'job_category' => $row['group']->job_category,
                'employees' => $row['people'],
                'saudis' => $row['saudis'],
                'saudi_share_percent' => $percent($row['share']),
                'required_percent' => $row['required'],
                'status' => match ($row['compliant']) {
                    true => 'compliant',
                    false => 'not compliant',
                    default => 'nobody in this group',
                },
                'saudis_required_at_this_size' => $row['people'] > 0 ? $row['saudis_required'] : null,
                'more_saudis_needed' => $row['compliant'] === false ? $row['short_by'] : null,
                'announced_for_later' => array_map(fn (array $step) => array_filter([
                    'percent' => $step['percent'],
                    'from' => $month($step['from']),
                    'met_by_todays_staff' => $step['meets'],
                    'more_saudis_needed' => $step['meets'] === false ? $step['short_by'] : null,
                    'month_has_arrived' => $step['arrived'] ?: null,
                ], fn ($value) => $value !== null), $row['announced']) ?: null,
            ], fn ($value) => $value !== null))->values()->all(),
            'counted_by_no_group' => $report['outside']->map(fn (array $count) => [
                'job_category' => $count['job_category'] ?? 'No job category in Oracle',
                'employees' => $count['people'],
                'saudis' => $count['saudis'],
            ])->values()->all(),
            'note' => 'A head count: Saudis divided by everybody in the group, from the nationality and job category Oracle holds for each current employee. '
                .'The ministry\'s own figure on Qiwa weighs people (part-time, salary, the husband or son of a citizen) and may not apply a rule below a number of staff, '
                .'so a group can read differently there. Say so when asked whether the company is officially compliant. '
                .'more_saudis_needed is at the group\'s size today: the Saudis the percentage requires of that many employees, less the Saudis it has. It is not a number of hires - hiring also grows the group. '
                .'The required percentages are edited by HR on Saudization in the NOC.',
        ];
    }

    /** @return array<string,mixed> */
    private function def(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    // An object even when empty: Azure rejects `[]` here (see AssistantToolbox::def).
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
