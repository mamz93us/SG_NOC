<?php

namespace App\Http\Requests\EmailMarketing;

use Illuminate\Foundation\Http\FormRequest;

class ImportSubscribersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-email-marketing') ?? false;
    }

    public function rules(): array
    {
        return [
            'email_list_id' => ['required', 'integer', 'exists:email_lists,id'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
            'skip_header' => ['nullable', 'boolean'],
        ];
    }
}
