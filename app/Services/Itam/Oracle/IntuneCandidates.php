<?php

namespace App\Services\Itam\Oracle;

use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\IdentityUser;
use App\Models\Itam\OracleAsset;
use Illuminate\Support\Collection;

/**
 * The computers each person could be holding, for IntuneAssetMatcher.
 *
 * A person's candidates are:
 *   - the laptops and desktops assigned to them in the NOC (most came from
 *     Intune through the Azure import), and
 *   - the Intune devices enrolled under any of their accounts — the main
 *     record's mailbox, a linked second mailbox, and each account's UPN —
 *     that no NOC asset is linked to yet.
 *
 * Left out: an asset another Oracle unit already holds, a retired or scrapped
 * one, one assigned to somebody else, and an unlinked Intune device whose
 * serial the NOC already has on an asset (that device is that asset, wherever
 * it is). Phones, tablets and printers are not candidates.
 *
 * Everything is read in a handful of queries for all the people at once.
 */
class IntuneCandidates
{
    private const COMPUTER_TYPES = ['laptop', 'desktop'];

    private const NOT_COMPUTERS = ['iOS', 'iPadOS', 'Android', 'Printer'];

    /**
     * Per main employee record id, candidates keyed "device:{id}" or "intune:{id}".
     *
     * @param  list<int>  $employeeIds  main employee records
     * @return array<int, array<string, array{facts: ComputerFacts, device: ?Device, intune: ?AzureDevice, label: string}>>
     */
    public function forEmployees(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'email', 'azure_id']);
        $secondaries = Employee::query()->whereIn('linked_primary_employee_id', $employeeIds)->get(['id', 'email', 'azure_id', 'linked_primary_employee_id']);

        /** @var array<int, int> $personOf any account's employee id => main record id */
        $personOf = [];
        /** @var array<string, int> $addressOf lower-case address => main record id */
        $addressOf = [];
        /** @var array<string, int> $azureOf azure object id => main record id */
        $azureOf = [];

        foreach ($employees as $employee) {
            $this->note((int) $employee->id, $employee, $personOf, $addressOf, $azureOf);
        }
        foreach ($secondaries as $employee) {
            $this->note((int) $employee->linked_primary_employee_id, $employee, $personOf, $addressOf, $azureOf);
        }

        if ($azureOf !== []) {
            IdentityUser::query()->whereIn('azure_id', array_keys($azureOf))->get(['azure_id', 'user_principal_name', 'mail'])
                ->each(function (IdentityUser $user) use ($azureOf, &$addressOf) {
                    foreach ([$user->user_principal_name, $user->mail] as $address) {
                        $address = strtolower(trim((string) $address));
                        if ($address !== '') {
                            $addressOf[$address] ??= $azureOf[$user->azure_id];
                        }
                    }
                });
        }

        $held = EmployeeAsset::query()->whereNull('returned_date')->whereIn('employee_id', array_keys($personOf))->get(['employee_id', 'asset_id']);

        $intunes = AzureDevice::query()
            ->whereNotNull('upn')->where('upn', '!=', '')
            ->where(fn ($q) => $q->whereNull('os')->orWhereNotIn('os', self::NOT_COMPUTERS))
            ->get()
            ->filter(fn (AzureDevice $intune) => isset($addressOf[strtolower(trim((string) $intune->upn))]));

        $deviceIds = $held->pluck('asset_id')->merge($intunes->pluck('device_id'))->filter()->unique()->values();

        $devices = Device::query()->whereIn('id', $deviceIds)->get()->keyBy('id');
        $openByDevice = EmployeeAsset::query()->whereNull('returned_date')->whereIn('asset_id', $deviceIds)->get(['asset_id', 'employee_id'])->groupBy('asset_id');
        $intuneByDevice = AzureDevice::query()->whereIn('device_id', $deviceIds)->orderByDesc('last_activity_at')->get()->groupBy('device_id');
        $taken = OracleAsset::query()->whereNotNull('device_id')->pluck('device_id')->flip();

        $candidates = [];

        foreach ($devices as $device) {
            if (isset($taken[$device->id]) || ! in_array($device->type, self::COMPUTER_TYPES, true) || in_array($device->status, ['retired', 'scrapped'], true)) {
                continue;
            }

            $intune = $this->bestIntune($intuneByDevice->get($device->id, collect()));
            $open = $openByDevice->get($device->id, collect());

            if ($open->isNotEmpty()) {
                $owners = $open->map(fn ($assignment) => $personOf[(int) $assignment->employee_id] ?? null)->unique();
                // Assigned to someone outside this batch, or to two people: not a candidate for anyone here.
                if ($owners->count() !== 1 || $owners->first() === null) {
                    continue;
                }
                $owner = (int) $owners->first();
            } else {
                $owner = $intune ? ($addressOf[strtolower(trim((string) $intune->upn))] ?? null) : null;
                if ($owner === null) {
                    continue;
                }
            }

            $candidates[$owner]['device:'.$device->id] = [
                'facts' => ComputerFacts::fromIntune(
                    $intune?->manufacturer ?: $device->manufacturer,
                    $intune?->model ?: $device->model,
                    $intune?->cpu_name,
                    $device->serial_number ?: $intune?->serial_number,
                    $intune?->enrolled_date,
                ),
                'device' => $device,
                'intune' => $intune,
                'label' => $this->label($device, $intune),
            ];
        }

        // Intune devices no asset is linked to yet. One pointing at an asset that was deleted is
        // left for the Azure page, which heals that link, rather than guessed at here.
        $unlinked = $intunes->filter(fn (AzureDevice $intune) => $intune->device_id === null);
        $knownSerials = Device::query()
            ->whereIn('serial_number', $unlinked->pluck('serial_number')->filter()->unique()->values())
            ->pluck('serial_number')
            ->map(fn ($serial) => strtoupper(trim((string) $serial)))
            ->flip();

        foreach ($unlinked->sortByDesc('last_activity_at') as $intune) {
            $owner = $addressOf[strtolower(trim((string) $intune->upn))];
            $serial = ComputerFacts::cleanSerial($intune->serial_number);

            if ($serial !== null && isset($knownSerials[$serial])) {
                continue;
            }
            if ($serial !== null && collect($candidates[$owner] ?? [])->contains(fn ($candidate) => in_array($serial, $candidate['facts']->serials, true))) {
                continue; // an older enrollment of a machine already listed
            }

            $candidates[$owner]['intune:'.$intune->id] = [
                'facts' => ComputerFacts::fromIntune($intune->manufacturer, $intune->model, $intune->cpu_name, $intune->serial_number, $intune->enrolled_date),
                'device' => null,
                'intune' => $intune,
                'label' => $this->label(null, $intune),
            ];
        }

        return $candidates;
    }

    /**
     * Intune computers no asset is linked to: the "other Intune devices" a
     * person can pick from when an asset is enrolled under an account that is
     * not its holder's.
     *
     * @return Collection<int, AzureDevice>
     */
    public function unlinkedComputers(): Collection
    {
        return AzureDevice::query()
            ->whereNull('device_id')
            ->where(fn ($q) => $q->whereNull('os')->orWhereNotIn('os', self::NOT_COMPUTERS))
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'manufacturer', 'model', 'serial_number', 'upn', 'enrolled_date']);
    }

    /**
     * @param  array<int, int>  $personOf
     * @param  array<string, int>  $addressOf
     * @param  array<string, int>  $azureOf
     */
    private function note(int $main, Employee $employee, array &$personOf, array &$addressOf, array &$azureOf): void
    {
        $personOf[(int) $employee->id] = $main;

        $email = strtolower(trim((string) $employee->email));
        if ($email !== '') {
            $addressOf[$email] ??= $main;
        }
        if (! empty($employee->azure_id)) {
            $azureOf[$employee->azure_id] ??= $main;
        }
    }

    /** @param  Collection<int, AzureDevice>  $intunes */
    private function bestIntune(Collection $intunes): ?AzureDevice
    {
        return $intunes->first(fn (AzureDevice $i) => $i->isInIntune())
            ?? $intunes->firstWhere('link_status', 'linked')
            ?? $intunes->first();
    }

    private function label(?Device $device, ?AzureDevice $intune): string
    {
        $model = trim(($intune?->manufacturer ?: $device?->manufacturer).' '.($intune?->model ?: $device?->model));

        return implode(' · ', array_filter([
            $device?->asset_code,
            $intune?->display_name ?: $device?->name,
            $model !== '' ? $model : null,
            ($serial = $device?->serial_number ?: $intune?->serial_number) ? 'SN '.$serial : null,
            $intune?->enrolled_date ? 'enrolled '.$intune->enrolled_date->format('M Y') : null,
        ]));
    }
}
