<?php

namespace App\Services\Itam\Oracle;

use App\Models\AccessoryAssignment;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\Itam\OracleAsset;
use App\Models\LicenseAssignment;
use App\Services\AssetCodeService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Str;

/**
 * Everything the Oracle register does to NOC assets, in one place:
 *
 * - attach: a unit is an asset the NOC already has (an Intune laptop). The
 *   asset keeps its code, name and serial; it gains Oracle's asset number, the
 *   real purchase date (the Intune import had put the enrollment date there)
 *   and Oracle's life as its depreciation years. What it held before is kept
 *   in the unit's evidence, so undoing a wrong match puts it back.
 * - attachIntune: the unit is an Intune device nobody imported yet — the
 *   asset is created from Intune the way the Azure import does, then attached.
 * - createFor: nothing matched — a new asset from Oracle's description,
 *   assigned to the holder, source `oracle`, flagged as not in Intune.
 * - retireUnheld: a unit whose holder is not in the NOC, written off — its
 *   asset is created already retired.
 * - linkIntune: a person says which Intune device an asset is. An asset the
 *   import created is merged into an Intune device that already has one.
 * - linkOracle: the same decision started from the other side — an Intune
 *   laptop with no Oracle number is told which Oracle unit it is.
 * - unmatch: a person undoes an automatic match.
 */
class OracleAssetDevices
{
    public function attach(OracleAsset $unit, Device $device, string $method, array $evidence, ?Employee $holder, ?CarbonInterface $assignedOn = null): void
    {
        $previous = [
            'oracle_asset_number' => $device->oracle_asset_number,
            'purchase_date' => $device->purchase_date?->toDateString(),
            'type' => $device->type,
            'depreciation_method' => $device->depreciation_method,
            'depreciation_years' => $device->depreciation_years,
        ];

        $device->oracle_asset_number = $device->oracle_asset_number ?: $unit->asset_number;

        if ($unit->purchase_date) {
            $device->purchase_date = $unit->purchase_date;
        }
        if ($years = $unit->lifeYears()) {
            $device->depreciation_method = 'straight_line';
            $device->depreciation_years = $years;
        }
        // The Azure import made every Intune device a laptop.
        if ($unit->category === OracleAsset::CATEGORY_DESKTOP && $device->type === 'laptop' && $method !== IntuneAssetMatcher::METHOD_ONLY_PAIR) {
            $device->type = 'desktop';
        }

        $device->save();

        $assignmentId = null;

        if ($holder && ! EmployeeAsset::where('asset_id', $device->id)->whereNull('returned_date')->exists()) {
            $assignmentId = $this->assign($device, $holder, $assignedOn ?? $unit->purchase_date, "Oracle asset {$unit->asset_number}");
        }

        AssetHistory::record($device, 'note_added', "Oracle asset {$unit->asset_number} linked (".(OracleAsset::DEVICE_MATCH_LABELS[$method] ?? $method).')'
            .(! empty($evidence['reasons']) ? ': '.implode(', ', $evidence['reasons']) : '').'.', [
                'oracle_asset_id' => $unit->id,
                'method' => $method,
            ]);

        $unit->forceFill([
            'device_id' => $device->id,
            'device_match' => $method,
            'match_evidence' => array_filter($evidence + ['previous' => $previous, 'assignment_id' => $assignmentId], fn ($value) => $value !== null && $value !== []),
        ])->save();
    }

    public function attachIntune(OracleAsset $unit, AzureDevice $intune, string $method, array $evidence, ?Employee $holder): Device
    {
        $device = Device::query()->where('source', 'azure')->where('source_id', $intune->azure_device_id)->first();

        if (! $device) {
            $type = $unit->deviceType();

            $device = Device::create([
                'type' => $type,
                'name' => $intune->display_name ?: Str::limit($unit->description, 250, ''),
                'manufacturer' => $intune->manufacturer,
                'model' => $intune->model,
                'serial_number' => $intune->serial_number,
                'asset_code' => app(AssetCodeService::class)->generate($type),
                'status' => 'active',
                'condition' => 'used',
                'source' => 'azure',
                'source_id' => $intune->azure_device_id,
                'branch_id' => $holder?->branch_id,
                'notes' => 'Created from Intune for Oracle asset '.$unit->asset_number.' on '.now()->toDateString().'.',
            ]);

            AssetHistory::record($device, 'created', "Created from Intune device {$intune->display_name} for Oracle asset {$unit->asset_number}.");
        }

        $intune->forceFill(['device_id' => $device->id, 'link_status' => 'linked'])->save();

        $this->attach($unit, $device, $method, $evidence + ['intune_device_id' => $intune->id], $holder, $intune->enrolled_date);

        return $device;
    }

