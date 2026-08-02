# HR portal subdomain (isolated)

HR runs on its **own subdomain** (default `hr.samirgroup.net`). HR staff sign in
there with Microsoft SSO — **no 2FA** — to onboard new hires, raise terminations,
and request employee data changes.

It is the **same Laravel app, same database**, domain-routed — no separate deploy.
Same arrangement as the marketing host ([MARKETING_SUBDOMAIN.md](MARKETING_SUBDOMAIN.md))
and the card host ([VCARD_SUBDOMAIN.md](VCARD_SUBDOMAIN.md)).

---

## The core rule

**Nothing in the HR portal writes to an employee record.** Every action creates a
`WorkflowRequest` that IT reviews in `/admin/workflows`. HR raises; IT approves;
the engine applies. That is what makes it safe to expose HR on a low-friction,
SSO-only host.

| HR action | Workflow type | What actually happens |
|---|---|---|
| Onboard a new hire | `create_user` | IT approves → the new hire's manager gets a setup form (extension? floor? groups? laptop?) → `UserProvisioningService` creates the Azure user, licenses, groups, UCM extension, `Employee` record, tickets and welcome mail. |
| Terminate / offboard | `employee_offboarding` | The manager gets a decision form (mailbox, laptop, assets) → `OffboardingProcessor` runs backups, group removal, Intune wipe, licence unassignment, asset moves. Final Azure delete is scheduled after the retention period. |
| Update employee data | `employee_update` | IT approves → the diff is applied to the `Employee` record and pushed to Microsoft 365. |

---

## Surfaces

| Surface | Host | Notes |
|---|---|---|
| HR workspace (`/`, `/requests`, `/onboarding/*`, `/offboarding/*`, `/employee-update/*`) | `hr.samirgroup.net` **only** | `Route::domain(...)` at the subdomain root. Route names are `portal.hr.*`. |
| HR sign-in | `hr.samirgroup.net` | `portal.hr.login` — SSO button only, no password form. |
| Everything else NOC (`/admin`, `/portal`, phonebook, marketing, cards) | their own hosts **only** | **404 on the HR host.** `EnforceHrPortalHostIsolation` is an allow-list. |

- **Domain source of truth:** `config/hr_portal.php` → `HR_PORTAL_DOMAIN`,
  resolved by `App\Support\HrPortal::domain()`. Read at route-registration time,
  so it is **not** editable in the UI.
- **Sessions are siloed per host** (cookie scoped to the exact hostname). Keep
  `SESSION_DOMAIN` **unset**.

> **Moved route:** HR onboarding used to live at `noc.samirgroup.net/portal/hr/onboarding`.
> It is now registered **only** on the HR host, so that old path 404s. Route
> *names* are unchanged (`portal.hr.onboarding.*`), so every `route()` call keeps
> working and now emits an absolute URL on the HR host.

---

## Why 2FA is skipped here — and what keeps it contained

The HR host is SSO-only for **everyone**, admins included. Two properties keep
that contained. Do not break either one:

1. **The host serves nothing but the HR workspace.**
   `EnforceHrPortalHostIsolation` is an allow-list — `/admin`, `/portal`, the
   phonebook and the other portals all 404 here, for guests and admins alike. A
   session established on this host can only ever reach an HR request form.
2. **The skip is scoped to the host, not to the session.** `RequireTwoFactor`
   short-circuits on the HR host, and `MicrosoftController` returns before the
   2FA branches **without** setting `2fa_verified`. So if that same session is
   ever pointed at a NOC route, the normal 2FA challenge still fires.

On top of that, every HR surface is permission-gated (below) and read/propose
only — the destructive half of every flow is behind IT approval plus, for
onboarding and offboarding, the line manager's own emailed form.

> This is a weaker gate than the NOC's. It is the owner's explicit decision, and
> it is the reason property 1 matters: **adding any route to this host widens
> what an SSO-only session can reach.** See "Adding a route" below.

---

## Permissions

| Slug | Grants |
|---|---|
| `manage-hr-portal` | Access the workspace at all (hub, requests list, employee search) |
| `submit-hr-onboarding` | The onboarding form |
| `submit-hr-offboarding` | The termination form |
| `submit-hr-employee-update` | The data-change form |

