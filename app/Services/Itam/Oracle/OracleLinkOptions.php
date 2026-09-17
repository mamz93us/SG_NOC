<?php

namespace App\Services\Itam\Oracle;

use App\Models\Device;
use App\Models\Employee;
use App\Models\Itam\OracleAsset;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Oracle units an asset can be linked to, for the "Link Oracle asset"
 * dialog on an Intune laptop that has no Oracle number. Only units still
 * waiting for a device are offered, in three groups:
 *
 *   1. held in Oracle by this asset's holder (any of their accounts): their
 *      "Not in Intune" assets and any unit with no asset;
 *   2. held by someone the NOC does not have — no asset yet;
 *   3. other people's "Not in Intune" assets: a laptop Oracle still lists
 *      under the person who had it before.
 *
 * Within a group, units that could be this machine come first
 * (IntuneAssetMatcher's score against the asset's Intune facts); one that
 * cannot — another brand, model or form — is marked, not hidden, because a
 * person choosing by hand may know better than the description.
 */
class OracleLinkOptions
{
    public function __construct(private IntuneAssetMatcher $matcher) {}

    /**
     * @return list<array{label: string, options: list<array{id: int, label: string}>}>
     */
    public function forDevice(Device $device): array
    {
        $device->loadMissing(['azureDevice', 'currentAssignment.employee']);

        $holder = $device->currentAssignment?->employee;
        $personIds = [];

        if ($holder) {
            $main = (int) ($holder->linked_primary_employee_id ?: $holder->id);
            $personIds = Employee::query()->whereKey($main)->orWhere('linked_primary_employee_id', $main)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $intune = $device->azureDevice;
        $facts = ComputerFacts::fromIntune(
            $intune?->manufacturer ?: $device->manufacturer,
            $intune?->model ?: $device->model,
            $intune?->cpu_name,
            $device->serial_number ?: $intune?->serial_number,
            $intune?->enrolled_date,
        );

        $units = OracleAsset::query()
            ->listed()
            ->where(fn (Builder $q) => $q
                ->whereNull('device_match')
                ->orWhereHas('device', fn (Builder $d) => $d
                    ->where('source', 'oracle')
                    ->whereNotIn('status', ['retired', 'scrapped'])
                    ->whereDoesntHave('azureDevice', fn (Builder $a) => $a->where('link_status', 'linked'))))
            ->with('device:id,asset_code,name,source,status')
            ->get();

        $groups = ['mine' => [], 'unheld' => [], 'others' => []];

        foreach ($units as $unit) {
            $score = $this->matcher->score(ComputerFacts::fromOracle($unit->description, $unit->purchase_date), $facts);
            $group = in_array((int) $unit->employee_id, $personIds, true) ? 'mine' : ($unit->device ? 'others' : 'unheld');

            $groups[$group][] = [
                'id' => (int) $unit->id,
                'label' => $this->label($unit, $score === null),
                'rank' => [$score === null ? 0 : 1, $score['points'] ?? 0, $unit->purchase_date?->timestamp ?? 0],
            ];
        }

        $sorted = array_map(function (array $options) {
            usort($options, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

            return array_map(fn (array $option) => ['id' => $option['id'], 'label' => $option['label']], $options);
        }, $groups);

        return [
            ['label' => $holder ? "Held in Oracle by {$holder->name}" : 'Held in Oracle by this asset\'s holder', 'options' => $sorted['mine']],
            ['label' => 'Held in Oracle by someone not in the NOC', 'options' => $sorted['unheld']],
            ['label' => 'Other people\'s assets not in Intune', 'options' => $sorted['others']],
        ];
    }

    private function label(OracleAsset $unit, bool $otherModel): string
    {
        return implode(' · ', array_filter([
            $unit->asset_number.($unit->unit > 1 ? " (unit {$unit->unit})" : ''),
            mb_strimwidth($unit->description, 0, 70, '…'),
            $unit->purchase_date ? 'bought '.$unit->purchase_date->format('M Y') : null,
            trim("Oracle #{$unit->emp_no} {$unit->emp_name}"),
            $unit->device ? 'now '.($unit->device->asset_code ?: $unit->device->name) : null,
        ])).($otherModel ? ' — a different model' : '');
    }
}
