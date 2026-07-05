import httpx

from gateway.proxy import (
    backend_host,
    build_upstream_headers,
    filter_response_headers,
    rewrite_location,
)


def test_backend_host():
    assert backend_host("http://10.0.0.20:8891") == "10.0.0.20:8891"
    assert backend_host("http://127.0.0.1:8891/") == "127.0.0.1:8891"


def test_upstream_headers_drop_hop_by_hop_and_set_forwarded():
    incoming = httpx.Headers(
        [
            ("Host", "arcmate.samirgroup.net"),
            ("Connection", "keep-alive"),
            ("Transfer-Encoding", "chunked"),
            ("X-Forwarded-For", "1.2.3.4"),  # client-supplied, must be replaced
            ("Cookie", "a=b"),
            ("Accept", "text/html"),
        ]
    )
    out = build_upstream_headers(
        incoming, "http://10.0.0.20:8891", "203.0.113.5", "arcmate.samirgroup.net"
    )
    d = {k.lower(): v for k, v in out}

    assert d["host"] == "10.0.0.20:8891"
    assert "connection" not in d
    assert "transfer-encoding" not in d
    assert d["x-forwarded-for"] == "203.0.113.5"
    assert d["x-forwarded-proto"] == "https"
    assert d["x-forwarded-host"] == "arcmate.samirgroup.net"
    assert d["cookie"] == "a=b"
    assert d["accept"] == "text/html"


def test_rewrite_location_backend_to_public():
    assert (
        rewrite_location(
            "http://10.0.0.20:8891/app/home", "http://10.0.0.20:8891", "arcmate.samirgroup.net"
        )
        == "https://arcmate.samirgroup.net/app/home"
    )


def test_rewrite_location_leaves_external_untouched():
    assert (
        rewrite_location(
            "https://microsoft.com/login", "http://10.0.0.20:8891", "arcmate.samirgroup.net"
        )
        == "https://microsoft.com/login"
    )


def test_rewrite_location_none():
    assert rewrite_location(None, "http://10.0.0.20:8891", "arcmate.samirgroup.net") is None


def test_response_headers_strip_and_rewrite():
    resp = httpx.Headers(
        [
            ("Transfer-Encoding", "chunked"),
            ("Connection", "close"),
            ("Location", "http://10.0.0.20:8891/next"),
            ("Content-Type", "text/html"),
        ]
    )
    out = filter_response_headers(resp, "http://10.0.0.20:8891", "arcmate.samirgroup.net")
    d = {k.lower(): v for k, v in out}

    assert "transfer-encoding" not in d
    assert "connection" not in d
    assert d["location"] == "https://arcmate.samirgroup.net/next"
    assert d["content-type"] == "text/html"
