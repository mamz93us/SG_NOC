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
            .self::SIG_MARKER.'</span>';
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

        // Strip Outlook/OWA image-attachment artifacts left by pasting a signature out of
        // an email: data-imagetype="AttachmentByCid" makes Outlook look for a CID email
        // attachment that isn't there -> the logo shows as a broken image even though the
        // real src is a valid URL. data-custom / naturalheight / naturalwidth are noise.
        // Keep src / alt / width / height / style so the logo still renders correctly.
        $html = preg_replace('/\s(?:data-[\w-]+|natural(?:height|width))\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\s(?:data-[\w-]+|natural(?:height|width))\s*=\s*'[^']*'/i", '', $html);

        // Inject template-level meta-variables so they are available inside {{#if}} blocks too
        $vars['logo_url'] = $vars['logo_url'] ?? $template->logo_url ?? '';
        $vars['primary_color'] = $vars['primary_color'] ?? $template->primary_color ?? '#d81f2a';
        $vars['year'] = $vars['year'] ?? date('Y');

        // 1. Process conditional blocks FIRST (before simple replacement)
        $html = preg_replace_callback(
            '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s',
            function (array $m) use ($vars): string {
                return ! empty($vars[$m[1]]) ? $m[2] : '';
            },
            $html
        );

        // 2. Replace all {{variable}} placeholders
        foreach ($vars as $key => $value) {
            $html = str_replace('{{'.$key.'}}', (string) ($value ?? ''), $html);
        }

        // 3. Remove any leftover unfilled placeholders
        $html = preg_replace('/\{\{\w+\}\}/', '', $html);

        return $html.$this->markerHtml();
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

        // Strip Word/Outlook cruft that Exchange's disclaimer parser rejects (a Word-pasted
        // template otherwise fails with "Disclaimer text is invalid"). Order matters:
        // hidden conditional comments (with their content) first, then the rest.
        $html = preg_replace('/<!--\[if[\s\S]*?<!\[endif\]-->/i', '', $html);   // <!--[if ...]> ... <![endif]-->
        $html = preg_replace('/<!\[if[^\]]*\]>/i', '', $html);                  // revealed <![if ...]> (keep inner)
        $html = preg_replace('/<!\[endif\]>/i', '', $html);
        $html = preg_replace('/<xml[\s\S]*?<\/xml>/i', '', $html);              // Word xml islands
        $html = preg_replace('/<!--[\s\S]*?-->/', '', $html);                   // any remaining HTML comments
        $html = preg_replace('/<\/?(o|v|w|m|st1|x):[^>]*>/i', '', $html);       // namespaced tags: <o:p>, VML <v:*>, etc.
        $html = preg_replace('/\x{FEFF}/u', '', $html);                         // stray BOM/zero-width

        // Strip editor-noise attributes. Word Online / Outlook-on-the-web paste huge
        // class="…SCXW…BCX8", data-*, aria-*, role, lang, and id values that mean nothing
        // in an email but bloat the disclaimer far past the size limit (Oriana was 18KB).
        $html = preg_replace('/\s(?:class|data-[\w-]+|aria-[\w-]+|role|lang|id|dir|contenteditable|spellcheck|xml:lang)\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\s(?:class|data-[\w-]+|aria-[\w-]+|role|lang|id|dir)\s*=\s*'[^']*'/i", '', $html);

        // Remove empty inline/paragraph elements a Word paste leaves behind (many
        // <p>&nbsp;</p> / <span>&nbsp;</span>), looping for nested ones. This is what
        // actually shrinks a Word-pasted template enough to fit the disclaimer limit.
        for ($i = 0; $i < 5; $i++) {
            $html = preg_replace('/<(p|span|div)\b[^>]*>(?:\s|&nbsp;|&#160;|&#xA0;|<br\s*\/?>)*<\/\1>/i', '', $html, -1, $n);
            if (! $n) {
                break;
            }
        }
        $html = preg_replace('/(?:<br\s*\/?>\s*){3,}/i', '<br><br>', $html);    // collapse long <br> runs

        // Extension has no AD token (it is folded into the business phone) — drop its block.
        $html = preg_replace('/\{\{#if\s+extension\}\}.*?\{\{\/if\}\}/s', '', $html);

        // Flatten remaining {{#if x}}...{{/if}} — keep inner content unconditionally.
        $html = preg_replace('/\{\{#if\s+\w+\}\}(.*?)\{\{\/if\}\}/s', '$1', $html);

        // Bake in static, template-level meta (same for every sender on this template).
        $html = str_replace('{{logo_url}}', (string) ($template->logo_url ?? ''), $html);
        $html = str_replace('{{primary_color}}', (string) ($template->primary_color ?? '#d81f2a'), $html);
        $html = str_replace('{{year}}', date('Y'), $html);

        // Map per-user NOC variables → Exchange AD-attribute tokens.
        // branch_phone maps to the business phone too — SSS templates use it for the office line.
        $map = [
            'name' => '%%DisplayName%%',
            'first_name' => '%%FirstName%%',
            'job_title' => '%%Title%%',
            'department' => '%%Department%%',
            'company' => '%%Company%%',
            'email' => '%%Email%%',
            'phone' => '%%PhoneNumber%%',
            'branch_phone' => '%%PhoneNumber%%',
            'mobile' => '%%MobileNumber%%',
            'extension' => '%%FaxNumber%%',   // extension carried in the Azure fax field
            'branch_name' => '%%Office%%',
            'branch_city' => '%%City%%',
            'branch_address' => '%%StreetAddress%%',
        ];
        foreach ($map as $var => $token) {
            $html = str_replace('{{'.$var.'}}', $token, $html);
        }

        // Drop any leftover placeholders with no AD token (extension, stray tags).
        $html = preg_replace('/\{\{[#\/]?\w+\}\}/', '', $html);

        // Collapse the whitespace bloat a Word paste leaves behind (long runs of blank
        // lines/spaces) so the disclaimer stays well under the Exchange size limit.
        $html = preg_replace('/[ \t]+/', ' ', $html);        // runs of spaces/tabs -> one
        $html = preg_replace('/(\r?\n\s*){2,}/', "\n", $html); // 2+ blank lines -> one
        $html = trim($html);

        return $html.$this->markerHtml();
    }

    /**
     * Build the variable map for an IdentityUser + their linked Employee/Branch.
     */
    public function varsForUser(IdentityUser $user): array
    {
        $employee = Employee::where('azure_id', $user->azure_id)->with(['branch', 'department'])->first();
        $branch = $employee?->branch;

        // NOC employee profile is the source of truth; fall back to the Azure cache.
        $name = $employee?->name ?: $user->display_name ?? '';

        return [
            'name' => $name,
            'first_name' => explode(' ', trim($name))[0] ?? '',
            'job_title' => $employee?->job_title ?: $user->job_title ?? '',
            'department' => $employee?->department?->name ?: $employee?->oracle_department ?: $user->department ?? '',
            'company' => $employee?->company ?: $user->company_name ?? '',
            'email' => $employee?->email ?: $user->mail ?: $user->user_principal_name,
            'phone' => $employee?->work_phone ?: $user->phone_number ?? '',
            'mobile' => $employee?->mobile_phone ?: $user->mobile_phone ?? '',
            'extension' => $employee?->extension_number ?? '',
            'branch_name' => $employee?->office_location ?: $branch?->name ?: $user->office_location ?? '',
            'branch_city' => $employee?->city ?: $branch?->city ?: $user->city ?? '',
            'branch_address' => $employee?->street_address ?: $branch?->street ?: $user->street_address ?? '',
            'branch_phone' => $branch?->phone_number ?? '',
        ];
    }

    /**
     * Resolve a UPN to an IdentityUser, pick the best template, and return rendered HTML.
     * Returns null when the user or a matching template cannot be found.
     *
     * @param  array|null  $meta  Filled with resolution details for audit logging:
     *                            [found_user, domain, gender, template, resolved_name].
     */
    public function resolveAndRender(string $upn, string $type = 'new_email', ?string $domain = null, ?array &$meta = null): ?string
    {
        $meta = ['found_user' => false, 'domain' => $domain, 'gender' => null, 'template' => null, 'resolved_name' => null];

        $user = IdentityUser::where('user_principal_name', $upn)
            ->orWhere('mail', $upn)
            ->first();

        if (! $user) {
            return null;
        }
        $meta['found_user'] = true;

        // Auto-detect domain from UPN when not supplied
        if (! $domain) {
            $parts = explode('@', $upn);
            $domain = count($parts) === 2 ? $parts[1] : null;
        }
        $meta['domain'] = $domain;

        // Pick the gendered template variant based on the employee's profile.
        $gender = Employee::where('azure_id', $user->azure_id)->value('gender');
        $meta['gender'] = $gender;
        $template = EmailSignatureTemplate::findBest($type, $domain, $gender);

        if (! $template) {
            return null;
        }
        $meta['template'] = $template;

        $vars = $this->varsForUser($user);
        $meta['resolved_name'] = $vars['name'] ?: ($user->display_name ?? null);

        return $this->render($template, $vars);
    }

    /**
     * Sample data used for the live editor preview.
     */
    public function sampleVars(): array
    {
        return [
            'name' => 'Ahmed Al-Rashidi',
            'first_name' => 'Ahmed',
            'job_title' => 'Senior IT Engineer',
            'department' => 'Information Technology',
            'company' => 'Samir Group',
            'email' => 'ahmed.alrashidi@samirgroup.com',
            'phone' => '+966 11 234 5678',
            'mobile' => '+966 50 123 4567',
            'extension' => '2205',
            'branch_name' => 'Riyadh Head Office',
            'branch_city' => 'Riyadh',
            'branch_address' => 'King Fahd Road, Al Olaya District',
            'branch_phone' => '+966 11 200 0000',
        ];
    }
}
