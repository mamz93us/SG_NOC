# Step 1 — Standalone Chromium smoke test

Goal: prove that a Neko Chromium container can stream to your browser from this
VPS, before we layer in the kill-switch, path routing, and Laravel portal.

## Run these commands on the VPS (SSH in first)

```bash
# 1. Sync latest code from your dev box (run from your dev box):
#    rsync -avz ./deployment/browser-portal/ user@VPS:~/sg_noc/deployment/browser-portal/

# 2. On the VPS, go to the test dir (path assumes the SG_NOC repo lives at ~/sg_noc):
cd ~/sg_noc/deployment/browser-portal/test

# 3. Verify Docker is installed and you can run it:
docker --version
docker compose version

# 4. Get the VPS public IP — you'll need it in a moment:
curl -s ifconfig.me; echo

# 5. Create the .env (DO NOT commit this file):
cp env.sample .env
nano .env
#   - VPS_PUBLIC_IP      = paste the IP from step 4
#   - NEKO_USER_PASSWORD = pick a strong password (shared with test users)
#   - NEKO_ADMIN_PASSWORD= pick a strong password (admin inside Neko)

# 6. Open the firewall for the test (we use port 18080 because 8080 is already
#    taken by the existing SG_NOC app; this is a throwaway test port):
sudo ufw allow 18080/tcp
sudo ufw allow 52000:52100/udp
sudo ufw status verbose

# 7. Start the container (use sudo — your user isn't in the docker group yet;
#    we'll set up sudoers for the Laravel user properly at Step 4):
sudo docker compose -f test-chromium.yml up -d

# 8. Watch the logs for ~15s until you see "neko/session" and "webrtc" lines:
sudo docker compose -f test-chromium.yml logs -f
# Ctrl-C to stop following.
```

## How to test

From your laptop's browser (**not** the VPS), open:

```
http://<VPS_PUBLIC_IP>:18080
```

- Log in as **user** — username `user`, password = `NEKO_USER_PASSWORD` from .env.
- You should see a streamed Chromium. Click around, type in the address bar, open google.com.
- Log out, log in as **admin** — username `admin`, password = `NEKO_ADMIN_PASSWORD`.
- In the Neko UI side panel you should see admin controls (kick, lock, etc.).

## What to report back before we continue

Tell me:
- [ ] Did the stream work? (yes/no)
- [ ] Could you type into the Chromium address bar and load pages?
- [ ] Any errors in `docker compose -f test-chromium.yml logs`? Paste the last ~30 lines if so.

If it didn't work, the usual culprits are:
1. UFW blocking UDP 52000-52100 — check `sudo ufw status verbose`.
2. `VPS_PUBLIC_IP` is wrong in `.env` (e.g. set to the internal IP).
3. Cloud provider blocks UDP at the security-group level (check your hosting panel).
4. Port 18080 collides with something else — check `sudo ss -ltnp | grep 18080`; pick another unused port and update both `test-chromium.yml` and the UFW rule.

## Teardown (after this step is confirmed)

```bash
cd ~/phonebook2/deployment/browser-portal/test
sudo docker compose -f test-chromium.yml down
# Leave the UDP range open, we'll reuse it in Step 3.
sudo ufw delete allow 18080/tcp
```

Then we proceed to Step 2 (Docker network + kill-switch).
