# Split-brain DNS escape hatch

Lets the NOC resolve `sgprd.samirgroup.com` and `sgapps-test.samirgroup.com` —
the ticketing API hosts behind **Create Ticket** and **My Tickets** — without
changing how anything else on the box resolves.

## The problem

`/etc/systemd/resolved.conf.d/sg-dns.conf` used to send **every** query to the
internal AD DNS:

```ini
[Resolve]
DNS=172.16.8.4 172.16.8.5
Domains=~.
```

Those servers are authoritative for `samirgroup.com` and hold only the internal
records. `sgprd` and `sgapps-test` exist solely in the **public** zone (they sit
behind Oracle Cloud WAF), so the AD servers answer NXDOMAIN and the name never
reaches a public resolver:

```
$ curl https://sgprd.samirgroup.com/
curl: (6) Could not resolve host: sgprd.samirgroup.com
$ dig +short sgprd.samirgroup.com @1.1.1.1
147.154.8.35                                    # ...resolves fine
```

Classic split-brain DNS: the internal zone shadows the public one for the whole
domain.

## The fix

**dnsmasq** on `127.0.0.1`, forwarding per domain — the two public hosts to a
public resolver, everything else to the AD servers. systemd-resolved keeps its
usual job and simply points upstream at dnsmasq, so `/etc/resolv.conf` stays the
systemd stub symlink and nothing fights over the file across reboots.

```
getaddrinfo → resolved (127.0.0.53) → dnsmasq (127.0.0.1) → ┬ 1.1.1.1   sgprd, sgapps-test
                                                            └ 172.16.8.4/.5  everything else
```

```sh
sudo apt-get install -y dnsmasq
sudo cp sg-split-dns.conf /etc/dnsmasq.d/
sudo cp sg-dns.conf       /etc/systemd/resolved.conf.d/
sudo systemctl enable --now dnsmasq
# Prove dnsmasq answers BEFORE repointing resolved at it:
dig +short sgapps-test.samirgroup.com @127.0.0.1
dig +short samirgroup.com             @127.0.0.1
sudo systemctl restart systemd-resolved
```

Add a new public-zone host by adding a `server=/name/1.1.1.1` line to
`sg-split-dns.conf` and restarting dnsmasq. Never pin `/etc/hosts`: the WAF
addresses are anycast and rotate — observed changing between two calls minutes
apart.

## Why not a systemd-resolved routing domain

The obvious systemd-native answer is a dummy link carrying
`DNS=168.63.129.16` with `Domains=~sgprd.samirgroup.com …`, which is more
specific than the global `~.` and wins for those names. **It does not work, and
it fails intermittently, which is worse than failing outright.**

A dummy interface needs an address before resolved will hang a DNS scope on it
(without one: `Current Scopes: none`, silently). But resolved then binds those
queries to that link, so they leave sourced from the dummy's address — and the
reply, addressed back to it, is routed to a **dummy** device, which discards
everything:

```
$ dig +short sgapps-test.samirgroup.com @168.63.129.16              # fine
130.61.27.114
$ dig +short -b 192.0.2.1 sgapps-test.samirgroup.com @168.63.129.16 # black hole
;; communications error to 168.63.129.16#53: timed out
```

Queries then succeed only when resolved happens not to bind, and each failure
makes it downgrade its feature level — `UDP+EDNS0` → `UDP` → `TCP`, roughly ten
seconds per step — so anything asking during that window stalls for 10s. It
looked like it worked; it produced `cURL error 28: Resolving timed out after
10001 milliseconds` a few times an hour. Picking a different upstream does not
help: the broken half is the reply path.

## Verify

```sh
systemctl is-active dnsmasq
resolvectl status | head -6                # Current DNS Server: 127.0.0.1
getent ahostsv4 sgapps-test.samirgroup.com # public address
getent ahostsv4 samirgroup.com             # still 172.16.8.4/.5
journalctl -u systemd-resolved -n 50 | grep 'degraded feature set'   # expect none
```

`AAAA` lookups for both hosts return "does not have any RR of the requested
type" — neither publishes IPv6. That is normal.

## This is a workaround

The real fix is a record for each host in the internal `samirgroup.com` zone, at
which point this directory can be deleted. Until then, note it only solves
*resolution* — `sgprd` separately returns `403 Access Rules-403` from the Oracle
WAF because the NOC's public IP (`20.13.145.161`) is not allow-listed. See
[CREATE_TICKET_SETUP.md](../../CREATE_TICKET_SETUP.md).
