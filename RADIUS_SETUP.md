# RADIUS / MAC Authentication (MAB) — Setup Runbook

This guide walks through installing FreeRADIUS on the SG-NOC VPS and wiring
it up to the existing `device_macs` registry. After completion, switches and
APs configured for MAC Authentication Bypass will hit the VPS, and allowed
MACs will be granted the correct VLAN.

Mirrors the pattern of `SYSLOG_SETUP.md`: package install + DB user + config
symlinks + systemd enable + firewall.

> **Prerequisites:** the Laravel app is already deployed on the VPS at
> `/home/azureuser/phonebook2`, with MySQL database `phonebook2`. SSH access
> to the VPS as `azureuser`.

---

## 1. Run the Laravel migrations

On the VPS:

```bash
cd /home/azureuser/phonebook2
php artisan migrate
```

This creates: `radius_nas_clients`, `radius_branch_vlan_policy`,
`radius_mac_overrides`, plus the `manage-radius` permission.

---

## 2. Create the FreeRADIUS MySQL user

```bash
# Edit the password before running (see deployment/freeradius/setup.sql).
sudo mysql phonebook2 < deployment/freeradius/setup.sql
```

Verify:

```bash
mysql -u freeradius -p -h 127.0.0.1 phonebook2 -e "SELECT COUNT(*) FROM device_macs;"
```

---

## 3. Install FreeRADIUS

```bash
sudo apt-get update
sudo apt-get install -y freeradius freeradius-mysql freeradius-utils
sudo systemctl stop freeradius   # we'll start it after the configs are in place
```

Confirm version is 3.2.x:

```bash
freeradius -v
```

---

## 4. Drop in our config

```bash
cd /home/azureuser/phonebook2/deployment/freeradius

# 4a. SQL module + queries
sudo cp mods-available/sql /etc/freeradius/3.0/mods-available/sql

# 4b. MAB virtual server
sudo cp sites-available/sg-noc-mab /etc/freeradius/3.0/sites-available/sg-noc-mab

# 4c. MAC normalization policy
sudo cp policy.d/mac_normalize /etc/freeradius/3.0/policy.d/mac_normalize

# 4d. clients.conf — only localhost; real NAS list comes from SQL
sudo cp clients.conf /etc/freeradius/3.0/clients.conf

# 4e. DB password (edit first, must match step 2)
sudo cp sql-secret.conf.example /etc/freeradius/3.0/mods-config/sql/secret.conf
sudo chown root:freerad /etc/freeradius/3.0/mods-config/sql/secret.conf
sudo chmod 640 /etc/freeradius/3.0/mods-config/sql/secret.conf
sudo nano /etc/freeradius/3.0/mods-config/sql/secret.conf

# 4f. Enable our SQL module + virtual server, disable the bundled defaults
sudo ln -sf /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
sudo ln -sf /etc/freeradius/3.0/sites-available/sg-noc-mab /etc/freeradius/3.0/sites-enabled/sg-noc-mab
sudo rm -f /etc/freeradius/3.0/sites-enabled/default
sudo rm -f /etc/freeradius/3.0/sites-enabled/inner-tunnel

# 4g. Disable the EAP module — it's enabled by default but expects an
#     `Auth-Type EAP` section that lived in the `default` site we just
#     removed. MAB doesn't use EAP, so just unlink it.
sudo rm -f /etc/freeradius/3.0/mods-enabled/eap
```

---

## 5. Install the Laravel control wrapper

This script lets the Laravel admin UI trigger `radmin reload clients` after
NAS edits, without giving PHP shell access.

```bash
# Symlink the wrapper into PATH
sudo ln -sf /home/azureuser/phonebook2/deployment/freeradius/sg-radius-control.sh /usr/local/bin/sg-radius-control
sudo chmod +x /home/azureuser/phonebook2/deployment/freeradius/sg-radius-control.sh

# Sudoers — only two whitelisted subcommands
sudo cp /home/azureuser/phonebook2/deployment/freeradius/sudoers /etc/sudoers.d/sg-radius
sudo chmod 0440 /etc/sudoers.d/sg-radius
sudo visudo -c -f /etc/sudoers.d/sg-radius   # validates: should print "parsed OK"
```

Edit `/etc/sudoers.d/sg-radius` if your PHP-FPM user is not `azureuser` or
`www-data`.

---

## 6. Validate the FreeRADIUS config

Run a verbose dry-check **before** enabling the service:

```bash
sudo freeradius -CX
```

It should end with `Configuration appears to be OK.` Any error here means
fix the config first — don't start the daemon.

Common errors:
- "Unknown query expansion" → the password file is missing or wrong permissions.
- "Connection refused" → DB user can't reach 127.0.0.1; check `setup.sql` ran cleanly.
- "Invalid MAC" → harmless if it appears during `client_query` parsing of empty rows.

---

## 7. Start the daemon

