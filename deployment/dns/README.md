# Split-brain DNS escape hatch

Lets the NOC resolve `sgprd.samirgroup.com` and `sgapps-test.samirgroup.com` —
the ticketing API hosts behind the **Create Ticket** page — without changing how
anything else on the box resolves.

## The problem

`/etc/systemd/resolved.conf.d/sg-dns.conf` sends **every** query to the internal
AD DNS:

```ini
[Resolve]
DNS=172.16.8.4 172.16.8.5
Domains=~.
```

Those servers are authoritative for `samirgroup.com` and hold only the internal
records. `sgprd` and `sgapps-test` exist solely in the **public** zone (they sit
behind Oracle Cloud WAF), so the AD servers answer NXDOMAIN and the name never
reaches the public resolver:

```
$ curl https://sgprd.samirgroup.com/
curl: (6) Could not resolve host: sgprd.samirgroup.com
$ dig +short sgprd.samirgroup.com @168.63.129.16     # Azure's resolver
147.154.228.73                                        # ...resolves fine
```

Classic split-brain DNS: the internal zone shadows the public one for the whole
domain.

## The fix

A dummy link whose only job is to carry a DNS scope for those two names.
systemd-resolved picks the **most specific** routing domain, and
`~sgprd.samirgroup.com` beats the global `~.`, so exactly two names go to Azure's
resolver (`168.63.129.16`) and everything else stays on the AD servers.

```sh
sudo cp 10-dnsalt.netdev 10-dnsalt.network /etc/systemd/network/
sudo networkctl reload
sudo resolvectl flush-caches
```

Queries leave over `eth0` on the normal routing table — the dummy interface
carries the *configuration*, not the traffic.

## Two traps

- **`Address=` is mandatory.** Without an address, resolved decides the link is
  not routable and silently ignores its `DNS=`: `resolvectl status dnsalt` shows
  `Current Scopes: none` and nothing resolves. It also must be a *routable*
  address — a `169.254.x` link-local leaves the link `degraded` and the scope is
  dropped just the same. `192.0.2.1/32` (TEST-NET-1) is reserved and never
  routed, so it cannot collide with anything.
- **`RequiredForOnline=no`.** A carrier-less dummy that counts toward
  `systemd-networkd-wait-online` stalls every boot.

## Verify

```sh
resolvectl status dnsalt          # expect: Current Scopes: DNS
resolvectl query sgprd.samirgroup.com   # expect: an answer "-- link: dnsalt"
getent ahostsv4 samirgroup.com    # expect: still 172.16.8.4/.5, unchanged
```

`AAAA` lookups for both names fail with *"does not have any RR of the requested
type"* — neither host publishes IPv6. That is normal, not a misconfiguration.

## Remove

```sh
sudo rm /etc/systemd/network/10-dnsalt.netdev /etc/systemd/network/10-dnsalt.network
sudo networkctl reload && sudo ip link del dnsalt && sudo resolvectl flush-caches
```

## This is a workaround

The real fix is a record for each host in the internal `samirgroup.com` zone, at
which point this whole directory can be deleted. Until then, note it only solves
*resolution* — `sgprd` separately returns `403 Access Rules-403` from the Oracle
WAF because the NOC's public IP (`20.13.145.161`) is not allow-listed. See
[CREATE_TICKET_SETUP.md](../../CREATE_TICKET_SETUP.md).
