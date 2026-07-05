"""Static process configuration for the NOC-AGW gateway.

Design goal: **no gateway-specific .env**. Everything operational — the upstream
App URL and the IP-ACL on/off toggle — lives in the SG_nOC `settings` table and
is edited from the NOC "Access Gateway" page (polled at runtime, see main.py).

The only thing the gateway can't get from the DB is the DB connection itself, so
those credentials are read from the **existing Laravel `.env`**
(`/home/azureuser/phonebook2/.env` by default, override with `LARAVEL_ENV`).
Non-DB knobs below have sensible defaults and rarely need touching; any of them
can still be overridden by a process environment variable if ever needed.
"""

from __future__ import annotations

import os
from pathlib import Path

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

# Default location of the SG_nOC Laravel app whose .env holds the DB creds.
DEFAULT_LARAVEL_ENV = "/home/azureuser/phonebook2/.env"

# Laravel .env key → our Settings field.
_LARAVEL_DB_MAP = {
    "DB_HOST": "db_host",
    "DB_PORT": "db_port",
    "DB_DATABASE": "db_name",
    "DB_USERNAME": "db_user",
    "DB_PASSWORD": "db_password",
}


def _split(value: str | list[str]) -> list[str]:
    if isinstance(value, list):
        return value
    return [item.strip() for item in value.split(",") if item.strip()]


def parse_env_file(path: str | os.PathLike) -> dict[str, str]:
    """Minimal .env parser: KEY=VALUE lines, ignoring comments/blanks and
    stripping surrounding single/double quotes (Laravel-style)."""
    data: dict[str, str] = {}
    p = Path(path)
    if not p.exists():
        return data
    for raw in p.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        key = key.strip()
        val = val.strip()
        if len(val) >= 2 and val[0] == val[-1] and val[0] in "\"'":
            val = val[1:-1]
        data[key] = val
    return data


class Settings(BaseSettings):
    model_config = SettingsConfigDict(extra="ignore")

    # ── Listener ────────────────────────────────────────────────────────
    listen_host: str = "127.0.0.1"
    listen_port: int = 8443

    # ── Upstream (fallback only — the DB value from Settings wins) ───────
    backend_url: str = "http://127.0.0.1:8891"

    # ── Enforcement (fallbacks — the DB toggle wins for the IP ACL) ──────
    enforce_ip_acl: bool = True
    enforce_sso: bool = False  # oauth2-proxy is deferred; kept for later

    # ── Client-IP trust ─────────────────────────────────────────────────
    trusted_ip_header: str = "X-Forwarded-For"
    trusted_proxies: list[str] = ["127.0.0.1", "::1"]

    # ── SSO (unused while enforce_sso is false) ──────────────────────────
    allowed_entra_groups: list[str] = []

    # ── Public identity (for Location rewrites) ──────────────────────────
    public_host: str = "arcmate.samirgroup.net"

    # ── Database (sourced from the Laravel .env; see load_settings) ──────
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
    """Build Settings, filling DB credentials from the Laravel .env."""
    laravel_env = os.environ.get("LARAVEL_ENV", DEFAULT_LARAVEL_ENV)
    raw = parse_env_file(laravel_env)

    db_kwargs: dict[str, str] = {}
    for laravel_key, field in _LARAVEL_DB_MAP.items():
        value = raw.get(laravel_key)
        if value:  # non-empty only; otherwise keep the field default
            db_kwargs[field] = value

    return Settings(**db_kwargs)
