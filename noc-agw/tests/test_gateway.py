"""End-to-end request handling through the real FastAPI app, with a real
in-process ASGI upstream (httpx.ASGITransport streams properly) and an
in-memory audit sink. No DB required."""

import httpx
import pytest
from fastapi.testclient import TestClient

from gateway.acl import Acl
from gateway.config import Settings
from gateway.main import Gateway, Runtime, build_app
from gateway.proxy import NullCookies, cookieless_client


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


class CookieUpstream:
    """Records the Cookie header it receives and always sets two cookies —
    mimics ASP.NET issuing a session cookie."""

    def __init__(self):
        self.seen_cookies = []

    async def __call__(self, scope, receive, send):
        headers = {k.decode(): v.decode() for k, v in scope["headers"]}
        self.seen_cookies.append(headers.get("cookie"))
        await _drain_body(receive)
        await send(
            {
                "type": "http.response.start",
                "status": 200,
                "headers": [
                    (b"content-type", b"text/plain"),
                    (b"set-cookie", b"ASP.NET_SessionId=abc123; path=/; HttpOnly"),
                    (b"set-cookie", b"AuthToken=xyz; path=/"),
                ],
            }
        )
        await send({"type": "http.response.body", "body": b"ok"})


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


def make_gateway(*, enforce_ip_acl=True, allow=("203.0.113.5/32",), block=(), upstream=None):
    upstream = upstream or HelloUpstream()
    settings = Settings(_env_file=None)  # defaults only; ignore any local .env
    acl = Acl()
    acl.load(list(allow))
    blocklist = Acl()
    blocklist.load(list(block))
    audit = FakeAudit()
    client = cookieless_client(transport=httpx.ASGITransport(app=upstream))
    runtime = Runtime(
        backend_url="http://upstream.local:8891",
        enforce_ip_acl=enforce_ip_acl,
        enforce_sso=False,
    )
    gw = Gateway(settings, acl, audit, client, runtime, db=None, blocklist=blocklist)
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


def test_gateway_is_cookie_transparent():
    """Set-Cookie flows to the client (all of them), and the gateway never
    stores/replays a cookie of its own across requests."""
    up = CookieUpstream()
    gw, _ = make_gateway(upstream=up)
    app = build_app(gateway=gw)
    # NullCookies on the test client too, so IT never stores/replays r1's
    # Set-Cookie — this isolates the gateway's own (non-)replay behavior.
    with TestClient(app, client=("203.0.113.5", 1)) as c:
        c._cookies = NullCookies()  # test client never stores/replays either
        r1 = c.get("/arcmate72/frmmain.aspx")   # client sends no cookie
        r2 = c.get("/arcmate72/frmmain.aspx")   # still no cookie from the client

    # Both upstream Set-Cookie headers reach the client (not collapsed).
    set_cookies = r1.headers.get_list("set-cookie")
    assert len(set_cookies) == 2
    assert any("ASP.NET_SessionId=abc123" in c for c in set_cookies)

    # The gateway did NOT inject a stored cookie on either upstream request.
    assert up.seen_cookies == [None, None]


def test_blocklist_denies_even_when_acl_disabled():
    gw, up = make_gateway(enforce_ip_acl=False, block=("198.51.100.9/32",))
    app = build_app(gateway=gw)
    with TestClient(app, client=("198.51.100.9", 1)) as c:
        r = c.get("/x")
    assert r.status_code == 403
    assert up.count == 0
    assert gw.audit.rows[-1]["decision"] == "deny_ip"
    assert "blocklist" in gw.audit.rows[-1]["reason"]


def test_blocklist_beats_allowlist():
    gw, up = make_gateway(
        enforce_ip_acl=True, allow=("203.0.113.5/32",), block=("203.0.113.5/32",)
    )
    app = build_app(gateway=gw)
    with TestClient(app, client=("203.0.113.5", 1)) as c:
        r = c.get("/x")
    assert r.status_code == 403
    assert up.count == 0


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
