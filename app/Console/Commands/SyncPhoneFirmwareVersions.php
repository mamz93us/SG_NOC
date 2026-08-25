<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\PhoneRequestLog;
use App\Services\GdmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `devices.firmware_version` honest for phones.
 *
 * That column was only ever written when a phone was first imported as an asset,
 * so it went stale the moment anything upgraded. Two sources refresh it here, in
 * order of freshness:
 *
 *  1. What the phone itself announced in the User-Agent on its last
 *     /phonebook.xml fetch (`phone_request_logs.firmware`). No cloud round trip,
 *     and it reflects reality within minutes of a reboot.
 *  2. GDMS, for phones that have not fetched the phonebook recently — a phone
 *     that is online in the cloud but silent to the NOC is exactly the case the
 *     first source cannot cover.
 *
 * Feeds both the firmware status board and the existing ITAM Firmware Tracker.
 */
class SyncPhoneFirmwareVersions extends Command
{
    protected $signature = 'phones:sync-firmware-versions
                            {--skip-gdms : Use only what phones self-reported}';

    protected $description = 'Refresh devices.firmware_version for phones from phonebook check-ins and GDMS.';

    public function handle(GdmsService $gdms): int
    {
        $phones = Device::where('type', 'phone')->whereNotNull('mac_address')->get();

        if ($phones->isEmpty()) {
            $this->info('No phone assets to update.');

            return self::SUCCESS;
        }

        $reported = $this->latestReportedFirmware();
        $fromPhones = 0;
        $fromGdms = 0;
        $unresolved = [];

        foreach ($phones as $phone) {
            $mac = $this->normaliseMac($phone->mac_address);
            $version = $reported[$mac] ?? null;

            if ($version) {
                if ($phone->firmware_version !== $version) {
                    $phone->update(['firmware_version' => $version]);
                    $fromPhones++;
                }

                continue;
            }

            $unresolved[$mac] = $phone;
        }

        if ($unresolved && ! $this->option('skip-gdms')) {
            $fromGdms = $this->fillFromGdms($gdms, $unresolved);
        }

        $this->info(sprintf(
            '%d updated from phone check-ins, %d from GDMS, %d still unknown.',
            $fromPhones,
            $fromGdms,
            count($unresolved) - $fromGdms
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, Device>  $unresolved
     */
    private function fillFromGdms(GdmsService $gdms, array $unresolved): int
    {
        try {
            $devices = $gdms->listAllPhoneDevices();
        } catch (\Throwable $e) {
            // GDMS being unreachable is not a failure of this command — the
            // self-reported versions above are already applied.
            $this->warn('GDMS lookup skipped: '.$e->getMessage());

            return 0;
        }

        $updated = 0;

        foreach ($devices as $device) {
            $mac = $this->normaliseMac($device['mac'] ?? '');
            $version = $device['firmwareVersion'] ?? null;

            if (! $mac || ! $version || $version === '—' || ! isset($unresolved[$mac])) {
                continue;
            }

            $phone = $unresolved[$mac];
            if ($phone->firmware_version !== $version) {
                $phone->update(['firmware_version' => $version]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Newest self-reported firmware per MAC.
     *
     * @return array<string, string>
     */
    private function latestReportedFirmware(): array
    {
        $latestIds = PhoneRequestLog::query()
            ->whereNotNull('mac')
            ->whereNotNull('firmware')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('mac')
            ->pluck('id');

        return PhoneRequestLog::whereIn('id', $latestIds)
            ->get()
            ->mapWithKeys(fn (PhoneRequestLog $log) => [
                $this->normaliseMac((string) $log->mac) => (string) $log->firmware,
            ])
            ->all();
    }

    /** MACs arrive as bare hex, colon- or dash-separated depending on the source. */
    private function normaliseMac(string $mac): string
    {
        return strtolower(preg_replace('/[^a-f0-9]/i', '', $mac) ?? '');
    }
}
