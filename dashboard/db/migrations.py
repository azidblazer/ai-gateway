"""Database migrations for the dashboard."""

import asyncpg


async def ensure_coaching_table(pool: asyncpg.Pool) -> None:
    """Create the coaching cache table if it doesn't exist.

    Stores one cached coaching result per user per day.
    Catches race conditions when multiple workers start simultaneously.
    """
    try:
        async with pool.acquire() as conn:
            await conn.execute("""
                CREATE TABLE IF NOT EXISTS dashboard_coaching_cache (
                    user_email   TEXT NOT NULL,
                    cache_date   DATE NOT NULL,
                    profile      JSON NOT NULL,
                    coaching     JSON,
                    generated_at TIMESTAMPTZ DEFAULT NOW(),
                    PRIMARY KEY (user_email, cache_date)
                )
            """)
    except asyncpg.UniqueViolationError:
        pass  # Another worker already created the table


async def ensure_feedback_ratelimit_table(pool: asyncpg.Pool) -> None:
    """Create the feedback rate-limit table if it doesn't exist.

    One row per user holding their last successful submission time. DB-backed so
    the limit is shared across all uvicorn workers (an in-memory dict is per-worker,
    which effectively multiplies the limit by the worker count and grows unbounded).
    """
    try:
        async with pool.acquire() as conn:
            await conn.execute("""
                CREATE TABLE IF NOT EXISTS dashboard_feedback_ratelimit (
                    email       TEXT PRIMARY KEY,
                    last_submit TIMESTAMPTZ NOT NULL
                )
            """)
    except asyncpg.UniqueViolationError:
        pass  # Another worker already created the table
