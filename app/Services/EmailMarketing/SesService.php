<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\Setting;
use Aws\Ses\SesClient;
use Illuminate\Support\Facades\Cache;

/**
 * Thin wrapper over the AWS SES classic (v2) SDK that reads its
 * credentials from the Setting singleton. Throws EmailMarketingNotConfiguredException
 * if anything required is missing — callers (jobs, controllers) catch
 * and surface a meaningful error.
 */
class SesService
{
    private SesClient $client;

    private Setting $settings;

    public function __construct(?Setting $settings = null)
    {
        $this->settings = $settings ?? Setting::get();
        $this->assertConfigured();

        $this->client = new SesClient([
            'version' => 'latest',
            'region' => $this->settings->ses_region,
            'credentials' => [
                'key' => $this->settings->ses_access_key_id,
                'secret' => $this->settings->ses_secret_access_key,
            ],
        ]);
    }

    /**
     * Send one rendered campaign email. Returns the SES MessageId.
     */
    public function sendCampaignEmail(
        EmailCampaignSend $send,
        string $renderedHtml,
        string $subject,
        ?string $textBody = null,
        array $extraHeaders = []
    ): string {
        $campaign = $send->campaign;
        $subscriber = $send->subscriber;

        $payload = [
            'Source' => sprintf('%s <%s>',
                $this->encodeAddressName($campaign->from_name),
                $campaign->from_email),
            'Destination' => [
                'ToAddresses' => [$subscriber->email],
            ],
            'Message' => [
                'Subject' => [
                    'Charset' => 'UTF-8',
                    'Data' => $subject,
                ],
                'Body' => [
                    'Html' => [
                        'Charset' => 'UTF-8',
                        'Data' => $renderedHtml,
                    ],
                ],
            ],
            'Tags' => [
                ['Name' => 'campaign_id',   'Value' => (string) $campaign->id],
                ['Name' => 'subscriber_id', 'Value' => (string) $subscriber->id],
                ['Name' => 'send_id',       'Value' => (string) $send->id],
            ],
        ];

        if ($textBody) {
            $payload['Message']['Body']['Text'] = [
                'Charset' => 'UTF-8',
                'Data' => $textBody,
            ];
        }

        if ($campaign->reply_to) {
            $payload['ReplyToAddresses'] = [$campaign->reply_to];
        }

        $configSet = $this->settings->ses_configuration_set;
        if ($configSet) {
            $payload['ConfigurationSetName'] = $configSet;
        }

        $result = $this->client->sendEmail($payload);

        return (string) $result->get('MessageId');
    }

    /**
     * Raw send for the admin "Send test email" button on Settings page,
     * and for the per-campaign "Send test" button on the campaign show
     * page. Doesn't wrap a CampaignSend.
     *
     * When called from a campaign, the caller passes the campaign's
     * own from_email / from_name / reply_to so the test arrives from
     * the same sender the real send would use. When called from the
     * Settings page (or without overrides) we fall back to the
     * ses_default_* values in Settings.
     */
    public function sendRawTestEmail(
        string $toAddress,
        string $subject,
        string $html,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyTo = null,
    ): string {
        $from = $fromEmail ?: $this->settings->ses_default_from_email;
        $fromName = $fromName ?: ($this->settings->ses_default_from_name ?: 'SG NOC');

        if (! $from) {
            throw EmailMarketingNotConfiguredException::missing('ses_default_from_email');
        }

        $payload = [
            'Source' => sprintf('%s <%s>',
                $this->encodeAddressName($fromName),
                $from),
            'Destination' => ['ToAddresses' => [$toAddress]],
            'Message' => [
                'Subject' => ['Charset' => 'UTF-8', 'Data' => $subject],
                'Body' => ['Html' => ['Charset' => 'UTF-8', 'Data' => $html]],
            ],
        ];

        if ($replyTo) {
            $payload['ReplyToAddresses'] = [$replyTo];
        }

        if ($this->settings->ses_configuration_set) {
            $payload['ConfigurationSetName'] = $this->settings->ses_configuration_set;
        }

        $result = $this->client->sendEmail($payload);

        return (string) $result->get('MessageId');
    }

    /**
     * Returns the SES account quota — cached for a minute so we don't
     * GetSendQuota on every batch.
     */
    public function getSendQuota(): array
    {
        $ttl = (int) config('email_marketing.quota_cache_seconds', 60);

        return Cache::remember('email_marketing.ses_quota', $ttl, function () {
            $r = $this->client->getSendQuota();

            return [
                'Max24HourSend' => (float) $r->get('Max24HourSend'),
                'MaxSendRate' => (float) $r->get('MaxSendRate'),
                'SentLast24Hours' => (float) $r->get('SentLast24Hours'),
            ];
        });
    }

    public function getSendStatistics(): array
    {
        $r = $this->client->getSendStatistics();

        return (array) ($r->get('SendDataPoints') ?? []);
    }

    public function listVerifiedIdentities(): array
    {
        $r = $this->client->listIdentities(['IdentityType' => 'Domain']);

        return (array) ($r->get('Identities') ?? []);
    }

    private function assertConfigured(): void
    {
        if (! $this->settings->email_marketing_enabled) {
            throw EmailMarketingNotConfiguredException::disabled();
        }
        foreach (['ses_region', 'ses_access_key_id', 'ses_secret_access_key'] as $required) {
            if (empty($this->settings->{$required})) {
                throw EmailMarketingNotConfiguredException::missing($required);
            }
        }
    }

    private function encodeAddressName(string $name): string
    {
        // SES wants RFC-2047 encoded names if they have non-ASCII chars,
        // but for the common case we just quote them.
        if (preg_match('/[^\x20-\x7e]/', $name)) {
            return '=?UTF-8?B?'.base64_encode($name).'?=';
        }
        // Quote if name contains specials.
        if (preg_match('/[(),:;<>@\[\]"\\\\]/', $name)) {
            return '"'.str_replace('"', '\\"', $name).'"';
        }

        return $name;
    }
}
