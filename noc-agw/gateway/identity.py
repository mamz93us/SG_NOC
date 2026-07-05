"""Identity extraction from oauth2-proxy headers.

Inert while SSO is disabled (IP-ACL-only mode): returns an anonymous identity
and never denies. When ENFORCE_SSO is turned on later, oauth2-proxy sits in
front of the gateway and sets the X-Forwarded-* / X-Auth-Request-* headers this
reads, and the group gate below is applied.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass
class Identity:
    email: str | None = None
    name: str | None = None
    groups: tuple[str, ...] = ()
    authenticated: bool = False


def extract_identity(headers) -> Identity:
    """Parse the identity oauth2-proxy forwards. Empty when absent."""
    def _get(*names: str) -> str | None:
        for n in names:
            v = headers.get(n)
            if v:
                return v
        return None

    email = _get("X-Forwarded-Email", "X-Auth-Request-Email")
    name = _get("X-Forwarded-User", "X-Auth-Request-User", "X-Forwarded-Preferred-Username")
    raw_groups = _get("X-Forwarded-Groups", "X-Auth-Request-Groups") or ""
    groups = tuple(g.strip() for g in raw_groups.split(",") if g.strip())

    return Identity(email=email, name=name, groups=groups, authenticated=bool(email))


def check_sso(identity: Identity, enforce_sso: bool, allowed_groups) -> tuple[bool, str | None]:
    """Return (ok, reason). In IP-only mode (enforce_sso=False) always ok."""
    if not enforce_sso:
        return True, None
    if not identity.authenticated:
        return False, "no authenticated identity"
    if allowed_groups:
        allowed = set(allowed_groups)
        if not allowed.intersection(identity.groups):
            return False, "user not in an allowed group"
    return True, None
