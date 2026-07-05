"""Async MySQL access against the SG_nOC database (phonebook2).

The gateway is a *reader* of NOC-managed config (allowlist + settings) and a
*writer* of audit rows only. It never touches other NOC tables.
"""

from __future__ import annotations

import aiomysql

from .config import Settings


class Database:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._pool: aiomysql.Pool | None = None

    async def connect(self) -> None:
        self._pool = await aiomysql.create_pool(
            host=self._settings.db_host,
            port=self._settings.db_port,
            user=self._settings.db_user,
            password=self._settings.db_password,
            db=self._settings.db_name,
            autocommit=True,
            minsize=1,
            maxsize=5,
            charset="utf8mb4",
        )

    async def close(self) -> None:
        if self._pool is not None:
            self._pool.close()
            await self._pool.wait_closed()
            self._pool = None

    async def fetch_active_cidrs(self) -> list[str]:
        """Active allowlist entries (both dynamic and manual)."""
        async with self._pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT cidr FROM agw_allowlist WHERE active = 1"
                )
                rows = await cur.fetchall()
        return [r[0] for r in rows]

    async def fetch_runtime_config(self) -> tuple[str | None, bool | None]:
        """(agw_backend_url, agw_enforce_ip_acl) from the settings singleton."""
        async with self._pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT agw_backend_url, agw_enforce_ip_acl "
                    "FROM settings ORDER BY id LIMIT 1"
                )
                row = await cur.fetchone()
        if not row:
            return None, None
        backend_url = row[0] or None
        enforce = None if row[1] is None else bool(row[1])
        return backend_url, enforce

    async def insert_audit_batch(self, rows: list[dict]) -> None:
        if not rows:
            return
        sql = (
            "INSERT INTO agw_audit "
            "(ts, client_ip, user_email, user_name, method, path, status, "
            " decision, reason, user_agent, latency_ms) "
            "VALUES (%(ts)s, %(client_ip)s, %(user_email)s, %(user_name)s, "
            "%(method)s, %(path)s, %(status)s, %(decision)s, %(reason)s, "
            "%(user_agent)s, %(latency_ms)s)"
        )
        async with self._pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.executemany(sql, rows)
