<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MailSender;
use App\Models\Setting;
use App\Services\EmailMarketing\SesService;
use App\Services\SmtpConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Admin → Sender Addresses.
 *
 * Which "From" address each kind of system email goes out as. Blank means
 * inherit the global default, so this page is additive — nothing changes until
 * an address is filled in.
 */
class MailSenderController extends Controller
{
    public function __construct(private SmtpConfigService $mailConfig) {}

    public function index(): View
    {
        // Keep the catalogue order from the model, not insertion order, and
        // materialise a row for any service added to SERVICES after the table
        // was seeded — so a new service key shows up without a migration.
        $existing = MailSender::all()->keyBy('service_key');

        $senders = collect(MailSender::SERVICES)
            ->map(fn ($meta, $key) => $existing->get($key) ?? new MailSender(['service_key' => $key]))
            ->values();

        $settings = Setting::get();
        $defaultAddress = $settings->smtp_from_address ?: $settings->ses_default_from_email;
        $defaultName = $settings->smtp_from_name ?: ($settings->ses_default_from_name ?: config('app.name'));
        $transport = $this->mailConfig->activeTransport();

        // On SES an unverified sender fails at send time with an opaque error,
        // so surface the verified domain list here instead. Never fatal: if the
        // lookup fails we just don't show the badges.
        $verifiedDomains = [];
        $verifyError = null;
        if ($transport === 'ses') {
            try {
                $verifiedDomains = array_map('strtolower', app(SesService::class)->listVerifiedIdentities());
            } catch (\Throwable $e) {
                $verifyError = $e->getMessage();
            }
        }

        return view('admin.mail_senders.index', compact(
            'senders', 'defaultAddress', 'defaultName', 'transport', 'verifiedDomains', 'verifyError'
        ));
    }

    /**
     * Save every row in one submit — the page is a single table form.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'senders' => 'required|array',
            'senders.*.from_address' => 'nullable|email|max:191',
            'senders.*.from_name' => 'nullable|string|max:191',
            'senders.*.reply_to' => 'nullable|email|max:191',
        ], [
            'senders.*.from_address.email' => 'One of the From addresses is not a valid email address.',
            'senders.*.reply_to.email' => 'One of the Reply-To addresses is not a valid email address.',
        ]);

        $before = MailSender::all()
            ->mapWithKeys(fn ($s) => [$s->service_key => $s->from_address])
            ->all();

        foreach ($validated['senders'] as $key => $fields) {
            // Ignore anything not in the catalogue — the form is generated from
            // it, so a stray key means a tampered post, not a new service.
            if (! array_key_exists($key, MailSender::SERVICES)) {
                continue;
            }

            MailSender::updateOrCreate(
                ['service_key' => $key],
                [
                    'from_address' => $fields['from_address'] ?: null,
                    'from_name' => $fields['from_name'] ?: null,
                    'reply_to' => $fields['reply_to'] ?: null,
                    'is_active' => true,
                ]
            );
        }

        MailSender::flushCache();

        ActivityLog::create([
            'model_type' => MailSender::class,
            'model_id' => 0,
            'action' => 'mail_senders_updated',
            'changes' => [
                'before' => $before,
                'after' => MailSender::all()
                    ->mapWithKeys(fn ($s) => [$s->service_key => $s->from_address])
                    ->all(),
            ],
            'user_id' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.mail-senders.index')
            ->with('success', 'Sender addresses updated.');
    }

    /**
     * Send a test message as one specific service, so you can confirm the
     * address is accepted by the transport before real mail depends on it.
     */
    public function test(Request $request, string $serviceKey): RedirectResponse
    {
        if (! array_key_exists($serviceKey, MailSender::SERVICES)) {
            abort(404);
        }

        $validated = $request->validate([
            'test_email' => 'required|email|max:191',
        ]);

        $sender = MailSender::for($serviceKey);
        $label = MailSender::SERVICES[$serviceKey]['label'];

        try {
            $this->mailConfig->loadFromSettings();

            Mail::raw(
                "This is a test of the \"{$label}\" sender address for SG NOC.\n\n"
                ."From: {$sender['name']} <{$sender['address']}>\n"
                .'Transport: '.strtoupper($this->mailConfig->activeTransport())."\n\n"
                .'If you received this, that service can send mail as that address.',
                function ($message) use ($validated, $sender, $label) {
                    $message->to($validated['test_email'])
                        ->subject("SG NOC — Sender test: {$label}");

                    if ($sender['address']) {
                        $message->from($sender['address'], $sender['name']);
                    }
                    if ($sender['reply_to']) {
                        $message->replyTo($sender['reply_to']);
                    }
                }
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.mail-senders.index')
                ->with('error', "Test send failed for {$label}: ".$e->getMessage());
        }

        return redirect()
            ->route('admin.mail-senders.index')
            ->with('success', "Test email sent as {$sender['address']} to {$validated['test_email']}.");
    }
}
