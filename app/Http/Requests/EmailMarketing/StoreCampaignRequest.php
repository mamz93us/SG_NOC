<?php

namespace App\Http\Requests\EmailMarketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-email-marketing') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'preview_text' => ['nullable', 'string', 'max:255'],
            // from_email MUST come from the admin-managed sender allowlist —
            // free-text isn't accepted. (No exists rule against email_sender_identities
            // because the table may be empty on a fresh install; we surface a clearer
            // error message via `in:` against the loaded list in the controller layer.)
            'from_email' => ['required', 'email', 'max:191',
                \Illuminate\Validation\Rule::in(
                    \App\Models\EmailMarketing\EmailSenderIdentity::active()->pluck('email')->all()
                ),
            ],
            'from_name' => ['required', 'string', 'max:191'],
            'reply_to' => ['nullable', 'email', 'max:191'],
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'email_list_id' => ['nullable', 'integer', 'exists:email_lists,id', 'required_without:email_segment_id'],
            'email_segment_id' => ['nullable', 'integer', 'exists:email_segments,id', 'required_without:email_list_id'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'status' => ['nullable', Rule::in(['draft', 'scheduled'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('email_list_id') && $this->input('email_segment_id')) {
                $v->errors()->add('email_list_id', 'Choose either a list or a segment, not both.');
            }
        });
    }
}
