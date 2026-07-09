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
$SignatureName = 'SamirGroup'                                    # label shown in Outlook
```

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

## Notes & limitations

- **Classic Outlook only.** The "new Outlook for Windows" (Monarch) uses cloud/roaming
  signatures set via OWA, not local files. The script sets
  `DisableRoamingSignaturesTemporaryToggle=1` so, in classic Outlook, the local files
  win over M365 cloud signatures.
- **Restart required once.** Outlook loads signatures at startup; a running Outlook
  won't pick up the change until restarted.
- **Per-account overrides.** If a mailbox already has a *manually chosen* per-account
  signature, that can override the global default. A clean deployment (or clearing the
  user's existing selection once) resolves it.
- **Remote logo.** The signature references the logo by URL (`logo_url`), so the image
  loads from the web. Recipients may need to allow image download on first view — this
  is normal for HTML signatures. To embed the logo instead, host it and it still renders;
  true CID-embedding would require extending the script to download + rewrite the `<img>`.
- **UPN detection.** Works on Azure AD-joined / hybrid-joined machines. For workgroup
  PCs, pass `-Upn user@domain.com` explicitly.
