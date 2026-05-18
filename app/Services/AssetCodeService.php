<?php

namespace App\Services;

use App\Models\Accessory;
use App\Models\AssetType;
use App\Models\Device;
use App\Models\Setting;

class AssetCodeService
{
    private string $prefix;

    private int $padding;

    public function __construct()
    {
        $settings = Setting::get();
        $this->prefix = $settings->itam_asset_prefix ?? 'SG';
        $this->padding = (int) ($settings->itam_code_padding ?? 6);
    }

    public function generate(string $deviceType): string
    {
        $cat = $this->categoryCode($deviceType);
        $fullPrefix = "{$this->prefix}-{$cat}-";
        $seq = $this->nextSequence($fullPrefix);

        return $fullPrefix.str_pad($seq, $this->padding, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a code for an Accessory row, e.g. SG-ACC-000042.
     * Accessory categories don't live in the AssetType table, so we use a
     * single 'ACC' namespace and one shared sequence across all accessories.
     */
    public function generateForAccessory(): string
    {
        $fullPrefix = "{$this->prefix}-ACC-";
        $seq = $this->nextAccessorySequence($fullPrefix);

        return $fullPrefix.str_pad($seq, $this->padding, '0', STR_PAD_LEFT);
    }

    public function categoryCode(string $deviceType): string
    {
        $assetType = AssetType::findBySlug(strtolower($deviceType));

        return $assetType?->category_code ?? 'OTH';
    }

    /**
     * Generate a code like LENOVO-T14-PF12345
     */
    public function generateFromSpecs(?string $mfg, ?string $model, ?string $sn): string
    {
        $mfgStr = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $mfg ?? 'GEN'));
        $modelStr = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $model ?? 'MDL'));
        $snStr = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $sn ?? 'NOSERIAL'));

        // Shorten MFG and Model if too long
        $mfgPart = substr($mfgStr, 0, 4);
        $modelPart = substr($modelStr, 0, 6);

        return "{$mfgPart}-{$modelPart}-{$snStr}";
    }

    public function nextSequence(string $fullPrefix): int
    {
        // Find highest number currently used for this prefix
        $last = Device::where('asset_code', 'like', $fullPrefix.'%')
            ->orderByRaw('LENGTH(asset_code) DESC, asset_code DESC')
            ->value('asset_code');

        if (! $last) {
            return 1;
        }

        $numPart = substr($last, strlen($fullPrefix));
        $num = (int) ltrim($numPart, '0');

        return $num + 1;
    }

    public function nextAccessorySequence(string $fullPrefix): int
    {
        $last = Accessory::where('asset_code', 'like', $fullPrefix.'%')
            ->orderByRaw('LENGTH(asset_code) DESC, asset_code DESC')
            ->value('asset_code');

        if (! $last) {
            return 1;
        }

        $numPart = substr($last, strlen($fullPrefix));
        $num = (int) ltrim($numPart, '0');

        return $num + 1;
    }

    public function isUnique(string $code, ?int $excludeDeviceId = null): bool
    {
        $query = Device::where('asset_code', $code);
        if ($excludeDeviceId) {
            $query->where('id', '!=', $excludeDeviceId);
        }

        return ! $query->exists();
    }
}
