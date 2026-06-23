<?php

namespace App\Support;

use App\Models\AzureBranchMapping;
use Illuminate\Support\Collection;

/**
 * Resolves a branch_id by scanning arbitrary strings (office location, city,
 * department, Oracle "Location Name", etc.) against the keyword→branch_id
 * mappings in azure_branch_mappings. Case-insensitive substring match; first
 * hit wins.
 *
 * Shared by AzureSyncController (device/employee branch detection) and
 * OracleHrImportService (Oracle Location Name → branch).
 */
class BranchKeywordMatcher
{
    /**
     * @param  array<int, ?string>  $searchStrings
     * @param  Collection<int, AzureBranchMapping>|null  $mappings  Pass a preloaded
     *                                                              collection when matching in a loop to avoid N queries.
     */
    public static function match(array $searchStrings, ?Collection $mappings = null): ?int
    {
        $mappings ??= AzureBranchMapping::all();
        if ($mappings->isEmpty()) {
            return null;
        }

        foreach ($searchStrings as $str) {
            if (! $str) {
                continue;
            }
            foreach ($mappings as $m) {
                if (stripos($str, $m->keyword) !== false) {
                    return $m->branch_id;
                }
            }
        }

        return null;
    }
}
