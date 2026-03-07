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

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $settings->smtp_host);
        Config::set('mail.mailers.smtp.port', $settings->smtp_port ?? 587);
        Config::set('mail.mailers.smtp.encryption', $settings->smtp_encryption === 'none' ? null : ($settings->smtp_encryption ?? 'tls'));
        Config::set('mail.mailers.smtp.username', $settings->smtp_username);
        Config::set('mail.mailers.smtp.password', $settings->smtp_password);
        Config::set('mail.from.address', $settings->smtp_from_address);
        Config::set('mail.from.name', $settings->smtp_from_name ?? config('app.name'));
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
