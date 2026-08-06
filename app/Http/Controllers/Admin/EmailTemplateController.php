<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MailSender;
use App\Models\SystemEmailTemplate;
use App\Services\SmtpConfigService;
use App\Support\EmailTemplateRenderer;
use App\Support\EmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Admin → Email Templates.
 *
 * Every designed email SG NOC sends, with an optional subject and body
 * override per template. Nothing here is required: an untouched template sends
 * exactly the design that ships in the repo, and "Restore original" is a
 * complete undo because the override is a single deletable row.
 */
class EmailTemplateController extends Controller
{
    public function __construct(private SmtpConfigService $mailConfig) {}

    // ─────────────────────────────────────────────────────────────
    // List
    // ─────────────────────────────────────────────────────────────

    public function index(): View
    {
        $overrides = SystemEmailTemplate::all()->keyBy('template_key');

        return view('admin.email_templates.index', [
            'grouped' => EmailTemplates::grouped(),
            'overrides' => $overrides,
            'senders' => collect(MailSender::SERVICES),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Edit
    // ─────────────────────────────────────────────────────────────

    public function edit(string $key): View
    {
        $meta = $this->metaOr404($key);

        $override = SystemEmailTemplate::where('template_key', $key)->first();

        // Everything the editor needs comes from rendering the coded design
        // once against sample data: the seed body, the placeholder list and the
        // subject the code would produce.
        $codedHtml = '';
        $codedSubject = '';
        $seedBody = '';
        $fields = [];
        $renderError = null;

        try {
            SystemEmailTemplate::withoutOverrides(function () use ($key, &$codedHtml, &$codedSubject) {
                $mailable = EmailTemplates::sampleMailable($key);
                $codedHtml = $mailable->renderCodedView();
                $codedSubject = $mailable->codedSubject();
            });

            // Union of what this render produced and what the template file
            // declares: a field inside a section the sample data does not
            // trigger (a leaver's reason, a deactivation notice) is still a
            // real placeholder and belongs in the list.
            $fields = array_values(array_unique(array_merge(
                array_keys(EmailTemplateRenderer::extract($codedHtml)),
                EmailTemplateRenderer::declaredFields(EmailTemplates::viewPath($key) ?? '')
            )));
            $seedBody = EmailTemplateRenderer::tokenise($codedHtml);
        } catch (\Throwable $e) {
            $renderError = $e->getMessage();
            $fields = EmailTemplateRenderer::declaredFields(EmailTemplates::viewPath($key) ?? '');
        }

        $sender = MailSender::for($meta['service']);

        return view('admin.email_templates.edit', [
            'key' => $key,
            'meta' => $meta,
            'override' => $override,
            'codedSubject' => $codedSubject,
            'seedBody' => $seedBody,
            'fields' => $fields,
            'renderError' => $renderError,
            'sender' => $sender,
            'senderLabel' => MailSender::SERVICES[$meta['service']]['label'] ?? $meta['service'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Save
    // ─────────────────────────────────────────────────────────────

    public function update(Request $request, string $key): RedirectResponse
    {
        $this->metaOr404($key);

        $data = $request->validate([
            'subject' => 'nullable|string|max:255',
            'body_html' => 'nullable|string|max:500000',
            'is_active' => 'nullable|boolean',
        ]);

        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body_html'] ?? ''));

        // Both blank means "no override" — drop the row rather than keeping an
        // empty one around pretending to be a customisation.
        if ($subject === '' && $body === '') {
            SystemEmailTemplate::where('template_key', $key)->delete();
            SystemEmailTemplate::flushCache();

            $this->log($key, 'email_template_cleared', []);

            return redirect()
                ->route('admin.email-templates.edit', $key)
                ->with('success', 'Override removed — this email now sends its original design.');
        }

        SystemEmailTemplate::updateOrCreate(
            ['template_key' => $key],
            [
                'subject' => $subject ?: null,
                'body_html' => $body ?: null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by_user_id' => Auth::id(),
            ]
        );

        SystemEmailTemplate::flushCache();

        $this->log($key, 'email_template_updated', [
            'subject' => $subject !== '',
            'body' => $body !== '',
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()
            ->route('admin.email-templates.edit', $key)
            ->with('success', 'Template saved.');
    }

    /** Delete the override entirely and go back to the shipped design. */
    public function reset(string $key): RedirectResponse
    {
        $this->metaOr404($key);

        SystemEmailTemplate::where('template_key', $key)->delete();
        SystemEmailTemplate::flushCache();

        $this->log($key, 'email_template_reset', []);

        return redirect()
            ->route('admin.email-templates.edit', $key)
            ->with('success', 'Restored the original design.');
    }

    // ─────────────────────────────────────────────────────────────
    // Preview
    // ─────────────────────────────────────────────────────────────

    /**
     * Render whatever is currently in the editor against sample data. Posted
     * rather than read from the database so the pane tracks unsaved edits.
     */
    public function preview(Request $request, string $key): Response
    {
        $this->metaOr404($key);

        $body = trim((string) $request->input('body_html', ''));

        try {
            $html = SystemEmailTemplate::withoutOverrides(function () use ($key, $body) {
                $coded = EmailTemplates::sampleMailable($key)->renderCodedView();

                if ($body === '') {
                    return EmailTemplateRenderer::strip($coded);
                }

                return EmailTemplateRenderer::fill($body, EmailTemplateRenderer::extract($coded));
            });
        } catch (\Throwable $e) {
            $html = '<pre style="font:13px/1.5 monospace;color:#b00;padding:16px;white-space:pre-wrap;">'
                .e('Preview failed: '.$e->getMessage())
                .'</pre>';
        }

        // Served into an iframe, so it must not inherit the admin layout.
        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    // ─────────────────────────────────────────────────────────────
    // Test send
    // ─────────────────────────────────────────────────────────────

    /**
     * Send the template — as saved, through the real Mailable and the real
     * sender address — to one address, using sample data.
     */
    public function test(Request $request, string $key): RedirectResponse
    {
        $meta = $this->metaOr404($key);

        $data = $request->validate([
            'test_email' => 'required|email|max:191',
        ]);

        try {
            $this->mailConfig->loadFromSettings();

            $mailable = EmailTemplates::sampleMailable($key);
            Mail::to($data['test_email'])->send(MailSender::apply($mailable, $meta['service']));
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.email-templates.edit', $key)
                ->with('error', 'Test send failed: '.$e->getMessage());
        }

        return redirect()
            ->route('admin.email-templates.edit', $key)
            ->with('success', "Test email sent to {$data['test_email']} using sample data.");
    }

    // ─────────────────────────────────────────────────────────────

    private function metaOr404(string $key): array
    {
        $meta = EmailTemplates::meta($key);

        abort_if($meta === null, 404);

        return $meta;
    }

    private function log(string $key, string $action, array $changes): void
    {
        ActivityLog::create([
            'model_type' => SystemEmailTemplate::class,
            'model_id' => 0,
            'action' => $action,
            'changes' => ['template' => $key] + $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
