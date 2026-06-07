<?php

namespace App\Http\Requests\EmailMarketing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailMarketingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-email-marketing-settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'email_marketing_enabled' => ['nullable', 'boolean'],
            'ses_region' => ['nullable', 'string', 'max:32'],
            'ses_access_key_id' => ['nullable', 'string', 'max:128'],
            'ses_secret_access_key' => ['nullable', 'string', 'max:255'],
            'ses_configuration_set' => ['nullable', 'string', 'max:128'],
            'ses_default_from_email' => ['nullable', 'email', 'max:191'],
            'ses_default_from_name' => ['nullable', 'string', 'max:191'],
            'ses_default_reply_to' => ['nullable', 'email', 'max:191'],
            'ses_throttle_per_second' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'ses_unsubscribe_base_url' => ['nullable', 'url', 'max:255'],
            'sns_topic_arn' => ['nullable', 'string', 'max:255'],
            'email_marketing_event_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'email_marketing_open_pixel_enabled' => ['nullable', 'boolean'],
            'email_marketing_click_tracking_enabled' => ['nullable', 'boolean'],
            'marketing_domain' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'email_marketing_internal_domains' => ['nullable', 'string', 'max:500'],
            'email_marketing_require_all_approval' => ['nullable', 'boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        // Bootstrap checkboxes post "on" or absent. Normalize to bool.
        $this->merge([
            'email_marketing_enabled' => $this->boolean('email_marketing_enabled'),
            'email_marketing_open_pixel_enabled' => $this->boolean('email_marketing_open_pixel_enabled'),
            'email_marketing_click_tracking_enabled' => $this->boolean('email_marketing_click_tracking_enabled'),
            'email_marketing_require_all_approval' => $this->boolean('email_marketing_require_all_approval'),
        ]);

        // Accept a pasted URL ("https://em.samirgroup.net/") and store just the host.
        if ($this->filled('marketing_domain')) {
            $host = strtolower(trim((string) $this->input('marketing_domain')));
            $host = preg_replace('#^https?://#', '', $host);
            $host = explode('/', $host)[0];
            $this->merge(['marketing_domain' => $host]);
        }
    }
}
