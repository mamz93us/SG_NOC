<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\AssetType;
use App\Models\Branch;
use App\Models\Device;

/**
 * Imports access points from a Sophos Central CSV export (and is shaped to
 * accept other vendors later). Upserts by serial number, maps the export's
 * "Current Site" to a branch, and links/creates a Device asset row.
 */
class AccessPointImporter
{
    public function __construct(protected AssetCodeService $assetCodes) {}

    /**
     * @return array{created:int, updated:int, assets:int, skipped:int, errors:array<string>}
     */
    public function importSophosCsv(string $path): array
    {
        $rows = $this->readCsv($path);

        $branchMap = $this->branchMap();
        $created = $updated = $assets = $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['AP Name'] ?? ''));
            $serial = trim((string) ($row['Serial Number'] ?? ''));
            $mac = strtolower(trim((string) ($row['MAC Address'] ?? '')));
            $ip = trim((string) ($row['Internal IP address'] ?? ''));
            $model = trim((string) ($row['Model'] ?? ''));

            if ($name === '' && $serial === '' && $mac === '') {
                $skipped++;

                continue;
            }

            $site = trim((string) ($row['Current Site'] ?? ''));

            $attributes = [
                'name' => $name ?: ($serial ?: $mac),
                'vendor' => $this->vendorFromModel($model),
                'controller' => 'sophos_central',
                'model' => $model ?: null,
                'mac_address' => $mac ?: null,
                'ip_address' => $ip ?: null,
                'site' => $site ?: null,
                'branch_id' => $this->resolveBranch($site, $branchMap),
                'firmware' => trim((string) ($row['Firmware'] ?? '')) ?: null,
                'license_state' => trim((string) ($row['License State'] ?? '')) ?: null,
                'profile' => trim((string) ($row['Profile'] ?? '')) ?: null,
                'config_status' => trim((string) ($row['Config Status'] ?? '')) ?: null,
                'channel_2g' => trim((string) ($row['Active Channel for 2.4 GHz'] ?? '')) ?: null,
                'channel_5g' => trim((string) ($row['Active Channel for 5 GHz'] ?? '')) ?: null,
                'channel_6g' => trim((string) ($row['Active Channel for 6 GHz'] ?? '')) ?: null,
                'cpu_usage' => $this->intOrNull($row['CPU Usage'] ?? null),
                'memory_usage' => $this->intOrNull($row['Memory Usage'] ?? null),
                'uptime_seconds' => $this->intOrNull($row['Uptime'] ?? null),
                'raw' => $row,
            ];

            // 1. Upsert the access point — the primary record.
            try {
                $ap = null;
                if ($serial !== '') {
                    $ap = AccessPoint::where('serial_number', $serial)->first();
                }
                if (! $ap && $mac !== '') {
                    $ap = AccessPoint::where('mac_address', $mac)->first();
                }

                if ($ap) {
                    $ap->fill($attributes);
                    if ($serial !== '') {
                        $ap->serial_number = $serial;
                    }
                    $ap->save();
                    $updated++;
                } else {
                    $attributes['serial_number'] = $serial ?: null;
                    $ap = AccessPoint::create($attributes);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i + 2).' ('.($name ?: $serial).'): '.$e->getMessage();

                continue;
            }

            // 2. Asset linkage is best-effort — a failure here must never
            //    discard the access point we just saved.
            try {
                if ($this->linkAsset($ap)) {
                    $assets++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i + 2).' ('.($name ?: $serial).') asset link skipped: '.$e->getMessage();
            }
        }

        return compact('created', 'updated', 'assets', 'skipped', 'errors');
    }

    // ─── Asset linkage ────────────────────────────────────────────

    protected function linkAsset(AccessPoint $ap): bool
    {
        if ($ap->device_id && Device::whereKey($ap->device_id)->exists()) {
            return false;
        }

        // Match an existing asset by serial or MAC before creating one
        $device = null;
        if ($ap->serial_number) {
            $device = Device::where('serial_number', $ap->serial_number)->first();
        }
        if (! $device && $ap->mac_address) {
            $device = Device::where('mac_address', $ap->mac_address)->first();
        }

        $created = false;
        if (! $device) {
            $this->ensureAssetType();
            $device = Device::create([
                'type' => 'access_point',
                'name' => $ap->name,
                'manufacturer' => $ap->vendorLabel(),
                'model' => $ap->model,
                'serial_number' => $ap->serial_number,
                'mac_address' => $ap->mac_address,
                'ip_address' => $ap->ip_address,
                'branch_id' => $ap->branch_id,
                'status' => 'active',
                'source' => 'access_point',
                // satisfies the unique(source, source_id) constraint on devices
                'source_id' => $ap->serial_number ?: ($ap->mac_address ?: 'ap-'.$ap->id),
                'firmware_version' => $ap->firmware,
                'asset_code' => $this->safeAssetCode('access_point'),
            ]);
            $created = true;
        }

        $ap->device_id = $device->id;
        $ap->saveQuietly();

        return $created;
    }

    protected function ensureAssetType(): void
    {
        if (! class_exists(AssetType::class)) {
            return;
        }
        try {
            AssetType::firstOrCreate(
                ['slug' => 'access_point'],
                [
                    'label' => 'Access Point',
                    'icon' => 'bi-router',
                    'badge_class' => 'success',
                    'category_code' => 'AP',
                ]
            );
        } catch (\Throwable) {
            // AssetType schema differences shouldn't block the import
        }
    }

    protected function safeAssetCode(string $type): ?string
    {
        try {
            return $this->assetCodes->generate($type);
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function vendorFromModel(string $model): string
    {
        $m = strtoupper($model);
        if (str_starts_with($m, 'APX') || str_starts_with($m, 'AP6') || str_contains($m, 'SOPHOS')) {
            return 'sophos';
        }
        if (str_contains($m, 'EAP') || str_contains($m, 'OMADA') || str_contains($m, 'TP-LINK')) {
            return 'tp_link';
        }

        return 'sophos'; // default for this Central export
    }

    /** @return array<string,int> lowercased branch name => id */
    protected function branchMap(): array
    {
        return Branch::query()->get(['id', 'name'])
            ->mapWithKeys(fn ($b) => [mb_strtolower(trim($b->name)) => $b->id])
            ->all();
    }

    protected function resolveBranch(string $site, array $branchMap): ?int
    {
        if ($site === '') {
            return null;
        }
        $key = mb_strtolower(trim($site));

        // Exact match
        if (isset($branchMap[$key])) {
            return $branchMap[$key];
        }

        // Common aliases from the Sophos site naming
        $aliases = [
            'abha' => ['abha'],
            'riyadh' => ['riyadh', 'ryd'],
            'jeddah' => ['jeddah', 'jed'],
            'khobar' => ['khobar', 'kbr'],
            'riyadh_new_office' => ['riyadh', 'ryd'],
            'whjed' => ['jeddah warehouse', 'wh jeddah', 'whjed'],
            'whkbr' => ['khobar warehouse', 'wh khobar', 'whkbr'],
            'whryd' => ['riyadh warehouse', 'wh riyadh', 'whryd'],
        ];

        foreach ($aliases[$key] ?? [] as $alias) {
            if (isset($branchMap[$alias])) {
                return $branchMap[$alias];
            }
        }

        // Fuzzy contains
        foreach ($branchMap as $name => $id) {
            if (str_contains($name, $key) || str_contains($key, $name)) {
                return $id;
            }
        }

        return null;
    }

    protected function intOrNull($value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }

    /** @return array<int,array<string,string>> */
    protected function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }

        $header = null;
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }
            if ($header === null) {
                // Strip UTF-8 BOM from the first header cell
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $header = array_map('trim', $line);

                continue;
            }
            if (count(array_filter($line, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $col) {
                $row[$col] = $line[$idx] ?? null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
