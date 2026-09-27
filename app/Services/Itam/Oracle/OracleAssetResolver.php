<?php

namespace App\Services\Itam\Oracle;

use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\Itam\OracleAsset;
use Illuminate\Support\Collection;

/**
 * Gives each Oracle unit without a NOC asset its asset:
 *
 *   1. by serial number, for the few descriptions that carry one — whoever
 *      holds it, since a serial names the machine itself;
 *   2. per holder, against their Intune devices (IntuneCandidates →
 *      IntuneAssetMatcher): a match puts the unit on that asset, or creates
 *      the asset from an Intune device nobody had imported;
 *   3. a holder's unit nothing matched gets a new asset of its own, assigned
 *      to them and shown as not in Intune, with the closest candidates kept;
 *   4. a unit whose holder is not in the NOC stays in the register until a
 *      person assigns it to someone or retires it.
 */
class OracleAssetResolver
{
    public function __construct(
        private IntuneCandidates $candidates,
        private IntuneAssetMatcher $matcher,
        private OracleAssetDevices $devices,
    ) {}

    /**
     * @param  iterable<OracleAsset>  $units
     * @return array{linked_intune: int, linked_serial: int, created_devices: int, no_employee: int}
     */
    public function resolve(iterable $units): array
    {
        $counts = ['linked_intune' => 0, 'linked_serial' => 0, 'created_devices' => 0, 'no_employee' => 0];

        $units = collect($units)->filter(fn (OracleAsset $unit) => ! $unit->isResolved() && $unit->removed_at === null)->values();

        if ($units->isEmpty()) {
            return $counts;
        }

        $holders = Employee::query()->whereIn('id', $units->pluck('employee_id')->filter()->unique()->values())->get()->keyBy('id');

        $this->bySerial($units, $holders, $counts);

        $held = $units->filter(fn (OracleAsset $unit) => ! $unit->isResolved() && $unit->employee_id !== null && $holders->has($unit->employee_id))
            ->groupBy('employee_id');

        $candidates = $this->candidates->forEmployees($held->keys()->map(fn ($id) => (int) $id)->all());

        foreach ($held as $employeeId => $group) {
            $holder = $holders->get($employeeId);
            $theirs = $candidates[(int) $employeeId] ?? [];

            $result = $this->matcher->assign(
                $group->mapWithKeys(fn (OracleAsset $unit) => [$unit->id => ComputerFacts::fromOracle($unit->description, $unit->purchase_date)])->all(),
                array_map(fn (array $candidate) => $candidate['facts'], $theirs),
            );

            foreach ($group as $unit) {
                $link = $result['links'][$unit->id] ?? null;

                if ($link !== null) {
                    $candidate = $theirs[$link['candidate']];
                    $evidence = ['reasons' => $link['reasons'], 'points' => $link['points'], 'matched' => $candidate['label']];

                    if ($candidate['device']) {
                        $this->devices->attach($unit, $candidate['device'], $link['method'], $evidence, $holder, $candidate['intune']?->enrolled_date);
                    } else {
                        $this->devices->attachIntune($unit, $candidate['intune'], $link['method'], $evidence, $holder);
                    }

                    $counts['linked_intune']++;

                    continue;
                }

                $suggestions = collect($result['suggestions'][$unit->id] ?? [])
                    ->map(fn (array $suggestion) => [
                        'label' => $theirs[$suggestion['candidate']]['label'],
                        'device_id' => $theirs[$suggestion['candidate']]['device']?->id,
                        'intune_device_id' => $theirs[$suggestion['candidate']]['intune']?->id,
                        'points' => $suggestion['points'],
                        'reasons' => $suggestion['reasons'],
                    ])
                    ->values()
                    ->all();

                $this->devices->createFor($unit, $holder, $suggestions !== [] ? ['suggestions' => $suggestions] : []);
                $counts['created_devices']++;
            }
        }

        $counts['no_employee'] = $units->filter(fn (OracleAsset $unit) => ! $unit->isResolved())->count();

        return $counts;
    }

    /**
     * @param  Collection<int, OracleAsset>  $units
     * @param  Collection<int, Employee>  $holders
     * @param  array<string, int>  $counts
     */
    private function bySerial(Collection $units, Collection $holders, array &$counts): void
    {
        $tokens = $units->mapWithKeys(fn (OracleAsset $unit) => [$unit->id => ComputerFacts::serialTokens($unit->description)])
            ->filter(fn (array $list) => $list !== []);

        if ($tokens->isEmpty()) {
            return;
        }

        $all = $tokens->flatten()->unique()->values()->all();
        $taken = OracleAsset::query()->whereNotNull('device_id')->pluck('device_id')->flip();

        $devices = Device::query()->whereIn('serial_number', $all)->whereNotIn('status', ['retired', 'scrapped'])->get()
            ->reject(fn (Device $device) => isset($taken[$device->id]))
            ->groupBy(fn (Device $device) => strtoupper(trim((string) $device->serial_number)));

        $intunes = AzureDevice::query()->whereIn('serial_number', $all)->whereNull('device_id')->get()
            ->groupBy(fn (AzureDevice $intune) => strtoupper(trim((string) $intune->serial_number)));

        foreach ($units as $unit) {
            $mine = $tokens->get($unit->id, []);

            if ($mine === []) {
                continue;
            }

            $holder = $unit->employee_id ? $holders->get($unit->employee_id) : null;
            $deviceHits = collect($mine)->flatMap(fn (string $token) => $devices->get($token, collect()))->unique('id')->values();

            if ($deviceHits->count() === 1) {
                $device = $deviceHits->first();
                // The serial names the machine. If the NOC already has it assigned, that assignment stands;
                // whether the NOC and Oracle agree on who holds it is for a person to look at.
                $alreadyHeld = EmployeeAsset::query()->where('asset_id', $device->id)->whereNull('returned_date')->exists();

                $this->devices->attach($unit, $device, IntuneAssetMatcher::METHOD_SERIAL, ['reasons' => ['same serial number'], 'matched' => $device->asset_code.' · '.$device->name],
                    $alreadyHeld ? null : $holder);
                $devices = $devices->map(fn (Collection $group) => $group->reject(fn (Device $d) => $d->id === $device->id));
                $counts['linked_intune']++;
                $counts['linked_serial']++;

                continue;
            }

            $intuneHits = collect($mine)->flatMap(fn (string $token) => $intunes->get($token, collect()))->unique('id')->values();

            if ($deviceHits->isEmpty() && $intuneHits->count() === 1 && $holder && $intuneHits->first()->device_id === null) {
                $intune = $intuneHits->first();

                $this->devices->attachIntune($unit, $intune, IntuneAssetMatcher::METHOD_SERIAL, ['reasons' => ['same serial number'], 'matched' => 'Intune '.$intune->display_name], $holder);
                $counts['linked_intune']++;
                $counts['linked_serial']++;
            }
        }
    }
}
