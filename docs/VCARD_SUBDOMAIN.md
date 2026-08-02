# Digital business card subdomain (isolated)

Employee digital business cards run on their **own subdomain** (default
`vcard.samirgroup.net`). Employees sign in there with Microsoft SSO — **no 2FA** —
to view their own card, add it to Apple Wallet, and share it. Every card QR code
and share link points at this host.

It is the **same Laravel app, same database**, domain-routed — no separate deploy.
Same arrangement as the marketing host ([MARKETING_SUBDOMAIN.md](MARKETING_SUBDOMAIN.md)).

---

## How it works

| Surface | Host | Notes |
|---|---|---|
| "My card" landing (SSO) | `vcard.samirgroup.net` **only** | `Route::domain(...)` at the subdomain root. Resolves the signed-in user's Employee record by email and redirects to their own card. |
| Card sign-in | `vcard.samirgroup.net` | `vcard.login` — SSO button only, no password form. |
| Public card / vCard / Wallet pass (`/card/{token}`, `/card/{token}/vcard`, `/card/{token}/wallet`) | **both hosts** | Registered host-agnostically, so links already printed or sent against `noc` keep working. **New** QR codes and share links use the card host. |
| Everything else NOC (`/admin`, `/portal`, phonebook, marketing) | `noc.samirgroup.net` **only** | **404 on the card host.** `EnforceVcardHostIsolation` is an allow-list. |

- **Domain source of truth:** `config/vcard.php` → `VCARD_DOMAIN`, resolved by
  `App\Support\VCard::domain()`. Unlike the marketing host this is **not** editable
  in the UI — it is read at route-registration time from config.
- **Canonical card URL:** `VCard::cardUrl($token)`. Used by the card page's own QR,
  the Share button, the admin Share-Card modal, and the Apple Wallet pass barcode.
- **Sessions are siloed per host** (cookie scoped to the exact hostname). Keep
  `SESSION_DOMAIN` **unset** — see the security note below.

---

## Why 2FA is skipped here — and why that is safe

The card host is SSO-only for **everyone**, admins and super admins included. That
is deliberate: an employee pulling up their own business card on their phone should
not have to produce a TOTP code.

Two properties keep it contained. Do not break either one:

1. **The host serves nothing but cards.** `EnforceVcardHostIsolation` is an
   allow-list — `/admin`, `/portal`, the phonebook and the marketing portal all
   404 here, for guests and admins alike. A session established on this host can
   only ever reach a business card.
2. **The skip is scoped to the host, not to the session.** `RequireTwoFactor`
   short-circuits on the card host, and `MicrosoftController` returns before the
   2FA branches **without** setting `2fa_verified`. So if that same session is
   ever pointed at a NOC route, the normal 2FA challenge still fires.

`tests/Unit/VcardTest.php` asserts both — in particular that the same
2FA-enrolled, unverified session passes on `vcard` and is challenged on `noc`.

> **Do not set `SESSION_DOMAIN=.samirgroup.net`.** Cookies are host-scoped today.
> Widening them would share one session across `noc`, `em` and `vcard`. Property 2
> above still holds (NOC would re-challenge), but it removes a layer for no gain.

---

## One-time setup

### The short version

Add the DNS record first, then run the provisioning script on the NOC VM:

```
vcard.samirgroup.net.   A   <VPS_PUBLIC_IP>
```

```bash
cd /home/azureuser/phonebook2 && sudo bash deployment/vcard/setup.sh
```

That creates the vhost (deriving docroot + php-fpm socket from the existing NOC
vhost, so it survives PHP upgrades), obtains the TLS certificate, clears the
cached config/routes, and smoke-tests that `/login` serves 200 and
`/admin/employees` 404s on the new host. It is idempotent.

Then do **step 4 below by hand** — the Entra redirect URI. Nothing else is needed.

Useful overrides:

```bash
sudo VCARD_DOMAIN=cards.samirgroup.com bash deployment/vcard/setup.sh
sudo CERTBOT_EMAIL=it@samirgroup.com   bash deployment/vcard/setup.sh
sudo SKIP_DNS_CHECK=1 bash deployment/vcard/setup.sh   # DNS not propagated yet
sudo SKIP_TLS=1       bash deployment/vcard/setup.sh   # HTTP now, cert later
```

The rest of this section is what the script does, for reference or manual setup.

### 1. DNS
```
vcard.samirgroup.net.   A   <VPS_PUBLIC_IP>
```

### 2. nginx vhost (same docroot as NOC)

```nginx
server {
    listen 443 ssl http2;
    server_name vcard.samirgroup.net;

    root /home/azureuser/phonebook2/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/vcard.samirgroup.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/vcard.samirgroup.net/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # match the NOC vhost
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name vcard.samirgroup.net;
    return 301 https://$host$request_uri;
}
```

Reload: `sudo nginx -t && sudo systemctl reload nginx`.

### 3. TLS certificate
```
sudo certbot --nginx -d vcard.samirgroup.net
```

### 4. Azure AD (Entra) — add the redirect URI

**This is the one that will bite you.** The SSO redirect URI is built from the
current host (`url('/auth/microsoft/callback')`), so in the App Registration used
for SSO add a **Web** redirect URI:

```
https://vcard.samirgroup.net/auth/microsoft/callback
```

Without it, sign-in on the card host fails with an AADSTS redirect-mismatch error.

### 5. `.env` (optional)

Only needed to override the default:

```
VCARD_DOMAIN=vcard.samirgroup.net
VCARD_ENABLED=true
```

Then, because the host is baked into the cached route table:

```
php artisan config:clear && php artisan route:clear
```

(Re-run `route:cache` / `config:cache` afterwards if you cache them at deploy time.)

---

## What an employee sees

1. Opens `https://vcard.samirgroup.net/` → "My Business Card" sign-in.
2. Signs in with Microsoft. No 2FA prompt.
3. Lands on their own card (`/card/{token}`), which shows:
   - **Add to Apple Wallet** — signed `.pkpass`, Samir-branded (see
     `WalletPassService`). Requires a session, so only the signed-in employee gets it.
   - **Share** — native share sheet on mobile, clipboard copy elsewhere. Always
     shares the canonical `vcard.samirgroup.net` URL.
   - **Save Contact** (vCard), Email, Call, and the QR code.
   - A **Sign out** link in the footer (owner only).

Cards are created lazily — an employee who has never been shared from the admin
panel gets a `card_token` minted on first visit.

If no **active** employee record matches the signed-in user's email, they get a
"No card for this account" page rather than a stack trace. The match is on email
(same as the rest of `/portal`); there is no FK between `users` and `employees`.

---

## Deploy notes / gotchas

- **Adding a new route to this host** means adding its route name to
  `EnforceVcardHostIsolation::ALLOWED_NAMES`, or naming it under `vcard.*`.
  Anything else 404s here regardless of permissions.
- **Old NOC card links keep working.** `/card/{token}` is not domain-locked. Only
  newly-generated QR codes, share links and Wallet barcodes use the card host.
- **Wallet passes already installed keep their old barcode.** The pass barcode is
  baked in at generation time; re-download to pick up the `vcard` URL.
- **The Wallet pass download stays auth-gated** on both hosts. A customer who scans
  a shared card can view it and save the contact, but cannot add someone else's
  pass to their Wallet.
