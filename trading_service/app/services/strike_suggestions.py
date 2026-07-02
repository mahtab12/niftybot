"""Market pattern labels and delta-based option strike suggestions."""

from __future__ import annotations

from typing import Any, Optional

from app.auto_trade_profiles import AutoTradeProfile
from app.models.schemas import OptionChainResponse, OptionData, StrikeData
from app.services.auto_trade_service import resolve_strike_price
from app.strategies.chain_signals import _nearest_strike_key

OI_PATTERN_LABELS = {
    "long_buildup": "Futures long buildup (price ↑, OI ↑)",
    "short_covering": "Short covering (price ↑, OI ↓)",
    "short_buildup": "Short buildup (price ↓, OI ↑)",
    "long_unwinding": "Long unwinding (price ↓, OI ↓)",
    "neutral": "Futures OI neutral",
}

STRIKE_TIERS = (
    ("primary", "ATM (~0.50 Δ)", 0.50, 0),
    ("conservative", "ITM (~0.55 Δ)", 0.55, -1),
    ("aggressive", "OTM (~0.40 Δ)", 0.40, 1),
)


def detect_market_pattern(indicators: dict[str, Any]) -> dict[str, Any]:
    """Score bullish vs bearish from OI, EMA, chain, structure, and rule stacks."""
    bullish = 0
    bearish = 0
    signals: list[str] = []

    oi_type = str(indicators.get("oi_buildup") or "neutral")
    oi_label = OI_PATTERN_LABELS.get(oi_type, oi_type.replace("_", " "))
    if oi_type in ("long_buildup", "short_covering"):
        bullish += 3
        signals.append(oi_label)
    elif oi_type in ("short_buildup", "long_unwinding"):
        bearish += 3
        signals.append(oi_label)
    elif oi_type != "neutral":
        signals.append(oi_label)

    ema_signal = str(indicators.get("ema_signal") or "neutral")
    if "bullish" in ema_signal:
        bullish += 2
        signals.append(f"EMA {ema_signal.replace('_', ' ')}")
    elif "bearish" in ema_signal:
        bearish += 2
        signals.append(f"EMA {ema_signal.replace('_', ' ')}")

    if indicators.get("core_setup_bull"):
        bullish += 2
        signals.append("CALL rule stack + futures OI + RSI aligned")
    if indicators.get("core_setup_bear"):
        bearish += 2
        signals.append("PUT rule stack + futures OI + RSI aligned")

    chain_bias = str(indicators.get("chain_bias") or "neutral")
    if chain_bias == "bullish":
        bullish += 2
        signals.append("Option chain: CE OI building near ATM")
    elif chain_bias == "bearish":
        bearish += 2
        signals.append("Option chain: PE OI building near ATM")

    if indicators.get("market_structure_hh_hl"):
        bullish += 1
        signals.append("Market structure HH/HL")
    if indicators.get("market_structure_lh_ll"):
        bearish += 1
        signals.append("Market structure LH/LL")

    if indicators.get("vwap_bull"):
        bullish += 1
        signals.append("Price above session VWAP")
    if indicators.get("vwap_bear"):
        bearish += 1
        signals.append("Price below session VWAP")

    pcr = indicators.get("chain_pcr")
    if pcr is not None:
        try:
            pcr_value = float(pcr)
            if pcr_value < 0.85:
                bullish += 1
                signals.append(f"PCR {pcr_value:.2f} (call-side lean)")
            elif pcr_value > 1.15:
                bearish += 1
                signals.append(f"PCR {pcr_value:.2f} (put-side lean)")
        except (TypeError, ValueError):
            pass

    if bullish > bearish + 1:
        pattern = "bullish"
        label = "Bullish pattern"
    elif bearish > bullish + 1:
        pattern = "bearish"
        label = "Bearish pattern"
    else:
        pattern = "neutral"
        label = "Mixed / neutral pattern"

    return {
        "pattern": pattern,
        "label": label,
        "signals": signals[:6],
        "bullish_score": bullish,
        "bearish_score": bearish,
    }


def _leg_delta(leg: Optional[OptionData]) -> Optional[float]:
    if leg is None or leg.greeks is None:
        return None
    try:
        return float(leg.greeks.delta)
    except (TypeError, ValueError):
        return None


def _format_strike(value: float | int | str) -> int:
    return int(round(float(value)))


