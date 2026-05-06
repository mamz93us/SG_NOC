<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $branch_id
 * @property string      $adapter_type   ethernet|wifi|usb_ethernet|management|virtual|any
 * @property string|null $device_type
 * @property int         $vlan_id
 * @property int         $priority
 * @property string|null $description
 */
class RadiusBranchVlanPolicy extends Model
{
    protected $table = 'radius_branch_vlan_policy';

    protected $fillable = [
        'branch_id',
        'adapter_type',
        'device_type',
        'vlan_id',
        'priority',
        'description',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'vlan_id'   => 'integer',
        'priority'  => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
