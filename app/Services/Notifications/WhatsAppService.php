<?php

namespace App\Services\Notifications;

use App\Models\Setting;
use App\Models\WhatsappLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WhatsApp Cloud API (graph.facebook.com) sender.
 *
 * Ported from the UBL portal's OTP sender and generalised for NOC alerts: the
 * template name, its language and which alert fields map onto its body
 * placeholders are all configured in Admin -> Settings, so an approved
 * template of any shape can be used without touching this class.
 *
 * Two modes:
 *  - template (default) - the only mode that can *start* a conversation. Meta
 *    rejects free-form text unless the recipient messaged the business in the
 *    last 24 hours, which is never true for an unattended alert.
 *  - text - plain body, useful for replying inside that 24-hour window and for
 *    testing against a number that has just messaged the business number.
 */
class WhatsAppService
{
    private const DEFAULT_API_VERSION = 'v21.0';

    private const DEFAULT_LANGUAGE = 'en';

    /** Tokens accepted in whatsapp_template_body_params, in any order. */
    public const TEMPLATE_TOKENS = ['title', 'message', 'severity', 'link', 'time'];

    private ?Setting $settings = null;

    private function settings(): Setting
    {
        return $this->settings ??= Setting::get();
    }

    public function isConfigured(): bool
    {
        $s = $this->settings();

        return (bool) $s->whatsapp_enabled
            && filled($s->whatsapp_phone_number_id)
            && filled($s->whatsapp_access_token);
    }

    /**
     * Strip a number to the digits the Cloud API expects.
     *
     * Meta wants E.164 without punctuation; it tolerates a leading '+', but
     * not spaces, dashes or parentheses. A local number written with a leading
     * 0 keeps that 0 here - prefixing the country code is done by callers that
     * know the default, see withCountryCode().
     */
    public static function normaliseNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';

