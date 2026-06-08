# Branch Agent (`sg-branch-agent`)

A single Go binary that runs on each branch VM and does three jobs, all linked
to the central NOC (`noc.samirgroup.net`):

1. **Log collection** — receives device syslog (UDP+TCP `:514`), stores it
   locally in daily-rolling SQLite, and answers the NOC's on-demand log search.
2. **Device monitoring** — polls SNMP devices the NOC assigns to the branch,
   pushes metrics to VictoriaMetrics, and reports newly discovered devices.
3. **DDNS** — detects the branch's public WAN IP and reports it so the NOC can
   update DNS (GoDaddy) and the IPsec tunnel.

It replaces the old multi-service `deployment/branch-vm/` stack (rsyslog +
MariaDB + PHP-FPM + nginx + Telegraf) with **one binary + one systemd unit**.
Everything else is configured in the agent's **web UI** — no files to hand-edit.

---

## 1. Register the branch in the NOC

In the NOC, go to **Branch Agents → Add branch agent**:

- Set the **branch code** (e.g. `jed`) and display name.
- (Optional, for DDNS) pick the **GoDaddy account**, a **DNS subdomain**
  (e.g. `jed` → `jed.branch.samirgroup.net`), and the **VPN tunnel** whose
  remote endpoint should track this WAN IP.
- Save → the page shows a **one-time enrollment code** (valid for 60 min).

> Tip: for the safest DDNS, set the linked tunnel's *remote endpoint* to the
> FQDN once. The agent then only updates the GoDaddy A record and strongSwan
> re-resolves it — the child-SA selectors are never touched.

## 2. Install on the VM (one line)

On a fresh **Ubuntu 24.04 LTS** VM:

```sh
curl -fsSL https://noc.samirgroup.net/branch-agent/install.sh | sudo bash
```

The installer only prepares the system: creates the `sgagent` user + dirs,
downloads and checksum-verifies the binary, installs/enables the systemd unit,
opens the firewall (`514/udp`, `514/tcp`, `8080/tcp`), and prints the setup URL
and a **setup token**. Re-running the same line upgrades the binary.

## 3. Finish in the browser

Open `http://<branch-ip>:8080/setup` and complete the wizard:

1. **Secure** — paste the setup token (also in
   `journalctl -u sg-branch-agent | grep 'SETUP TOKEN'`) and set the local admin
   password.
2. **Link** — enter the NOC URL and the enrollment code from step 1.
3. **Monitoring** — SNMP community and subnets to scan.
4. Finish. The agent enrolls, starts heartbeating, and turns **green** on the
   NOC's Branch Agents page.

Point the branch's devices' syslog at the VM's IP on port **514**.

---

## How each piece works

| Concern | Where it lives | Notes |
|---|---|---|
| Local logs | `/var/lib/sg-branch-agent/logs/agent-YYYY-MM-DD.db` | Daily SQLite files; retention = drop whole old files (age + size caps); low-disk guard stops ingest below 1 GiB free. |
| NOC log search | `GET /api/logs/search\|aggregate` on the agent | Token-authed; drop-in for the NOC's existing `BranchLogClient`. **Logs are never uploaded** — the NOC queries on demand and gets only matching rows. |
| SNMP | NOC assigns devices (Branch Logs → SNMP Devices) | Agent polls, pushes to VictoriaMetrics (`/api/v1/write`), and reports discoveries. |
| DDNS | Agent → `POST /api/branch-agents/ddns` | NOC updates GoDaddy + tunnel, writes WAN-IP history, audits, and alerts on failure. |
| Config | `/etc/sg-branch-agent/config.yaml` (`0600`) | Wizard-managed; intervals/retention/metrics creds come from the NOC on each heartbeat. |

The NOC marks an agent **down** if its heartbeat goes quiet
(`branch-agents:check-stale`, every 5 min) and raises a NocEvent.

---

## Building / releasing the binary

The NOC serves the binary from `<NOC_URL>/branch-agent/sg-branch-agent`. To
(re)build it on the server after a `git pull`:

```sh
./deployment/branch-agent/build.sh            # version from git describe
# → storage/app/branch-agent/sg-branch-agent (+ .sha256)
```

`CGO_ENABLED=0` keeps it a static binary (pure-Go SQLite), so it runs on any
Ubuntu 24.04 box with no libraries. Agents pick up the new binary when the
operator re-runs the install one-liner.

## Troubleshooting

- **Setup token lost** — `journalctl -u sg-branch-agent | grep 'SETUP TOKEN'`,
  or restart the service to mint a new one (only matters before setup completes).
- **Not turning green in the NOC** — check the agent can reach the NOC over the
  tunnel: `journalctl -u sg-branch-agent | grep heartbeat`.
- **Re-link a re-provisioned VM** — in the NOC, **Regenerate enrollment code**
  (this also invalidates the old token), then re-run the wizard.
- **Revoke access** — **Revoke token** on the agent's NOC page; the agent loses
  NOC access and log search until it re-enrolls.
