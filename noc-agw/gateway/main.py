"""NOC-AGW FastAPI gateway — IP-ACL mode.

Request flow (per CLAUDE.md): derive real client IP → IP ACL → (SSO, inert for
now) → reverse-proxy to the legacy app → write exactly one audit row with the
upstream status + latency. Fails closed: any error evaluating access denies and
audits.
"""

from __future__ import annotations

import asyncio
import contextlib
import logging
import time
from dataclasses import dataclass

import httpx
from fastapi import FastAPI, Request
from starlette.responses import PlainTextResponse, Response

from .acl import Acl, real_client_ip
from .audit import AuditWriter
from .config import Settings, load_settings
from .db import Database
from .identity import check_sso, extract_identity
from .proxy import proxy_request

log = logging.getLogger("noc-agw")

_PROXY_METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "HEAD"]


@dataclass
class Runtime:
    """The knobs NOC staff can change at runtime (polled from the DB)."""

    backend_url: str
    enforce_ip_acl: bool
    enforce_sso: bool


class Gateway:
    """Bundles the live state the request handler needs."""

    def __init__(
        self,
        settings: Settings,
        acl: Acl,
        audit: AuditWriter,
        http_client: httpx.AsyncClient,
        runtime: Runtime,
        db: Database | None = None,
    ) -> None:
        self.settings = settings
        self.acl = acl
        self.audit = audit
        self.http_client = http_client
        self.runtime = runtime
        self.db = db

    async def refresh(self) -> None:
        """Pull allowlist + runtime config from the DB, atomically swap in."""
        if self.db is None:
            return
        cidrs = await self.db.fetch_active_cidrs()
        backend_url, enforce_ip_acl = await self.db.fetch_runtime_config()
        self.acl.load(cidrs)
        if backend_url:
            self.runtime.backend_url = backend_url
        if enforce_ip_acl is not None:
            self.runtime.enforce_ip_acl = enforce_ip_acl


async def _handle(request: Request) -> Response:
    gw: Gateway = request.app.state.gateway
    started = time.monotonic()

    peer = request.client.host if request.client else None
    client_ip = real_client_ip(
        peer, request.headers, gw.settings.trusted_proxies, gw.settings.trusted_ip_header
    )
    method = request.method
    path = request.url.path
    user_agent = request.headers.get("user-agent")
    identity = extract_identity(request.headers)

    def elapsed_ms() -> int:
        return int((time.monotonic() - started) * 1000)

    def audit(decision: str, status: int | None, reason: str | None) -> None:
        gw.audit.record(
            client_ip=client_ip or (peer or "unknown"),
            decision=decision,
            method=method,
            path=path,
            status=status,
            reason=reason,
            user_email=identity.email,
            user_name=identity.name,
            user_agent=user_agent,
            latency_ms=elapsed_ms(),
        )

    try:
        # ── IP ACL ──────────────────────────────────────────────────────
        if gw.runtime.enforce_ip_acl and not gw.acl.is_allowed(client_ip):
            audit("deny_ip", 403, "source IP not in allowlist")
            return PlainTextResponse("Forbidden", status_code=403)

        # ── SSO (inert until enforce_sso is turned on) ──────────────────
        sso_ok, sso_reason = check_sso(
            identity, gw.runtime.enforce_sso, gw.settings.allowed_entra_groups
        )
        if not sso_ok:
            audit("deny_auth", 403, sso_reason)
            return PlainTextResponse("Forbidden", status_code=403)

        # ── Reverse proxy ───────────────────────────────────────────────
        try:
            response = await proxy_request(
                gw.http_client, gw.runtime.backend_url, request, client_ip, gw.settings.public_host
            )
        except httpx.HTTPError as exc:
            log.warning("upstream error for %s %s: %s", method, path, exc)
            audit("allow", 502, f"upstream error: {type(exc).__name__}")
            return PlainTextResponse("Bad Gateway", status_code=502)

        audit("allow", response.status_code, None)
        return response

    except Exception as exc:  # noqa: BLE001 — fail closed on anything unexpected
        log.exception("gateway error for %s %s", method, path)
        audit("deny_ip", 500, f"gateway error: {type(exc).__name__}")
        return PlainTextResponse("Internal Server Error", status_code=500)


def build_app(gateway: Gateway | None = None, lifespan=None) -> FastAPI:
    app = FastAPI(title="NOC-AGW", lifespan=lifespan)
    if gateway is not None:
        app.state.gateway = gateway

    @app.get("/_agw/health")
    async def health():  # noqa: ANN202
        return {"status": "ok"}

    app.add_api_route("/{full_path:path}", _handle, methods=_PROXY_METHODS)
    return app


def create_app() -> FastAPI:
    settings = load_settings()

    @contextlib.asynccontextmanager
    async def lifespan(app: FastAPI):
        db = Database(settings)
        await db.connect()

        acl = Acl()
        audit = AuditWriter(db, flush_sec=settings.audit_flush_sec)
        http_client = httpx.AsyncClient(
            timeout=httpx.Timeout(30.0, connect=10.0), follow_redirects=False
        )
        runtime = Runtime(
            backend_url=settings.backend_url,
            enforce_ip_acl=settings.enforce_ip_acl,
            enforce_sso=settings.enforce_sso,
        )
        gateway = Gateway(settings, acl, audit, http_client, runtime, db)

        # Initial load (fail-closed: on error the ACL stays empty → denies all).
        with contextlib.suppress(Exception):
            await gateway.refresh()

        await audit.start()
        refresher = asyncio.create_task(_refresh_loop(gateway, settings.allowlist_refresh_sec))
        app.state.gateway = gateway

        try:
            yield
        finally:
            refresher.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await refresher
            await audit.stop()
            await http_client.aclose()
            await db.close()

    return build_app(lifespan=lifespan)


async def _refresh_loop(gateway: Gateway, interval: int) -> None:
    while True:
        await asyncio.sleep(interval)
        try:
            await gateway.refresh()
        except Exception:  # noqa: BLE001 — keep last-known-good on transient DB errors
            log.warning("allowlist refresh failed; keeping previous state")


app = None  # populated by uvicorn factory (see systemd unit: --factory)
