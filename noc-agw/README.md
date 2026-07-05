# NOC-AGW — Access Gateway

A small FastAPI reverse proxy that fronts a legacy, HTTP-only IIS app behind
HTTPS on `arcmate.samirgroup.net`, enforcing an **IP allowlist** and writing a
full **audit trail** into the SG_nOC database (`phonebook2`). Microsoft SSO is
designed-in but deferred — the current build runs in IP-ACL-only mode.

The legacy app is never modified. See [DEPLOY.md](DEPLOY.md) for the runbook.

## How it fits together

- **This service** (`gateway/`) — terminates nothing itself; nginx on the NOC VM
  does TLS and proxies to `127.0.0.1:8443`. The gateway derives the real client
  IP, checks it against the allowlist, proxies to the legacy app, and audits.
- **SG_nOC (Laravel)** owns the schema and the admin UI: Admin → **Access
  Gateway** edits the upstream App URL + ACL toggle, manages the allowlist
  (branch WAN IPs auto-synced + manual CIDRs), and shows the audit log. The
  `agw:sync-allowlist` command + 5-minute scheduler keep branch IPs current.
- **Shared tables**: `agw_allowlist`, `agw_audit`, `agw_ip_history`, plus
  `settings.agw_backend_url` / `settings.agw_enforce_ip_acl`. The gateway polls
  these, so config changes apply live with no restart.

## Request flow

```
derive real client IP → IP ACL → (SSO: inert) → reverse-proxy → audit(one row)
fail closed: any error evaluating access → deny + audit
```

## Layout

```
gateway/
  config.py     process settings (env/.env)
  db.py         async MySQL (reads allowlist+settings, writes audit)
  acl.py        CIDR allowlist + trusted client-IP derivation
  audit.py      non-blocking, batched audit writer
  identity.py   oauth2-proxy header parsing (used once SSO is enabled)
  proxy.py      httpx streaming reverse proxy, header hygiene, Location rewrite
  main.py       FastAPI app, middleware order, background refresh loop
deploy/         systemd unit + nginx vhost
tests/          unit (acl, proxy) + end-to-end (mocked upstream, no DB)
```

## Local dev / tests

```sh
python -m venv .venv && .venv/bin/pip install -r requirements-dev.txt
.venv/bin/pytest -q
```

## Config

Copy `.env.example` → `.env`. The dynamic values (App URL, IP-ACL on/off) are
managed from the NOC admin page and override the `.env` fallbacks at runtime.
