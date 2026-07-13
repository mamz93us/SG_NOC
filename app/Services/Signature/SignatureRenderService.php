<?php

namespace App\Services\Signature;

use App\Models\EmailSignatureTemplate;
use App\Models\Employee;
use App\Models\IdentityUser;

class SignatureRenderService
{
    /**
     * Hidden marker embedded in every rendered signature so a server-side Exchange
     * transport rule can detect an already-signed message and skip it (dedup — stops
     * classic-Outlook mail, which carries the client signature, being double-signed).
     */
    public const SIG_MARKER = 'SGSIGMARKER';

    private function markerHtml(): string
    {
        return '<span style="display:none;mso-hide:all;font-size:1px;line-height:0;color:#ffffff;">'
            . self::SIG_MARKER . '</span>';
    }

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

        return $html . $this->markerHtml();
    }

    /**
     * Render a template for an Exchange transport-rule disclaimer (New Outlook / OWA /
     * mobile): NOC variables mapped to Exchange %%AD-attribute%% tokens, {{#if}} blocks
     * flattened (no per-message logic server-side), static template meta baked in, and
     * the dedup marker appended. Per-user values are filled by Exchange at send time
     * from Azure AD — which NOC already populates via AzureContactSyncService.
     */
    public function renderForTransportRule(EmailSignatureTemplate $template): string
    {
        $html = $template->html_body;

        // Extension has no AD token (it is folded into the business phone) — drop its block.
        $html = preg_replace('/\{\{#if\s+extension\}\}.*?\{\{\/if\}\}/s', '', $html);

        // Flatten remaining {{#if x}}...{{/if}} — keep inner content unconditionally.
        $html = preg_replace('/\{\{#if\s+\w+\}\}(.*?)\{\{\/if\}\}/s', '$1', $html);

        // Bake in static, template-level meta (same for every sender on this template).
        $html = str_replace('{{logo_url}}',      (string) ($template->logo_url ?? ''), $html);
        $html = str_replace('{{primary_color}}', (string) ($template->primary_color ?? '#d81f2a'), $html);
        $html = str_replace('{{year}}',          date('Y'), $html);

        // Map per-user NOC variables → Exchange AD-attribute tokens.
        $map = [
            'name'           => '%%DisplayName%%',
            'first_name'     => '%%FirstName%%',
            'job_title'      => '%%Title%%',
            'department'     => '%%Department%%',
            'company'        => '%%Company%%',
            'email'          => '%%Email%%',
            'phone'          => '%%PhoneNumber%%',
            'mobile'         => '%%MobileNumber%%',
            'branch_name'    => '%%Office%%',
            'branch_city'    => '%%City%%',
            'branch_address' => '%%StreetAddress%%',
        ];
        foreach ($map as $var => $token) {
            $html = str_replace('{{' . $var . '}}', $token, $html);
        }

        // Drop any leftover placeholders with no AD token (extension, branch_phone, stray tags).
        $html = preg_replace('/\{\{[#\/]?\w+\}\}/', '', $html);

        return $html . $this->markerHtml();
    }

    /**
     * Build the variable map for an IdentityUser + their linked Employee/Branch.
     */
    public function varsForUser(IdentityUser $user): array
    {
        $employee = Employee::where('azure_id', $user->azure_id)->with(['branch', 'department'])->first();
        $branch   = $employee?->branch;

        // NOC employee profile is the source of truth; fall back to the Azure cache.
        $name = $employee?->name ?: $user->display_name ?? '';

        return [
            'name'           => $name,
            'first_name'     => explode(' ', trim($name))[0] ?? '',
            'job_title'      => $employee?->job_title ?: $user->job_title ?? '',
            'department'     => $employee?->department?->name ?: $employee?->oracle_department ?: $user->department ?? '',
            'company'        => $employee?->company ?: $user->company_name ?? '',
            'email'          => $employee?->email ?: $user->mail ?: $user->user_principal_name,
            'phone'          => $employee?->work_phone ?: $user->phone_number ?? '',
            'mobile'         => $employee?->mobile_phone ?: $user->mobile_phone ?? '',
            'extension'      => $employee?->extension_number ?? '',
            'branch_name'    => $employee?->office_location ?: $branch?->name ?: $user->office_location ?? '',
            'branch_city'    => $employee?->city ?: $branch?->city ?: $user->city ?? '',
            'branch_address' => $employee?->street_address ?: $branch?->street ?: $user->street_address ?? '',
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