All four are on the `hr` role by default (`RolePermission::defaultPermissions()`)
and granted to `super_admin` / `admin` by migration
`2026_08_02_000010_add_hr_portal_permissions`. They are independent, so a narrow
HR user can be given, say, onboarding only.

An authenticated user without `manage-hr-portal` lands on `portal.hr.no-access`
rather than being bounced to the NOC — see `EnsurePermission`.

---

## One-time setup

### The short version

Add the DNS record first, then run the provisioning script on the NOC VM:

```
hr.samirgroup.net.   A   <VPS_PUBLIC_IP>
```

```bash
cd /home/azureuser/phonebook2 && sudo bash deployment/hr-portal/setup.sh
```

That creates the vhost (deriving docroot + php-fpm socket from the existing NOC
vhost, so it survives PHP upgrades), obtains the TLS certificate, runs
migrations, clears the cached config/routes, and smoke-tests that `/login` serves
200 and `/admin/employees` 404s on the new host. It is idempotent.

Then do **steps 4 and 5 below by hand**. Nothing else is needed.

Useful overrides:

```bash
sudo HR_PORTAL_DOMAIN=hr.samirgroup.com bash deployment/hr-portal/setup.sh
sudo CERTBOT_EMAIL=it@samirgroup.com    bash deployment/hr-portal/setup.sh
sudo SKIP_DNS_CHECK=1 bash deployment/hr-portal/setup.sh   # DNS not propagated yet
sudo SKIP_TLS=1       bash deployment/hr-portal/setup.sh   # HTTP now, cert later
```

The rest of this section is what the script does, for reference or manual setup.

### 1. DNS
```
hr.samirgroup.net.   A   <VPS_PUBLIC_IP>
```

### 2. nginx vhost (same docroot as NOC)

```nginx
server {
    listen 443 ssl http2;
    server_name hr.samirgroup.net;

    root /home/azureuser/phonebook2/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/hr.samirgroup.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hr.samirgroup.net/privkey.pem;

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
    server_name hr.samirgroup.net;
    return 301 https://$host$request_uri;
}
```

Reload: `sudo nginx -t && sudo systemctl reload nginx`.

### 3. TLS certificate
```
sudo certbot --nginx -d hr.samirgroup.net
```

### 4. Azure AD (Entra) — add the redirect URI

**This is the one that will bite you.** The SSO redirect URI is built from the
current host (`url('/auth/microsoft/callback')`), so in the App Registration used
for SSO add a **Web** redirect URI:

```
https://hr.samirgroup.net/auth/microsoft/callback
```

Without it, sign-in on the HR host fails with an AADSTS redirect-mismatch error.

### 5. Grant HR staff access

Admin → Users → set the user's role to **hr**. Or grant the individual slugs in
Admin → Permissions. A brand-new SSO user is created as `browser_user` and will
land on the "No HR access" page until this is done.

### 6. `.env` (optional)

Only needed to override the default:

```
HR_PORTAL_DOMAIN=hr.samirgroup.net
HR_PORTAL_ENABLED=true
```

Then, because the host is baked into the cached route table:

```
php artisan config:clear && php artisan route:clear
```

(Re-run `route:cache` / `config:cache` afterwards if you cache them at deploy time.)

---

## Deploy notes / gotchas

- **Adding a new route to this host** means naming it under `portal.hr.*`, or
  adding its route name to `EnforceHrPortalHostIsolation::ALLOWED_NAMES`.
  Anything else 404s here regardless of permissions. Because this host skips 2FA,
  treat every addition as widening what an SSO-only session can reach — don't put
  anything here that writes directly to a record.
- **Terminations must go through `OffboardingRequestService`.** Creating a bare
  `employee_offboarding` WorkflowRequest (as the admin *Create Workflow* form
  does) produces no manager token and no `OffboardingWorkflow` row, so nothing
  ever executes. The portal form and `POST /api/hr/offboarding` both use the
  service and are equivalent.
- **Access analytics** label this host as app `hr` (`AccessVisitRecorder::appFor`),
  so HR traffic does not inflate the NOC numbers on `/admin/access-stats`.
- **`employee_update` is inert until the apply arm ships.** The form raises the
  request and IT can approve it, but `ExecuteWorkflowJob` has no
  `employee_update` handler yet — approving one currently changes nothing.
