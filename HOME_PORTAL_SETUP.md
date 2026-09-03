# Employee Home Portal — `home.samirgroup.net`

The page every company PC opens on. Signs people in **as their Windows account,
with no click**, and shows them their systems, their ID card, company
announcements, the calendar, payday and their own security score.

- **Page:** `https://home.samirgroup.net`
- **Announcements:** Admin → **Announcements** (`manage-announcements`)
- **Greeting wording:** Admin → **Greeting Lines** (`manage-greeting-lines`)
- **Integrations:** Admin → Settings → **Employee Home Portal** (`#home-portal`)

---

## How the silent sign-in works

A web page cannot read the Windows account. What it can do is ask Entra for a
token **without permission to show any UI**:

1. A guest hits `/`. The controller redirects once to
   `/auth/microsoft?from=home&silent=1`.
2. That adds `prompt=none` to the Entra authorize request.
3. An Entra-joined Windows machine holds a **Primary Refresh Token** issued at
   Windows sign-in. The browser presents it automatically, Entra returns a code,
   and **nothing is rendered** — the person lands on the portal already signed
   in, as whoever they logged into Windows as.
4. If Entra cannot do that silently it returns `?error=login_required`. The
   callback sets a short-lived cookie and shows a normal sign-in page.

**That cookie is the loop guard and is not optional.** Without it, any browser
that can never satisfy `prompt=none` — a personal device, a private window, a
machine that is not Entra-joined — bounces against `login.microsoftonline.com`
on *every browser launch, forever*. It lives in `config/home_portal.php`
(`silent_retry_minutes`, default 10).

Kerberos/SPNEGO was considered and rejected: it needs a custom nginx module, an
AD keytab, an SPN, and line-of-sight to a domain controller. Since the NOC2
migration the NOC is an Azure VM reaching branches over the Azure VPN gateway,
so a per-branch DC dependency is exactly the fragility the tunnel watchdog
exists to catch.

### Edge works. Chrome needs one extra policy.

Edge does PRT SSO natively. **Chrome needs `CloudAPAuthEnabled = 1`** (or the
"Windows Accounts" extension) or it cannot use the Windows account, and every
Chrome user falls back to the sign-in button while Edge users see their portal.
It is in the Intune kit — do not drop it.

---

## Host isolation

Fourth isolated subdomain, same pattern as `vcard.`, `hr.` and
`em.samirgroup.net`: same Laravel app, domain-routed, SSO-only, **2FA skipped by
host**.

`EnforceHomePortalHostIsolation` allows only the `home.*` route namespace plus
the SSO endpoints and `/up`. Everything else — `/admin`, the NOC dashboards, the
phonebook, the other portals — **404s here**. That containment is what makes
skipping 2FA defensible on the one host a browser opens unattended on every PC.

The session is **never** marked `2fa_verified`, so the same user hitting a NOC
route is still challenged normally. Do not "optimise" that into a session flag.

---

## What is on the page

The page is in three bands: **Quick access** (the applications), **IT &
support**, and **Company**.

| Section | Card | Backed by |
|---|---|---|
| Quick access | Oracle ERP / Salesforce / ArcMate | `config/home_portal.php` → `core_systems` |
| Quick access | Outlook Mail | `config/home_portal.php` → `webmail_url` (not a `core_systems` entry) |
| IT & support | IT Service Desk | The Create Ticket service (`NocTicketService`), in a modal |
| IT & support | Phish-Prone Score | `knowbe4_scores`, synced daily |
| IT & support | Documentation & Manuals | `portal_documents`, every category but `policy` |
| IT & support | IT Policy | `portal_documents`, category `policy` |
| IT & support | My Assets | ITAM assignments for the signed-in employee |
| Company | My Payroll | Countdown only — `config/home_portal.php` → `payday` |
| Company | Company Calendar | `company_events`, synced hourly from Microsoft Graph |
| Company | Employees Directory | The existing public phonebook |
| Company | Announcements | `announcements` table, full-width slider + archive |
| Sidebar | ID card + Add to Wallet | `Employee.card_token`, the existing `/card/{token}` + `.pkpass` |

Tiles with no URL configured are **hidden**, not rendered as dead links. Cards
whose integration is off hide themselves rather than showing dashes.

### If someone has no HR record

The ID card falls back to their sign-in name and email and says so. Announcement
targeting treats them as "everyone" audience only. A missing `employees` row
must never blank the page.

---

## Configuration

### Core system URLs (`.env`)