    public function createFor(OracleAsset $unit, Employee $holder, array $evidence = []): Device
    {
        $device = $this->newAsset($unit, 'assigned', $holder->branch_id);

        AssetHistory::record($device, 'created', "Created from Oracle asset {$unit->asset_number}: nothing in Intune matched it.");

        $assignmentId = $this->assign($device, $holder, $unit->purchase_date, "Oracle asset {$unit->asset_number}");

        $unit->forceFill([
            'device_id' => $device->id,
            'device_match' => OracleAsset::DEVICE_CREATED,
            'match_evidence' => array_filter($evidence + ['assignment_id' => $assignmentId]) ?: null,
        ])->save();

        return $device;
    }

    public function retireUnheld(OracleAsset $unit, string $reason, CarbonInterface $on, ?int $userId): Device
    {
        if ($unit->isResolved()) {
            throw new DomainException("Oracle asset {$unit->asset_number} already has a NOC asset.");
        }

        $device = $this->newAsset($unit, 'retired', null);

        AssetHistory::record($device, 'created', "Created from Oracle asset {$unit->asset_number}, held in Oracle by #{$unit->emp_no} {$unit->emp_name}, who is not in the NOC.");
        AssetHistory::record($device, 'retired', 'Retired: '.$reason, ['retired_on' => $on->toDateString()]);

        $unit->forceFill([
            'device_id' => $device->id,
            'device_match' => OracleAsset::DEVICE_CREATED,
            'decided_by' => $userId,
            'decided_at' => now(),
        ])->save();

        return $device;
    }

    /** @return Device the asset the Oracle units are on afterwards */
    public function linkIntune(Device $device, AzureDevice $intune, ?int $userId): Device
    {
        $code = $device->asset_code ?: $device->name;

        if (in_array($device->status, ['retired', 'scrapped'], true)) {
            throw new DomainException("{$code} is {$device->status}; a retired asset is not linked to Intune.");
        }

        if ((int) $intune->device_id === (int) $device->id) {
            if ($intune->link_status !== 'linked') {
                $intune->forceFill(['link_status' => 'linked'])->save();
            }

            return $device;
        }

        $other = $intune->device_id ? Device::find($intune->device_id) : null;

        if ($other) {
            return $this->mergeInto($device, $other, $intune, $userId);
        }

        $others = AzureDevice::where('device_id', $device->id)->where('id', '!=', $intune->id)->get();

        if ($linked = $others->firstWhere('link_status', 'linked')) {
            throw new DomainException("{$code} is already linked to the Intune device {$linked->display_name}.");
        }

        // A pending or rejected suggestion that pointed at this asset is superseded by the person's choice.
        $others->each(fn (AzureDevice $stale) => $stale->forceFill(['device_id' => null, 'link_status' => 'unlinked'])->save());

        $intune->forceFill(['device_id' => $device->id, 'link_status' => 'linked'])->save();

        $device->serial_number = $device->serial_number ?: $intune->serial_number;
        $device->model = $device->model ?: $intune->model;
        if ($intune->manufacturer) {
            $device->manufacturer = $intune->manufacturer;
        }
        $device->save();

        OracleAsset::where('device_id', $device->id)->get()->each(fn (OracleAsset $unit) => $unit->forceFill([
            'device_match' => OracleAsset::DEVICE_MANUAL,
            'decided_by' => $userId,
            'decided_at' => now(),
        ])->save());

        AssetHistory::record($device, 'note_added', "Linked to Intune device {$intune->display_name}".($intune->serial_number ? " (serial {$intune->serial_number})" : '').'.');

        return $device;
    }

