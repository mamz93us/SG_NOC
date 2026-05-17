<?php

namespace App\Http\Requests\EmailMarketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-email-marketing') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('subscriber');
        $subscriberId = is_object($id) ? $id->id : $id;

        return [
            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('email_subscribers', 'email')->ignore($subscriberId),
            ],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'subscribed', 'unsubscribed', 'bounced', 'complained'])],
            'attributes' => ['nullable', 'array'],
            'list_ids' => ['nullable', 'array'],
            'list_ids.*' => ['integer', 'exists:email_lists,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:email_tags,id'],
        ];
    }
}
