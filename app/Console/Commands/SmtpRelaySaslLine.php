<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Emits the Postfix SASL password-map line for the local SMTP relay
 * (deployment/smtp-relay) that lets legacy Ricoh MFPs scan-to-email via SES.
 *
 * The relay authenticates to Amazon SES with "SMTP credentials", whose password
 * is NOT the raw AWS secret — it is the secret run through AWS SigV4 against the
 * fixed message "SendRawEmail" (0x04 prefix, base64). We reuse the SES creds the
 * app already stores for Email Marketing (encrypted at rest on the Setting
 * singleton), so no separate credential is created and setup.sh does not need
 * them in .env. Output is one line, ready to write to /etc/postfix/sasl_passwd:
 *
 *   [email-smtp.<region>.amazonaws.com]:587 <ACCESS_KEY_ID>:<smtp-password>
 */
class SmtpRelaySaslLine extends Command
{
    protected $signature = 'smtp-relay:sasl-line';

    protected $description = 'Print the Postfix sasl_passwd line for the SES relay, from the stored SES credentials.';

    public function handle(): int
    {
        $settings = Setting::get();

        $region = $settings->ses_region ?: 'us-east-1';
        $keyId = $settings->ses_access_key_id;
        $secret = $settings->ses_secret_access_key; // decrypted by the model accessor

        if (empty($keyId) || empty($secret)) {
            // Write guidance to stderr so stdout stays clean for capture.
            $this->components->error(
                'SES credentials are not set. Configure them under '
                .'Admin → Email Marketing settings (ses_access_key_id / ses_secret_access_key).'
            );

            return self::FAILURE;
        }

        $password = $this->sesSmtpPassword($secret, $region);

        $this->line(sprintf('[email-smtp.%s.amazonaws.com]:587 %s:%s', $region, $keyId, $password));

        return self::SUCCESS;
    }

    /**
     * Derive an Amazon SES SMTP password from an IAM secret access key using
     * AWS's documented SigV4 chain. Mirrors deployment/smtp-relay/ses-smtp-password.sh.
     */
    private function sesSmtpPassword(string $secret, string $region): string
    {
        $sign = static fn (string $key, string $msg): string => hash_hmac('sha256', $msg, $key, true);

        $sig = $sign('AWS4'.$secret, '11111111');
        $sig = $sign($sig, $region);
        $sig = $sign($sig, 'ses');
        $sig = $sign($sig, 'aws4_request');
        $sig = $sign($sig, 'SendRawEmail');

        return base64_encode("\x04".$sig);
    }
}
