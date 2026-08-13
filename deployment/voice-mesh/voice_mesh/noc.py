"""Talking to the NOC.

Two calls: pull the branch list and tunables before a sweep, push the combined
report after it. Both authenticate with the X-Voice-Mesh-Secret header; the NOC
also requires the request to come from this host.

Both directions are made resilient on purpose, because the thing on the other
end is a PHP app on the same box that gets restarted for deploys:

  - a failed config fetch falls back to the last good response, so a
    `composer install` that happens to overlap the timer doesn't cancel a sweep;
  - a failed report POST is retried and then spilled to disk, because the run it
    describes cost ~14 minutes of real PBX calls and is not cheap to redo.
"""
import json
import logging
import time
import urllib.error
import urllib.request
from pathlib import Path

log = logging.getLogger("voice-mesh")

CONFIG_CACHE_NAME = "noc-config.json"
UNSENT_DIR_NAME = "unsent"


def _request(url: str, secret: str, data: bytes | None = None, timeout: int = 15):
    req = urllib.request.Request(
        url,
        data=data,
        headers={
            "X-Voice-Mesh-Secret": secret,
            "Accept": "application/json",
            **({"Content-Type": "application/json"} if data else {}),
        },
        method="POST" if data else "GET",
    )
    return urllib.request.urlopen(req, timeout=timeout)


def fetch_config(url: str, secret: str, state_dir: Path, timeout: int = 15) -> dict:
    """Branch list + tunables, with a last-known-good fallback."""
    cache_path = state_dir / CONFIG_CACHE_NAME

    try:
        with _request(url, secret, timeout=timeout) as resp:
            payload = json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        if e.code == 401:
            # Don't fall back to the cache here: a rejected secret is a
            # configuration error that must be fixed, and dialling on with stale
            # credentials would just hide it behind a green board.
            raise SystemExit(
                f"NOC rejected our secret (401) at {url}. Rotate it at "
                "/admin/network/voice-mesh and put the new value in NOC_SECRET."
            )
        log.warning("config fetch failed (HTTP %s) — trying the cached copy", e.code)
        return _cached_config(cache_path, e)
    except (urllib.error.URLError, OSError, ValueError) as e:
        log.warning("config fetch failed (%s) — trying the cached copy", e)
        return _cached_config(cache_path, e)

    if payload.get("warning"):
        log.warning("NOC: %s", payload["warning"])

    # Refreshing the cache is best-effort. We already have a good config in hand,
    # so a cache we cannot write is a warning, never a reason to abandon a sweep
    # — e.g. a stale copy left root-owned by a `sudo deploy.py` run that the
    # service user can no longer overwrite.
    try:
        state_dir.mkdir(parents=True, exist_ok=True)
        # Replace rather than write in place, so ownership follows the writer
        # instead of inheriting whatever the previous run left behind.
        tmp_path = cache_path.with_suffix(".json.tmp")
        tmp_path.write_text(json.dumps(payload))
        tmp_path.chmod(0o600)       # it holds every branch's SIP password
        tmp_path.replace(cache_path)
    except OSError as e:
        log.warning("could not refresh the config cache at %s: %s", cache_path, e)

    return payload


def _cached_config(cache_path: Path, original_error) -> dict:
    if not cache_path.exists():
        raise SystemExit(
            f"Could not reach the NOC and no cached config at {cache_path}: {original_error}"
        )

    log.warning("using cached NOC config from %s", cache_path)
    return json.loads(cache_path.read_text())


def post_report(url: str, secret: str, payload: dict, state_dir: Path,
                timeout: int = 15, attempts: int = 3) -> bool:
    """POST the combined report, retrying, then spilling to disk if it can't."""
    body = json.dumps(payload).encode()
    backoff = 2

    for attempt in range(1, attempts + 1):
        try:
            with _request(url, secret, data=body, timeout=timeout) as resp:
                log.info("report posted to %s (HTTP %s)", url, resp.status)
                return True
        except urllib.error.HTTPError as e:
            detail = e.read().decode(errors="replace")[:300]
            log.warning("report POST rejected (HTTP %s): %s", e.code, detail)
            if e.code in (401, 403, 422):
                break       # our fault, not a blip — retrying changes nothing
        except (urllib.error.URLError, OSError) as e:
            log.warning("report POST failed (attempt %s/%s): %s", attempt, attempts, e)

        if attempt < attempts:
            time.sleep(backoff)
            backoff *= 4

    _spill(state_dir, payload)
    return False


def _spill(state_dir: Path, payload: dict) -> None:
    """Keep the report rather than discard it — it cost a full sweep of real
    calls, and it is the only record of what those calls did."""
    try:
        unsent = state_dir / UNSENT_DIR_NAME
        unsent.mkdir(parents=True, exist_ok=True)
        stamp = str(payload.get("timestamp", "unknown")).replace(":", "-")
        path = unsent / f"report-{stamp}.json"
        path.write_text(json.dumps(payload, indent=2))
        log.warning("report saved to %s — post it by hand once the NOC is reachable", path)
    except OSError as e:
        log.error("could not even save the unsent report: %s", e)