    /**
     * Undoes an automatic match. The asset gets back what it held before; the
     * unit gets an asset of its own again, or waits in the register when its
     * holder is not in the NOC.
     */
    public function unmatch(OracleAsset $unit, ?int $userId): ?Device
    {
        $device = $unit->device;

        if (! $device || $unit->device_match === OracleAsset::DEVICE_CREATED) {
            throw new DomainException("Oracle asset {$unit->asset_number} is not matched to an Intune device.");
        }

        // An asset the import created, which a person then linked to an Intune device: undo that link.
        if ($device->source === 'oracle') {
            AzureDevice::where('device_id', $device->id)->update(['device_id' => null, 'link_status' => 'unlinked']);
            AssetHistory::record($device, 'note_added', 'Unlinked from its Intune device.');
            $unit->forceFill(['device_match' => OracleAsset::DEVICE_CREATED, 'decided_by' => $userId, 'decided_at' => now()])->save();

            return $device;
        }

        $evidence = (array) $unit->match_evidence;
        $previous = (array) ($evidence['previous'] ?? []);

        if (! OracleAsset::where('device_id', $device->id)->whereKeyNot($unit->id)->exists()) {
            $device->oracle_asset_number = $previous['oracle_asset_number'] ?? null;
        }
        // Put back only what still holds the value the match wrote; a later edit by a person stays.
        if (array_key_exists('purchase_date', $previous) && $unit->purchase_date
            && $device->purchase_date?->toDateString() === $unit->purchase_date->toDateString()) {
            $device->purchase_date = $previous['purchase_date'];
        }
        if (($previous['type'] ?? null) === 'laptop' && $device->type === 'desktop' && $unit->category === OracleAsset::CATEGORY_DESKTOP) {
            $device->type = 'laptop';
        }
        if (array_key_exists('depreciation_years', $previous) && $unit->lifeYears() !== null
            && (int) $device->depreciation_years === $unit->lifeYears()) {
            $device->depreciation_years = $previous['depreciation_years'];
            $device->depreciation_method = $previous['depreciation_method'] ?? $device->depreciation_method;
        }
        $device->save();

        // An assignment the match made, still open, was made on a wrong premise.
        if (! empty($evidence['assignment_id'])) {
            EmployeeAsset::whereKey($evidence['assignment_id'])->whereNull('returned_date')->get()
                ->each(fn (EmployeeAsset $assignment) => $assignment->update([
                    'returned_date' => now()->toDateString(),
                    'notes' => trim(($assignment->notes ? $assignment->notes.' — ' : '').'Closed: the Oracle match that made it was undone.'),
                ]));
        }

        AssetHistory::record($device, 'note_added', "Oracle asset {$unit->asset_number} unlinked: it is not this device.");

        $unit->forceFill(['device_id' => null, 'device_match' => null, 'match_evidence' => null, 'decided_by' => $userId, 'decided_at' => now()])->save();

        $holder = $unit->employee;

        if (! $holder) {
            return null;
        }

        $created = $this->createFor($unit, $holder);
        $unit->forceFill(['decided_by' => $userId, 'decided_at' => now()])->save();

        return $created;
    }

    /**
     * A person says which Oracle unit an asset is, starting from the asset —
     * typically an Intune laptop already assigned to someone, which the import
     * could not match. The unit's own asset, if the import created one ("Not
     * in Intune"), is merged into this one and deleted; a unit with no asset
     * yet is simply put on it. The asset keeps its code, name and holder: the
     * NOC's assignment is who has it now, even where Oracle still lists the
     * unit under somebody else, and the history says so.
     *
     * @return Device the asset the unit is on afterwards (always $device)
     */
    public function linkOracle(Device $device, OracleAsset $unit, ?int $userId): Device
    {
        $code = $device->asset_code ?: $device->name;

        if (in_array($device->status, ['retired', 'scrapped'], true)) {
            throw new DomainException("{$code} is {$device->status}; a retired asset is not linked to Oracle.");
        }
        if ($unit->removed_at !== null) {
            throw new DomainException("Oracle asset {$unit->asset_number} is no longer in Oracle's register.");
        }

        if ($existing = OracleAsset::where('device_id', $device->id)->first()) {
            if ((int) $existing->id === (int) $unit->id) {
                return $device;
            }

            throw new DomainException("{$code} is already Oracle asset {$existing->asset_number}.");
        }

        $current = $unit->device;

        if ($current) {
            if ($current->source !== 'oracle') {
                throw new DomainException("Oracle asset {$unit->asset_number} is already ".($current->asset_code ?: $current->name)
                    .'. Undo that match on the Oracle Asset Register first.');
            }

            return $this->mergeInto($current, $device, $device->azureDevice, $userId, true);
        }

        if ($unit->isResolved()) {
            throw new DomainException("Oracle asset {$unit->asset_number} had an asset that was deleted; set it again from the Oracle Asset Register.");
        }

        $this->attach($unit, $device, OracleAsset::DEVICE_MANUAL, array_filter([
            'reasons' => ['linked by hand from the asset'],
            'intune_device_id' => $device->azureDevice?->id,
        ]), null);

        $unit->forceFill(['decided_by' => $userId, 'decided_at' => now()])->save();

        return $device;
    }

