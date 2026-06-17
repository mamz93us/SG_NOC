<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\FormTemplate;
use App\Models\FormToken;
use App\Models\Setting;
use App\Models\Training\CourseCertificate;
use App\Support\Marketing;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Resolves merge tags ({{first_name}}, {{unsubscribe_url}}, etc.) in
 * a rendered HTML body for a specific recipient. Unknown tags are left
 * as literals — we never throw on missing data, since campaigns must
 * always reach the recipient when possible.
 */
class MergeTagRenderer
{
    public function render(string $html, EmailSubscriber $subscriber, ?EmailCampaignSend $send = null, ?EmailList $list = null, ?EmailCampaign $campaign = null): string
    {
        // Per-recipient World Cup contest links: {{guess_link:slug}} → a unique,
        // one-use tokenised URL on the marketing host for THIS subscriber. Handled
        // before the generic tag pass because the slug contains characters (`:`,`-`)
        // the generic matcher does not allow.
        $html = $this->renderGuessLinks($html, $subscriber);

        $tags = $this->buildTagMap($subscriber, $send, $list, $campaign);

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function ($match) use ($tags) {
                $key = strtolower($match[1]);

                return $tags[$key] ?? $match[0];
            },
            $html
        ) ?? $html;
    }

    /**
     * Build the full tag map. Includes custom attributes from the
     * subscriber's `attributes` JSON column, exposed as
     * `{{attributes.country}}` (or just `{{country}}` if no collision).
     */
    private function buildTagMap(EmailSubscriber $subscriber, ?EmailCampaignSend $send, ?EmailList $list, ?EmailCampaign $campaign = null): array
    {
        $base = [
            'first_name' => (string) ($subscriber->first_name ?? ''),
            'last_name' => (string) ($subscriber->last_name ?? ''),
            'email' => (string) $subscriber->email,
            'full_name' => $subscriber->fullName(),
            'unsubscribe_url' => $this->unsubscribeUrl($subscriber, $list),
            'certificate_url' => $this->certificateUrl($subscriber, $campaign),
        ];

        $attrs = is_array($subscriber->attributes) ? $subscriber->attributes : [];
        foreach ($attrs as $k => $v) {
            $k = strtolower((string) $k);
            if (! isset($base[$k])) {
                $base[$k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
            $base['attributes.'.$k] = is_scalar($v) ? (string) $v : json_encode($v);
        }

        return $base;
    }

    /**
     * Resolve {{certificate_url}} per recipient. Requires the campaign to
     * have a course_id set; otherwise returns an empty string so authors
     * can include the tag in templates without it crashing for non-course
     * campaigns.
     */
    public function certificateUrl(EmailSubscriber $subscriber, ?EmailCampaign $campaign): string
    {
        if (! $campaign || ! $campaign->course_id) {
            return '';
        }

        $cert = CourseCertificate::where('course_id', $campaign->course_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $subscriber->email)])
            ->first();

        return $cert ? $cert->publicUrl() : '';
    }

    /**
     * Build the signed unsubscribe URL. If we have a specific list,
     * the URL unsubscribes only from that list; otherwise it removes
     * the subscriber globally.
     */
    public function unsubscribeUrl(EmailSubscriber $subscriber, ?EmailList $list = null): string
    {
        $token = $this->buildToken($subscriber, $list);

        // Recipient-facing links live on the isolated marketing subdomain so the
        // inbox never shows a NOC URL. An explicit unsubscribe base URL (if the
        // admin set one) wins; otherwise we default to https://{marketing_domain}.
        // The route itself stays host-agnostic (answers on NOC too), so links
        // already delivered against the old host keep resolving — we only change
        // where NEW links point. The signature is computed over this host, so we
        // force the generator root for the duration of this call and restore it.
        $base = rtrim(Setting::get()->ses_unsubscribe_base_url ?: Marketing::url('/'), '/');
        $original = rtrim(URL::to('/'), '/');

        URL::forceRootUrl($base);

        try {
            // Far-future expiry rather than a never-expiring URL, because
            // Laravel's signed routes require an expiry.
            return URL::temporarySignedRoute(
                'email.unsubscribe.show',
                now()->addYears(5),
                ['token' => $token]
            );
        } finally {
            URL::forceRootUrl($original);
        }
    }

    /**
     * Replace every {{guess_link:slug}} with a per-recipient one-use contest link.
     * The token is keyed by (form, email) via firstOrCreate, so re-renders/retries
     * reuse the same link for the same person.
     */
    private function renderGuessLinks(string $html, EmailSubscriber $subscriber): string
    {
        return preg_replace_callback(
            '/\{\{\s*guess_link:([a-z0-9\-]+)\s*\}\}/i',
            fn ($m) => $this->guessLinkUrl($subscriber, strtolower($m[1])),
            $html
        ) ?? $html;
    }

    /** Per-recipient one-use contest URL on the marketing host (empty if no such form). */
    public function guessLinkUrl(EmailSubscriber $subscriber, string $slug): string
    {
        $form = FormTemplate::where('slug', $slug)->first();
        if (! $form) {
            return '';
        }

        $token = FormToken::firstOrCreate(
            ['form_id' => $form->id, 'email' => $subscriber->email],
            [
                'token'      => Str::random(48),
                'label'      => trim((string) $subscriber->fullName()) ?: (string) $subscriber->email,
                'uses_limit' => 1,
                'expires_at' => $form->expires_at,
            ]
        );

        return rtrim(Marketing::url('/'), '/').'/forms/'.$form->slug.'?token='.$token->token;
    }

    private function buildToken(EmailSubscriber $subscriber, ?EmailList $list): string
    {
        $parts = [$subscriber->id, $list?->id ?? 0];

        return rtrim(strtr(base64_encode(implode(':', $parts)), '+/', '-_'), '=');
    }

    public static function decodeToken(string $token): array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (! $decoded || ! str_contains($decoded, ':')) {
            return [null, null];
        }
        [$subscriberId, $listId] = explode(':', $decoded, 2) + [null, null];

        return [
            $subscriberId !== null ? (int) $subscriberId : null,
            $listId !== null && (int) $listId > 0 ? (int) $listId : null,
        ];
    }
}
