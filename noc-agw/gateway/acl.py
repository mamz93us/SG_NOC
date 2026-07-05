"""IP allowlist matching + trusted client-IP derivation.

Pure, dependency-free logic so it can be unit-tested without a DB or a running
server. The allowlist is loaded from `agw_allowlist` (active rows) and matched
CIDR-aware for both IPv4 and IPv6.
"""

from __future__ import annotations

import ipaddress
from collections.abc import Iterable


class Acl:
    """In-memory CIDR allowlist, refreshed periodically from the DB."""

    def __init__(self) -> None:
        self._networks: list[ipaddress._BaseNetwork] = []

    def load(self, cidrs: Iterable[str]) -> int:
        """Replace the allowlist. Silently skips malformed entries."""
        nets: list[ipaddress._BaseNetwork] = []
        for cidr in cidrs:
            try:
                nets.append(ipaddress.ip_network(cidr.strip(), strict=False))
            except (ValueError, AttributeError):
                continue
        self._networks = nets
        return len(nets)

    def is_allowed(self, ip: str | None) -> bool:
        if not ip:
            return False
        try:
            addr = ipaddress.ip_address(ip)
        except ValueError:
            return False
        for net in self._networks:
            if addr.version == net.version and addr in net:
                return True
        return False

    def __len__(self) -> int:
        return len(self._networks)


def real_client_ip(
    peer_ip: str | None,
    headers,
    trusted_proxies: Iterable[str],
    header_name: str = "X-Forwarded-For",
) -> str | None:
    """Derive the true client IP.

    A forwarded header is only believed when the immediate TCP peer is a trusted
    proxy (our local nginx). nginx appends the real remote address to the right
    of X-Forwarded-For, so we walk from the right and return the first address
    that is not itself a trusted proxy. X-Real-IP (single value) works too.
    """
    trusted = set(trusted_proxies)

    if peer_ip is None:
        return None
    if peer_ip not in trusted:
        # Direct connection (or an untrusted hop) — trust only the socket peer.
        return peer_ip

    raw = None
    if headers is not None:
        # Header lookups are case-insensitive on Starlette/httpx Headers, and
        # we fall back to .get for plain dicts in tests.
        try:
            raw = headers.get(header_name)
        except AttributeError:
            raw = None
    if not raw:
        return peer_ip

    parts = [p.strip() for p in raw.split(",") if p.strip()]
    if not parts:
        return peer_ip
    for candidate in reversed(parts):
        if candidate not in trusted:
            return candidate
    return parts[0]
