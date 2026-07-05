"""Reverse-proxy core: header hygiene, Location rewriting, streaming relay.

The header helpers are pure functions so they can be unit-tested in isolation.
"""

from __future__ import annotations

from urllib.parse import urlsplit

import httpx
from starlette.background import BackgroundTask
from starlette.requests import Request
from starlette.responses import StreamingResponse

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
    """Strip hop-by-hop headers and rewrite Location for the client."""
    out: list[tuple[str, str]] = []
    for key, value in response_headers.items():
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

    return StreamingResponse(
        upstream_resp.aiter_raw(),
        status_code=upstream_resp.status_code,
        headers=dict(resp_headers),
        background=BackgroundTask(upstream_resp.aclose),
    )
