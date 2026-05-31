# Marketing portal subdomain (isolated)

The email-marketing portal runs on its **own subdomain** (default `em.samirgroup.net`),
isolated from the NOC, while NOC keeps all admin control (SES credentials,
suppression lists, sender allowlist, quota).

It is the **same Laravel app, same database**, domain-routed — no separate deploy.
The subdomain is **configured in the UI**, not in `.env`.

---

## How it works

| Surface | Host | Notes |
|---|---|---|
| Marketing portal (dashboard, campaigns, subscribers, lists, templates, courses) | `em.samirgroup.net` **only** | `Route::domain(...)`, served at the subdomain root. Route names unchanged (`portal.marketing.*`). |
| NOC admin, incl. **Email Marketing → Settings** | `noc.samirgroup.net` **only** | `/admin/*` is 404 on the marketing host (`EnforceMarketingHostIsolation`). |
| Public unsubscribe / opt-in / SNS webhook | both hosts | Stay host-agnostic so links already delivered against `noc` keep working. **New** unsubscribe links point at the marketing host. |

- **Domain source of truth:** `settings.marketing_domain`, resolved by
  `App\Support\Marketing::domain()` with a safe fallback (`em.samirgroup.net`).
  Editable at **Admin → Email Marketing → Settings → Marketing Portal**.
- **Auth:** Microsoft SSO. The redirect URI is built from the current host
  (`url('/auth/microsoft/callback')`), so SSO completes natively on the marketing
  host — *once the redirect URI is registered in Azure* (below).
- **Sessions are siloed per host** (cookie scoped to the exact hostname). A login
  on `em` is independent of a login on `noc`. Keep `SESSION_DOMAIN` **unset**
  (do **not** set it to `.samirgroup.net`, which would share cookies).

---

## One-time setup checklist

### 1. DNS
Add an `A` record for the marketing host pointing at the VPS:

```
em.samirgroup.net.   A   <VPS_PUBLIC_IP>
```

### 2. nginx vhost (same docroot as NOC)
Add a server block that serves the **same** app root. Minimal example:

```nginx
server {
    listen 443 ssl http2;
    server_name em.samirgroup.net;

    root /home/azureuser/phonebook2/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/em.samirgroup.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/em.samirgroup.net/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # match the NOC vhost
    }

    location ~ /\.(?!well-known).* { deny all; }
}

# Optional: redirect plain HTTP to HTTPS
server {
    listen 80;
    server_name em.samirgroup.net;
    return 301 https://$host$request_uri;
}
```

Reload: `sudo nginx -t && sudo systemctl reload nginx`.

### 3. TLS certificate
```
sudo certbot --nginx -d em.samirgroup.net
```
(Or add `em.samirgroup.net` as a SAN to the existing cert.)

### 4. Azure AD (Entra) — add the redirect URI
In the App Registration used for SSO, add a **Web** redirect URI:

```
https://em.samirgroup.net/auth/microsoft/callback
```

Without this, SSO on the marketing host fails with an AADSTS redirect-mismatch error.

### 5. Set the domain in the UI
**Admin → Email Marketing → Settings → Marketing Portal → Marketing portal domain**
= `em.samirgroup.net` (host only; a pasted `https://.../` is normalised to the host).
Saving a changed value **auto-clears the route cache**.

### 6. (Optional) AWS SNS endpoint
SNS bounce/complaint ingest still works on `noc`. If you want it on the marketing
host instead, update the SNS subscription URL to
`https://em.samirgroup.net/api/sns/email-events` and confirm the new subscription.
Not required — recipients never see this endpoint.

---

## Assigning the marketing role

Set a user's role to **Marketing** (User admin → Role). The role grants:

- `view-email-marketing` — full portal (campaigns, subscribers, lists, templates)
- `view-courses`, `manage-courses` — the courses section of the portal

It deliberately does **not** grant `manage-email-marketing` /
`manage-email-marketing-settings` — SES credentials and the sender allowlist stay
NOC-admin-only. After login, a marketing-only user lands on
`https://em.samirgroup.net/` and cannot reach `/admin/*` (404).

Run the migrations on deploy so the role gets its grants and the settings column exists:

```
php artisan migrate
```

---

## Deploy notes / gotchas

- **Route cache + dynamic domain.** The host is baked into the cached route table.
  Changing it in the UI clears the route cache automatically. If you run
  `php artisan route:cache` at deploy time, do it **after** the domain is set (or
  just re-run it — the resolver reads the current value).
- **Old unsubscribe links keep working.** Links already delivered point at the host
  they were generated on and still resolve there; only newly-sent links use the
  marketing host. The unsubscribe route is intentionally not domain-locked.
- **`ses_unsubscribe_base_url` override** still wins if set; otherwise links default
  to `https://{marketing_domain}`.
- **Isolation is defense-in-depth.** `/admin/*` is 404 on the marketing host, but
  every admin route is permission-gated anyway — a marketing user could never act
  there even if it were reachable.
