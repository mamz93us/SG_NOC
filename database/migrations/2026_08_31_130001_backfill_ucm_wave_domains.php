<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill in each UCM's GDMS Wave domain.
 *
 * `ucm_servers.cloud_domain` existed but was empty on every row, so
 * IppbxApiService::getExtensionWave() fell back to the UCM's own URL host —
 * a 10.x private address. Any Wave/SIP client provisioned from that could only
 * ever register from inside the tunnel, which defeats the point of a softphone.
 *
 * Values are the "Wave Server" GDMS reports per on-premise PBX. They are keyed
 * on the private IP in the stored URL rather than the name, because the NOC and
 * GDMS disagree on naming (NOC "ABHA" vs GDMS site "ABH").
 *
 * GDMS's /device/list does NOT carry this field — it returns handsets, not
 * UCMs — so there is nothing to sync it from automatically today. The field is
 * editable per UCM in Admin → Settings ("Wave Domain"), which is where it
 * should be maintained if a PBX is re-homed.
 *
 * Only fills blanks, so a hand-edited value is never overwritten.
 */
return new class extends Migration
{
    /** private IP in ucm_servers.url => GDMS Wave/SIP host */
    private array $map = [
        '10.1.8.10' => 'sgjed.a.gdms.cloud',
        '10.2.88.10' => 'sgryd.a.gdms.cloud',
        '10.3.0.10' => 'sgkbr1.a.gdms.cloud',
        '10.4.0.9' => 'sgabh.a.gdms.cloud',
        '10.5.0.9' => 'ec74d7af41ca.a.gdms.cloud',
        '10.6.0.10' => 'ec74d7af41bc.a.gdms.cloud',
        '10.9.8.10' => 'sgcai.a.gdms.cloud',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ucm_servers') || ! Schema::hasColumn('ucm_servers', 'cloud_domain')) {
            return;
        }

        foreach (DB::table('ucm_servers')->get(['id', 'url', 'cloud_domain']) as $server) {
            if (! empty($server->cloud_domain)) {
                continue;
            }

            $host = parse_url((string) $server->url, PHP_URL_HOST);

            if ($host && isset($this->map[$host])) {
                DB::table('ucm_servers')
                    ->where('id', $server->id)
                    ->update(['cloud_domain' => $this->map[$host]]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ucm_servers')) {
            return;
        }

        // Only clear the values this migration could have set, so a domain
        // someone typed by hand afterwards survives a rollback.
        DB::table('ucm_servers')
            ->whereIn('cloud_domain', array_values($this->map))
            ->update(['cloud_domain' => null]);
    }
};