    /**
     * @param  bool  $otherHolderAllowed  true when a person started from the asset and chose the unit: the asset's
     *                                    current holder stands even if Oracle lists the unit under someone else
     */
    private function mergeInto(Device $created, Device $intuneAsset, ?AzureDevice $intune, ?int $userId, bool $otherHolderAllowed = false): Device
    {
        $code = $created->asset_code ?: $created->name;
        $otherCode = $intuneAsset->asset_code ?: $intuneAsset->name;

        if ($created->source !== 'oracle') {
            throw new DomainException("That Intune device is already the asset {$otherCode}.");
        }
        if (in_array($intuneAsset->status, ['retired', 'scrapped'], true)) {
            throw new DomainException("That Intune device is the asset {$otherCode}, which is {$intuneAsset->status}.");
        }
        if (OracleAsset::where('device_id', $intuneAsset->id)->exists()) {
            throw new DomainException("{$otherCode} already carries Oracle asset {$intuneAsset->oracle_asset_number}.");
        }

        $createdHolder = EmployeeAsset::with('employee')->where('asset_id', $created->id)->whereNull('returned_date')->first();
        $intuneHolder = EmployeeAsset::with('employee')->where('asset_id', $intuneAsset->id)->whereNull('returned_date')->first();
        $otherHolder = $createdHolder?->employee && $intuneHolder?->employee && ! $this->samePerson($createdHolder->employee, $intuneHolder->employee);

        if ($otherHolder && ! $otherHolderAllowed) {
            throw new DomainException("{$otherCode} is assigned to {$intuneHolder->employee->name}, not {$createdHolder->employee->name}.");
        }
        if (LicenseAssignment::where('assignable_type', Device::class)->where('assignable_id', $created->id)->exists()
            || AccessoryAssignment::where('device_id', $created->id)->whereNull('returned_date')->exists()) {
            throw new DomainException("{$code} has licences or accessories assigned; move them before linking.");
        }

        $units = OracleAsset::where('device_id', $created->id)->get();

        foreach ($units as $unit) {
            $this->attach($unit, $intuneAsset, OracleAsset::DEVICE_MANUAL, array_filter(['merged_from' => $code, 'intune_device_id' => $intune?->id]),
                $intuneHolder ? null : $createdHolder?->employee, $createdHolder?->assigned_date);
            $unit->forceFill(['decided_by' => $userId, 'decided_at' => now()])->save();
        }

        if ($intune && $intune->link_status !== 'linked') {
            $intune->forceFill(['link_status' => 'linked'])->save();
        }

        AssetHistory::record($intuneAsset, 'note_added', "Took over Oracle asset {$intuneAsset->oracle_asset_number} from {$code}, which the Oracle import had created for it; {$code} was deleted."
            .($otherHolder ? " Oracle lists it under {$createdHolder->employee->name}; it stays with {$intuneHolder->employee->name}." : ''));

        ActivityLog::create([
            'model_type' => Device::class,
            'model_id' => $intuneAsset->id,
            'model_label' => $otherCode,
            'action' => 'oracle_asset_merged',
            'changes' => ['deleted' => $created->only(['id', 'asset_code', 'name', 'oracle_asset_number', 'purchase_date', 'source_id']), 'units' => $units->pluck('id')->all()],
            'user_id' => $userId,
        ]);

        $created->delete();

        return $intuneAsset;
    }

    private function newAsset(OracleAsset $unit, string $status, ?int $branchId): Device
    {
        $type = $unit->deviceType();
        $years = $unit->lifeYears();

        return Device::create([
            'type' => $type,
            'name' => Str::limit($unit->description, 250, ''),
            'manufacturer' => ComputerFacts::brandLabel(ComputerFacts::brandOfDescription($unit->description)),
            'asset_code' => app(AssetCodeService::class)->generate($type),
            'oracle_asset_number' => $unit->asset_number,
            'status' => $status,
            'condition' => 'used',
            'source' => 'oracle',
            'source_id' => 'oracle:'.$unit->key(),
            'branch_id' => $branchId,
            'purchase_date' => $unit->purchase_date,
            'depreciation_method' => $years ? 'straight_line' : 'none',
            'depreciation_years' => $years,
            'notes' => "From Oracle's asset register: asset {$unit->asset_number}, held by #{$unit->emp_no} {$unit->emp_name}.",
        ]);
    }

    private function assign(Device $device, Employee $holder, ?CarbonInterface $on, string $note): int
    {
        $assignment = EmployeeAsset::create([
            'employee_id' => $holder->id,
            'asset_id' => $device->id,
            'assigned_date' => ($on ?? now())->toDateString(),
            'condition' => 'used',
            'notes' => $note,
        ]);

        if (! in_array($device->status, ['retired', 'scrapped'], true) && $device->status !== 'assigned') {
            $device->update(['status' => 'assigned']);
        }

        AssetHistory::record($device, 'assigned', "Assigned to {$holder->name} from the Oracle asset register.");

        return (int) $assignment->id;
    }

    private function samePerson(Employee $a, Employee $b): bool
    {
        return (int) ($a->linked_primary_employee_id ?: $a->id) === (int) ($b->linked_primary_employee_id ?: $b->id);
    }
}
