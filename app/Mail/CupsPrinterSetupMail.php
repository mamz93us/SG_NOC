<?php

namespace App\Mail;

use App\Models\CupsPrinter;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CupsPrinterSetupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CupsPrinter $cupsPrinter,
        public readonly string $airprintUrl,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Printer Setup Instructions — ' . $this->cupsPrinter->name,
        );
    }

    public function content(): Content
    {
        $domain = \App\Models\Setting::get()->cups_ipp_domain ?? 'localhost';

        // Generate QR code as base64 data URI for the AirPrint profile URL
        $qrOptions = new QROptions([
            'outputType'   => QRCode::OUTPUT_MARKUP_SVG,
            'svgUseCssProperties' => false,
            'scale'        => 10,
            'quietzoneSize' => 2,
        ]);

        $qrDataUri = (new QRCode($qrOptions))->render($this->airprintUrl);

        return new Content(
            view: 'emails.cups_printer_setup',
            with: [
                'cupsPrinter'   => $this->cupsPrinter,
                'airprintUrl'   => $this->airprintUrl,
                'recipientName' => $this->recipientName,
                'ippAddress'    => $this->cupsPrinter->getIppAddress(),
                'httpAddress'   => "http://{$domain}:631/printers/{$this->cupsPrinter->queue_name}",
                'domain'        => $domain,
                'qrDataUri'     => $qrDataUri,
            ],
        );
    }
}
