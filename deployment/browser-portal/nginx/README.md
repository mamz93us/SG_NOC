# Step 4 — Path-based routing through the existing host Nginx

Goal: prove `http://<VPS_IP>/s/testid/` streams a Neko Chromium through the
existing SG_NOC Nginx vhost, with no extra host ports published. This is the
highest-risk integration step. Do it **once manually** before we let Laravel
automate it in Step 5.

## What you're changing

1. Add ONE include line inside the existing SG_NOC server block.
2. Drop a single `testid.conf` snippet.
3. Flip the Neko test container to path-prefix mode and stop publishing :18080.

After this step:
- `http://<VPS_IP>:18080` goes away (port no longer published).
- `http://<VPS_IP>/` still serves SG_NOC as before.
- `http://<VPS_IP>/s/testid/` streams the Neko test container.

## 1. Inspect the existing vhost (on the VPS)

```bash
sudo cat /etc/nginx/sites-enabled/phonebook2
```

Identify the `server { ... }` block that owns `noc.samirgroup.net` (or listens
on :80 as the default). You'll add the include **inside** that block, near the
top — **before** any `location /` block, so dynamic snippets match first.

## 2. Create the dynamic include directory

```bash
sudo mkdir -p /etc/nginx/sites-dynamic
sudo chown root:root /etc/nginx/sites-dynamic
sudo chmod 0755 /etc/nginx/sites-dynamic
# Laravel (running as www-data) writes snippets here later; it uses
# `sudo nginx -s reload` via sudoers, so it doesn't need write perms on
# other Nginx dirs.
sudo chown www-data:root /etc/nginx/sites-dynamic
sudo chmod 0775 /etc/nginx/sites-dynamic
```

## 3. Add the include to the existing SG_NOC vhost

Edit `/etc/nginx/sites-available/phonebook2` and add this line **inside** the
relevant `server { ... }` block, ideally right after the `server_name` line:

```nginx
    include /etc/nginx/sites-dynamic/*.conf;
```

Then:

```bash
sudo nginx -t
```

If `-t` fails, fix the edit and retry. Do NOT reload yet — the dir is empty so
reload would be a no-op, but we want to gate reload behind both the include
being present AND the testid snippet being in place.

## 4. Flip the Neko test container to path-prefix mode

On your dev box, the test compose has been updated to:
- Add `NEKO_SERVER_PROXY=true` and `NEKO_SERVER_PATH_PREFIX=/s/testid`.
- Drop the `18080:8080` host publish (Nginx proxies over browser-net now).

Rsync the `deployment/browser-portal/` tree to the VPS, then on the VPS:

```bash
cd ~/sg_noc/deployment/browser-portal/test
sudo docker compose -f test-chromium.yml down
sudo docker compose -f test-chromium.yml up -d
sudo docker compose -f test-chromium.yml logs --tail=30
```

UFW: you can now close 18080 since nothing publishes it.

```bash
sudo ufw delete allow 18080/tcp
```

## 5. Drop the hardcoded testid snippet

Get the Neko container's bridge IP:

```bash
NEKO_IP=$(sudo docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' neko-test)
echo "neko-test is at $NEKO_IP"
```

Copy the template and substitute:

```bash
sudo cp ~/sg_noc/deployment/browser-portal/nginx/testid.conf /etc/nginx/sites-dynamic/testid.conf
sudo sed -i "s|172.30.0.2|$NEKO_IP|" /etc/nginx/sites-dynamic/testid.conf
sudo nginx -t && sudo nginx -s reload
```

## 6. Test from your laptop

The SG_NOC vhost is HTTPS-only (Let's Encrypt, `listen 443 ssl http2;`), so
there is no port-80 entrypoint. Use the real hostname over HTTPS:

```
https://noc.samirgroup.net/s/testid/
```

Log in as `user` with `NEKO_USER_PASSWORD` from `.env`. You should see the
streamed Chromium exactly as in Step 3.

Also verify the existing SG_NOC still loads:

```
https://noc.samirgroup.net/
```

## 7. What to report back

- [ ] `https://noc.samirgroup.net/s/testid/` streams a working Chromium (control works, WS stays open).
- [ ] `https://noc.samirgroup.net/` still loads the existing SG_NOC Laravel app.
- [ ] `sudo ss -ltnp | grep 18080` returns nothing (port is gone).
- [ ] Any errors in `sudo docker logs neko-test` or `sudo tail -f /var/log/nginx/error.log`? Paste the relevant lines.

Common failure modes:
- `404` on `/s/testid/`: the include line isn't inside the correct server
  block, or a broader `location /` is catching first. Check `nginx -T | grep -A2 'sites-dynamic'`.
- Blank page / WS never upgrades: `proxy_http_version 1.1` missing, or
  `Upgrade`/`Connection` headers missing. Re-copy `testid.conf`.
- Neko shows but controls are read-only / stream never starts: Neko wasn't
  restarted with `NEKO_SERVER_PROXY=true` / `NEKO_SERVER_PATH_PREFIX`. Check
  `sudo docker exec neko-test env | grep NEKO_SERVER`.

Stop here. Once all four checkboxes above pass, we proceed to Step 5 (the
Laravel module).
