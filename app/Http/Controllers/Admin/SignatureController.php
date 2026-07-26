<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\EmailSignatureTemplate;
use App\Models\HrApiKey;
use App\Models\IdentityUser;
use App\Models\SignatureRequestLog;
use App\Services\Signature\SignatureRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SignatureController extends Controller
{
    public function __construct(private readonly SignatureRenderService $renderer) {}

    // ─── Admin CRUD ───────────────────────────────────────────────

    public function index(): View
    {
        $templates = EmailSignatureTemplate::orderBy('sort_order')->orderBy('name')->get();

        return view('admin.signatures.index', compact('templates'));
    }

    public function create(): View
    {
        $domains = AllowedDomain::orderBy('is_primary', 'desc')->orderBy('domain')->get();
        $template = null;
        $default = $this->defaultNewEmailHtml();

        return view('admin.signatures.edit', compact('template', 'domains', 'default'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        EmailSignatureTemplate::create($data);

        return redirect()->route('admin.signatures.index')
            ->with('success', 'Signature template created.');
    }

    public function edit(EmailSignatureTemplate $signature): View
    {
        $domains = AllowedDomain::orderBy('is_primary', 'desc')->orderBy('domain')->get();
        $template = $signature;
        $default = null;

        return view('admin.signatures.edit', compact('template', 'domains', 'default'));
    }

    public function update(Request $request, EmailSignatureTemplate $signature): RedirectResponse
    {
        $data = $this->validated($request);
        $signature->update($data);

        return redirect()->route('admin.signatures.index')
            ->with('success', 'Signature template updated.');
    }

    public function destroy(EmailSignatureTemplate $signature): RedirectResponse
    {
        $signature->delete();

        return redirect()->route('admin.signatures.index')
            ->with('success', 'Signature template deleted.');
    }

    public function duplicate(EmailSignatureTemplate $signature): RedirectResponse
    {
        $copy = $signature->replicate();
        $copy->name .= ' (copy)';
        $copy->is_active = false;
        $copy->save();

        return redirect()->route('admin.signatures.edit', $copy)
            ->with('success', 'Template duplicated — edit and activate when ready.');
    }

    // ─── Live preview (called from the editor via fetch) ──────────

    /** Preview a saved template by ID — used by the index page quick-preview. */
    public function previewSaved(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $upn = $request->input('upn');

        $template = EmailSignatureTemplate::find($id);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }

        if ($upn) {
            $user = IdentityUser::where('user_principal_name', $upn)
                ->orWhere('mail', $upn)
                ->first();
            $vars = $user ? $this->renderer->varsForUser($user) : $this->renderer->sampleVars();
        } else {
            $vars = $this->renderer->sampleVars();
        }

        return response()->json(['html' => $this->renderer->render($template, $vars)]);
    }

    /** Preview raw HTML from the editor in real-time (unsaved). */
    public function preview(Request $request): JsonResponse
    {
        $html = $request->input('html_body', '');
        $color = $request->input('primary_color', '#d81f2a');
        $logoUrl = $request->input('logo_url', '');
        $upn = $request->input('upn');

        // Resolve the variable map (real employee when a UPN is given, else sample data)
        if ($upn) {
            $user = IdentityUser::where('user_principal_name', $upn)
                ->orWhere('mail', $upn)
                ->first();
            $vars = $user ? $this->renderer->varsForUser($user) : $this->renderer->sampleVars();
        } else {
            $vars = $this->renderer->sampleVars();
        }

        // Always render the template CURRENTLY being edited (WYSIWYG) — never swap in
        // another saved template. The New-email/Reply toggle only reframes the mock email.
        $fake = new EmailSignatureTemplate([
            'html_body' => $html,
            'primary_color' => $color,
            'logo_url' => $logoUrl,
        ]);

        return response()->json([
            'html' => $this->renderer->render($fake, $vars),
            'source' => 'live',
        ]);
    }

    // ─── Public API (called by Intune scripts + Graph job) ────────

    /**
     * GET /api/signature?upn=user@domain.com&type=new_email&api_key=...
     *
     * type: new_email | reply (default: new_email)
     * domain: optional — auto-detected from UPN when omitted
     * api_key: must match SIGNATURE_API_KEY in .env
     */
    public function apiRender(Request $request): Response|JsonResponse
    {
        $upn = $request->query('upn');
        $type = in_array($request->query('type'), ['new_email', 'reply']) ? $request->query('type') : 'new_email';
        $domain = $request->query('domain') ?: null;

        // Auth: accept key via ?api_key= (Intune scripts) or Authorization: Bearer (Graph job)
        $raw = $request->query('api_key') ?? $request->bearerToken();
        if (empty($raw)) {
            $this->logRequest($request, 'render', $upn, $type, $domain, 'unauthorized');

            return response()->json(['error' => 'API key required. Pass ?api_key= or Authorization: Bearer.'], 401);
        }
        $apiKey = HrApiKey::findByRawKey($raw, 'signature');
        if (! $apiKey) {
            $this->logRequest($request, 'render', $upn, $type, $domain, 'unauthorized');

            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }
        try {
            $apiKey->recordUsage($request->ip());
        } catch (\Throwable) {
        }

        if (! $upn) {
            $this->logRequest($request, 'render', null, $type, $domain, 'bad_request', apiKeyId: $apiKey->id);

            return response()->json(['error' => 'upn parameter required'], 400);
        }

        $meta = null;
        $html = $this->renderer->resolveAndRender($upn, $type, $domain, $meta);

        if ($html === null) {
            $this->logRequest($request, 'render', $upn, $type, $meta['domain'] ?? $domain, 'not_found', apiKeyId: $apiKey->id, meta: $meta);

            return response()->json(['error' => 'No template or user found'], 404);
        }

        $this->logRequest($request, 'render', $upn, $type, $meta['domain'] ?? $domain, 'ok', apiKeyId: $apiKey->id, meta: $meta);

        if ($request->query('format') === 'json') {
            return response()->json(['html' => $html, 'upn' => $upn, 'type' => $type]);
        }

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Write one audit row for a signature API call (best-effort; never breaks the response). */
    private function logRequest(
        Request $request,
        string $endpoint,
        ?string $upn,
        ?string $type,
        ?string $domain,
        string $status,
        ?int $apiKeyId = null,
        ?array $meta = null,
    ): void {
        try {
            SignatureRequestLog::create([
                'upn' => $upn ? mb_substr($upn, 0, 255) : null,
                'endpoint' => $endpoint,
                'type' => $type,
                'domain' => $domain,
                'gender' => $meta['gender'] ?? null,
                'template_id' => $meta['template']->id ?? null,
                'template_name' => $meta['template']->name ?? null,
                'resolved_name' => $meta['resolved_name'] ?? null,
                'status' => $status,
                'ip' => $request->ip(),
                'api_key_id' => $apiKeyId,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ]);
        } catch (\Throwable) {
            // Logging must never take down the signature endpoint.
        }
    }

    /**
     * GET /admin/signatures/log — audit page: every signature request, who asked,
     * and what NOC served. Gated by manage-signatures (same as the templates CRUD).
     */
    public function log(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $domain = $request->query('domain');

        $logs = SignatureRequestLog::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('upn', 'like', "%{$q}%")
                ->orWhere('resolved_name', 'like', "%{$q}%")
                ->orWhere('template_name', 'like', "%{$q}%")))
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($domain, fn ($w) => $w->where('domain', $domain))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $domains = SignatureRequestLog::query()
            ->whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain');

        $stats = [
            'total' => SignatureRequestLog::count(),
            'today' => SignatureRequestLog::whereDate('created_at', today())->count(),
            'ok' => SignatureRequestLog::where('status', 'ok')->count(),
            'missing' => SignatureRequestLog::where('status', 'not_found')->count(),
            'people' => SignatureRequestLog::where('status', 'ok')->distinct('upn')->count('upn'),
        ];

        return view('admin.signatures.log', compact('logs', 'q', 'status', 'domain', 'domains', 'stats'));
    }

    /**
     * GET /api/signature/transport-rule?domain=sssegypt.com&type=new_email&api_key=...
     *
     * Returns the domain's signature as Exchange transport-rule HTML: NOC variables
     * mapped to %%AD-attribute%% tokens, {{#if}} flattened, dedup marker appended.
     * Consumed by Deploy-TransportRules.ps1 to set the per-domain mail-flow rule for
     * New Outlook / OWA / mobile. Same signature-scoped API-key auth as apiRender.
     */
    public function transportRuleHtml(Request $request): Response|JsonResponse
    {
        $raw = $request->query('api_key') ?? $request->bearerToken();
        if (empty($raw)) {
            return response()->json(['error' => 'API key required. Pass ?api_key= or Authorization: Bearer.'], 401);
        }
        $apiKey = HrApiKey::findByRawKey($raw, 'signature');
        if (! $apiKey) {
            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }
        try {
            $apiKey->recordUsage($request->ip());
        } catch (\Throwable) {
        }

        $domain = $request->query('domain');
        $type = in_array($request->query('type'), ['new_email', 'reply']) ? $request->query('type') : 'new_email';
        $gender = in_array($request->query('gender'), ['male', 'female']) ? $request->query('gender') : null;

        if (! $domain) {
            return response()->json(['error' => 'domain parameter required'], 400);
        }

        $template = EmailSignatureTemplate::findBest($type, $domain, $gender);
        if (! $template) {
            $this->logRequest($request, 'transport_rule', null, $type, $domain, 'not_found', apiKeyId: $apiKey->id, meta: ['gender' => $gender]);

            return response()->json(['error' => 'No active template for this domain'], 404);
        }
        $this->logRequest($request, 'transport_rule', null, $type, $domain, 'ok', apiKeyId: $apiKey->id, meta: ['gender' => $gender, 'template' => $template]);

        $html = $this->renderer->renderForTransportRule($template);

        if ($request->query('format') === 'json') {
            return response()->json(['html' => $html, 'domain' => $domain, 'type' => $type]);
        }

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * GET /api/signature/gender-members?domain=samirgroup.com&gender=male&api_key=...
     *
     * Returns the active UPNs for a domain, optionally filtered by gender, so the
     * Exchange mail groups that scope the transport rules can be populated from NOC data.
     * Omit gender (or gender=all) to list every active user of the domain — used for the
     * gender-neutral SSS group. Same signature-scoped API-key auth.
     */
    public function genderMembers(Request $request): JsonResponse
    {
        $raw = $request->query('api_key') ?? $request->bearerToken();
        if (empty($raw)) {
            return response()->json(['error' => 'API key required.'], 401);
        }
        $apiKey = HrApiKey::findByRawKey($raw, 'signature');
        if (! $apiKey) {
            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }
        try {
            $apiKey->recordUsage($request->ip());
        } catch (\Throwable) {
        }

        $domain = strtolower((string) $request->query('domain'));
        $gender = in_array($request->query('gender'), ['male', 'female'], true) ? $request->query('gender') : null;

        if (! $domain) {
            return response()->json(['error' => 'domain required'], 400);
        }

        // Male group is the catch-all for male + unknown-gender (matches the signature's
        // unknown->male default), so gender-less users still land in a transport-rule group.
        // Female group is female only. No gender param = everyone in the domain.
        $upns = \App\Models\Employee::when($gender === 'male', fn ($q) => $q->where(fn ($w) => $w->where('gender', 'male')->orWhereNull('gender')))
            ->when($gender === 'female', fn ($q) => $q->where('gender', 'female'))
            ->where('status', 'active')
            ->whereNotNull('azure_id')
            ->with('identityUser')
            ->get()
            ->map(fn ($e) => strtolower((string) ($e->identityUser?->user_principal_name ?: $e->email)))
            ->filter(fn ($u) => $u !== '' && str_ends_with($u, '@'.$domain))
            ->unique()
            ->sort()
            ->values();

        $this->logRequest($request, 'gender_members', null, null, $domain, 'ok', apiKeyId: $apiKey->id, meta: ['gender' => $gender]);

        return response()->json([
            'domain' => $domain,
            'gender' => $gender ?? 'all',
            'count' => $upns->count(),
            'upns' => $upns,
        ]);
    }

    /**
     * GET /signature/deploy.ps1?api_key=...
     *
     * Serves the classic-Outlook per-account deploy script so it can be fetched and
     * run on a device in one line. Gated by the signature API key (the script itself
     * carries the same key baked in), so it is not a fully-public download.
     */
    public function downloadScript(Request $request): Response
    {
        $raw = $request->query('api_key') ?? $request->bearerToken();
        $apiKey = $raw ? HrApiKey::findByRawKey($raw, 'signature') : null;
        if (! $apiKey) {
            return response("# Invalid or missing signature API key.\n", 401)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }
        try {
            $apiKey->recordUsage($request->ip());
        } catch (\Throwable) {
        }

        $path = base_path('deployment/signature/Deploy-Signature.ps1');
        if (! is_file($path)) {
            return response("# Deploy-Signature.ps1 not found on the server.\n", 404)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:200',
            'domain' => 'nullable|string|max:100',
            'type' => 'required|in:new_email,reply,all',
            'gender' => 'required|in:all,male,female',
            'logo_url' => 'nullable|url|max:500',
            'primary_color' => 'nullable|regex:/^#[0-9a-fA-F]{3,8}$/',
            'html_body' => 'required|string',
            'plain_text_body' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0|max:9999',
        ]);
    }

    private function defaultNewEmailHtml(): string
    {
        return <<<'HTML'
<table cellpadding="0" cellspacing="0" border="0" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #333333; line-height: 1.5; max-width: 560px;">
  <tr>
    <td style="padding-right: 18px; border-right: 3px solid {{primary_color}}; vertical-align: top; padding-top: 4px;">
      {{#if logo_url}}
      <img src="{{logo_url}}" alt="Samir Group" width="110" style="display: block; max-width: 110px; margin-bottom: 6px;">
      {{/if}}
    </td>
    <td style="padding-left: 18px; vertical-align: top;">
      <div style="font-size: 15px; font-weight: 700; color: {{primary_color}}; margin-bottom: 1px;">{{name}}</div>
      <div style="font-size: 12px; color: #777777; margin-bottom: 8px;">{{job_title}}{{#if department}} &bull; {{department}}{{/if}}</div>
      <table cellpadding="0" cellspacing="0" border="0" style="font-size: 12px; color: #555555;">
        {{#if phone}}
        <tr>
          <td style="padding: 1px 8px 1px 0; color: #aaaaaa; white-space: nowrap; font-size: 14px;">&#9990;</td>
          <td>{{phone}}</td>
        </tr>
        {{/if}}
        {{#if mobile}}
        <tr>
          <td style="padding: 1px 8px 1px 0; color: #aaaaaa; white-space: nowrap; font-size: 14px;">&#128241;</td>
          <td>{{mobile}}</td>
        </tr>
        {{/if}}
        {{#if extension}}
        <tr>
          <td style="padding: 1px 8px 1px 0; color: #aaaaaa; white-space: nowrap; font-size: 14px;">&#9742;</td>
          <td>Ext. {{extension}}</td>
        </tr>
        {{/if}}
        <tr>
          <td style="padding: 1px 8px 1px 0; color: #aaaaaa; white-space: nowrap; font-size: 14px;">&#9993;</td>
          <td><a href="mailto:{{email}}" style="color: {{primary_color}}; text-decoration: none;">{{email}}</a></td>
        </tr>
        {{#if branch_name}}
        <tr>
          <td style="padding: 1px 8px 1px 0; color: #aaaaaa; white-space: nowrap; font-size: 14px;">&#127968;</td>
          <td>{{branch_name}}{{#if branch_city}}, {{branch_city}}{{/if}}</td>
        </tr>
        {{/if}}
      </table>
    </td>
  </tr>
</table>
HTML;
    }
}
