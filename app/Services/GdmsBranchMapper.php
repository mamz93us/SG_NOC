<?php

namespace App\Services;

class GdmsBranchMapper
{
    protected array $map = [
        '10.1.8.10:5060'  => 10, // JED
        '10.2.88.10:5060' => 20, // RYD
        '10.3.0.10:5060'  => 30, // KBR
        '10.4.0.9:5060'   => 40, // ABH
        '10.9.8.10:5060'  => 60, // CAI
        '10.5.0.10:5060'  => 10, // JED
        '10.6.0.10:5060'  => 20, // RYD
    ];

    // Set this to a valid branch id for “Unknown / Default”
    protected int $defaultBranchId = 10; // for example JED, change as needed

    public function resolveBranchId(string $sipServer): int
    {
        // sipServer may contain multiple entries, separated by comma
        $first = trim(explode(',', $sipServer)[0]);

        if (!str_contains($first, ':')) {
            $first .= ':5060';
        }

        return $this->map[$first] ?? $this->defaultBranchId;
    }
}
