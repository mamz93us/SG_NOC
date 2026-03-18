<?php

namespace App\Services;

use App\Models\Device;
use App\Models\PhoneAccount;
use App\Models\PhonePortMap;
use App\Models\PhoneRequestLog;
use App\Models\UcmExtensionCache;

class PhoneDeviceLookup
{
    /**
     * Resolve a phone device from an employee's extension number.
     *
     * Tries PhoneAccount first (GDMS data), falls back to UcmExtensionCache + PhonePortMap.
     *
     * @return array|null  ['device' => Device|null, 'mac' => string, 'ip' => string|null,
     *                      'status' => string|null, 'source' => string, 'model' => string|null,
     *                      'switch_location' => string|null]
     */
    /**
     * Normalize a MAC address to lowercase 12-char hex (strip colons, dashes, dots, spaces).
     */
    private static function normalizeMac(?string $raw): ?string
    {
        if (!$raw) return null;
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw));
        return strlen($mac) >= 12 ? substr($mac, 0, 12) : null;
    }

    public static function findByExtension(string $extension, ?int $ucmServerId = null): ?array
    {
        $result = null;

        // ── Step 1: PhoneAccount (GDMS data) ──
        $phoneAccount = PhoneAccount::where('sip_user_id', $extension)->first();

        if ($phoneAccount && $phoneAccount->mac) {
            $mac    = self::normalizeMac($phoneAccount->mac);
            $device = $mac ? Device::where('mac_address', $mac)->first() : null;

            $result = [
                'device'          => $device,
                'mac'             => $mac,
                'ip'              => $device?->ip_address,
                'status'          => $phoneAccount->account_status,
                'source'          => 'PhoneAccount',
                'model'           => $device?->model,
                'switch_location' => null,
            ];

            // Enrich IP from PhonePortMap if device has no IP
            if (!$result['ip'] && $mac) {
                // Try both normalized and original MAC format for port map lookup
                $portMap = PhonePortMap::where(function ($q) use ($mac, $phoneAccount) {
                        $q->where('phone_mac', $mac)
                          ->orWhere('phone_mac', $phoneAccount->mac);
                    })
                    ->when($ucmServerId, fn ($q) => $q->where('ucm_server_id', $ucmServerId))
                    ->first();
                if ($portMap) {
                    $result['ip']              = $portMap->phone_ip;
                    $result['switch_location'] = $portMap->locationLabel();
                }
            }
        }

        // ── Step 2: Fallback — UcmExtensionCache + PhonePortMap ──
        if (!$result || !$result['device']) {
            $ucmExt = UcmExtensionCache::where('extension', $extension)
                ->when($ucmServerId, fn ($q) => $q->where('ucm_id', $ucmServerId))
                ->first();

            if ($ucmExt) {
                $portMap = PhonePortMap::where('extension', $extension)
                    ->when($ucmServerId, fn ($q) => $q->where('ucm_server_id', $ucmServerId))
                    ->first();

                $mac    = $portMap?->phone_mac ? self::normalizeMac($portMap->phone_mac) : ($result['mac'] ?? null);
                $device = $mac ? Device::where('mac_address', $mac)->first() : ($result['device'] ?? null);

                $result = [
                    'device'          => $device,
                    'mac'             => $mac,
                    'ip'              => $device?->ip_address ?? $portMap?->phone_ip ?? $ucmExt->ip_address,
                    'status'          => $ucmExt->status,
                    'source'          => 'UcmCache',
                    'model'           => $device?->model,
                    'switch_location' => $portMap?->locationLabel(),
                ];
            }
        }

        if (!$result) {
            return null;
        }

        // ── Enrich from PhoneRequestLog if we have a MAC but missing data ──
        if ($result['mac'] && (!$result['model'] || !$result['ip'])) {
            $prl = PhoneRequestLog::where('mac', $result['mac'])->latest()->first();
            if ($prl) {
                $result['model'] = $result['model'] ?: $prl->model;
                $result['ip']    = $result['ip'] ?: $prl->ip;
            }
        }

        return $result;
    }
}
