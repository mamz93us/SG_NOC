<?php

namespace App\Services\EmployeeCard;

use App\Models\Employee;
use App\Models\Setting;
use App\Support\VCard;

/**
 * "Add to Samsung Wallet" for the employee card.
 *
 * Deliberately NOT shaped like WalletPassService. Apple's pass is a signed ZIP
 * the phone downloads; Samsung's is a LINK. There is no file, nothing to stream
 * and nothing to clean up — the whole card travels inside the URL as `cdata`, a
 * JWS the Samsung app verifies against the public key held in the Partner
 * Portal. So this class returns a string, and the callers redirect to it.
 *
 * The link format (Samsung Wallet "Data Transmit Link"):
 *
 *     https://a.swallet.link/atw/v3/{cardId}#Clip?cdata={jws}
 *
 * `#Clip` is a literal fragment, capital C, and the query sits AFTER it — that
 * is not a typo and not a URL you can build with http_build_query.
 *
 * The JWS header carries Samsung-specific claims (`cty`, `ver`, `partnerId`,
 * `certificateId`, `utc`) that a generic JWT library will not let you set, so
 * the token is assembled here with openssl_sign rather than by pulling in a
 * package for three base64url joins.
 *
 * PREREQUISITE, and it is not a small one: this needs an APPROVED Samsung
 * Wallet partner account (partner.walletsvc.samsung.com) with a Wallet Card
 * template published and your public key uploaded. Unlike Apple, where a
 * developer account gives you a Pass Type ID the same afternoon, Samsung
 * onboarding is a business agreement. Until those four values exist,
 * isConfigured() is false and every button stays hidden.
 *
 * @see https://developer.samsung.com/wallet/api/add-to-wallet-interface.html
 * @see https://developer.samsung.com/wallet/securityauthentication/carddatatoken.html
 */
class SamsungWalletService
{
    /** Samsung's "Data Transmit Link" host for adding a card. */
    private const ATW_BASE = 'https://a.swallet.link/atw';

    /** Token version the JWS header must declare. Bumped by Samsung, not by us. */
    private const TOKEN_VERSION = '3';

    /** Samir brand red — the same value the Apple pass and the login wallpaper use. */
    private const BRAND_RED = '#c8102e';

    /**
     * True when a card can actually be built and signed.
     *
     * All four identifiers come from the Partner Portal and none has a sane
     * default, so a missing one means the button must not be shown at all — a
     * "Add to Samsung Wallet" link that 500s is worse than no link.
     */
    public function isConfigured(): bool
    {
        $s = Setting::get();

        return (bool) ($s->samsung_wallet_enabled
            && $s->samsung_wallet_partner_id
            && $s->samsung_wallet_card_id
            && $s->samsung_wallet_certificate_id
            && $s->samsung_wallet_private_key);
    }

