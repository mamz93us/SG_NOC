<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Notifications\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Import / set the WhatsApp Cloud API credentials.
 *
 * Exists so the token can be moved from the UBL portal — where it sits
 * hardcoded in `SamirGroup/settings.py` — into the NOC's encrypted settings
 * without it ever passing through this repo, a shell history entry, or the
 * command's own output.
 */
class ConfigureWhatsapp extends Command
{
    protected $signature = 'whatsapp:configure
        {--from-django= : Path to a Django settings.py holding WHATSAPP_ACCESS_TOKEN / WHATSAPP_PHONE_NUMBER_ID (e.g. the UBL portal)}
        {--token= : Permanent access token (prompted for if omitted)}
        {--phone-number-id= : WhatsApp Cloud API phone number ID}
        {--business-account-id= : WhatsApp Business Account ID (auto-discovered when omitted)}
        {--api-version= : Graph API version, default v21.0}
        {--template= : Approved template name to send alerts through}
        {--language= : Template language code, default en}
        {--params= : Ordered body parameter tokens, default title,message}
        {--country-code= : Default country code for numbers stored in national form}
        {--enable : Switch the channel on}
        {--test : Verify the credentials against Graph afterwards and list approved templates}';

    protected $description = 'Import or set the WhatsApp Cloud API credentials (Admin -> Settings -> WhatsApp)';

    public function handle(WhatsAppService $whatsapp): int
    {
        $settings = Setting::get();

        $token = $this->option('token');
        $phoneNumberId = $this->option('phone-number-id');

        if ($path = $this->option('from-django')) {
            [$token, $phoneNumberId] = $this->readDjangoSettings($path, $token, $phoneNumberId);

            if ($token === null && $phoneNumberId === null) {
                return self::FAILURE;
            }
        }

        // Prompted rather than passed on the command line so the token does not
        // land in shell history or the process list.
        if (! $token && ! $settings->whatsapp_access_token) {
            $token = $this->secret('Permanent access token (leave blank to keep current)') ?: null;
        }

        if (! $phoneNumberId && ! $settings->whatsapp_phone_number_id) {
            $phoneNumberId = $this->ask('Phone Number ID') ?: null;
        }

        $before = [
            'whatsapp_enabled' => (bool) $settings->whatsapp_enabled,
            'whatsapp_phone_number_id' => $settings->whatsapp_phone_number_id,
            'whatsapp_alert_template' => $settings->whatsapp_alert_template,
        ];

        if ($token) {
            $settings->whatsapp_access_token = $token;
        }
        if ($phoneNumberId) {
            $settings->whatsapp_phone_number_id = trim($phoneNumberId);
        }
        if ($v = $this->option('business-account-id')) {
            $settings->whatsapp_business_account_id = trim($v);
        }
        if ($v = $this->option('template')) {
            $settings->whatsapp_alert_template = trim($v);
        }
        if ($v = $this->option('params')) {
            $settings->whatsapp_template_body_params = trim($v);
        }
        if ($v = $this->option('country-code')) {
            $settings->whatsapp_default_country_code = preg_replace('/\D+/', '', $v) ?: null;
        }

        $settings->whatsapp_api_version = $this->option('api-version') ?: ($settings->whatsapp_api_version ?: 'v21.0');
        $settings->whatsapp_template_language = $this->option('language') ?: ($settings->whatsapp_template_language ?: 'en');
        $settings->whatsapp_template_body_params = $settings->whatsapp_template_body_params ?: 'title,message';

        if ($this->option('enable')) {
            $settings->whatsapp_enabled = true;
        }

        $settings->save();

        // The credentials are already saved by this point; a failed audit write
        // must not abort the run and leave the operator thinking nothing landed.
        try {
            ActivityLog::create([
                'model_type' => 'Setting',
                'model_id' => 1,
                'action' => 'whatsapp_configured_via_cli',
                'changes' => [
                    'before' => $before,
                    'after' => [
                        'whatsapp_enabled' => (bool) $settings->whatsapp_enabled,
                        'whatsapp_phone_number_id' => $settings->whatsapp_phone_number_id,
                        'whatsapp_alert_template' => $settings->whatsapp_alert_template,
                    ],
                    // Never log the token, only whether it changed.
                    'token_replaced' => (bool) $token,
                ],
                'user_id' => null,
            ]);
        } catch (\Throwable $e) {
            $this->warn('Settings saved, but the activity log write failed: '.$e->getMessage());
        }

        $this->newLine();
        $this->line('  Phone Number ID .... '.($settings->whatsapp_phone_number_id ?: '<not set>'));
        $this->line('  Access token ....... '.$this->fingerprint($settings->whatsapp_access_token));
        $this->line('  API version ........ '.$settings->whatsapp_api_version);
        $this->line('  Template ........... '.($settings->whatsapp_alert_template ?: '<not set — alerts cannot send>'));
        $this->line('  Template language .. '.$settings->whatsapp_template_language);
        $this->line('  Body parameters .... '.$settings->whatsapp_template_body_params);
        $this->line('  Country code ....... '.($settings->whatsapp_default_country_code ?: '<none>'));
        $this->line('  Channel ............ '.($settings->whatsapp_enabled ? 'ENABLED' : 'disabled (pass --enable)'));
        $this->newLine();

        if ($issue = $whatsapp->configurationIssue()) {
            $this->warn('Not sendable yet: '.$issue);
            $this->newLine();
        }

        if ($this->option('test')) {
            return $this->verify($whatsapp, $settings);
        }

        $this->info('Saved. Re-run with --test to check the credentials against Meta.');

        return self::SUCCESS;
    }

