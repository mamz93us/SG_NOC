<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SmtpConfigService
{
    public function loadFromSettings(): void
    {
        $settings = Setting::get();

        if (empty($settings->smtp_host)) {
            return;
        }

        // Purge the SMTP mailer to ensure new settings are applied immediately
        Mail::purge('smtp');

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $settings->smtp_host);
        Config::set('mail.mailers.smtp.port', $settings->smtp_port ?? 587);
        Config::set('mail.mailers.smtp.encryption', $settings->smtp_encryption === 'none' ? null : ($settings->smtp_encryption ?? 'tls'));
        Config::set('mail.mailers.smtp.username', $settings->smtp_username);
        Config::set('mail.mailers.smtp.password', $settings->smtp_password);
        Config::set('mail.mailers.smtp.timeout', 30);
        Config::set('mail.mailers.smtp.auth_mode', 'login');
        Config::set('mail.mailers.smtp.local_domain', parse_url(config('app.url'), PHP_URL_HOST));

        Config::set('mail.from.address', $settings->smtp_from_address);
        Config::set('mail.from.name', $settings->smtp_from_name ?? config('app.name'));

        // DEBUG: Log final values to check if they are correct
        $debug = "SMTP Debug at " . now()->toDateTimeString() . "\n";
        $debug .= "Host: " . config('mail.mailers.smtp.host') . "\n";
        $debug .= "Port: " . config('mail.mailers.smtp.port') . "\n";
        $debug .= "Encryption: " . config('mail.mailers.smtp.encryption') . "\n";
        $debug .= "User: " . config('mail.mailers.smtp.username') . "\n";
        $pass = config('mail.mailers.smtp.password');
        $debug .= "Pass Length: " . strlen($pass ?? '') . "\n";
        $debug .= "Pass Preview: " . (strlen($pass ?? '') > 2 ? substr($pass, 0, 2) . '...' : 'N/A') . "\n";
        @file_put_contents('/tmp/smtp_debug.log', $debug);
    }

    public function sendTestEmail(string $toAddress): bool
    {
        $this->loadFromSettings();

        try {
            Mail::raw('This is a test email from SG NOC. If you received this, your SMTP configuration is working correctly.', function ($message) use ($toAddress) {
                $message->to($toAddress)->subject('SG NOC — SMTP Test Email');
            });
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
