<?php

namespace App\Http\Requests\EmailMarketing;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-email-marketing') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'preview_text' => ['nullable', 'string', 'max:255'],
            'design_json' => ['nullable', 'string'],
            'rendered_html' => ['nullable', 'string'],
        ];
    }
}