        // Shortest plausible international number is ~8 digits; anything less
        // is a typo or an extension, and sending it just burns an API call.
        return strlen($digits) >= 8 ? $digits : null;
    }

    /**
     * Apply the configured default country code to a national number.
     *
     * A number saved as 01001234567 is unusable as-is; with a default of 20 it
     * becomes 201001234567. Numbers that already start with the code are left
     * alone.
     */
    public function withCountryCode(?string $number): ?string
    {
        $digits = self::normaliseNumber($number);
        if ($digits === null) {
            return null;
        }

        $cc = preg_replace('/\D+/', '', (string) $this->settings()->whatsapp_default_country_code) ?: null;
        if (! $cc) {
            return $digits;
        }

        if (str_starts_with($digits, $cc)) {
            return $digits;
        }

        // National form: drop the trunk '0' before prefixing.
        if (str_starts_with($digits, '0')) {
            return $cc.ltrim($digits, '0');
        }

        return $digits;
    }

    /**
     * Send one alert, honouring the configured mode.
     *
     * @param  array<string,string|null>  $fields  title / message / severity / link / time
     * @return array<string,mixed>
     */
    public function sendAlert(
        string $to,
        array $fields,
        ?int $notificationId = null,
        ?string $notificationType = null,
        ?string $toName = null,
    ): array {
        $s = $this->settings();

        if ($s->whatsapp_use_template && filled($s->whatsapp_alert_template)) {
            return $this->sendTemplate(
                to: $to,
                template: $s->whatsapp_alert_template,
                params: $this->buildTemplateParams($fields),
                notificationId: $notificationId,
                notificationType: $notificationType,
                toName: $toName,
            );
        }

        return $this->sendText(
            to: $to,
            body: $this->buildTextBody($fields),
            notificationId: $notificationId,
            notificationType: $notificationType,
            toName: $toName,
        );
    }

    /**
     * Map the alert's fields onto the approved template's body placeholders,
     * in the order the admin configured.
     *
     * @param  array<string,string|null>  $fields
     * @return list<string>
     */
    public function buildTemplateParams(array $fields): array
    {
        $configured = (string) ($this->settings()->whatsapp_template_body_params ?: 'title,message');

        $tokens = collect(explode(',', $configured))
            ->map(fn ($t) => trim(strtolower($t)))
            ->filter(fn ($t) => in_array($t, self::TEMPLATE_TOKENS, true))
            ->values();

        if ($tokens->isEmpty()) {
            $tokens = collect(['title', 'message']);
        }

        return $tokens
            ->map(fn ($t) => $this->flatten((string) ($fields[$t] ?? '')))
            ->all();
    }

    /**
     * @param  array<string,string|null>  $fields
     */
    private function buildTextBody(array $fields): string
    {
        $lines = array_filter([
            trim((string) ($fields['title'] ?? '')),
            trim((string) ($fields['message'] ?? '')),
            filled($fields['link'] ?? null) ? $fields['link'] : null,
        ]);

        return implode("\n\n", $lines) ?: 'SG NOC alert';
    }

    /**
     * Template parameters may not contain newlines, tabs or 4+ consecutive
     * spaces - Meta returns error 132000 and the whole send fails.
     */
    private function flatten(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            $value = '-';
        }

        // The Cloud API caps a body parameter at 1024 characters.
        return mb_substr($value, 0, 1000);
    }

    /**
     * @param  list<string>  $params
     * @return array<string,mixed>
     */
    public function sendTemplate(
        string $to,
        string $template,
        array $params = [],
        ?string $language = null,
        ?int $notificationId = null,
        ?string $notificationType = null,
        ?string $toName = null,
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language ?: ($this->settings()->whatsapp_template_language ?: self::DEFAULT_LANGUAGE)],
            ],
        ];

        if ($params !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn ($p) => ['type' => 'text', 'text' => (string) $p],
                    array_values($params)
                ),
            ]];
        }

        return $this->dispatch($payload, [
            'to_number' => $to,
            'to_name' => $toName,
            'channel_mode' => 'template',
            'template_name' => $template,
            'body' => implode(' | ', $params),
            'notification_id' => $notificationId,
            'notification_type' => $notificationType,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function sendText(
        string $to,
        string $body,
        ?int $notificationId = null,
        ?string $notificationType = null,
        ?string $toName = null,
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => mb_substr($body, 0, 4000)],
        ];

        return $this->dispatch($payload, [
            'to_number' => $to,
            'to_name' => $toName,
            'channel_mode' => 'text',
            'template_name' => null,
            'body' => $body,
            'notification_id' => $notificationId,
            'notification_type' => $notificationType,
        ]);
    }

    /**
     * POST to the Cloud API and write one audit row either way.
     *
     * Throws on failure so the queued job retries; the log row is written
     * before the throw so a permanent failure is still visible on the WhatsApp
     * Log page after the job gives up.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $logContext
     * @return array<string,mixed>
     */
    private function dispatch(array $payload, array $logContext): array
    {
        $s = $this->settings();

        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp is not configured (Admin -> Settings -> WhatsApp).');
        }

        $version = $s->whatsapp_api_version ?: self::DEFAULT_API_VERSION;
        $url = "https://graph.facebook.com/{$version}/{$s->whatsapp_phone_number_id}/messages";

        $status = 'failed';
        $wamid = null;
        $error = null;
        $data = [];

        try {
            $response = Http::withToken($s->whatsapp_access_token)
                ->acceptJson()
                ->timeout(20)
                ->post($url, $payload);

            $data = $response->json() ?? [];

            if ($response->successful()) {
                $status = 'sent';
                $wamid = data_get($data, 'messages.0.id');
            } else {
                // Meta puts the useful part in error.message / error.error_data.
                $error = data_get($data, 'error.message')
                    ?: data_get($data, 'error.error_data.details')
                    ?: ('HTTP '.$response->status().' '.mb_substr($response->body(), 0, 400));
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        try {
            WhatsappLog::create($logContext + [
                'status' => $status,
                'wamid' => $wamid,
                'error_message' => $error,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp log write failed: '.$e->getMessage());
        }

        if ($status !== 'sent') {
            throw new RuntimeException("WhatsApp send failed: {$error}");
        }

        return ['ok' => true, 'wamid' => $wamid, 'response' => $data];
    }

    /**
     * Credential check that costs nothing and sends no message: read the
     * phone number back from the Graph API.
     *
     * @return array{ok:bool,detail:string}
     */
    public function testConnection(): array
    {
        $s = $this->settings();

        if (blank($s->whatsapp_phone_number_id) || blank($s->whatsapp_access_token)) {
            return ['ok' => false, 'detail' => 'Phone Number ID and Access Token are both required.'];
        }

        $version = $s->whatsapp_api_version ?: self::DEFAULT_API_VERSION;

        try {
            $response = Http::withToken($s->whatsapp_access_token)
                ->acceptJson()
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$s->whatsapp_phone_number_id}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating',
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'detail' => data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status()),
                ];
            }

            $j = $response->json();

            return [
                'ok' => true,
                'detail' => sprintf(
                    'Connected as %s (%s), quality: %s',
                    $j['verified_name'] ?? 'unknown',
                    $j['display_phone_number'] ?? '-',
                    $j['quality_rating'] ?? '-',
                ),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