def _pick_strike_by_delta(
    chain: OptionChainResponse,
    option_type: str,
    target_delta_abs: float,
    ltp: float,
    strike_step: int,
    offset_steps: int = 0,
) -> Optional[dict[str, Any]]:
    if not chain.strikes or ltp <= 0:
        return None

    atm_key = _nearest_strike_key(chain.strikes, ltp)
    if atm_key is None:
        return None

    try:
        fallback_strike = resolve_strike_price(
            ltp, strike_step, strike_offset=offset_steps,
        )
    except (TypeError, ValueError):
        fallback_strike = float(atm_key)

    if option_type == "CE" and offset_steps < 0:
        fallback_strike = float(atm_key) - (abs(offset_steps) * strike_step)
    elif option_type == "CE" and offset_steps > 0:
        fallback_strike = float(atm_key) + (offset_steps * strike_step)
    elif option_type == "PE" and offset_steps < 0:
        fallback_strike = float(atm_key) + (abs(offset_steps) * strike_step)
    elif option_type == "PE" and offset_steps > 0:
        fallback_strike = float(atm_key) - (offset_steps * strike_step)

    best: Optional[dict[str, Any]] = None
    best_distance = float("inf")

    for strike_key, row in chain.strikes.items():
        try:
            strike_float = float(strike_key)
        except (TypeError, ValueError):
            continue
        if abs(strike_float - ltp) > strike_step * 5.5:
            continue

        leg = row.CE if option_type == "CE" else row.PE
        if leg is None:
            continue

        delta = _leg_delta(leg)
        if delta is None:
            distance = abs(strike_float - fallback_strike)
            score = distance
        else:
            score = abs(abs(delta) - target_delta_abs)

        if score < best_distance:
            best_distance = score
            best = {
                "strike": _format_strike(strike_key),
                "option_type": option_type,
                "delta": round(delta, 3) if delta is not None else None,
                "ltp": leg.ltp,
                "oi": leg.open_interest,
                "trading_symbol": leg.trading_symbol or "",
                "expiry_date": chain.expiry_date,
            }

    if best is None:
        best = {
            "strike": _format_strike(fallback_strike),
            "option_type": option_type,
            "delta": None,
            "ltp": None,
            "oi": None,
            "trading_symbol": "",
            "expiry_date": chain.expiry_date,
        }
    return best


def _build_contract_label(strike: int, option_type: str) -> str:
    return f"{strike} {option_type}"


def recommend_option_strikes(
    chain: Optional[OptionChainResponse],
    profile: AutoTradeProfile,
    pattern: str,
    ltp: Optional[float],
) -> list[dict[str, Any]]:
    """Return CE/PE strike ideas ranked by delta tiers and pattern bias."""
    if ltp is None or ltp <= 0:
        return []

    suggestions: list[dict[str, Any]] = []

    for tier_id, tier_label, target_delta, offset in STRIKE_TIERS:
        for option_type in ("CE", "PE"):
            row: Optional[dict[str, Any]] = None
            if chain is not None and chain.success and chain.strikes:
                row = _pick_strike_by_delta(
                    chain,
                    option_type,
                    target_delta,
                    float(ltp),
                    profile.strike_step,
                    offset_steps=offset,
                )
            else:
                strike_offset = offset if option_type == "CE" else -offset
                strike = resolve_strike_price(
                    float(ltp),
                    profile.strike_step,
                    strike_offset=strike_offset,
                )
                row = {
                    "strike": _format_strike(strike),
                    "option_type": option_type,
                    "delta": None,
                    "ltp": None,
                    "oi": None,
                    "trading_symbol": "",
                    "expiry_date": chain.expiry_date if chain else None,
                }

            if row is None:
                continue

            side_bias = "bullish" if option_type == "CE" else "bearish"
            pattern_match = (
                (pattern == "bullish" and option_type == "CE")
                or (pattern == "bearish" and option_type == "PE")
                or pattern == "neutral"
            )
            priority = "high" if pattern_match and tier_id == "primary" else (
                "medium" if pattern_match else "low"
            )

            delta_text = (
                f"Δ {row['delta']:+.2f}" if row.get("delta") is not None else "Δ est. ~0.50"
            )
            premium_text = (
                f"premium ₹{row['ltp']:.2f}" if row.get("ltp") is not None else "premium —"
            )
            rationale = (
                f"{tier_label} {option_type} for manual buy — {delta_text}, {premium_text}"
            )
            if pattern == "bullish" and option_type == "CE":
                rationale = f"Bullish pattern lean — {rationale}"
            elif pattern == "bearish" and option_type == "PE":
                rationale = f"Bearish pattern lean — {rationale}"

            suggestions.append({
                "contract": _build_contract_label(row["strike"], option_type),
                "strike": row["strike"],
                "option_type": option_type,
                "tier": tier_id,
                "tier_label": tier_label,
                "delta": row.get("delta"),
                "premium": row.get("ltp"),
                "open_interest": row.get("oi"),
                "expiry_date": row.get("expiry_date"),
                "trading_symbol": row.get("trading_symbol") or "",
                "pattern_bias": side_bias,
                "priority": priority,
                "rationale": rationale,
            })

    priority_order = {"high": 0, "medium": 1, "low": 2}
    tier_order = {"primary": 0, "conservative": 1, "aggressive": 2}
    suggestions.sort(
        key=lambda item: (
            priority_order.get(str(item.get("priority")), 9),
            tier_order.get(str(item.get("tier")), 9),
            0 if item.get("option_type") == "CE" else 1,
        ),
    )
    return suggestions[:6]
