"""Open-interest buildup classification for Nifty futures."""

from __future__ import annotations

from typing import Optional


OI_BUILDUP_TYPES = (
    "long_buildup",
    "long_unwinding",
    "short_buildup",
    "short_covering",
    "neutral",
)


def classify_oi_buildup(
    price_change: Optional[float],
    oi_change: Optional[float],
    min_price_change: float = 0.0,
    min_oi_change: float = 0.0,
) -> str:
    """
    Classify session OI buildup from price and OI change.

    Long buildup: price up + OI up
    Long unwinding: price down + OI down
    Short buildup: price down + OI up
    Short covering: price up + OI down
    """
    if price_change is None or oi_change is None:
        return "neutral"

    price_up = price_change > min_price_change
    price_down = price_change < -min_price_change
    oi_up = oi_change > min_oi_change
    oi_down = oi_change < -min_oi_change

    if price_up and oi_up:
        return "long_buildup"
    if price_down and oi_down:
        return "long_unwinding"
    if price_down and oi_up:
        return "short_buildup"
    if price_up and oi_down:
        return "short_covering"
    return "neutral"


def oi_buildup_bias(buildup_type: str) -> str:
    """Map OI buildup to bullish / bearish / neutral."""
    if buildup_type in ("long_buildup", "short_covering"):
        return "bullish"
    if buildup_type in ("short_buildup", "long_unwinding"):
        return "bearish"
    return "neutral"
