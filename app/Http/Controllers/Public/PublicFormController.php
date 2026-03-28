<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormToken;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PublicFormController extends Controller
{
    /**
     * GET /forms/{slug}[?token=xxx]
     * GET /my/forms/{slug}  (auth required)
     */
    public function show(string $slug, Request $request)
    {
        $form  = FormTemplate::where('slug', $slug)->firstOrFail();
        $token = null;

        if (! $form->isOpen()) {
            return view('public.form_submitted', [
                'form'    => $form,
                'error'   => true,
                'message' => 'This form is no longer accepting responses.',
            ]);
        }

        if ($form->visibility === 'private') {
            if (! Auth::check()) {
                return redirect()->route('login')->with('intended', $request->fullUrl());
            }
        }

        if ($form->visibility === 'token_only') {
            $token = $this->resolveToken($form, $request->query('token'));
            if (! $token) {
                return view('public.form_submitted', [
                    'form'    => $form,
                    'error'   => true,
                    'message' => 'This link is invalid, expired, or has already been used.',
                ]);
            }
        }

        return view('public.form', compact('form', 'token'));
    }

    /**
     * POST /forms/{slug}
     * POST /my/forms/{slug}
     */
    public function submit(string $slug, Request $request)
    {
        $form  = FormTemplate::where('slug', $slug)->firstOrFail();
        $token = null;

        if (! $form->isOpen()) {
            abort(422, 'This form is closed.');
        }

        if ($form->visibility === 'private' && ! Auth::check()) {
            abort(403);
        }

        if ($form->visibility === 'token_only') {
            $token = $this->resolveToken($form, $request->input('_form_token'));
            if (! $token) {
                return view('public.form_submitted', [
                    'form'    => $form,
                    'error'   => true,
                    'message' => 'This link is invalid or has already been used.',
                ]);
            }
        }

        // Build dynamic validation rules from schema
        $rules = $this->buildValidationRules($form->schema);
        $data  = $request->validate($rules);

        // Strip hidden helper fields
        unset($data['_form_token']);

        // Check max_submissions
        $maxSubs = $form->settings['max_submissions'] ?? null;
        if ($maxSubs && $form->submissions()->count() >= $maxSubs) {
            return view('public.form_submitted', [
                'form'    => $form,
                'error'   => true,
                'message' => 'This form has reached its maximum number of responses.',
            ]);
        }

        // Store submission
        $submission = FormSubmission::create([
            'form_id'         => $form->id,
            'token_id'        => $token?->id,
            'submitted_by'    => Auth::id(),
            'submitter_email' => $data['_email'] ?? ($form->settings['collect_email'] ? $request->input('_email') : null),
            'ip_address'      => $request->ip(),
            'data'            => $data,
            'status'          => 'new',
        ]);

        // Increment token uses
        if ($token && ($form->settings['one_per_token'] ?? true)) {
            $token->increment();
        }

        // Notify configured users
        $this->notifyReviewers($form, $submission);

        $confirmMessage = $form->settings['confirmation_message'] ?? 'Thank you! Your response has been recorded.';
        $redirectUrl    = $form->settings['redirect_url'] ?? null;

        if ($redirectUrl) {
            return redirect($redirectUrl)->with('success', $confirmMessage);
        }

        return view('public.form_submitted', [
            'form'    => $form,
            'error'   => false,
            'message' => $confirmMessage,
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────

    private function resolveToken(FormTemplate $form, ?string $tokenString): ?FormToken
    {
        if (! $tokenString) {
            return null;
        }

        $token = FormToken::where('form_id', $form->id)->where('token', $tokenString)->first();

        return ($token && $token->isValid()) ? $token : null;
    }

    /**
     * Dynamically build Laravel validation rules from the form schema.
     */
    private function buildValidationRules(array $schema): array
    {
        $rules = [];

        foreach ($schema as $field) {
            if (($field['type'] ?? '') === 'section') {
                continue; // section dividers are not inputs
            }

            $name     = $field['name'] ?? null;
            $required = ($field['required'] ?? false) ? 'required' : 'nullable';

            if (! $name) {
                continue;
            }

            $typeRules = match ($field['type'] ?? 'text') {
                'email'    => [$required, 'email', 'max:150'],
                'number'   => [$required, 'numeric'],
                'date'     => [$required, 'date'],
                'rating'   => [$required, 'integer', 'min:'.($field['min'] ?? 1), 'max:'.($field['max'] ?? 10)],
                'checkbox' => [$required, 'array'],
                'file'     => [$required, 'file', 'max:10240'],
                default    => [$required, 'string', 'max:2000'],
            };

            $rules[$name] = $typeRules;
        }

        return $rules;
    }

    private function notifyReviewers(FormTemplate $form, FormSubmission $submission): void
    {
        $userIds = $form->settings['notify_user_ids'] ?? [];

        foreach ($userIds as $userId) {
            Notification::create([
                'user_id'  => $userId,
                'type'     => 'system_alert',
                'severity' => 'info',
                'title'    => 'New Form Submission: ' . $form->name,
                'message'  => 'A new response was submitted to "' . $form->name . '".',
                'link'     => route('admin.forms.submission.show', [$form, $submission]),
                'is_read'  => false,
            ]);
        }
    }
}
