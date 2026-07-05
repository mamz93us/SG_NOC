from gateway.acl import Acl, real_client_ip


def test_ipv4_cidr_and_host_match():
    acl = Acl()
    acl.load(["197.1.2.0/24", "203.0.113.5/32"])
    assert acl.is_allowed("197.1.2.55")
    assert acl.is_allowed("203.0.113.5")
    assert not acl.is_allowed("203.0.113.6")
    assert not acl.is_allowed("198.51.100.1")


def test_ipv6_match_and_family_isolation():
    acl = Acl()
    acl.load(["2001:db8::/32"])
    assert acl.is_allowed("2001:db8::1")
    assert not acl.is_allowed("2001:dead::1")
    # An IPv4 address must not match an IPv6 network and vice-versa.
    assert not acl.is_allowed("127.0.0.1")


def test_zero_prefix_allows_everything():
    acl = Acl()
    acl.load(["0.0.0.0/0"])
    assert acl.is_allowed("8.8.8.8")


def test_invalid_entries_skipped_and_invalid_ip_denied():
    acl = Acl()
    loaded = acl.load(["not-a-cidr", "10.0.0.0/8"])
    assert loaded == 1
    assert acl.is_allowed("10.1.2.3")
    assert not acl.is_allowed("garbage")
    assert not acl.is_allowed(None)


def test_empty_allowlist_denies_all():
    acl = Acl()
    assert not acl.is_allowed("10.0.0.1")


def test_untrusted_peer_ignores_forwarded_header():
    # Peer is not a trusted proxy → the header is a spoof and must be ignored.
    headers = {"X-Forwarded-For": "1.2.3.4"}
    assert real_client_ip("198.51.100.9", headers, ["127.0.0.1"]) == "198.51.100.9"


def test_trusted_peer_takes_rightmost_untrusted():
    # nginx appends the true remote addr to the right of the chain.
    headers = {"X-Forwarded-For": "9.9.9.9, 203.0.113.5"}
    assert real_client_ip("127.0.0.1", headers, ["127.0.0.1"]) == "203.0.113.5"


def test_trusted_peer_single_value_header():
    headers = {"X-Forwarded-For": "203.0.113.5"}
    assert real_client_ip("127.0.0.1", headers, ["127.0.0.1"]) == "203.0.113.5"


def test_trusted_peer_missing_header_falls_back_to_peer():
    assert real_client_ip("127.0.0.1", {}, ["127.0.0.1"]) == "127.0.0.1"


def test_none_peer_returns_none():
    assert real_client_ip(None, {}, ["127.0.0.1"]) is None
