# SMTP Relay Setup (Ricoh MFPs → Amazon SES)

A local Postfix **smarthost relay** on the NOC VM so legacy Ricoh MP **C3001 /
C3003** printers can scan-to-email. The printers speak plain SMTP to the NOC's
internal IP on port 25; Postfix rewrites the sender to a single SES-verified
identity and relays to Amazon SES over authenticated TLS on 587.

Config lives in [`deployment/smtp-relay/`](deployment/smtp-relay/). This document
is the operator runbook.

---

## Why

The C3001/C3003 firmware SMTP client predates what SES requires (TLS 1.2 on 587,
SASL auth, verified-identity enforcement). Rather than replace the fleet, we put
a relay in front of SES that the printers *can* talk to.

Design decisions:
- **Sender rewrite** — every printer's envelope sender **and** `From` header are
  rewritten to `scanner@samirgroup.com`. Only that one identity needs SES
  verification. Replies go to `scanner@samirgroup.com`.
- **Reuse existing SES credentials** — the app already stores SES creds (encrypted)
  in **Admin → Email Marketing settings** (`ses_access_key_id` /
  `ses_secret_access_key` / `ses_region`). The relay's SES **SMTP** password is
  *derived* from that secret via `php artisan smtp-relay:sasl-line`; no new
  credential is created, and no AWS keys need to be in `.env`.
- **Native Postfix**, deployed from version-controlled config.

---

## Prerequisites

1. **SES out of sandbox** in `us-east-1` (production sending access). Printers
   mail external recipients (gmail, customers) as well as internal, so sandbox is
   not enough. Check: SES console → *Account dashboard*.
2. **`scanner@samirgroup.com` verified** in SES `us-east-1` (verify the address,
   or the whole `samirgroup.com` domain with DKIM).
3. **SES creds configured** in Admin → Email Marketing settings, and the IAM key
   has `ses:SendRawEmail`. (If they aren't there, either configure them, or pass
   `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` env vars to `setup.sh`.)
4. **Network reachability** — branch **printer VLANs** must route to the NOC
   internal IP (`172.16.8.x`). Per the NOC2 migration, confirm the NOC subnet is
   in each branch's Azure VPN scope (this has caused breakage before).
5. **Azure NSG / host firewall** — allow **inbound TCP 25** to the NOC VM from
   printer subnets only (never public). Outbound **TCP 587** to SES is allowed
   (Azure blocks outbound 25, but we relay out on 587).

---

## Install

On the NOC VM (`/home/azureuser/phonebook2`), after `git pull`:

```sh
cd deployment/smtp-relay
sudo bash setup.sh
```

This installs Postfix, deploys `main.cf`, obtains the SES SMTP line via
`php artisan smtp-relay:sasl-line` (reads the SES creds from Email Marketing
settings and derives the SMTP password), writes/`postmap`s
`/etc/postfix/sasl_passwd` (mode 600), and starts Postfix.

**Set the relay-able subnets.** Edit `mynetworks` in
`deployment/smtp-relay/main.cf` to the real printer VLAN CIDRs (defaults ship as
the branch LANs `10.1.0.0/22` … `10.10.0.0/22` for branches 1–10 — narrow to a
printer VLAN where one exists), then re-apply with `setup.sh` (idempotent):

```sh
cd deployment/smtp-relay && sudo bash setup.sh
```

> Do **not** just `cp main.cf /etc/postfix/main.cf` — the file carries a default
> `relayhost` region, and copying it over resets the region so it no longer
> matches the `sasl_passwd` entry, causing `530 Authentication required` bounces.
> `setup.sh` re-installs main.cf **and** re-sets the correct region + credentials.

Open the firewall for printer subnets (example with ufw; also mirror in the NSG):

```sh
sudo ufw allow from 10.1.0.0/22 to any port 25 proto tcp   # repeat per branch (10.1..10.10.0.0/22)
# ...repeat per printer subnet
```

---

## Printer configuration (Ricoh MP C3001 / C3003)

*User Tools → System Settings → File Transfer* (or the Web Image Monitor →
*Device Management → Configuration → Email*):

| Field | Value |
|-------|-------|
| SMTP Server Name | NOC internal IP (e.g. `172.16.8.x`) |
| Port No. | `25` |
| SMTP Authentication | **Off** |
| Use Secure Connection (SSL) | **Off** (STARTTLS optional if clean) |
| Administrator's Email Address | anything sane (rewritten to `scanner@samirgroup.com`) |

Roll out to **one branch first**, verify end-to-end, then the rest. Leave a
couple of printers on the old SES-direct config until the relay is proven.

---

## Verification

1. **Relay up:**
   ```sh
   systemctl status postfix
   ss -ltnp | grep ':25'
   ```
2. **Send a test** (install `swaks` or use telnet / a real scan):
   ```sh
   swaks --server <noc-ip>:25 --from test@printer.local \
         --to you@samirgroup.com --to youralt@gmail.com \
         --header 'Subject: SG-NOC relay test'
   ```
3. **Relay → SES:** `tail -f /var/log/mail.log` shows
   `relay=email-smtp.us-east-1.amazonaws.com[...]:587 ... status=sent`.
4. **Delivery + rewrite:** message arrives at both the internal and external
   mailbox, `From: scanner@samirgroup.com`.
5. **NOT an open relay** — from an IP *outside* `mynetworks`, sending to an
   external domain must be rejected:
   ```sh
   swaks --server <noc-ip>:25 --from x@y.com --to stranger@gmail.com
   # expect: 554 5.7.1 <...>: Relay access denied
   ```
6. **Real device test:** run an actual scan-to-email from one C3001 and one
   C3003, to one internal and one external recipient.

---

## Operations

- **Credential rotation** — if the AWS key rotates, re-run `sudo bash setup.sh`
  (re-derives and re-`postmap`s the SES SMTP password).
- **Add/remove a subnet** — edit `mynetworks` in `main.cf`, `postfix reload`,
  update NSG/ufw.
- **Queue** — `postqueue -p` to inspect, `postqueue -f` to flush.
- **Logs** — `/var/log/mail.log`.

## Rollback

Point the printers back at their previous SMTP settings (or stop Postfix:
`sudo systemctl stop postfix`). Backups of the prior `main.cf` are kept as
`/etc/postfix/main.cf.bak.<timestamp>` by `setup.sh`.
