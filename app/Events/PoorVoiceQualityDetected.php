<?php

namespace App\Events;

use App\Models\VoiceQualityReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PoorVoiceQualityDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(public VoiceQualityReport $report) {}
}
