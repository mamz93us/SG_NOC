"""Reverse-proxy core: header hygiene, Location rewriting, streaming relay.

The header helpers are pure functions so they can be unit-tested in isolation.
"""

from __future__ import annotations

from urllib.parse import urlsplit

import httpx
from starlette.background import BackgroundTask
from starlette.requests import Request
from starlette.responses import StreamingResponse


class NullCookies(httpx.Cookies):
    """A cookie jar that stores and sends nothing.

    The default httpx client keeps ONE cookie jar shared across every request,
    so it would store an upstream Set-Cookie and replay it to other clients —
    making all users share a single upstream session. The gateway must instead
    be cookie-transparent: forward the client's Cookie header up and the
    upstream Set-Cookie headers back, and keep no state of its own.
    """

    def extract_cookies(self, response) -> None:  # noqa: D401 - httpx hook
        pass

    def set_cookie_header(self, request) -> None:  # noqa: D401 - httpx hook
        pass


def cookieless_client(**kwargs) -> httpx.AsyncClient:
    """An httpx client that never stores or replays cookies.

    Note: passing `cookies=NullCookies()` to the constructor does NOT work —
    httpx re-wraps it into a plain Cookies jar. We must assign the private
    `_cookies` after construction for the override to take effect.
    """
    client = httpx.AsyncClient(**kwargs)
    client._cookies = NullCookies()
    return client


# Connection-specific headers that must never be forwarded end-to-end.
HOP_BY_HOP = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
}


def backend_host(backend_url: str) -> str:
    """netloc (host[:port]) of the upstream, for the outgoing Host header."""
    return urlsplit(backend_url).netloc


def build_upstream_headers(
    request_headers,
    backend_url: str,
    client_ip: str | None,
    original_host: str | None,
) -> list[tuple[str, str]]:
    """Copy client → upstream headers, dropping hop-by-hop, fixing Host, and
    setting the X-Forwarded-* trio so the app sees the real client + https."""
    out: list[tuple[str, str]] = []
    for key, value in request_headers.items():
        lk = key.lower()
        if lk in HOP_BY_HOP or lk == "host":
            continue
        if lk in ("x-forwarded-for", "x-forwarded-proto", "x-forwarded-host"):
            continue
        out.append((key, value))

    out.append(("Host", backend_host(backend_url)))
    if client_ip:
        out.append(("X-Forwarded-For", client_ip))
    out.append(("X-Forwarded-Proto", "https"))
    if original_host:
        out.append(("X-Forwarded-Host", original_host))
    return out


def rewrite_location(location: str | None, backend_url: str, public_host: str) -> str | None:
    """Rewrite an upstream redirect target from the internal backend origin to
    the public https origin, so the browser follows arcmate.samirgroup.net."""
    if not location:
        return location

    backend = backend_url.rstrip("/")
    if location.startswith(backend):
        return "https://" + public_host + location[len(backend):]

    # Same host without scheme (rare): http://host... already covered above.
    bhost = backend_host(backend_url)
    for scheme in ("http://", "https://"):
        prefix = scheme + bhost
        if location.startswith(prefix):
            return "https://" + public_host + location[len(prefix):]
    return location


def filter_response_headers(
    response_headers,
    backend_url: str,
    public_host: str,
) -> list[tuple[str, str]]:
    """Strip hop-by-hop headers and rewrite Location for the client.

    Uses multi_items() so repeated headers — notably multiple Set-Cookie — are
    preserved as separate entries rather than comma-joined.
    """
    out: list[tuple[str, str]] = []
    for key, value in response_headers.multi_items():
        lk = key.lower()
        if lk in HOP_BY_HOP:
            continue
        if lk == "location":
            value = rewrite_location(value, backend_url, public_host) or value
        out.append((key, value))
    return out


async def proxy_request(
    client: httpx.AsyncClient,
    backend_url: str,
    request: Request,
    client_ip: str | None,
    public_host: str,
) -> StreamingResponse:
    """Stream the request to the upstream and stream its response back."""
    target = backend_url.rstrip("/") + "/" + request.url.path.lstrip("/")
    if request.url.query:
        target += "?" + request.url.query

    upstream_headers = build_upstream_headers(
        request.headers,
        backend_url,
        client_ip,
        request.headers.get("host"),
    )

    upstream_req = client.build_request(
        request.method,
        target,
        headers=upstream_headers,
        content=request.stream(),
    )
    upstream_resp = await client.send(upstream_req, stream=True)

    resp_headers = filter_response_headers(upstream_resp.headers, backend_url, public_host)

    response = StreamingResponse(
        upstream_resp.aiter_raw(),
        status_code=upstream_resp.status_code,
        background=BackgroundTask(upstream_resp.aclose),
    )
    # Set raw_headers directly so duplicate headers (multiple Set-Cookie) are
    # preserved — a dict would collapse them.
    response.raw_headers = [
        (key.lower().encode("latin-1"), value.encode("latin-1")) for key, value in resp_headers
    ]
    return response
