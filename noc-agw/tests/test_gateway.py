"""End-to-end request handling through the real FastAPI app, with a real
in-process ASGI upstream (httpx.ASGITransport streams properly) and an
in-memory audit sink. No DB required."""

import httpx
import pytest
from fastapi.testclient import TestClient

from gateway.acl import Acl
from gateway.config import Settings
from gateway.main import Gateway, Runtime, build_app


class FakeAudit:
    def __init__(self):
        self.rows = []

    def record(self, **kwargs):
        self.rows.append(kwargs)


async def _drain_body(receive):
    while True:
        msg = await receive()
        if msg["type"] == "http.request" and not msg.get("more_body", False):
            return


class HelloUpstream:
    """ASGI app that replies 200 'hello' and counts invocations."""

    def __init__(self):
        self.count = 0

    async def __call__(self, scope, receive, send):
        assert scope["type"] == "http"
        self.count += 1
        await _drain_body(receive)
        await send(
            {
                "type": "http.response.start",
                "status": 200,
                "headers": [(b"content-type", b"text/plain")],
            }
        )
        await send({"type": "http.response.body", "body": b"hello"})


async def redirect_upstream(scope, receive, send):
    await _drain_body(receive)
    await send(
        {
            "type": "http.response.start",
            "status": 302,
            "headers": [(b"location", b"http://upstream.local:8891/next")],
        }
    )
    await send({"type": "http.response.body", "body": b""})


def make_gateway(*, enforce_ip_acl=True, allow=("203.0.113.5/32",), upstream=None):
    upstream = upstream or HelloUpstream()
    settings = Settings(_env_file=None)  # defaults only; ignore any local .env
    acl = Acl()
    acl.load(list(allow))
    audit = FakeAudit()
    client = httpx.AsyncClient(transport=httpx.ASGITransport(app=upstream))
    runtime = Runtime(
        backend_url="http://upstream.local:8891",
        enforce_ip_acl=enforce_ip_acl,
        enforce_sso=False,
    )
    gw = Gateway(settings, acl, audit, client, runtime, db=None)
    return gw, upstream


def test_allow_passes_through_and_audits_once():
    gw, upstream = make_gateway()
    app = build_app(gateway=gw)
    with TestClient(app, client=("203.0.113.5", 1)) as c:
        r = c.get("/app/home")
    assert r.status_code == 200
    assert r.text == "hello"
    assert upstream.count == 1
    assert len(gw.audit.rows) == 1
    assert gw.audit.rows[0]["decision"] == "allow"
    assert gw.audit.rows[0]["status"] == 200


def test_denied_ip_returns_403_without_hitting_upstream():
    gw, upstream = make_gateway()
    app = build_app(gateway=gw)
    with TestClient(app, client=("198.51.100.9", 1)) as c:
        r = c.get("/app/home")
    assert r.status_code == 403
    assert upstream.count == 0
    assert len(gw.audit.rows) == 1
    assert gw.audit.rows[0]["decision"] == "deny_ip"
    assert gw.audit.rows[0]["status"] == 403


def test_acl_disabled_allows_any_ip():
    gw, _ = make_gateway(enforce_ip_acl=False)
    app = build_app(gateway=gw)
    with TestClient(app, client=("198.51.100.9", 1)) as c:
        r = c.get("/x")
    assert r.status_code == 200
    assert gw.audit.rows[0]["decision"] == "allow"


def test_trusted_proxy_uses_forwarded_client_ip():
    gw, _ = make_gateway()
    app = build_app(gateway=gw)
    with TestClient(app, client=("127.0.0.1", 1)) as c:
        r = c.get("/x", headers={"X-Forwarded-For": "203.0.113.5"})
    assert r.status_code == 200
    assert gw.audit.rows[0]["client_ip"] == "203.0.113.5"


def test_redirect_location_is_rewritten():
    gw, _ = make_gateway(upstream=redirect_upstream)
    app = build_app(gateway=gw)
    with TestClient(app, client=("203.0.113.5", 1)) as c:
        r = c.get("/go", follow_redirects=False)
    assert r.status_code == 302
    assert r.headers["location"] == "https://arcmate.samirgroup.net/next"


def test_health_endpoint_not_proxied():
    gw, upstream = make_gateway()
    app = build_app(gateway=gw)
    with TestClient(app) as c:
        r = c.get("/_agw/health")
    assert r.status_code == 200
    assert r.json() == {"status": "ok"}
    assert upstream.count == 0


if __name__ == "__main__":
    raise SystemExit(pytest.main([__file__, "-v"]))
