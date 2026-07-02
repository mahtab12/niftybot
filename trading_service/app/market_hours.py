"""Indian index (NSE/BSE) session hours in IST."""

from __future__ import annotations

from datetime import datetime
from zoneinfo import ZoneInfo

IST = ZoneInfo("Asia/Kolkata")
INDEX_MARKET_OPEN_MINUTES = 9 * 60 + 15
INDEX_MARKET_CLOSE_MINUTES = 15 * 60 + 30
INDEX_MARKET_HOURS = "9:15 AM – 3:30 PM IST"


def index_market_is_open(now: datetime | None = None) -> bool:
    """True during Nifty/Sensex regular session (Mon–Fri, 9:15 AM–3:30 PM IST)."""
    current = now or datetime.now(tz=IST)
    if current.tzinfo is None:
        current = current.replace(tzinfo=IST)
    else:
        current = current.astimezone(IST)

    if current.weekday() >= 5:
        return False

    session_minutes = current.hour * 60 + current.minute
    return INDEX_MARKET_OPEN_MINUTES <= session_minutes <= INDEX_MARKET_CLOSE_MINUTES


def index_market_status(now: datetime | None = None) -> dict[str, str | bool]:
    """Market open flag and user-facing message for index auto-trade pages."""
    current = now or datetime.now(tz=IST)
    if current.tzinfo is None:
        current = current.replace(tzinfo=IST)
    else:
        current = current.astimezone(IST)

    if index_market_is_open(current):
        return {
            "market_open": True,
            "market_status": "open",
            "market_message": f"Market is open · Nifty & Sensex session {INDEX_MARKET_HOURS}.",
        }

    if current.weekday() >= 5:
        message = (
            f"Market is closed — NSE/BSE are closed on weekends. "
            f"Session hours: {INDEX_MARKET_HOURS}."
        )
    elif current.hour * 60 + current.minute < INDEX_MARKET_OPEN_MINUTES:
        message = (
            f"Market is closed — Nifty & Sensex session opens at 9:15 AM IST "
            f"(hours: {INDEX_MARKET_HOURS})."
        )
    else:
        message = (
            f"Market is closed — Nifty & Sensex session ended at 3:30 PM IST "
            f"(hours: {INDEX_MARKET_HOURS})."
        )

    return {
        "market_open": False,
        "market_status": "closed",
        "market_message": message,
    }
