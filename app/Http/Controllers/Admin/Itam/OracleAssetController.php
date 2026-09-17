<?php

namespace App\Http\Controllers\Admin\Itam;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Itam\OracleAsset;
use App\Models\Itam\OracleAssetImport;
use App\Services\Itam\AssetRetirement;
use App\Services\Itam\Oracle\IntuneCandidates;
use App\Services\Itam\Oracle\OracleAssetDevices;
use App\Services\Itam\Oracle\OracleAssetImporter;
use App\Services\Itam\Oracle\OracleAssetResolver;
use App\Services\Itam\Oracle\OracleAssetSheetReader;
use App\Services\Itam\PendingScrapRequests;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * ITAM → Oracle Asset Register: the laptops and desktops in Oracle's
 * fixed-asset register, each with the NOC employee and asset it is, and
 * whether that asset is an Intune device.
 *
 * Every laptop has to be in Intune, so "Not in Intune" is the list to work
 * through: link each to its Intune device, or retire or scrap it. A unit whose
 * holder is not in the NOC waits here to be assigned to someone or retired.
 *
 * The import runs in the request — a whole company's register is under a
 * thousand rows, read and matched in seconds — and the file is not kept.
 */
class OracleAssetController extends Controller
{
    public const STATES = [
        'all' => 'All in Oracle',
        'not_in_intune' => 'Not in Intune',
        'in_intune' => 'In Intune',
        'no_holder' => 'Holder not in the NOC',
        'name_match' => 'Holder matched by name',
        'retired' => 'Retired or scrapped',
        'asset_deleted' => 'NOC asset deleted',
        'removed' => 'No longer in Oracle',
    ];

