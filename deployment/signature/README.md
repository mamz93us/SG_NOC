# Outlook Signature Deployment (Intune)

Pushes the domain-driven Outlook signature to every user's **classic Outlook for
Windows**. The signature content is chosen server-side by the user's email domain
(`samirgroup.com`, `sssegypt.com`, …) and personalised with their name / title /
branch from the employee profile — so one script serves everyone.

```
Windows PC (user logged in)
   │  Intune runs Deploy-Signature.ps1 in USER context
   ▼
whoami /upn  ──►  GET https://noc.samirgroup.net/api/signature?upn=…&type=new_email&api_key=…
   │                     (NOC picks template by domain, fills branch from profile)
   ▼
%APPDATA%\Microsoft\Signatures\SamirGroup.htm / .txt   +   HKCU default-signature registry
   ▼
Outlook shows the signature on New mail & Reply/Forward
```

## Two clients, two mechanisms (the hybrid — recommended)

| Client | Reads signature from | Deploy with |
|---|---|---|
| **Classic Outlook** (desktop) | local files `%APPDATA%\Microsoft\Signatures` + HKCU | **`Deploy-Signature.ps1`** (Intune, per-PC, user context) |
| **New Outlook** / **Outlook on the web** / **mobile** | server-stamped at send time by an **Exchange transport rule** | **`Deploy-TransportRules.ps1`** (admin-run, per-domain rule) |

Both are driven by the **same NOC signature** (design once in the NOC editor). New Outlook
ignores local files, and with roaming signatures ON there is no supported API to write the
per-mailbox cloud signature — so the reliable server path is a **transport rule** that stamps
the signature in mail flow.

**No double-signing:** every rendered signature carries a hidden marker (`SGSIGMARKER`). The
transport rule stamps **except** when the body already contains that marker — so classic
Outlook mail (already client-signed) is skipped, while new Outlook / OWA / mobile mail is
stamped server-side. Roll out with a **pilot group** used by *both* the Intune assignment and
the transport rules — add a user to the group and they're covered on every client; remove them
and they're fully removed. No org-wide switch.

> The older `Deploy-NewOutlook-Signatures.ps1` (per-mailbox `Set-MailboxMessageConfiguration`)
> only surfaces when roaming signatures are **disabled** org-wide. Prefer the transport rule.

## 1. Create a Signature API key

1. NOC → **Admin → HR API Keys** (`/admin/hr-api-keys`).
2. **New key** → Name `Outlook Signature Deploy`, **Scope = `signature`**.
3. Copy the `hrk_…` key **once** (it is not shown again).

The key only lets a caller *fetch rendered signature HTML*. It cannot read or write
anything else, and the `/api/signature` endpoint is throttled (120 req/min).

## 2. Fill in the scripts

Edit the `param()` block at the top of **`Deploy-Signature.ps1`** (and, if you use
Proactive Remediations, **`Detect-Signature.ps1`**):

```powershell
$ApiKey        = 'hrk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'   # the key from step 1
$BaseUrl       = 'https://noc.samirgroup.net'
$SignatureName = 'SamirGroup'                                    # main (new-mail) name in Outlook
$ReplyName     = 'SamirGroup Reply'                              # reply/forward name in Outlook
```