```bash
sudo systemctl enable --now freeradius
sudo systemctl status freeradius
```

Live debug (recommended on first run — Ctrl-C to stop):

```bash
sudo systemctl stop freeradius
sudo freeradius -X | tee /tmp/radius-debug.log
```

In a second terminal, run a smoke test (next section). When happy:

```bash
# Ctrl-C the foreground daemon, then:
sudo systemctl start freeradius
```

---

## 8. Smoke tests (localhost)

```bash
# Replace AA:BB:CC:DD:EE:FF with a MAC you know is in device_macs and active.
echo 'User-Name = "AA:BB:CC:DD:EE:FF"
Calling-Station-Id = "aa-bb-cc-dd-ee-ff"
Service-Type = Call-Check
Message-Authenticator = 0x00' | radclient -x 127.0.0.1:1812 auth testing123
```

Expected output:

```
Received Access-Accept Id ...
        Tunnel-Type:0 = VLAN
        Tunnel-Medium-Type:0 = IEEE-802
        Tunnel-Private-Group-Id:0 = "<vlan>"
```

(`<vlan>` only appears when a `radius_branch_vlan_policy` row matches; otherwise
the switch will use its own configured default.)

Negative tests:

```bash
# Unknown MAC → reject + Reply-Message: mac-not-registered
echo 'User-Name = "DE:AD:BE:EF:00:01"
Calling-Station-Id = "deadbeef0001"
Service-Type = Call-Check
Message-Authenticator = 0x00' | radclient -x 127.0.0.1:1812 auth testing123

# Format variants — all four must produce identical accepts:
for fmt in "AA:BB:CC:DD:EE:FF" "aa-bb-cc-dd-ee-ff" "AABBCCDDEEFF" "aabb.ccdd.eeff"; do
    echo "User-Name = \"$fmt\"
Calling-Station-Id = \"$fmt\"
Service-Type = Call-Check
Message-Authenticator = 0x00" | radclient 127.0.0.1:1812 auth testing123
done
```

Tail the FreeRADIUS log to follow live:

```bash
sudo tail -f /var/log/freeradius/radius.log
```

---

## 9. Open the firewall

Restrict 1812/1813 to your office subnets / IPsec child networks. Adjust the
CIDR to match your environment.

```bash
sudo ufw allow from 10.0.0.0/8 to any port 1812 proto udp comment 'RADIUS auth from offices'
sudo ufw allow from 10.0.0.0/8 to any port 1813 proto udp comment 'RADIUS acct from offices'
sudo ufw status
```

**Never expose 1812/1813 to the public internet.** All your switches reach
the VPS over the existing IPsec tunnels (172.22.0.0/24 is the VPS-side
subnet); RADIUS lives behind that boundary.

---

## 10. Add NAS clients and VLAN policy via the Laravel UI

Navigate to:

- `/admin/radius/nas` — add each switch / AP that will query us. `nasname`
  is the IP or CIDR; `secret` is the shared secret you'll paste into the
  switch config. After saving, the controller calls
  `sg-radius-control reload-clients` and FreeRADIUS picks up the new client
  immediately.
- `/admin/radius/vlan-policy` — add at least a per-branch catch-all row
  (`adapter_type = any`, `device_type = (blank)`, `vlan_id = <your default>`).

The "Preview" panel on the VLAN policy page lets you enter a MAC and see
exactly which row would match — useful when diagnosing why a phone landed
in the wrong VLAN.

Per-MAC overrides (deny a MAC, or pin it to a specific VLAN) are set from
the **RADIUS** column on `/admin/itam/mac-address`.

---

## 11. Switch-side configuration (Cisco example)

```
aaa new-model
aaa authentication dot1x default group radius
aaa authorization network default group radius
aaa accounting dot1x default start-stop group radius

radius server SG_NOC
 address ipv4 <vps-ip-on-tunnel> auth-port 1812 acct-port 1813
 key <same-shared-secret-as-radius_nas_clients.secret>

interface GigabitEthernet1/0/24
 description test MAB port
 switchport mode access
 authentication port-control auto
 mab
 dot1x pae authenticator
 spanning-tree portfast
```

Verify on the switch:

```
show authentication sessions interface gi1/0/24
show running-config | include radius
```

---

## 12. What's deferred to v2

This MVP intentionally does **not** include:

- In-app audit log (`radius_auth_logs` table + viewer in Laravel). Use
  `/var/log/freeradius/radius.log` on the VPS until v2 ships.
- Accounting (`radius_accounting`, UDP 1813 SQL writes).
- 802.1X EAP for user-level auth.
- Change-of-Authorization (CoA / RFC 5176).
- RADIUS-over-TLS (RadSec).
- HA / clustering across multiple VPS hosts.

See `C:/Users/MohamedZahran/.claude/plans/i-have-a-mac-sprightly-sphinx.md`
for the full deferred list.
