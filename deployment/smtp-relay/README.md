# SG-NOC local SMTP relay (Ricoh MFPs → Amazon SES)

Legacy Ricoh MP **C3001 / C3003** MFPs can't scan-to-email through Amazon SES
directly — their SMTP client is too old for SES's TLS 1.2 + SASL-auth +
verified-identity requirements. This is a **Postfix smarthost relay** that runs
on the NOC VM: printers submit plain, unauthenticated mail on port 25 (which they
*can* do), and Postfix relays it to SES over authenticated TLS.

```
  Ricoh MFP                     NOC VM (this relay)                 Amazon SES
 ┌─────────┐  plain SMTP :25   ┌──────────────────┐  TLS+SASL :587  ┌─────────┐
 │ C3001/  │ ────────────────▶ │ Postfix          │ ──────────────▶ │  SES    │ ─▶ inbox
 │ C3003   │  (no auth/TLS)    │ • mynetworks ACL │  scanner@       │ us-east-1│
 └─────────┘                   │ • sender rewrite │  samirgroup.com └─────────┘
                               └──────────────────┘
```

## Files

| File | Purpose |
|------|---------|
| `main.cf` | Postfix relay config template → `/etc/postfix/main.cf` |
| `ses-smtp-password.sh` | Derives the SES SMTP password from the existing `AWS_SECRET_ACCESS_KEY` |
| `setup.sh` | Idempotent installer (run as root on the NOC VM) |
| `sasl_passwd.example` | Shape of `/etc/postfix/sasl_passwd` (real file is git-ignored) |

## Install

```sh
cd /home/azureuser/phonebook2/deployment/smtp-relay
sudo bash setup.sh     # reuses the SES creds stored in Email Marketing settings
```

`setup.sh` installs Postfix (no local mail config), deploys `main.cf`, obtains the
SES SMTP line via `php artisan smtp-relay:sasl-line` (which reads the SES
credentials stored — encrypted — in **Admin → Email Marketing settings** and
derives the SMTP password), writes and `postmap`s `/etc/postfix/sasl_passwd`
(mode 600), and starts Postfix. Override with explicit
`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` env vars if you prefer.

Then edit `mynetworks` in `main.cf` to the real **printer VLAN** CIDRs, and:

```sh
sudo cp main.cf /etc/postfix/main.cf && sudo postfix reload
```

## Ricoh scan-to-email settings (per printer)

- **SMTP Server Name** = NOC internal IP (e.g. `172.16.8.x`)
- **Port** = `25`
- **SMTP Authentication** = Off
- **Use Secure Connection (SSL/TLS)** = Off *(STARTTLS optional if the unit does it cleanly)*
- **Administrator / sender address** = anything sane — the relay rewrites every
  sender to `scanner@samirgroup.com` anyway.

## Security note — this is NOT an open relay

`mynetworks` is the guard: only the listed printer subnets may relay through this
host (`reject_unauth_destination` blocks everyone else). Keep it narrow, and keep
inbound port 25 restricted to printer subnets at the Azure NSG + host firewall.
Verify with the open-relay negative test in `SMTP_RELAY_SETUP.md`.

## Troubleshooting

```sh
systemctl status postfix
ss -ltnp | grep ':25'            # confirm Postfix is listening
tail -f /var/log/mail.log        # delivery / SES relay lines
postqueue -p                     # stuck mail
postqueue -f                     # flush the queue
postconf -n                      # effective config
```

Common SES rejections in `mail.log`:
- `Email address is not verified` → `scanner@samirgroup.com` (or `samirgroup.com`)
  isn't verified in the SES **us-east-1** region.
- `... in the sandbox` / recipient not verified → SES account still in sandbox;
  request production access.
- `535 Authentication Credentials Invalid` → wrong region, or the IAM key lacks
  `ses:SendRawEmail`, or the SMTP password wasn't re-derived after a key rotation.

Full runbook: [../../SMTP_RELAY_SETUP.md](../../SMTP_RELAY_SETUP.md).
