"""Option chain strike-level OI analysis for directional bias."""

from __future__ import annotations

from typing import Any, Optional

from app.models.schemas import OptionChainResponse, OptionData

MIN_STRIKE_OI_CHANGE_PCT = 2.5


def _nearest_strike_key(strikes: dict[str, Any], ltp: float) -> Optional[str]:
    if not strikes or ltp <= 0:
        return None
    best_key: Optional[str] = None
    best_dist = float("inf")
    for key in strikes:
        try:
            dist = abs(float(key) - ltp)
        except (TypeError, ValueError):
            continue
        if dist < best_dist:
            best_dist = dist
            best_key = key
    return best_key


def _strike_offset_key(
    strikes: dict[str, Any], atm_key: str, strike_step: int, steps: int,
) -> Optional[str]:
    try:
        target = float(atm_key) + (strike_step * steps)
    except (TypeError, ValueError):
        return None
    for key in strikes:
        try:
            if abs(float(key) - target) < strike_step * 0.51:
                return key
        except (TypeError, ValueError):
            continue
    return None


def _leg_oi_change(leg: Optional[OptionData]) -> Optional[float]:
    if leg is None:
        return None
    return leg.oi_change_percentage


def _leg_oi(leg: Optional[OptionData]) -> int:
    if leg is None or leg.open_interest is None:
        return 0
    return int(leg.open_interest)


def analyze_strike_oi(
    chain: Optional[OptionChainResponse],
    strike_step: int = 50,
) -> dict[str, Any]:
    """
    Derive bullish/bearish bias from ATM ±1 strike CE/PE open interest.

    Bullish: call OI building / put OI unwinding near ATM.
    Bearish: put OI building / call OI unwinding near ATM.
    """
    empty: dict[str, Any] = {
        "bias": "neutral",
        "bull_factor": None,
        "bear_factor": None,
        "pcr": None,
        "atm_strike": None,
        "atm_ce_oi_change_pct": None,
        "atm_pe_oi_change_pct": None,
        "chain_oi_summary": "unavailable",
    }
    if chain is None or not chain.success or not chain.strikes:
        return empty

    ltp = chain.underlying_ltp
    if ltp is None or ltp <= 0:
        return empty

    atm_key = _nearest_strike_key(chain.strikes, float(ltp))
    if atm_key is None:
        return empty

    atm = chain.strikes[atm_key]
    otm_ce_key = _strike_offset_key(chain.strikes, atm_key, strike_step, 1)
    otm_pe_key = _strike_offset_key(chain.strikes, atm_key, strike_step, -1)

    ce_changes: list[float] = []
    pe_changes: list[float] = []
    ce_oi_total = 0
    pe_oi_total = 0

    for key in (atm_key, otm_ce_key, otm_pe_key):
        if not key or key not in chain.strikes:
            continue
        row = chain.strikes[key]
        ce_chg = _leg_oi_change(row.CE)
        pe_chg = _leg_oi_change(row.PE)
        if ce_chg is not None:
            ce_changes.append(ce_chg)
        if pe_chg is not None:
            pe_changes.append(pe_chg)
        ce_oi_total += _leg_oi(row.CE)
        pe_oi_total += _leg_oi(row.PE)

    atm_ce_chg = _leg_oi_change(atm.CE)
    atm_pe_chg = _leg_oi_change(atm.PE)
    avg_ce_chg = sum(ce_changes) / len(ce_changes) if ce_changes else None
    avg_pe_chg = sum(pe_changes) / len(pe_changes) if pe_changes else None
    pcr = (pe_oi_total / ce_oi_total) if ce_oi_total > 0 else None

    bull_score = 0
    bear_score = 0
    bull_factor: Optional[str] = None
    bear_factor: Optional[str] = None

    if avg_ce_chg is not None and avg_ce_chg >= MIN_STRIKE_OI_CHANGE_PCT:
        bull_score += 1
    if avg_pe_chg is not None and avg_pe_chg <= -MIN_STRIKE_OI_CHANGE_PCT:
        bull_score += 1
    if avg_pe_chg is not None and avg_pe_chg >= MIN_STRIKE_OI_CHANGE_PCT:
        bear_score += 1
    if avg_ce_chg is not None and avg_ce_chg <= -MIN_STRIKE_OI_CHANGE_PCT:
        bear_score += 1
    if pcr is not None:
        if pcr < 0.85:
            bull_score += 1
        elif pcr > 1.15:
            bear_score += 1

    if bull_score >= 2 and bull_score > bear_score:
        bull_factor = "chain_ce_oi_buildup"
    if bear_score >= 2 and bear_score > bull_score:
        bear_factor = "chain_pe_oi_buildup"

    bias = "neutral"
    if bull_factor:
        bias = "bullish"
    elif bear_factor:
        bias = "bearish"

    summary_parts = [f"ATM {atm_key}"]
    if atm_ce_chg is not None:
        summary_parts.append(f"CE Δ{atm_ce_chg:+.1f}%")
    if atm_pe_chg is not None:
        summary_parts.append(f"PE Δ{atm_pe_chg:+.1f}%")
    if pcr is not None:
        summary_parts.append(f"PCR {pcr:.2f}")

    return {
        "bias": bias,
        "bull_factor": bull_factor,
        "bear_factor": bear_factor,
        "pcr": round(pcr, 3) if pcr is not None else None,
        "atm_strike": atm_key,
        "atm_ce_oi_change_pct": atm_ce_chg,
        "atm_pe_oi_change_pct": atm_pe_chg,
        "avg_ce_oi_change_pct": (
            round(avg_ce_chg, 2) if avg_ce_chg is not None else None
        ),
        "avg_pe_oi_change_pct": (
            round(avg_pe_chg, 2) if avg_pe_chg is not None else None
        ),
        "chain_oi_summary": " · ".join(summary_parts),
    }


def chain_oi_factor(
    chain_analysis: dict[str, Any],
    direction: str,
) -> Optional[str]:
    if direction == "bullish":
        return chain_analysis.get("bull_factor")
    if direction == "bearish":
        return chain_analysis.get("bear_factor")
    return None
