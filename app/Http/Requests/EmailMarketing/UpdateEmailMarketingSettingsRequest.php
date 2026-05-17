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
        ];
    }

    public function prepareForValidation(): void
    {
        // Bootstrap checkboxes post "on" or absent. Normalize to bool.
        $this->merge([
            'email_marketing_enabled' => $this->boolean('email_marketing_enabled'),
            'email_marketing_open_pixel_enabled' => $this->boolean('email_marketing_open_pixel_enabled'),
            'email_marketing_click_tracking_enabled' => $this->boolean('email_marketing_click_tracking_enabled'),
        ]);
    }
}
