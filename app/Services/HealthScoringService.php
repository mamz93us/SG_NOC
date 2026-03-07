<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Device;
use App\Models\EmployeeAsset;
use App\Models\IdentityUser;
use App\Models\NetworkSwitch;
use Illuminate\Support\Collection;

class HealthScoringService
{
    public function allBranches(): Collection
    {
        return Branch::orderBy('name')->get()->map(function (Branch $branch) {
            $score = $this->scoreForBranch($branch->id);
            $branch->health = $score;
            return $branch;
        })->sortByDesc(fn ($b) => $b->health['total']);
    }

    public function scoreForBranch(int $branchId): array
    {
        $identity = $this->identityScore($branchId);
        $voice    = $this->voiceScore($branchId);
        $network  = $this->networkScore($branchId);
        $asset    = $this->assetScore($branchId);

        $total = (int) round(($identity + $voice + $network + $asset) / 4);

        return [
            'total'    => max(0, $total),
            'identity' => max(0, (int) $identity),
            'voice'    => max(0, (int) $voice),
            'network'  => max(0, (int) $network),
            'asset'    => max(0, (int) $asset),
        ];
    }

    public function identityScore(int $branchId): float
    {
        // Identity users are not branch-scoped in current schema; use global
        $users = IdentityUser::all();
        if ($users->isEmpty()) return 100.0;

        $score = 100.0;
        foreach ($users as $user) {
            $licenses = is_array($user->assigned_licenses) ? $user->assigned_licenses : json_decode($user->assigned_licenses ?? '[]', true);
            if (empty($licenses)) {
                $score -= 1; // -1% per unlicensed user
            }
            if (!$user->account_enabled) {
                $score -= 2; // -2% per disabled user
            }
        }

        return max(0, $score);
    }

    public function voiceScore(int $branchId): float
    {
        // UCM extensions not branch-aware in current DB; return 100 as baseline
        // Extend when UCM branch mapping is available
        return 100.0;
    }

    public function networkScore(int $branchId): float
    {
        $switches = NetworkSwitch::where('branch_id', $branchId)->get();
        if ($switches->isEmpty()) return 100.0;

        $score = 100.0;
        foreach ($switches as $sw) {
            if (!$sw->isOnline()) {
                $score -= 5; // -5% per offline switch
            }
        }

        return max(0, $score);
    }

    public function assetScore(int $branchId): float
    {
        $devices = Device::where('branch_id', $branchId)->get();
        if ($devices->isEmpty()) return 100.0;

        $score = 100.0;

        // -1% per device with no credentials
        foreach ($devices as $device) {
            if ($device->credentials()->count() === 0) {
                $score -= 1;
            }
        }

        // -2% per overdue asset return (active assignment > 2 years)
        $overdue = EmployeeAsset::whereNull('returned_date')
            ->where('assigned_date', '<', now()->subYears(2))
            ->whereHas('device', fn ($q) => $q->where('branch_id', $branchId))
            ->count();
        $score -= $overdue * 2;

        return max(0, $score);
    }

    public function healthColor(int $score): string
    {
        return self::healthColorStatic($score);
    }

    public static function healthColorStatic(int $score): string
    {
        return match (true) {
            $score >= 90 => 'success',
            $score >= 70 => 'info',
            $score >= 50 => 'warning',
            default      => 'danger',
        };
    }
}
