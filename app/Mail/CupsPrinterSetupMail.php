<?php

namespace App\Mail;

use App\Models\CupsPrinter;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;
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

    public function build(): static
    {
        $domain = \App\Models\Setting::get()->cups_ipp_domain ?? 'localhost';

        // Generate QR code as PNG base64
        $qrOptions = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'scale'           => 10,
            'quietzoneSize'   => 2,
        ]);

        $qrBase64 = (new QRCode($qrOptions))->render($this->airprintUrl);

        // Strip data URI prefix, decode to raw PNG bytes
        $qrPngData = base64_decode(preg_replace('#^data:image/png;base64,#', '', $qrBase64));

        // Save temp file, then embed as CID (works in Outlook, Gmail, Apple Mail)
        $tmpPath = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
        file_put_contents($tmpPath, $qrPngData);

        $qrCid = $this->embed($tmpPath);

        return $this->view('emails.cups_printer_setup', [
            'cupsPrinter'   => $this->cupsPrinter,
            'airprintUrl'   => $this->airprintUrl,
            'recipientName' => $this->recipientName,
            'ippAddress'    => $this->cupsPrinter->getIppAddress(),
            'httpAddress'   => "http://{$domain}:631/printers/{$this->cupsPrinter->queue_name}",
            'domain'        => $domain,
            'qrCid'         => $qrCid,
        ]);
    }
}
