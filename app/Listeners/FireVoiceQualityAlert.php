<?php

namespace App\Listeners;

use App\Events\PoorVoiceQualityDetected;
use App\Models\VqAlertEvent;

class FireVoiceQualityAlert
{
    public function handle(PoorVoiceQualityDetected $event): void
    {
        $r = $event->report;
        VqAlertEvent::create([
            'source_type' => 'voice',
            'source_ref'  => $r->extension,
            'branch'      => $r->branch,
            'metric'      => 'mos_lq',
            'value'       => $r->mos_lq,
            'threshold'   => 3.0,
            'severity'    => $r->mos_lq < 2.0 ? 'critical' : 'warning',
            'message'     => "Poor voice quality on extension {$r->extension} "
                           . "(MOS: {$r->mos_lq}, branch: {$r->branch}). "
                           . "Codec: {$r->codec}, Jitter: {$r->jitter_avg}ms, Loss: {$r->packet_loss}%",
        ]);
    }
}