The script installs **both** signatures: the **New email (main)** slot from the
`new_email` template and the **Reply/forward** slot from the `reply` template
(falls back to the main one if you haven't made a reply template). It sets each as
the Outlook default for its slot.

**Old signatures removed:** by default the script deletes every *other* signature in
the user's `%APPDATA%\Microsoft\Signatures` (files and `_files` folders), leaving only
`$SignatureName` and `$ReplyName`. Pass `-KeepOtherSignatures` to leave existing ones.

**Lock (default on):** signature files are written **read-only**; the default selection
is forced through the **Policy** registry hive (Outlook honours it over any UI change);
and the compose-window **Signature button is disabled** (control ID 5608). Combined with
the daily Proactive Remediation re-apply (3b), users cannot change, delete, or add a
signature that actually gets used. Pass `-NoLock` to install without locking (testing).

You can leave `$Upn` blank — it is auto-detected (`whoami /upn`, then the Office
identity cache, then `dsregcmd`).

## 3a. Deploy — simple (one-time / on enrolment)

Intune admin center → **Devices → Scripts and remediations → Platform scripts →
Add → Windows 10 and later**:

- **Script location:** `Deploy-Signature.ps1`
- **Run this script using the logged-on credentials:** **Yes**  ← required (HKCU/%APPDATA%)
- **Enforce script signature check:** No
- **Run script in 64-bit PowerShell:** Yes
- **Assign** to your users/devices group.

Platform scripts run once per device and retry on failure. Good for initial rollout.

## 3b. Deploy — recommended (auto-refresh when a template changes)

Use a **Proactive Remediation** so signatures update automatically whenever an admin
edits a template in the NOC:

Intune → **Devices → Scripts and remediations → Remediations → Create**:

- **Detection script:** `Detect-Signature.ps1`
- **Remediation script:** `Deploy-Signature.ps1`
- **Run this script using the logged-on credentials:** **Yes**
- **Run script in 64-bit PowerShell:** Yes
- **Schedule:** Daily
- **Assign** to your users group.

`Detect-Signature.ps1` compares a stored hash against the current server render and
reports "non-compliant" only when the signature is missing or has changed — then the
remediation rewrites it. (Requires Windows Enterprise E3/E5 or Intune Suite for
Proactive Remediations.)

## 4. Verify

On a test PC (as the user):

```powershell
powershell -ExecutionPolicy Bypass -File .\Deploy-Signature.ps1 `
    -ApiKey 'hrk_…' -SignatureName 'SamirGroup'
```

- Log: `%LOCALAPPDATA%\SamirGroup\SignatureDeploy\deploy.log`
- Files: `%APPDATA%\Microsoft\Signatures\SamirGroup.htm`
- Restart Outlook → **File → Options → Mail → Signatures** shows `SamirGroup`, set as
  default for New messages and Replies/forwards. Open a new mail to confirm.

## New Outlook / OWA / mobile — transport rule (recommended)

Because roaming signatures are ON, the reliable way to cover new Outlook / OWA / mobile is a
per-domain **Exchange transport rule** that stamps the NOC signature in mail flow. NOC
generates the rule HTML (its variables mapped to `%%AD-attribute%%` tokens); an admin runs
`Deploy-TransportRules.ps1` to push it into Exchange, scoped to the pilot group.

```
Admin box
   │  Connect-ExchangeOnline (interactive)
   ▼  for each domain:
GET /api/signature/transport-rule?domain=…  ──►  New-/Set-TransportRule
   │   (NOC vars → %%DisplayName%% %%Title%% …)      -ApplyHtmlDisclaimerText …
   ▼                                                  -FromMemberOf <PilotGroup>
Exchange fills %% tokens per sender from Azure AD (which NOC populates) and appends the
signature at send time — EXCEPT when the body already carries the SGSIGMARKER (classic
Outlook already signed it), so no double signature.
```

**Run it** (as an Exchange admin):
```powershell
# Preview (no changes):
.\Deploy-TransportRules.ps1 -ApiKey hrk_… -PilotGroup 'SG-Signature-Pilot@samirgroup.com' -WhatIf

# Apply (scoped to the pilot group):
.\Deploy-TransportRules.ps1 -ApiKey hrk_… -PilotGroup 'SG-Signature-Pilot@samirgroup.com'
```
Prereqs: `Install-Module ExchangeOnlineManagement`; a **mail-enabled group** for the pilot;
keep the template logo **hosted by URL** (not embedded) so the disclaimer stays within
Exchange's size limit. Re-run whenever you change the NOC template.

---

## (Legacy) New Outlook via per-mailbox roaming signature

New Outlook, Outlook on the web, and Outlook mobile read a **roaming signature stored in
the mailbox**, not local files. `Deploy-NewOutlook-Signatures.ps1` sets it with
`Set-MailboxMessageConfiguration` — **but this only surfaces when roaming signatures are
disabled org-wide** (`Set-OrganizationConfig -PostponeRoamingSignaturesUntilLater $true`).
Prefer the transport rule above unless you've deliberately disabled roaming.

```
NOC VM / admin box (scheduled daily)
   │  Connect-ExchangeOnline (app-only cert auth)
   ▼  for each mailbox in samirgroup.com / sssegypt.com:
GET /api/signature?upn=…&type=new_email   ──►   Set-MailboxMessageConfiguration
   ▼                                              -SignatureHtml … -AutoAddSignature $true
New Outlook / OWA / mobile show the signature (roaming)
```

### One-time setup (app-only auth)

1. **Install the module** on the runner: `Install-Module ExchangeOnlineManagement -Scope AllUsers`.
2. **Entra app registration** (you can reuse the identity-sync app):
   - API permission **Office 365 Exchange Online → `Exchange.ManageAsApp`** (Application) → **Grant admin consent**.
   - Upload a **certificate** (public key) to the app; keep the matching cert in the runner's
     certificate store (note its **thumbprint**).
   - Assign the app the **Exchange Administrator** Entra role (lets it write mailbox config).

### Run it

```powershell
# Preview (no changes) for the whole org:
.\Deploy-NewOutlook-Signatures.ps1 -AppId <app-guid> -Organization samirgroup.onmicrosoft.com `
    -CertThumbprint <thumbprint> -ApiKey hrk_… -WhatIf

# Apply to all user mailboxes in the two domains:
.\Deploy-NewOutlook-Signatures.ps1 -AppId <app-guid> -Organization samirgroup.onmicrosoft.com `
    -CertThumbprint <thumbprint> -ApiKey hrk_…

# Just a few users:
.\Deploy-NewOutlook-Signatures.ps1 -AppId … -Organization … -CertThumbprint … -ApiKey hrk_… `
    -Upns 'ahmed.mohsen@sssegypt.com','someone@samirgroup.com'
```

Schedule it **daily** (Task Scheduler on the NOC VM) so cloud signatures refresh when a
template changes and any user edit is overwritten. Log: `%LOCALAPPDATA%\SamirGroup\SignatureDeploy\newoutlook-deploy.log`.

### New-Outlook caveats

- **One signature per mailbox.** `Set-MailboxMessageConfiguration` applies a single
  signature; `-AutoAddSignatureOnReply` makes replies use it too, but a *separate* reply
  template is not supported in the cloud (classic Outlook still gets the distinct reply one).
- **Enforcement.** Users can still edit their OWA signature; the daily re-run overwrites it.
  A truly tamper-proof result (any client, no re-run) needs an **Exchange transport-rule
  disclaimer** — the only server-stamped option, at the cost of stacking on long threads.
- **Roaming signatures must be enabled** for the org (default on in most tenants). If your
  tenant disabled them (`Set-OrganizationConfig -PostponeRoamingSignaturesUntilLater $false`),
  new Outlook will pick up the mailbox signature.

## Notes & limitations

- **Classic Outlook only.** The "new Outlook for Windows" (Monarch) uses cloud/roaming
  signatures set via OWA, not local files. The script sets
  `DisableRoamingSignaturesTemporaryToggle=1` so, in classic Outlook, the local files
  win over M365 cloud signatures.
- **Restart required once.** Outlook loads signatures at startup; a running Outlook
  won't pick up the change until restarted.
- **About the lock.** Classic Outlook has no single "grey out signatures" switch. The
  lock here is the practical combination that works: old signatures removed + read-only
  files + the Policy hive forcing the selection (Outlook honours policy over user choice)
  + the compose Signature button disabled + the daily re-apply. A determined local admin
  can still remove it; for a *hard* guarantee use a server-side Exchange transport rule
  or Exclaimer/CodeTwo. To unlock a machine:

  ```powershell
  $ver = '16.0'
  Get-ChildItem "$env:APPDATA\Microsoft\Signatures\SamirGroup*" | ForEach-Object { $_.IsReadOnly = $false }
  Remove-Item "HKCU:\Software\Policies\Microsoft\Office\$ver\Common\MailSettings" -Recurse -Force -EA SilentlyContinue
  Remove-Item "HKCU:\Software\Policies\Microsoft\Office\$ver\Outlook\DisabledCmdBarItemsList" -Recurse -Force -EA SilentlyContinue
  ```

- **Per-account overrides.** If a mailbox already has a *manually chosen* per-account
  signature, that can override the global default. A clean deployment (or clearing the
  user's existing selection once) resolves it.
- **Remote logo.** The signature references the logo by URL (`logo_url`), so the image
  loads from the web. Recipients may need to allow image download on first view — this
  is normal for HTML signatures. To embed the logo instead, host it and it still renders;
  true CID-embedding would require extending the script to download + rewrite the `<img>`.
- **UPN detection.** Works on Azure AD-joined / hybrid-joined machines. For workgroup
  PCs, pass `-Upn user@domain.com` explicitly.
