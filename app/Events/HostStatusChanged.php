<?php

namespace App\Events;

use App\Models\MonitoredHost;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HostStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MonitoredHost $host,
        public readonly string        $newStatus  // 'up' | 'down' | 'warning'
    ) {}
}
