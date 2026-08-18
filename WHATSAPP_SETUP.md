# WhatsApp Alerts — Setup

NOC alerts can be delivered over WhatsApp using the **Meta WhatsApp Cloud API**
(`graph.facebook.com`), the same integration the UBL portal uses for its login
OTPs. This document covers what has to exist on the Meta side and how to wire it
up in the NOC.

Everything is configured in **Admin → Settings → WhatsApp Alerts** — nothing goes
in `.env`, and the access token is encrypted at rest (`Setting::whatsapp_access_token`).

---

## 1. What you need from Meta

In [business.facebook.com](https://business.facebook.com) → your app → **WhatsApp**:

| Value | Where | Notes |
|---|---|---|
| **Phone Number ID** | WhatsApp → API Setup | Numeric, e.g. `300975966437834`. Not the phone number itself. |
| **WhatsApp Business Account ID** | WhatsApp → API Setup | Optional here; recorded for reference. |
| **Permanent access token** | Business Settings → System Users → Generate Token | Scopes: `whatsapp_business_messaging`, `whatsapp_business_management`. |

> **Use a System User token, not the token shown in API Setup.** That one expires
> after 24 hours; when it does, alerting goes silent with a 401 and the only
> visible symptom is failed rows on the WhatsApp Log page.

## 2. The message template

WhatsApp will not let a business *start* a conversation with free-form text —
only an approved template can. An unattended NOC alert is always a
conversation-start, so **template mode is required** in practice. With it off,
sends fail with error `131047` ("re-engagement message") unless the recipient
happens to have messaged the business number in the last 24 hours.

Create a template under **WhatsApp Manager → Message templates**:

- **Category**: Utility
- **Name**: e.g. `sg_noc_alert`
- **Language**: `en` (must match the Template language field in Settings)
- **Body**, for the default `title,message` parameter mapping:

  ```
  SG NOC alert: {{1}}

  {{2}}
  ```

Approval usually takes minutes to a few hours.

### Mapping alert fields onto the template

The **Body parameters** setting is a comma-separated, ordered list of tokens that
fill `{{1}}`, `{{2}}`, … in order. Available tokens:

| Token | Value |
|---|---|
| `title` | Alert headline, e.g. "Branch tunnel down: JED" |
| `message` | Alert detail |
| `severity` | `INFO` / `WARNING` / `CRITICAL` |
| `link` | Absolute URL to the relevant NOC page |
| `time` | Send time, `Y-m-d H:i` |

**The number of tokens must match the approved template exactly.** A template with
two placeholders and three configured tokens is rejected with error `132000`.

Newlines and repeated spaces are flattened out of every parameter before sending —
Meta rejects them inside a template parameter.

## 2b. Importing the credentials from the UBL portal

The UBL portal already has a working Cloud API setup, hardcoded in
`SamirGroup/settings.py`. `whatsapp:configure` lifts those two values straight
into the NOC's encrypted settings without them passing through this repo, a
shell history entry, or the command's own output (it prints a masked
fingerprint, never the token):

```bash
php artisan whatsapp:configure --from-django=/path/to/UnitedByLegacyWebApp/SamirGroup/settings.py --country-code=20 --test
```

Without `--from-django` the command prompts for the token (hidden) or takes
`--token=` / `--phone-number-id=`. `--test` verifies against Graph, lists the
approved templates and checks that the selected one's placeholder count matches
the configured body parameters. Nothing is sent.

**What that account is, as verified on 2026-08-18:**

| | |
|---|---|
| Verified name | Samir Trading & Marketing |
| Number | +966 59 328 2053 |
| Quality rating | GREEN |
| Token type | System user, `expires_at: 0` — permanent |
| Scopes | `whatsapp_business_management`, `whatsapp_business_messaging` |

Two consequences worth deciding on before switching the channel on:

- **NOC alerts will arrive from the Samir Trading & Marketing number**, the same
  identity UBL sends its login OTPs from. If NOC alerting should have its own
  sender, register a separate number on the WABA instead.