    public function index(Request $request, IntuneCandidates $candidates, PendingScrapRequests $scrapRequests): View
    {
        $state = array_key_exists((string) $request->query('state'), self::STATES) ? (string) $request->query('state') : 'all';

        $query = OracleAsset::query()->with([
            'employee:id,name,branch_id,status,email,oracle_emp_no',
            'employee.branch:id,name',
            'device:id,asset_code,name,status,type,serial_number,oracle_asset_number',
            'device.azureDevice:id,device_id,display_name,link_status,serial_number',
        ]);

        $this->applyState($query, $state);

        if ($request->filled('category') && in_array($request->query('category'), OracleAsset::CATEGORIES, true)) {
            $query->where('category', $request->query('category'));
        }
        if ($request->filled('branch')) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('branch_id', (int) $request->query('branch')));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(fn (Builder $q) => $q
                ->where('asset_number', $term)
                ->orWhere('emp_no', $term)
                ->orWhere('description', 'like', '%'.$term.'%')
                ->orWhere('emp_name', 'like', '%'.$term.'%')
                ->orWhereHas('employee', fn (Builder $e) => $e->where('name', 'like', '%'.$term.'%'))
                ->orWhereHas('device', fn (Builder $d) => $d->where('asset_code', 'like', '%'.$term.'%')->orWhere('serial_number', $term)));
        }

        $units = $query->orderBy('emp_name')->orderBy('asset_number')->orderBy('unit')->paginate(50)->withQueryString();

        $counts = [];
        foreach (array_keys(self::STATES) as $key) {
            $counter = OracleAsset::query();
            $this->applyState($counter, $key);
            $counts[$key] = $counter->count();
        }

        // The holders' Intune devices, for the link dialog.
        $holderIds = $units->getCollection()
            ->filter(fn (OracleAsset $unit) => $unit->employee_id && $unit->device && ! $unit->device->azureDevice)
            ->pluck('employee_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $holderIntune = [];
        foreach ($candidates->forEmployees($holderIds) as $employeeId => $list) {
            foreach ($list as $candidate) {
                if ($candidate['intune']) {
                    $holderIntune[$employeeId][] = ['id' => $candidate['intune']->id, 'label' => $candidate['label']];
                }
            }
        }

        $pendingScrap = $scrapRequests->forDevices($units->getCollection()->pluck('device_id')->filter()->all());

        $needsEmployees = $units->getCollection()->contains(fn (OracleAsset $unit) => ! $unit->isResolved());

        return view('admin.itam.oracle-assets.index', [
            'units' => $units,
            'state' => $state,
            'states' => self::STATES,
            'counts' => $counts,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'imports' => OracleAssetImport::with('importer:id,name')->latest('id')->limit(10)->get(),
            'holderIntune' => $holderIntune,
            'unlinkedIntune' => $candidates->unlinkedComputers(),
            'pendingScrap' => $pendingScrap,
            'employees' => $needsEmployees
                ? Employee::query()->whereNull('linked_primary_employee_id')->where('status', 'active')->with('branch:id,name')->orderBy('name')->get(['id', 'name', 'oracle_emp_no', 'branch_id'])
                : collect(),
        ]);
    }

    public function import(Request $request, OracleAssetSheetReader $reader, OracleAssetImporter $importer): RedirectResponse
    {
        $request->validate([
            // By extension: fileinfo calls some Oracle .xls files "CDFV2", which no mimes rule knows.
            'file' => ['required', 'file', 'extensions:xls,xlsx,csv', 'max:'.(int) config('oracle_assets.max_upload_kb', 20480)],
            'dry_run' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'Choose the register file exported from Oracle.',
        ]);

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $dryRun = $request->boolean('dry_run');

        try {
            $rows = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
            $import = $importer->import($rows, $name, Auth::id(), $dryRun);
        } catch (RuntimeException $e) {
            return back()->with('error', "{$name}: {$e->getMessage()}");
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "{$name} could not be imported ({$e->getMessage()}).");
        }

        $notes = count($import->notes ?? []);

        return redirect()
            ->route('admin.itam.oracle-assets.index')
            ->with($dryRun ? 'info' : 'success', ($dryRun ? 'Dry run — nothing was saved. The import would find: ' : 'Imported '.$name.': ')
                .$import->summaryLine()
                .($notes > 0 ? " {$notes} row(s) had problems; see the import history." : ''));
    }

    public function assignEmployee(Request $request, OracleAsset $oracleAsset, OracleAssetResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ], [
            'employee_id.required' => 'Choose the employee who holds it.',
        ]);

        if ($oracleAsset->isResolved()) {
            return back()->with('error', "Oracle asset {$oracleAsset->asset_number} already has a NOC asset; change its holder there.");
        }

        $employee = Employee::findOrFail($data['employee_id']);
        // A linked second mailbox is the same person as the main record it mirrors.
        $employee = $employee->linked_primary_employee_id ? ($employee->linkedPrimary ?? $employee) : $employee;

        DB::transaction(function () use ($oracleAsset, $employee, $resolver) {
            $oracleAsset->forceFill([
                'employee_id' => $employee->id,
                'employee_match' => OracleAsset::EMPLOYEE_MANUAL,
                'employee_candidates' => null,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ])->save();

            $resolver->resolve([$oracleAsset]);
        });

        ActivityLog::create([
            'model_type' => OracleAsset::class,
            'model_id' => $oracleAsset->id,
            'model_label' => "Oracle asset {$oracleAsset->asset_number}",
            'action' => 'oracle_asset_holder_set',
            'changes' => ['emp_no' => $oracleAsset->emp_no, 'emp_name' => $oracleAsset->emp_name, 'employee_id' => $employee->id, 'device_id' => $oracleAsset->fresh()->device_id],
            'user_id' => Auth::id(),
        ]);

        $unit = $oracleAsset->fresh(['device.azureDevice']);

        return back()->with('success', "Oracle asset {$unit->asset_number} is now {$employee->name}'s "
            .($unit->device ? ($unit->device->asset_code ?: $unit->device->name) : 'asset')
            .($unit->device?->azureDevice ? ', matched to their Intune device.' : ', not matched to an Intune device yet.'));
    }

    public function retire(Request $request, OracleAsset $oracleAsset, OracleAssetDevices $devices, AssetRetirement $retirement): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'retired_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'reason.required' => 'Say why the asset is retired.',
        ]);

        $on = CarbonImmutable::parse($data['retired_on']);

        try {
            if ($oracleAsset->device) {
                abort_unless(Auth::user()?->can('manage-assets'), 403);
                $retirement->retire($oracleAsset->device, $data['reason'], $on);
            } else {
                DB::transaction(fn () => $devices->retireUnheld($oracleAsset, $data['reason'], $on, Auth::id()));
            }
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Oracle asset {$oracleAsset->asset_number} ({$oracleAsset->emp_name}) retired.");
    }

    public function unmatch(OracleAsset $oracleAsset, OracleAssetDevices $devices): RedirectResponse
    {
        try {
            $device = DB::transaction(fn () => $devices->unmatch($oracleAsset, Auth::id()));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::create([
            'model_type' => OracleAsset::class,
            'model_id' => $oracleAsset->id,
            'model_label' => "Oracle asset {$oracleAsset->asset_number}",
            'action' => 'oracle_asset_unmatched',
            'changes' => ['emp_no' => $oracleAsset->emp_no, 'new_device_id' => $device?->id],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Oracle asset {$oracleAsset->asset_number} is no longer matched to that device"
            .($device ? '; it has its own asset again ('.($device->asset_code ?: $device->name).').' : '.'));
    }

    private function applyState(Builder $query, string $state): void
    {
        $inService = fn (Builder $q) => $q->whereNotIn('status', ['retired', 'scrapped']);
        $linked = fn (Builder $q) => $q->where('link_status', 'linked');

        match ($state) {
            'removed' => $query->whereNotNull('removed_at'),
            'not_in_intune' => $query->whereNull('removed_at')->whereHas('device', fn (Builder $q) => $inService($q)->whereDoesntHave('azureDevice', $linked)),
            'in_intune' => $query->whereNull('removed_at')->whereHas('device', fn (Builder $q) => $inService($q)->whereHas('azureDevice', $linked)),
            'no_holder' => $query->whereNull('removed_at')->whereNull('device_match'),
            'name_match' => $query->whereNull('removed_at')->where('employee_match', OracleAsset::EMPLOYEE_NAME),
            'retired' => $query->whereNull('removed_at')->whereHas('device', fn (Builder $q) => $q->whereIn('status', ['retired', 'scrapped'])),
            'asset_deleted' => $query->whereNull('removed_at')->whereNotNull('device_match')->whereNull('device_id'),
            default => $query->whereNull('removed_at'),
        };
    }
}
