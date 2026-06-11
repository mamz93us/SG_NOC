<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnTunnel extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'local_id',
        'remote_id',
        'remote_public_ip',
        'remote_subnet',
        'local_subnet',
        'pre_shared_key',
        'ike_version',
        'encryption',
        'hash',
        'dh_group',
        'dpd_delay',
        'lifetime',
        'status',
        'last_checked_at',
        'ping_target_ip',
        'ping_status',
        'ping_latency_ms',
        'last_ping_at',
    ];

    protected $casts = [
        // 'pre_shared_key' => 'encrypted', // handled manually with error catching
        'last_checked_at' => 'datetime',
        'last_ping_at' => 'datetime',
        'dh_group' => 'integer',
        'dpd_delay' => 'integer',
        'ping_latency_ms' => 'integer',
    ];

    /**
     * IP pinged through the tunnel as a connectivity double-check. Explicit
     * ping_target_ip wins; otherwise the first host of the first remote
     * subnet (the branch Sophos answers on 10.x.0.1 at every site).
     */
    public function pingTarget(): ?string
    {
        if (! empty($this->ping_target_ip)) {
            return $this->ping_target_ip;
        }

        $first = trim(explode(',', (string) $this->remote_subnet)[0] ?? '');
        if (! str_contains($first, '/')) {
            return filter_var($first, FILTER_VALIDATE_IP) ? $first : null;
        }

        [$network, $bits] = explode('/', $first, 2);
        $long = ip2long(trim($network));
        if ($long === false || (int) $bits >= 31) {
            return null;
        }

        return long2ip($long + 1);
    }

    /**
     * The CHILD_SAs this tunnel is configured for — the cross-product of local
     * and remote subnets, named exactly as VpnControlService::generateConfig
     * writes them (first child = tunnel name, then -2, -3…). Used to show
     * per-child up/down status like the Sophos "Connection detail" view.
     *
     * @return array<int, array{name:string, local_ts:string, remote_ts:string}>
     */
    public function expectedChildren(): array
    {
        $locals = array_values(array_filter(array_map('trim', explode(',', (string) $this->local_subnet))));
        $remotes = array_values(array_filter(array_map('trim', explode(',', (string) $this->remote_subnet))));
        if (empty($locals)) {
            $locals = ['0.0.0.0/0'];
        }
        if (empty($remotes)) {
            $remotes = ['0.0.0.0/0'];
        }

        $out = [];
        $i = 1;
        foreach ($locals as $local) {
            foreach ($remotes as $remote) {
                $out[] = [
                    'name' => $i === 1 ? $this->name : "{$this->name}-{$i}",
                    'local_ts' => $local,
                    'remote_ts' => $remote,
                ];
                $i++;
            }
        }

        return $out;
    }

    public function getPreSharedKeyAttribute($value)
    {
        if (empty($value)) {
            return '';
        }
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::error("Decryption failed for VPN Tunnel {$this->id} ({$this->name}). MAC probably invalid.");

            return '********';
        }
    }

    public function setPreSharedKeyAttribute($value)
    {
        $this->attributes['pre_shared_key'] = encrypt($value);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VpnLog::class, 'vpn_id');
    }

    public function monitoredHosts(): HasMany
    {
        return $this->hasMany(MonitoredHost::class, 'vpn_id');
    }
}
