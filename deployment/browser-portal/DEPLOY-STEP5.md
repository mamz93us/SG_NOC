# Step 5 deploy checklist — Laravel Browser Portal module

Run these once on the VPS after pulling the new code.

## 1. Install the sudoers file

```bash
cd ~/phonebook2
sudo cp deployment/browser-portal/sudoers.d/browser-portal /etc/sudoers.d/browser-portal
sudo chmod 0440 /etc/sudoers.d/browser-portal
sudo visudo -c
```

`visudo -c` must print `parsed OK`. This is what lets `www-data` (the Nginx /
PHP-FPM user) run `sudo docker ...` and `sudo nginx -s reload` without a
password.

## 2. Create the Nginx sites-dynamic directory (if not already there)

```bash
sudo mkdir -p /etc/nginx/sites-dynamic
sudo chown www-data:www-data /etc/nginx/sites-dynamic
sudo chmod 0775 /etc/nginx/sites-dynamic
```

The existing SG_NOC vhost must already contain
`include /etc/nginx/sites-dynamic/*.conf;` inside the HTTPS server block
(this was done in Step 4).

## 3. Add env vars

Append to `.env` on the VPS:

```ini
# ─── Browser Portal ─────────────────────────────────────────────────
# VPS public IP — used for NEKO_WEBRTC_NAT1TO1 (WebRTC relay target).
BROWSER_PORTAL_VPS_IP=20.82.165.228

# Neko admin password (fixed across sessions — admin role inside the
# Neko multiuser provider). Per-session user passwords are generated.
BROWSER_PORTAL_NEKO_ADMIN_PASSWORD=CHANGE_ME_STRONG_ADMIN_PASSWORD

# Idle cutoff: containers with last_active_at older than this are
# stopped by the scheduled CleanupIdleSessionsJob. Volume is preserved.
BROWSER_PORTAL_IDLE_MINUTES=240
```

Then:

```bash
php artisan config:clear
```

## 4. Run the migrations

```bash
php artisan migrate --force
```

This creates `browser_sessions` and seeds the two permissions
(`view-browser-portal`, `manage-browser-portal`) onto the `super_admin`
and `admin` roles. `viewer` gets neither by default.

## 5. Verify the scheduler picked up the cleanup job

```bash
php artisan schedule:list | grep browser
```

You should see `cleanup-browser-sessions` running every 5 minutes.

## 6. Smoke test from the browser

1. Log into SG_NOC as an `admin` or `super_admin` user.
2. Sidebar → Tasks/Network → **Remote Browser (VPN)**.
3. Click **Launch browser**. The DB row goes `starting` → `running`, a
   Neko container appears in `docker ps`, and a file appears in
   `/etc/nginx/sites-dynamic/{id}.conf`.
4. Click **Open browser** — iframe loads `/s/{id}/` and you see Chromium.
5. Inside Chromium, visit an internal company URL → works; visit
   `https://1.1.1.1` → blocked (kill-switch).
6. Click **Stop**. DB row → `stopped`, container gone, snippet gone,
   volume `neko-user-{uid}` preserved.
7. Launch again — previous cookies/bookmarks are still there.

## 7. Admin view

As a `super_admin`, hit **Admin view** on the portal page
(or navigate to `/admin/browser-portal/admin`). You should see the
current session with live CPU/Mem (cached 10 s). Force-stop works.

## 8. Known limits

- **10 concurrent sessions max** (UDP pool 52000-52100, 10 ports per
  session). To raise: widen the UFW allow-list and bump
  `PORTS_PER_SESSION` / `MAX_CONCURRENT` in `SessionManager`.
- **Idle cutoff = 4h** by default. Tab-visible heartbeat keeps the
  session alive; a closed tab → container stops at the next 5-min tick
  after the cutoff.
- **Azure hairpin NAT**: the VPS itself cannot reach its own public IP.
  Test from a client on a different network, not from the VPS shell.
