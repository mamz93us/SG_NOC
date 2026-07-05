# NOC-AGW — Deployment Runbook

Fronts the legacy IIS app with HTTPS + IP allowlist + audit on
`arcmate.samirgroup.net`. IP-ACL mode (no SSO yet). The legacy app is **never
modified**; it keeps serving plain HTTP on the LAN.

## Topology

```
Browser ─HTTPS:443─► nginx (arcmate vhost, on the NOC VM)
                      └─proxy_pass 127.0.0.1:8443─► noc-agw (FastAPI, systemd)
                                                     └─HTTP─► IIS app  http://<IIS-LAN-IP>:8891
```

- Gateway host: the existing NOC Azure VM (`noc.samirgroup.net`, `/home/azureuser`).
- The IIS VM is on the **same LAN** as the NOC VM; the gateway reaches it by LAN IP.
- DB: reuse `phonebook2`. Tables `agw_allowlist`, `agw_audit`, `agw_ip_history`
  and the `settings.agw_*` columns are created by the SG_nOC Laravel migrations.

---

## 1. DNS

Create one A record:

```
arcmate.samirgroup.net.  A  <NOC VM public IP>   # same IP as noc.samirgroup.net
```

Do **not** point it at the IIS box. nginx separates the two hostnames by
`server_name`.

---

## 2. Database migrations + least-privilege gateway user

On the NOC VM (schema is owned by Laravel — run the migrations, don't hand-edit):

```sh
cd /home/azureuser/phonebook2
php artisan migrate            # creates agw_* tables + settings.agw_* columns + permissions
```

Create a DB user the gateway uses (reads config, writes audit only):

```sql
CREATE USER 'agw_gateway'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON phonebook2.agw_allowlist TO 'agw_gateway'@'127.0.0.1';
GRANT SELECT (id, agw_backend_url, agw_enforce_ip_acl) ON phonebook2.settings TO 'agw_gateway'@'127.0.0.1';
GRANT INSERT ON phonebook2.agw_audit TO 'agw_gateway'@'127.0.0.1';
FLUSH PRIVILEGES;
```

---

## 3. Install the gateway (systemd)

```sh
# Clone/copy the noc-agw/ directory to /home/azureuser/noc-agw
cd /home/azureuser/noc-agw
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt

cp .env.example .env
#   set BACKEND_URL=http://<IIS-LAN-IP>:8891   (fallback; the Settings page value wins)
#   set DB_USER=agw_gateway  DB_PASSWORD=...
#   PUBLIC_HOST=arcmate.samirgroup.net

sudo cp deploy/systemd/noc-agw.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now noc-agw
curl -s http://127.0.0.1:8443/_agw/health      # -> {"status":"ok"}
```

---

## 4. nginx vhost + certificate

```sh
sudo cp deploy/nginx/arcmate.samirgroup.net.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/arcmate.samirgroup.net.conf /etc/nginx/sites-enabled/
# Ensure the websocket upgrade map exists once in http{} (see the note at the
# bottom of the vhost file).
sudo certbot --nginx -d arcmate.samirgroup.net
sudo nginx -t && sudo systemctl reload nginx
```

---

## 5. Lock down the legacy IIS app (port 8891)

The app is currently on a **public IP over plain HTTP** — close that off. Both
VMs share the LAN, so the gateway reaches IIS privately; the public exposure is
removed entirely.

On the IIS/Windows host:

1. **Bind the gateway to the LAN IP.** In the NOC Settings → Access Gateway page
   set the App URL to `http://<IIS-LAN-IP>:8891`.
2. **Close 8891 to the internet.** Windows Firewall → Inbound Rules → the rule
   for TCP 8891:
   - Remove it from the **Public** profile (or set Action = Block there).
   - Keep it only on the **Private/Domain** profile, and under *Scope →
     Remote IP* restrict it to the **NOC VM's LAN IP** (single address).
3. **Drop any public DNS/binding** that pointed users straight at the IIS host
   on 8891, so the gateway is the only way in.

Verify from outside the LAN:

```sh
curl -m 5 http://<IIS-public-IP>:8891/     # must time out / connection refused
```

---

## 6. Verify end-to-end

- From an allowlisted branch IP: `https://arcmate.samirgroup.net` renders the app.
- From a non-listed IP: **403**, and one `deny_ip` row appears in
  Access Gateway → Audit.
- Toggle **Enforce IP allowlist** off in Settings → all IPs pass within
  `ALLOWLIST_REFRESH_SEC` (confirms live DB reload). Turn it back on.
- Change **App URL** in Settings → the gateway proxies to the new upstream on
  its next refresh, no restart.

---

## 7. Operate (from the NOC dashboard)

Admin → **Access Gateway** (needs `manage-agw-allowlist` / `manage-agw-settings`;
audit needs `view-agw-audit`):

- **Gateway Settings** — App URL + Enforce-IP-ACL toggle (read live by the gateway).
- **Branch WAN IPs** — auto-synced from `branch_agents.wan_ip` every 5 minutes
  (`agw:sync-allowlist`); "Sync now" forces it. Disable a row to block a branch.
- **Manual Allowlist** — fixed office/admin CIDRs (never overwritten by the sync).
- **Audit** — every decision, filterable by IP / decision / date.

---

## 8. Later: turn on Microsoft SSO

Out of scope for now. When wanted: place `oauth2-proxy` (Entra OIDC) between
nginx and the gateway, set `ENFORCE_SSO=true`, and populate
`ALLOWED_ENTRA_GROUPS`. `gateway/identity.py` already parses the
`X-Forwarded-Email/User/Groups` headers oauth2-proxy forwards.
