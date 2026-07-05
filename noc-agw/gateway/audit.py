"""Non-blocking audit writer.

Request handlers call `record()` (cheap, synchronous enqueue) so proxy latency
is never tied to a DB round-trip. A background task drains the queue in batches
every `flush_sec`. The queue is unbounded, so a `deny_*` event is never dropped.
"""

from __future__ import annotations

import asyncio
from datetime import datetime, timezone


class AuditWriter:
    def __init__(self, db, flush_sec: float = 2.0, batch_size: int = 200) -> None:
        self._db = db
        self._flush_sec = flush_sec
        self._batch_size = batch_size
        self._queue: asyncio.Queue[dict] = asyncio.Queue()
        self._task: asyncio.Task | None = None
        self._stopping = False

    def record(
        self,
        *,
        client_ip: str,
        decision: str,
        method: str | None = None,
        path: str | None = None,
        status: int | None = None,
        reason: str | None = None,
        user_email: str | None = None,
        user_name: str | None = None,
        user_agent: str | None = None,
        latency_ms: int | None = None,
    ) -> None:
        self._queue.put_nowait(
            {
                "ts": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S.%f")[:-3],
                "client_ip": client_ip,
                "user_email": user_email,
                "user_name": user_name,
                "method": method,
                "path": (path or "")[:1024],
                "status": status,
                "decision": decision,
                "reason": (reason or None) and reason[:255],
                "user_agent": (user_agent or None) and user_agent[:512],
                "latency_ms": latency_ms,
            }
        )

    async def start(self) -> None:
        self._task = asyncio.create_task(self._run())

    async def stop(self) -> None:
        self._stopping = True
        if self._task is not None:
            await self._task
        await self._drain_once()

    async def _run(self) -> None:
        while not self._stopping:
            await asyncio.sleep(self._flush_sec)
            await self._drain_once()

    async def _drain_once(self) -> None:
        rows: list[dict] = []
        while not self._queue.empty() and len(rows) < self._batch_size:
            rows.append(self._queue.get_nowait())
        if not rows:
            return
        try:
            await self._db.insert_audit_batch(rows)
        except Exception:  # noqa: BLE001 — audit must never crash the proxy
            # Re-queue so we retry on the next tick rather than losing events.
            for row in rows:
                self._queue.put_nowait(row)
