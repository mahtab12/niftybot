"""Weekly option expiry date helpers for Indian index options."""

from datetime import date, timedelta

# NSE index options (Nifty) expire on Tuesday; BSE Sensex on Thursday.
_WEEKLY_WEEKDAY: dict[str, int] = {
    "NSE": 1,
    "BSE": 3,
}


def weekly_expiries(
    exchange: str,
    count: int = 12,
    from_date: date | None = None,
) -> list[str]:
    """Return the next `count` weekly expiry dates for an exchange."""
    weekday = _WEEKLY_WEEKDAY.get(exchange.upper(), 1)
    start = from_date or date.today()
    days_ahead = (weekday - start.weekday()) % 7
    if days_ahead == 0:
        first = start
    else:
        first = start + timedelta(days=days_ahead)

    expiries: list[str] = []
    current = first
    for _ in range(count):
        expiries.append(current.isoformat())
        current += timedelta(days=7)
    return expiries
