"""Static process configuration for the NOC-AGW gateway.

Everything here comes from the environment / .env and is fixed for the life of
the process. The *dynamic* bits that NOC staff edit at runtime — the upstream
app URL and the IP-ACL on/off toggle — are NOT here; they live in the SG_nOC
`settings` table and are polled by db.fetch_runtime_config() (see main.py).
"""

from __future__ import annotations

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


def _split(value: str | list[str]) -> list[str]:
    if isinstance(value, list):
        return value
    return [item.strip() for item in value.split(",") if item.strip()]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # ── Listener ────────────────────────────────────────────────────────
    listen_host: str = "127.0.0.1"
    listen_port: int = 8443

    # ── Upstream (fallback only — the DB value from Settings wins) ───────
    backend_url: str = "http://127.0.0.1:8891"

    # ── Enforcement (fallbacks — the DB toggle wins for the IP ACL) ──────
    enforce_ip_acl: bool = True
    enforce_sso: bool = False  # oauth2-proxy is deferred; kept for later

    # ── Client-IP trust ─────────────────────────────────────────────────
    # Only headers arriving from a trusted immediate peer are believed.
    trusted_ip_header: str = "X-Forwarded-For"
    trusted_proxies: list[str] = ["127.0.0.1", "::1"]

    # ── SSO (unused while enforce_sso is false) ──────────────────────────
    allowed_entra_groups: list[str] = []

    # ── Public identity (for Location rewrites) ──────────────────────────
    public_host: str = "arcmate.samirgroup.net"

    # ── Database (SG_nOC MySQL — phonebook2) ─────────────────────────────
    db_host: str = "127.0.0.1"
    db_port: int = 3306
    db_name: str = "phonebook2"
    db_user: str = "phonebook2"
    db_password: str = ""

    # ── Refresh / flush cadence ──────────────────────────────────────────
    allowlist_refresh_sec: int = 60
    audit_flush_sec: int = 2

    @field_validator("trusted_proxies", "allowed_entra_groups", mode="before")
    @classmethod
    def _coerce_list(cls, value):
        return _split(value) if isinstance(value, str) else value


def load_settings() -> Settings:
    return Settings()
