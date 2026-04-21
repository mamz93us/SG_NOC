# Browser-portal infra (VPS-side)

Infrastructure for the SG_NOC browser-portal module. Runs on the Ubuntu 24.04
VPS that already hosts the SG_NOC Laravel app and already has a corporate
IPsec tunnel up via strongSwan.

## Layout

```
deployment/browser-portal/
├── bootstrap-vps.sh                    # idempotent installer (run as root)
├── kill-switch.sh                      # iptables rules for browser-net
├── browser-portal-killswitch.service   # systemd unit wrapping the above
├── allowed-subnets.conf.sample         # seeded to /etc/browser-portal/allowed-subnets.conf
└── test/                               # Step-1 standalone smoke test (Neko Chromium)
```

## Step 2 — install the kill-switch

On the VPS:

```bash
cd ~/phonebook2/deployment/browser-portal
sudo bash bootstrap-vps.sh
```

That creates the Docker network `browser-net` (172.30.0.0/16), installs the
kill-switch script to `/usr/local/sbin/`, seeds
`/etc/browser-portal/allowed-subnets.conf` (**default is permissive — allows
everything**), and starts the systemd unit.

Inspect what's live:

```bash
sudo systemctl status browser-portal-killswitch
sudo iptables -S DOCKER-USER | grep browser-portal
sudo iptables -t nat -S POSTROUTING | grep browser-portal
docker network inspect browser-net
```

## Step 2 — verify the network

Spin a throwaway container on `browser-net` and prove it behaves:

```bash
# Should succeed — permissive default allows anything:
sudo docker run --rm --network browser-net alpine sh -c \
    "apk add --quiet curl >/dev/null 2>&1; curl -sSf -m 5 https://1.1.1.1 >/dev/null && echo 'public-internet-reachable' || echo 'public-blocked'"

# Then try an internal company URL (pick one you know is behind the tunnel):
sudo docker run --rm --network browser-net alpine sh -c \
    "apk add --quiet curl >/dev/null 2>&1; curl -sSv -m 5 http://<INTERNAL_HOST>/ 2>&1 | head -5"
```

## Tightening the kill-switch later

The default `/etc/browser-portal/allowed-subnets.conf` contains `0.0.0.0/0`,
which means "allow everything" — the kill-switch is effectively disabled.
When you're ready to lock it down to just company subnets:

```bash
sudo nano /etc/browser-portal/allowed-subnets.conf
#   - comment out 0.0.0.0/0
#   - add one CIDR per line for each company subnet (e.g. the rightsubnet
#     values from JED.conf and RYD.conf)
sudo systemctl restart browser-portal-killswitch
```

Verify with the same throwaway container test above — public should now be
blocked, company subnets should succeed.

## Troubleshooting

- **`docker network create`** fails with subnet overlap → another Docker
  network uses 172.30.0.0/16. Pick a different subnet in `bootstrap-vps.sh`
  and in `kill-switch.sh` (keep them matched).
- **Rules look right but traffic to company subnets times out** → the host
  itself can't reach them either. Check `ip route` and `ip xfrm policy`;
  strongSwan may be down.
- **Kill-switch is "permissive" but internal is still blocked** → unlikely;
  more commonly host routing is the problem. `sudo tcpdump -i any -n host
  <INTERNAL_IP>` from the host while you `curl` from a container.
- **Rules vanish after `systemctl restart docker`** → Docker flushes its own
  chains on restart. The systemd unit needs to be restarted too:
  `sudo systemctl restart browser-portal-killswitch`. (The unit already
  declares `Requires=docker.service` so Docker reloads should trigger it;
  verify with `systemctl list-dependencies browser-portal-killswitch`.)