```
HOME_PORTAL_DOMAIN=home.samirgroup.net
HOME_PORTAL_URL_ORACLE=https://...
HOME_PORTAL_URL_SALESFORCE=https://...
HOME_PORTAL_URL_ARCMATE=https://arcmate.samirgroup.net
HOME_PORTAL_PAYDAY_DAY=25
# ...or the last Sun–Thu of the month instead:
HOME_PORTAL_PAYDAY_LAST_WORKING_DAY=false
```

No salary figure is read, shown or stored — the payroll card is a countdown and
a link.

### Company Calendar (Microsoft Graph)

Admin → Settings → Employee Home Portal → shared calendar mailbox.

**Requires the `Calendars.Read` APPLICATION permission, admin-consented** on the
same app registration SSO uses. This client is app-only, so a *delegated* grant
does nothing. A 403 in `company-calendar:sync` is almost always this.

Uses `calendarView`, not `/events`, so recurring series are expanded into
individual occurrences.

### KnowBe4 (Security Score)

Admin → Settings → Employee Home Portal → token + **region**.

The API host is region-specific (US / EU / CA / UK / DE). **A token from the
wrong region fails with a 401 that is indistinguishable from a bad token** — set
the region correctly, and use **Save & Test** to confirm both at once.

Everyone sees only their own risk score and phishing count. There is no
cross-employee view, deliberately.

### Documents (manuals & IT policies)

Admin → **Employee Documents** (`manage-portal-documents`). One library feeds
both IT cards; the **category** decides which — `policy` lands behind *IT
Policy*, everything else behind *Documentation & Manuals*.

A document is **either an upload or a link**, never both — a card can only do
one thing, and an upload wins if you supply both. Uploads go to the **`private`
disk** (`storage/app/private/portal-documents/`) under a generated UUID name,
and are reachable only through `/documents/{id}/download`, which re-checks
publication **and** audience on every request. They are never a public URL, and
nginx cannot serve them: do not "fix" a download by moving them to the public
disk.

Audience is the same triple as announcements (everyone / one branch / one
department), and someone with no HR record still sees the "everyone" documents —
a missing `employees` row must never hide the IT policy from them.

Tile counts are cached per audience for 5 minutes and flushed on save. The two
cards are always shown, with static wording, even when the library is empty:
they are where people are told to go.

### Announcements

Admin → **Announcements**. Audience is everyone, one branch, or one department.
`published_at` in the future schedules; `expires_at` retires a notice on its own.
The slider shows the newest few; the card links to the archive.

### Greeting

Admin → **Greeting Lines**. One line is chosen per person per hour, so it does
not flicker on refresh. **If the table is empty a built-in set is used** — the
greeting is never blank.

---

## Scheduled tasks

```
company-calendar:sync   hourly
knowbe4:sync            daily 05:30
```

Both no-op when their integration is disabled. **Neither is ever called from a
page load**: the whole company opens this page within minutes of 9am, and a
Graph or KnowBe4 round trip per visit would be slow and a good way to get
throttled.

---

## Deploy

```bash
git pull && php artisan migrate && php artisan config:clear && php artisan route:clear
sudo bash deployment/home-portal/setup.sh
```

Then, and **nothing works until these are done**:

1. **DNS** — `home.samirgroup.net` → the NOC public IP.
2. **Entra redirect URI** — add
   `https://home.samirgroup.net/auth/microsoft/callback` to the app
   registration, or every sign-in fails with `AADSTS50011`.
3. **Browser policy** — see [deployment/homepage/README.md](deployment/homepage/README.md).
   Pilot group first; this is the first thing everyone sees each morning.

Optional: `Calendars.Read` consent, and a KnowBe4 token.

---

## Troubleshooting

**Sign-in button instead of the portal, on a company PC.**
`dsregcmd /status` — `AzureAdJoined` should be `YES` and, under SSO State,
`AzureAdPrt : YES`. In Chrome, confirm `CloudAPAuthEnabled` at `chrome://policy`.
A private window always shows the button; that is correct.

**`AADSTS50011`.** The redirect URI is not registered. See step 2.

**Everything 404s on the host.** Cached routes still hold the old host list:
`php artisan route:clear && php artisan config:clear`.

**Calendar card is empty.** Run `php artisan company-calendar:sync -v`. A 403
means the `Calendars.Read` application permission is missing or unconsented.

**Security Score card missing.** It hides until the first successful sync —
showing dashes would imply a perfect score. Run `php artisan knowbe4:sync`.

**A corrected announcement still shows the old text.** Shared panels are cached
for five minutes; saving through the admin screen clears it immediately, so this
only happens if the row was edited directly in the database.
