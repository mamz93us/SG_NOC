<?php

namespace App\Services\Itam;

use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\EmployeeAsset;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Retires an asset: it is out of service for good, written off without the
 * scrap approval chain (an old laptop nobody has any more, a machine kept for
 * parts). Its holder's assignment is closed on the retirement date, so the
 * asset leaves the employee's list and counts as retired everywhere.
 *
 * Scrapping stays the approval workflow in AssetScrapController; an asset in
 * a scrap request still waiting for approval cannot be retired as well, or the
 * two would disagree about what happened to it. Neither touches Intune: a
 * retired laptop still enrolled there has to be removed there too.
 */
class AssetRetirement
{
    public function __construct(private PendingScrapRequests $scrapRequests) {}

    public function retire(Device $device, string $reason, CarbonInterface $on, ?string $reasonCode = null): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why the asset is retired.');
        }
        if (in_array($device->status, ['retired', 'scrapped'], true)) {
            throw new DomainException(($device->asset_code ?: $device->name)." is already {$device->status}.");
        }
        if ($requestId = $this->scrapRequests->forDevice((int) $device->id)) {
            throw new DomainException(($device->asset_code ?: $device->name)." is in scrap request #{$requestId}, which is waiting for approval. Approve or reject that first.");
        }

        DB::transaction(function () use ($device, $reason, $on, $reasonCode) {
            $holders = [];
            $holderNumbers = [];

            EmployeeAsset::with('employee:id,name,oracle_emp_no')
                ->where('asset_id', $device->id)
                ->whereNull('returned_date')
                ->get()
                ->each(function (EmployeeAsset $assignment) use ($reason, $on, &$holders, &$holderNumbers) {
                    $holders[] = $assignment->employee?->name;
                    $holderNumbers[] = $assignment->employee?->oracle_emp_no;

                    // A model update, not a query update, so EmployeeAssetObserver closes the return tasks.
                    $assignment->update([
                        'returned_date' => $on->toDateString(),
                        'notes' => trim(($assignment->notes ? $assignment->notes.' — ' : '').'Retired: '.$reason),
                    ]);
                });

            $device->update(['status' => 'retired', 'storage_location' => null]);

            AssetHistory::record($device, 'retired', 'Retired: '.$reason, array_filter([
                'retired_on' => $on->toDateString(),
                'holder' => implode(', ', array_filter($holders)) ?: null,
                // Their Oracle employee number, so finance posts the write-off against the right person.
                'holder_no' => implode(', ', array_filter($holderNumbers)) ?: null,
                'reason' => $reason,
                'reason_code' => $reasonCode,
            ]));
        });
    }
}
