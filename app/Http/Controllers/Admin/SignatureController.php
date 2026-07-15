<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\EmailSignatureTemplate;
use App\Models\HrApiKey;
use App\Models\IdentityUser;
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
        $domains  = AllowedDomain::orderBy('is_primary', 'desc')->orderBy('domain')->get();
        $template = null;
        $default  = $this->defaultNewEmailHtml();
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
        $default  = null;
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
        $id  = $request->input('id');
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
        $html    = $request->input('html_body', '');
        $color   = $request->input('primary_color', '#d81f2a');
        $logoUrl = $request->input('logo_url', '');
        $upn     = $request->input('upn');

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
            'html_body'     => $html,
            'primary_color' => $color,
            'logo_url'      => $logoUrl,
        ]);

        return response()->json([
            'html'   => $this->renderer->render($fake, $vars),
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
        // Auth: accept key via ?api_key= (Intune scripts) or Authorization: Bearer (Graph job)
        $raw = $request->query('api_key') ?? $request->bearerToken();
        if (empty($raw)) {
            return response()->json(['error' => 'API key required. Pass ?api_key= or Authorization: Bearer.'], 401);
        }
        $apiKey = HrApiKey::findByRawKey($raw, 'signature');
        if (! $apiKey) {
            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }
        try { $apiKey->recordUsage($request->ip()); } catch (\Throwable) {}

        $upn    = $request->query('upn');
        $type   = in_array($request->query('type'), ['new_email', 'reply']) ? $request->query('type') : 'new_email';
        $domain = $request->query('domain') ?: null;

        if (! $upn) {
            return response()->json(['error' => 'upn parameter required'], 400);
        }

        $html = $this->renderer->resolveAndRender($upn, $type, $domain);

        if ($html === null) {
            return response()->json(['error' => 'No template or user found'], 404);
        }

        if ($request->query('format') === 'json') {
            return response()->json(['html' => $html, 'upn' => $upn, 'type' => $type]);
        }

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
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
        try { $apiKey->recordUsage($request->ip()); } catch (\Throwable) {}

        $domain = $request->query('domain');
        $type   = in_array($request->query('type'), ['new_email', 'reply']) ? $request->query('type') : 'new_email';
        $gender = in_array($request->query('gender'), ['male', 'female']) ? $request->query('gender') : null;

        if (! $domain) {
            return response()->json(['error' => 'domain parameter required'], 400);
        }

        $template = EmailSignatureTemplate::findBest($type, $domain, $gender);
        if (! $template) {
            return response()->json(['error' => 'No active template for this domain'], 404);
        }

        $html = $this->renderer->renderForTransportRule($template);

        if ($request->query('format') === 'json') {
            return response()->json(['html' => $html, 'domain' => $domain, 'type' => $type]);
        }

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    // ─── Private helpers ──────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'            => 'required|string|max:200',
            'domain'          => 'nullable|string|max:100',
            'type'            => 'required|in:new_email,reply,all',
            'gender'          => 'required|in:all,male,female',
            'logo_url'        => 'nullable|url|max:500',
            'primary_color'   => 'nullable|regex:/^#[0-9a-fA-F]{3,8}$/',
            'html_body'       => 'required|string',
            'plain_text_body' => 'nullable|string',
            'is_active'       => 'boolean',
            'sort_order'      => 'integer|min:0|max:9999',
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