    /**
     * The "Add to Samsung Wallet" URL for one employee.
     *
     * Everything the card shows is inside the returned link, so it is personal
     * to that employee and should be treated like the pass itself: minted for
     * the person who asked for it, not published.
     */
    public function addToWalletUrl(Employee $employee): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Samsung Wallet credentials are not configured in Settings.');
        }

        $setting = Setting::get();

        $cdata = $this->sign($this->buildCard($employee, $setting), $setting);

        // Built by hand: the query belongs after the `#Clip` fragment, which no
        // URL builder will do for you.
        return sprintf(
            '%s/v%s/%s#Clip?cdata=%s',
            self::ATW_BASE,
            self::TOKEN_VERSION,
            rawurlencode($setting->samsung_wallet_card_id),
            rawurlencode($cdata),
        );
    }

    // ─── Card payload ─────────────────────────────────────────────────────────

    /**
     * The card JSON, in Samsung's `generic` card shape.
     *
     * `generic` rather than `idcard`: Digital ID is a restricted, region-gated
     * card type meant for government and campus credentials, and applying for it
     * to print an employee's extension number would not be approved. The generic
     * card is the one Samsung points partners at for staff and membership cards.
     *
     * IMPORTANT: the LABELS for text1…text4 are set on the card template in the
     * Partner Portal, not here — this only supplies values. Whoever creates the
     * template has to label them in this order, or the card reads "Field 3:
     * ext. 214". The order is documented in EMPLOYEE_CARD_SETUP.md and must not
     * be shuffled without changing the template to match.
     */
    private function buildCard(Employee $employee, Setting $setting): array
    {
        $identity = $employee->identityUser;
        $branch = $employee->branch;

        $name = $employee->name;
        $title = $employee->job_title ?: $identity?->job_title ?: '';
        $dept = $identity?->department ?: $employee->department?->name ?: '';
        $email = $employee->email ?: $identity?->mail ?: $identity?->user_principal_name ?: '';
        $mobile = $employee->mobile_phone ?: $identity?->mobile_phone ?: '';
        $ext = $employee->extension_number ?: '';
        $office = trim(($branch?->name ?: '').($branch?->city ? ', '.$branch->city : ''));
        $org = $setting->samsung_wallet_org_name ?: ($setting->company_name ?: 'Samir Group');

        // The canonical public card host. The link may be minted from an admin
        // request on the NOC, but the QR on the card has to send a scanner to
        // the public host — same rule as the Apple pass.
        $cardUrl = VCard::cardUrl($employee->card_token);

        $bgColor = $setting->samsung_wallet_bg_color ?: self::BRAND_RED;
        $now = (int) (microtime(true) * 1000);

        $attributes = array_filter([
            'title' => $name,
            'subtitle' => $title,
            'providerName' => $org,

            // Values only — see the note above about where the labels live.
            'text1' => $dept,
            'text2' => $office,
            'text3' => $ext,
            'text4' => $email,

            // The QR the card shows, which opens the same digital business card
            // the Apple pass and the printed QR do.
            'serial1' => [
                'value' => $cardUrl,
                'serialType' => 'QRCODE',
                'ptFormat' => 'QRCODE',
                'ptSubFormat' => 'QR_CODE',
            ],

            'bgColor' => $bgColor,
            // Samsung takes a keyword here, not a hex: the text flips as a set.
            'fontColor' => $this->isLightColor($bgColor) ? 'dark' : 'light',

            // Samsung's servers FETCH this, so it has to be reachable from the
            // public internet — an intranet-only URL renders an empty logo.
            'logoImage' => $this->logoUrl($setting),

            'noticeDesc' => $mobile
                ? "Mobile: {$mobile}"
                : 'Scan the code to open this card and save the contact.',

            // Where the card's own button goes. Required by the generic schema.
            'appLinkName' => 'Open my card',
            'appLinkData' => $cardUrl,
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'card' => [
                'type' => 'generic',
                'subType' => 'others',
                'data' => [[
                    // Stable per employee: Samsung treats refId as the card's
                    // identity, so re-adding updates the existing card instead
                    // of leaving a second stale copy in the wallet.
                    'refId' => 'sg-emp-'.$employee->id,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                    'language' => 'en',
                    'attributes' => $attributes,
                ]],
            ],
        ];
    }

    /**
     * A publicly fetchable logo URL for Samsung to pull.
     *
     * Prefers the admin-uploaded company logo on the public storage disk and
     * falls back to the brand mark shipped with the app. Both are rendered on
     * the public card host, which is internet-facing; the NOC host is not
     * guaranteed to be, and Samsung fetching from it would silently give a
     * blank logo.
     */
    private function logoUrl(Setting $setting): ?string
    {
        // Same fallback as VCard::cardUrl: on a NOC-only install where the card
        // subdomain is switched off, the current host is all there is.
        $base = rtrim(VCard::enabled() ? VCard::url() : url('/'), '/');

        if ($setting->company_logo) {
            return $base.'/storage/'.ltrim($setting->company_logo, '/');
        }

        return $base.'/images/brand/samir-mark.png';
    }

    // ─── Signing ──────────────────────────────────────────────────────────────

    /**
     * Wrap the card JSON in the JWS Samsung expects.
     *
     * Header claims are Samsung's own, not RFC 7515's: `cty` is the literal
     * "CARD", `ver` the token version, and `utc` a millisecond timestamp used
     * for replay and expiry checks — a clock more than a few minutes out on this
     * server makes every link fail with nothing useful in the response.
     *
     * The payload is signed, not encrypted. Samsung's JWE layer (RSA1_5 +
     * A128GCM against their public key) applies to card types carrying
     * sensitive data; a generic staff card is a signed JWS. If Samsung's
     * onboarding asks for encryption for this card template, that wraps the
     * payload BEFORE this step rather than replacing it.
     */
    private function sign(array $card, Setting $setting): string
    {
        $key = openssl_pkey_get_private($setting->samsung_wallet_private_key);

        if ($key === false) {
            throw new \RuntimeException(
                'The stored Samsung Wallet private key could not be read. It must be an '
                .'unencrypted RSA private key in PEM format ("-----BEGIN PRIVATE KEY-----").'
            );
        }

        $header = [
            'alg' => 'RS256',
            'cty' => 'CARD',
            'ver' => self::TOKEN_VERSION,
            'partnerId' => $setting->samsung_wallet_partner_id,
            'certificateId' => $setting->samsung_wallet_certificate_id,
            'utc' => (int) (microtime(true) * 1000),
        ];

        $signingInput = $this->b64(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            .'.'
            .$this->b64(json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing the Samsung Wallet card failed: '.openssl_error_string());
        }

        return $signingInput.'.'.$this->b64($signature);
    }

    /** base64url, per RFC 7515 — no padding, and the two swapped characters. */
    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // ─── Colour ───────────────────────────────────────────────────────────────

    /**
     * Perceived brightness, so `fontColor` follows the background an admin
     * picked. Same weighting as WalletPassService uses for the Apple pass.
     */
    private function isLightColor(string $hex): bool
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) < 6) {
            return true;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160;
    }
}
