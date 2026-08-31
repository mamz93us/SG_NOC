# Browser homepage policy — Employee Home Portal

Points the fleet's browsers at `https://home.samirgroup.net` on launch and on
the Home button. The new-tab page is **deliberately left alone** so opening a tab
to search stays fast and familiar.

Two ways to apply it. Intune Settings Catalog is the right one; the `.ps1` is a
fallback for machines Intune does not manage.

---

## The one that is easy to miss

**Edge signs in silently on its own. Chrome does not, unless you set
`CloudAPAuthEnabled = 1`.**

The portal signs people in as their Windows account by asking Entra for a token
with `prompt=none`, which the browser satisfies from the device's Primary
Refresh Token. Edge does this natively. Chrome only participates when
`CloudAPAuthEnabled` is on (or the "Windows Accounts" extension is installed).

Without it, every Chrome user gets a "Sign in with Microsoft" button instead of
their portal — the feature looks broken for a third of the fleet while working
perfectly for everyone else. Set it in the same policy.

---

## Intune — Settings Catalog

Devices → Configuration → **Create** → Windows 10 and later → **Settings catalog**.

### Microsoft Edge

| Setting | Value |
|---|---|
| Action to take on startup | **Open a list of URLs** |
| Sites to open when the browser starts | `https://home.samirgroup.net` |
| Configure the home page URL | `https://home.samirgroup.net` |
| Show Home button on toolbar | **Enabled** |

### Google Chrome

Chrome ADMX must be ingested first (Devices → Configuration → **Import ADMX**)
if you have not already done so.

| Setting | Value |
|---|---|
| Action on startup | **Open a list of URLs** |
| URLs to open on startup | `https://home.samirgroup.net` |
| Configure the home page URL | `https://home.samirgroup.net` |
| Show Home button on toolbar | **Enabled** |
| **Enable Cloud AP SSO** (`CloudAPAuthEnabled`) | **Enabled** |

Assign to a **pilot group first**. This is the first thing every person in the
company sees each morning; a bad URL is very visible, very fast.

---

## Fallback — `Set-HomepagePolicy.ps1`

For anything Intune does not manage. Writes the same policy registry keys
under `HKLM`, so it takes effect for every user on the machine and the browser
shows them as managed.

```powershell
# Apply (run elevated)
.\Set-HomepagePolicy.ps1

# A different URL
.\Set-HomepagePolicy.ps1 -Url "https://start.samirgroup.com"

# Edge only
.\Set-HomepagePolicy.ps1 -Browsers Edge

# See what it would do
.\Set-HomepagePolicy.ps1 -WhatIf

# Undo
.\Set-HomepagePolicy.ps1 -Remove
```

Deploy as an Intune platform script, a GPO startup script, or by hand.

---

## Verifying it worked

On a target machine, after a reboot or `gpupdate /force`:

1. `edge://policy` (or `chrome://policy`) — **Reload policies**, then confirm
   `RestoreOnStartupURLs` and `HomepageLocation` show the portal URL, and for
   Chrome that `CloudAPAuthEnabled` is `true`.
2. Close the browser fully and reopen it. The portal should appear **already
   signed in**, greeting the person by name, with no click.
3. If it shows a sign-in button instead, the device is not getting a Primary
   Refresh Token to the browser. Check `dsregcmd /status` — `AzureAdJoined`
   should be `YES` and, under SSO State, `AzureAdPrt : YES`.

A private/incognito window will always show the sign-in button. That is correct
behaviour, not a fault — there is no Windows session to borrow.