    /**
     * Pull the two values out of a Django settings.py.
     *
     * @return array{0:?string,1:?string} token, phone number id
     */
    private function readDjangoSettings(string $path, ?string $token, ?string $phoneNumberId): array
    {
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return [null, null];
        }

        $source = file_get_contents($path) ?: '';

        $grab = function (string $key) use ($source): ?string {
            // Matches KEY = "value" or KEY = 'value'.
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]/m', $source, $m)) {
                return $m[1];
            }

            return null;
        };

        $token ??= $grab('WHATSAPP_ACCESS_TOKEN');
        $phoneNumberId ??= $grab('WHATSAPP_PHONE_NUMBER_ID');

        if (! $token && ! $phoneNumberId) {
            $this->error("No WHATSAPP_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID found in {$path}");

            return [null, null];
        }

        $this->info('Imported from '.$path.':');
        $this->line('  token '.($token ? $this->fingerprint($token) : 'not found'));
        $this->line('  phone number id '.($phoneNumberId ?: 'not found'));

        return [$token, $phoneNumberId];
    }

    private function verify(WhatsAppService $whatsapp, Setting $settings): int
    {
        $result = $whatsapp->testConnection();

        if (! $result['ok']) {
            $this->error('Credential check failed: '.$result['detail']);

            return self::FAILURE;
        }

        $this->info('Credentials OK — '.$result['detail']);
        $this->newLine();

        $templates = $whatsapp->listTemplates();

        if (! $templates['ok']) {
            $this->warn('Could not list templates: '.$templates['detail']);
            $this->warn('The channel still works; you just cannot verify the template shape from here.');

            return self::SUCCESS;
        }

        $this->line('Approved templates on business account '.$templates['detail'].':');
        $this->table(
            ['Name', 'Lang', 'Status', 'Category', 'Params'],
            collect($templates['templates'])->map(fn ($t) => [
                $t['name'], $t['language'], $t['status'], $t['category'], $t['params'],
            ])->all()
        );

        $configured = count(array_filter(explode(',', (string) $settings->whatsapp_template_body_params)));
        $match = collect($templates['templates'])
            ->firstWhere('name', $settings->whatsapp_alert_template);

        if (! $settings->whatsapp_alert_template) {
            $this->warn('No template selected — pick one above and re-run with --template=NAME --params=…');
        } elseif (! $match) {
            $this->error("Configured template '{$settings->whatsapp_alert_template}' is not in the approved list above.");
        } elseif ($match['status'] !== 'APPROVED') {
            $this->error("Template '{$match['name']}' is {$match['status']}, not APPROVED — sends will fail.");
        } elseif ($match['params'] !== $configured) {
            $this->error("Template '{$match['name']}' takes {$match['params']} parameter(s) but {$configured} are configured — sends fail with error 132000.");
        } else {
            $this->info("Template '{$match['name']}' matches the configured parameters.");
        }

        return self::SUCCESS;
    }

    /**
     * Enough of a secret to tell two apart, never enough to use.
     */
    private function fingerprint(?string $secret): string
    {
        if (blank($secret)) {
            return '<not set>';
        }

        return substr($secret, 0, 4).str_repeat('.', 8).substr($secret, -4)
            .' ('.strlen($secret).' chars)';
    }
}
