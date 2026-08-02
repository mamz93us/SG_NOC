<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Configures Laravel's mailer at runtime from the Setting singleton.
 *
 * Two transports, chosen by `settings.mail_transport`:
 *
 *   'ses'  — AWS SES, reusing the SAME credentials the marketing portal uses
 *            (ses_region / ses_access_key_id / ses_secret_access_key). One AWS
 *            account, one sending reputation, one place to rotate keys.
 *   'smtp' — the legacy standalone SMTP host in the smtp_* columns.
 *
 * This is called from AppServiceProvider::boot(), so every mail path in the app
 * — workflows, onboarding, offboarding, notifications, printer alerts — picks up
 * whichever transport is configured without any of them knowing about it.
 *
 * Marketing campaigns do NOT come through here: they call the SES API directly
 * via Services\EmailMarketing\SesService because they need per-message tagging
 * for open/click/bounce attribution. Same AWS account, different entry point.
 */
class SmtpConfigService
{
    public function loadFromSettings(): void
    {
        $settings = Setting::get();

        if ($this->shouldUseSes($settings)) {
            $this->configureSes($settings);

            return;
        }

        $this->configureSmtp($settings);
    }

    /**
     * SES is used only when explicitly selected AND fully configured. A
     * half-configured install falls back to SMTP rather than failing every send.
     */
    private function shouldUseSes(Setting $settings): bool
    {
        return $settings->mail_transport === 'ses'
            && ! empty($settings->ses_region)
            && ! empty($settings->ses_access_key_id)
            && ! empty($settings->ses_secret_access_key);
    }

    private function configureSes(Setting $settings): void
    {
        // Purge so a settings change takes effect immediately rather than on the
        // next process — the scheduler is long-lived enough for this to matter.
        Mail::purge('ses');

        Config::set('services.ses.key', $settings->ses_access_key_id);
        Config::set('services.ses.secret', $settings->ses_secret_access_key);
        Config::set('services.ses.region', $settings->ses_region);

        // Tie transactional mail into the same configuration set as marketing so
        // bounces and complaints land on the existing SNS webhook.
        //
        // This goes on the MAILER config, not services.ses: MailManager builds
        // the transport with array_merge(services.ses, defaults, mailerConfig)
        // and reads $config['options'] from the result, so the mailer entry is
        // the one that authoritatively wins.
        Config::set(
            'mail.mailers.ses.options',
            ! empty($settings->ses_configuration_set)
                ? ['ConfigurationSetName' => $settings->ses_configuration_set]
                : []
        );

        Config::set('mail.default', 'ses');

        $this->applyFromAddress($settings, sesFallback: true);
    }

    private function configureSmtp(Setting $settings): void
    {
        // No SMTP host configured — leave the framework's own mail config alone
        // (MAIL_MAILER from .env) rather than forcing a transport that cannot
        // work. Pre-existing behaviour; the settings page shows "Not Configured"
        // in this state so it is visible rather than silent.
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

        $this->applyFromAddress($settings, sesFallback: false);
    }

    /**
     * The transactional From address stays smtp_from_address on both transports,
     * so switching to SES does not silently change who mail appears to come from.
     * On SES it must be a verified identity; ses_default_from_email is the
     * fallback because that one is known-verified (marketing already sends from it).
     */
    private function applyFromAddress(Setting $settings, bool $sesFallback): void
    {
        $address = $settings->smtp_from_address;
        $name = $settings->smtp_from_name;

        if ($sesFallback) {
            $address = $address ?: $settings->ses_default_from_email;
            $name = $name ?: $settings->ses_default_from_name;
        }

        if ($address) {
            Config::set('mail.from.address', $address);
        }

        Config::set('mail.from.name', $name ?: config('app.name'));
    }

    /**
     * Which transport is actually in effect — reflects the fallback, not just the
     * stored preference. Used by the settings UI and the test-send button.
     */
    public function activeTransport(): string
    {
        return $this->shouldUseSes(Setting::get()) ? 'ses' : 'smtp';
    }

    public function sendTestEmail(string $toAddress): bool
    {
        $this->loadFromSettings();

        $via = strtoupper($this->activeTransport());

        Mail::raw(
            "This is a test email from SG NOC, sent via {$via}. "
            .'If you received this, transactional mail is configured correctly.',
            function ($message) use ($toAddress, $via) {
                $message->to($toAddress)->subject("SG NOC — Test Email ({$via})");
            }
        );

        return true;
    }
}
