<?php

namespace App\Services\Signature;

use App\Models\EmailSignatureTemplate;
use App\Models\Employee;
use App\Models\IdentityUser;

class SignatureRenderService
{
    /**
     * Render a template with the given variable map.
     * Supports:
     *   {{variable}}           — replaced with the value (empty string if missing)
     *   {{#if variable}}...{{/if}} — block removed entirely when the variable is blank
     */
    public function render(EmailSignatureTemplate $template, array $vars): string
    {
        $html = $template->html_body;

        // Inject template-level meta-variables so they are available inside {{#if}} blocks too
        $vars['logo_url']      = $vars['logo_url'] ?? $template->logo_url ?? '';
        $vars['primary_color'] = $vars['primary_color'] ?? $template->primary_color ?? '#d81f2a';
        $vars['year']          = $vars['year'] ?? date('Y');

        // 1. Process conditional blocks FIRST (before simple replacement)
        $html = preg_replace_callback(
            '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s',
            function (array $m) use ($vars): string {
                return !empty($vars[$m[1]]) ? $m[2] : '';
            },
            $html
        );

        // 2. Replace all {{variable}} placeholders
        foreach ($vars as $key => $value) {
            $html = str_replace('{{' . $key . '}}', (string) ($value ?? ''), $html);
        }

        // 3. Remove any leftover unfilled placeholders
        $html = preg_replace('/\{\{\w+\}\}/', '', $html);

        return $html;
    }

    /**
     * Build the variable map for an IdentityUser + their linked Employee/Branch.
     */
    public function varsForUser(IdentityUser $user): array
    {
        $employee = Employee::where('azure_id', $user->azure_id)->with('branch')->first();
        $branch   = $employee?->branch;

        $email = $user->mail ?: $user->user_principal_name;

        return [
            'name'           => $user->display_name ?? '',
            'first_name'     => explode(' ', $user->display_name ?? '')[0],
            'job_title'      => $user->job_title ?? $employee?->job_title ?? '',
            'department'     => $user->department ?? '',
            'company'        => $user->company_name ?? '',
            'email'          => $email,
            'phone'          => $user->phone_number ?? '',
            'mobile'         => $user->mobile_phone ?? $employee?->mobile_phone ?? '',
            'extension'      => $employee?->extension_number ?? '',
            'branch_name'    => $branch?->name ?? $user->office_location ?? '',
            'branch_city'    => $branch?->city ?? $user->city ?? '',
            'branch_address' => $branch?->street ?? $user->street_address ?? '',
            'branch_phone'   => $branch?->phone_number ?? '',
        ];
    }

    /**
     * Resolve a UPN to an IdentityUser, pick the best template, and return rendered HTML.
     * Returns null when the user or a matching template cannot be found.
     */
    public function resolveAndRender(string $upn, string $type = 'new_email', ?string $domain = null): ?string
    {
        $user = IdentityUser::where('user_principal_name', $upn)
            ->orWhere('mail', $upn)
            ->first();

        if (! $user) {
            return null;
        }

        // Auto-detect domain from UPN when not supplied
        if (! $domain) {
            $parts  = explode('@', $upn);
            $domain = count($parts) === 2 ? $parts[1] : null;
        }

        $template = EmailSignatureTemplate::findBest($type, $domain);

        if (! $template) {
            return null;
        }

        return $this->render($template, $this->varsForUser($user));
    }

    /**
     * Sample data used for the live editor preview.
     */
    public function sampleVars(): array
    {
        return [
            'name'           => 'Ahmed Al-Rashidi',
            'first_name'     => 'Ahmed',
            'job_title'      => 'Senior IT Engineer',
            'department'     => 'Information Technology',
            'company'        => 'Samir Group',
            'email'          => 'ahmed.alrashidi@samirgroup.com',
            'phone'          => '+966 11 234 5678',
            'mobile'         => '+966 50 123 4567',
            'extension'      => '2205',
            'branch_name'    => 'Riyadh Head Office',
            'branch_city'    => 'Riyadh',
            'branch_address' => 'King Fahd Road, Al Olaya District',
            'branch_phone'   => '+966 11 200 0000',
        ];
    }
}
