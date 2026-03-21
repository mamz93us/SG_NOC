<?php

namespace App\Jobs;

use App\Mail\PrinterSetupMail;
use App\Models\PrinterDeployToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPrinterSetupEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private int $tokenId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $token = PrinterDeployToken::with('printer')->find($this->tokenId);
        if (! $token) return;

        Mail::to($token->sent_to_email)->send(new PrinterSetupMail($token));
    }
}