- **The only approved template on that account is UBL's OTP template**
  (`united_by_legacy_verfitcation_code`), whose body is a verification-code
  message. It cannot carry an alert — a NOC template still has to be created and
  approved as described above.

**The Business Account ID has to be pasted in by hand.** It is not derivable
from a phone number ID: the phone node has no `whatsapp_business_account` field,
and the token's granular scopes carry no target ids. `whatsapp:configure` tries
the system user's assigned accounts, which is empty whenever the WABA reaches the
app through the business rather than a direct assignment. Copy it from WhatsApp
Manager → API Setup. Only template listing and validation need it — sending does
not.

## 3. Configure the NOC

**Admin → Settings → WhatsApp Alerts**:

1. Tick **Enable WhatsApp alerting**.
2. Paste the Phone Number ID and the permanent token, keep Graph API version at `v21.0`.
3. Set **Default country code** to `20` so numbers saved in national form
   (`01001234567`) are dialed as `201001234567`.
4. Leave **Send as an approved template** on; fill in the template name, language
   and body parameters.
5. **Test** — with the number field blank it only reads the phone number back
   from Graph (credential check, sends nothing). With a number, it sends a real
   test alert through the configured template.

## 4. Give people a number

**Admin → Users** → edit a user → **WhatsApp Number**. Digits only; the country
code is applied automatically if the number is in national form. A user with no
number is skipped by WhatsApp sends — the recipient picker on the Rules page
marks them.

Each user can also opt out for themselves at **Notifications → Preferences**.
The rule decides who is paged; the user preference can only veto.

## 5. Route the alerts

**Admin → Notifications → Rules**:

- A rule can now name **several users** at once — tick everyone who should be
  paged. Previously one rule meant one recipient, so covering three people meant
  three near-identical rules each with its own cooldown.
- Switch on **WhatsApp** per rule, alongside Email and In-App.
- **Extra WhatsApp numbers** on the rule reach an on-call phone or a manager who
  has no NOC login. These are messaged regardless of the user list.

Delivery is deduplicated by number within one event, so a person listed both as a
user and as an extra number gets one message.

## 6. Verifying and troubleshooting

**Admin → Notifications → WhatsApp Log** (permission `view-whatsapp-logs`) records
every send: destination, mode, template, status, Meta's message id (`wamid`) and
the API error verbatim.

| Symptom | Cause |
|---|---|
| `131047` re-engagement message | Template mode is off, or the template was not used. Turn template mode on. |
| `132000` number of parameters mismatch | Body parameters setting does not match the approved template. |
| `132001` template does not exist | Name or language mismatch — the language code is part of the identity. |
| `190` / 401 | Token expired. The API Setup token lasts 24 h; use a System User token. Check with `whatsapp:configure --test`. |
| `131030` recipient not in allowed list | The app is still in development mode — add the number as a test recipient, or take the app live. |
| Nothing queued at all | WhatsApp disabled in Settings, no token, or the recipients have no number saved. |

Sends are queued as `SendNotificationWhatsAppJob`. Production runs no long-lived
worker — the scheduler's `queue-drainer` (see `routes/console.php`) empties the DB
queue, so a message can lag by up to a few minutes. Failures retry three times
with a 30 s / 120 s backoff; the log row is written on every attempt.

## 7. Where the code lives

| Piece | Path |
|---|---|
| API client | `app/Services/Notifications/WhatsAppService.php` |
| Queued send | `app/Jobs/SendNotificationWhatsAppJob.php` |
| Routing | `app/Services/NotificationService.php` (`notifyViaRules`, `applyNotificationRules`) |
| Rule model | `app/Models/NotificationRule.php` (`recipients()`, `resolveRecipients()`, `whatsappNumberList()`) |
| Import / CLI setup | `app/Console/Commands/ConfigureWhatsapp.php` (`whatsapp:configure`) |
| Admin pages | `Admin\NotificationRuleController`, `Admin\WhatsappLogController`, `Admin\SettingsController::updateWhatsapp` |
| Log | `whatsapp_logs` table / `App\Models\WhatsappLog` |
