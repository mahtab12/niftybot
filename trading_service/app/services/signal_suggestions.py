"""Human-readable AI-style trade suggestions from live signal output."""

from __future__ import annotations

from typing import Any, Optional

from app.auto_trade_profiles import AutoTradeProfile
from app.models.schemas import OptionChainResponse
from app.services.strike_suggestions import detect_market_pattern, recommend_option_strikes

DISCLAIMER = (
    "AI suggestion for planning only — not financial advice. "
    "Place trades manually on Groww at your own risk."
)

SUGGESTION_REFRESH_SECONDS = 30

_TRIGGER_LABELS = {
    "confirmation_high": "confirmation candle high",
    "confirmation_high_triggered": "confirmation high (broken)",
    "confirmation_low": "confirmation candle low",
    "confirmation_low_triggered": "confirmation low (broken)",
    "vwap": "session VWAP",
    "ema20": "EMA 20",
    "nearest_overhead": "nearest overhead level",
    "nearest_support": "nearest support level",
    "session_open_high": "first 15m candle high",
    "session_open_low": "first 15m candle low",
    "atr_buffer": "ATR-based buffer",
    "none": "level unavailable",
}


def signal_is_stale(last_check_at: str | None, max_age: int = SUGGESTION_REFRESH_SECONDS) -> bool:
    if not last_check_at:
        return True
    try:
        from datetime import datetime

        checked = datetime.fromisoformat(last_check_at)
        return (datetime.now() - checked).total_seconds() >= max_age
    except (TypeError, ValueError):
        return True


def _confidence_tier(confidence: float, action: str) -> str:
    if action == "HOLD":
        return "wait"
    if confidence >= 0.75:
        return "high"
    if confidence >= 0.55:
        return "medium"
    return "low"


def _format_reasons(reasons: list[str]) -> list[str]:
    labels: list[str] = []
    for raw in reasons[:6]:
        text = str(raw).replace("_", " ").strip()
        if text:
            labels.append(text[:1].upper() + text[1:])
    return labels


def _format_price(value: float | None) -> str:
    if value is None:
        return "—"
    return f"{value:,.2f}"


def _format_points(value: float | None) -> str:
    if value is None:
        return "—"
    rounded = round(value, 1)
    if abs(rounded - round(rounded)) < 0.05:
        return str(int(round(rounded)))
    return f"{rounded:.1f}"


def _trigger_label(source: str | None) -> str:
    if not source:
        return "key level"
    return _TRIGGER_LABELS.get(source, source.replace("_", " "))


def _align_setup_levels(setup: dict[str, Any], live_ltp: float | None) -> dict[str, Any]:
    """Keep trigger distances consistent with the LTP shown in the UI."""
    if not setup or live_ltp is None or live_ltp <= 0:
        return setup

    out = dict(setup)
    ref = float(live_ltp)
    out["ltp"] = round(ref, 2)

    ce = out.get("ce_trigger_price")
    if ce is not None:
        ce_f = float(ce)
        if ce_f > ref:
            out["ce_points_away"] = round(ce_f - ref, 2)
        else:
            out["ce_points_away"] = 0.0

    pe = out.get("pe_trigger_price")
    if pe is not None:
        pe_f = float(pe)
        if pe_f < ref:
            out["pe_points_away"] = round(ref - pe_f, 2)
        else:
            out["pe_points_away"] = 0.0

    return out


def _build_call_setup(
    label: str,
    setup: dict[str, Any],
    ltp: float | None,
    rules_met: int | None,
    rules_total: int | None,
    confidence_pct: int,
) -> dict[str, Any]:
    trigger = setup.get("ce_trigger_price")
    points = setup.get("ce_points_away")
    triggered = bool(setup.get("ce_triggered"))
    source = setup.get("ce_trigger_source")
    ltp_value = ltp if ltp is not None else setup.get("ltp")

    if trigger is None or ltp_value is None:
        message = f"Call buy setup on {label}: waiting for enough candle data to compute a trigger."
        status = "waiting"
    elif ltp_value is not None and trigger is not None and float(trigger) <= float(ltp_value):
        message = (
            f"Call buy setup on {label}: price already above {_format_price(trigger)} "
            f"({_trigger_label(source)}). Waiting for the next overhead level."
        )
        status = "above_trigger"
    elif triggered:
        message = (
            f"Call buy setup on {label}: confirmation high break done at {_format_price(trigger)}. "
            f"Rules {rules_met}/{rules_total} · setup strength {confidence_pct}%."
        )
        status = "active"
    elif points is not None and points <= 0:
        message = (
            f"Call buy setup on {label}: at trigger {_format_price(trigger)} "
            f"({_trigger_label(source)}). Watch for a clean break before buying CE."
        )
        status = "at_trigger"
    else:
        message = (
            f"Call buy setup on {label}: buy CE when price crosses "
            f"{_format_price(trigger)} (+{_format_points(points)} pts from "
            f"current {_format_price(ltp_value)}). Level: {_trigger_label(source)}."
        )
        status = "waiting"

    return {
        "side": "call",
        "title": "Call (CE) buy setup",
        "status": status,
        "ltp": ltp_value,
        "trigger_price": trigger,
        "points_away": points,
        "trigger_source": source,
        "trigger_label": _trigger_label(source),
        "rules_met": rules_met,
        "rules_total": rules_total,
        "confidence_pct": confidence_pct,
        "message": message,
    }


def _build_put_setup(
    label: str,
    setup: dict[str, Any],
    ltp: float | None,
    rules_met: int | None,
    rules_total: int | None,
    confidence_pct: int,
) -> dict[str, Any]:
    trigger = setup.get("pe_trigger_price")
    points = setup.get("pe_points_away")
    triggered = bool(setup.get("pe_triggered"))
    source = setup.get("pe_trigger_source")
    ltp_value = ltp if ltp is not None else setup.get("ltp")

    if trigger is None or ltp_value is None:
        message = f"Put buy setup on {label}: waiting for enough candle data to compute a trigger."
        status = "waiting"
    elif ltp_value is not None and trigger is not None and float(trigger) >= float(ltp_value):
        message = (
            f"Put buy setup on {label}: price already below {_format_price(trigger)} "
            f"({_trigger_label(source)}). Waiting for the next support level."
        )
        status = "below_trigger"
    elif triggered:
        message = (
            f"Put buy setup on {label}: confirmation low break done at {_format_price(trigger)}. "
            f"Rules {rules_met}/{rules_total} · setup strength {confidence_pct}%."
        )
        status = "active"
    elif points is not None and points <= 0:
        message = (
            f"Put buy setup on {label}: at trigger {_format_price(trigger)} "
            f"({_trigger_label(source)}). Watch for a clean break before buying PE."
        )
        status = "at_trigger"
    else:
        message = (
            f"Put buy setup on {label}: buy PE if price falls "
            f"{_format_points(points)} pts to {_format_price(trigger)} "
            f"(from current {_format_price(ltp_value)}). Level: {_trigger_label(source)}."
        )
        status = "waiting"

    return {
        "side": "put",
        "title": "Put (PE) buy setup",
        "status": status,
        "ltp": ltp_value,
        "trigger_price": trigger,
        "points_away": points,
        "trigger_source": source,
        "trigger_label": _trigger_label(source),
        "rules_met": rules_met,
        "rules_total": rules_total,
        "confidence_pct": confidence_pct,
        "message": message,
    }


def build_ai_suggestion(
    signal: dict[str, Any],
    profile: AutoTradeProfile,
    *,
    underlying_ltp: float | None = None,
    option_chain: Optional[OptionChainResponse] = None,
    min_entry_confidence: float = 0.75,
) -> dict[str, Any]:
    """Turn raw signal dict into a user-facing suggestion payload."""
    action = str(signal.get("action") or "HOLD").upper()
    confidence = float(signal.get("confidence") or 0.0)
    reasons = signal.get("reasons") if isinstance(signal.get("reasons"), list) else []
    indicators = signal.get("indicators") if isinstance(signal.get("indicators"), dict) else {}
    tier = _confidence_tier(confidence, action)
    conf_pct = round(confidence * 100)

    setup = indicators.get("setup_levels") if isinstance(indicators.get("setup_levels"), dict) else {}
    ltp = underlying_ltp
    if ltp is None and setup.get("ltp") is not None:
        ltp = float(setup["ltp"])
    if ltp is None and indicators.get("last_close") is not None:
        ltp = float(indicators["last_close"])
    setup = _align_setup_levels(setup, ltp)

    ce_met = indicators.get("ce_rules_met")
    ce_total = indicators.get("ce_rules_total")
    pe_met = indicators.get("pe_rules_met")
    pe_total = indicators.get("pe_rules_total")
    bull_conf = indicators.get("bullish_confidence")
    bear_conf = indicators.get("bearish_confidence")
    call_conf_pct = round(float(bull_conf) * 100) if bull_conf is not None else conf_pct
    put_conf_pct = round(float(bear_conf) * 100) if bear_conf is not None else conf_pct

    call_setup = _build_call_setup(
        profile.label,
        setup,
        ltp,
        int(ce_met) if ce_met is not None else None,
        int(ce_total) if ce_total is not None else None,
        call_conf_pct,
    )
    put_setup = _build_put_setup(
        profile.label,
        setup,
        ltp,
        int(pe_met) if pe_met is not None else None,
        int(pe_total) if pe_total is not None else None,
        put_conf_pct,
    )

    market_pattern = detect_market_pattern(indicators)
    pattern = market_pattern.get("pattern", "neutral")
    trade_suggestions: list[dict[str, Any]] = []
    if profile.trade_kind == "options":
        trade_suggestions = recommend_option_strikes(
            option_chain,
            profile,
            str(pattern),
            float(ltp) if ltp is not None else None,
        )
        for item in trade_suggestions:
            if item.get("option_type") == "CE" and item.get("tier") == "primary":
                call_setup["recommended_contract"] = item.get("contract")
                call_setup["recommended_delta"] = item.get("delta")
                call_setup["recommended_premium"] = item.get("premium")
            if item.get("option_type") == "PE" and item.get("tier") == "primary":
                put_setup["recommended_contract"] = item.get("contract")
                put_setup["recommended_delta"] = item.get("delta")
                put_setup["recommended_premium"] = item.get("premium")

    if action in ("BUY_CE", "SELL_PE"):
        bias = "bullish"
        headline = market_pattern.get("label", "Bullish pattern")
    elif action in ("BUY_PE", "SELL_CE"):
        bias = "bearish"
        headline = market_pattern.get("label", "Bearish pattern")
    else:
        bias = str(pattern)
        headline = market_pattern.get("label", "Mixed / neutral pattern")

    summary_parts = [call_setup["message"], put_setup["message"]]
    summary = " ".join(summary_parts)

    if action != "HOLD" and confidence >= min_entry_confidence:
        summary += " Live signal meets the 75%+ auto-trade entry threshold."
    elif reasons:
        summary += f" Note: {_format_reasons(reasons)[0].lower()}."

    factors: list[str] = []
    if ltp is not None:
        factors.append(f"LTP: {_format_price(ltp)}")
    if call_setup.get("trigger_price") is not None:
        if call_setup.get("status") == "above_trigger":
            factors.append(
                f"CE trigger: price above prior level "
                f"{_format_price(call_setup['trigger_price'])}"
            )
        else:
            factors.append(
                f"CE trigger: {_format_price(call_setup['trigger_price'])} "
                f"(+{_format_points(call_setup.get('points_away'))} pts)"
            )
    if put_setup.get("trigger_price") is not None:
        if put_setup.get("status") == "below_trigger":
            factors.append(
                f"PE trigger: price below prior level "
                f"{_format_price(put_setup['trigger_price'])}"
            )
        else:
            factors.append(
                f"PE trigger: {_format_price(put_setup['trigger_price'])} "
                f"(-{_format_points(put_setup.get('points_away'))} pts)"
            )
    if ce_met is not None and ce_total is not None:
        factors.append(f"CALL rules: {ce_met}/{ce_total}")
    if pe_met is not None and pe_total is not None:
        factors.append(f"PUT rules: {pe_met}/{pe_total}")
    if indicators.get("oi_buildup"):
        factors.append(f"OI: {indicators['oi_buildup']}")
    if indicators.get("ema_signal"):
        factors.append(f"EMA: {indicators['ema_signal']}")
    if indicators.get("adx") is not None:
        factors.append(f"ADX: {indicators['adx']}")
    rsi = indicators.get("rsi_divergence") or indicators.get("rsi")
    if rsi is not None:
        factors.append(f"RSI: {rsi}")
    if indicators.get("chain_oi_summary") and indicators.get("chain_oi_summary") != "unavailable":
        factors.append(f"Chain OI: {indicators['chain_oi_summary']}")
    for pattern_signal in market_pattern.get("signals") or []:
        factors.append(str(pattern_signal))
    if trade_suggestions:
        top = trade_suggestions[0]
        factors.append(
            f"Top pick: {top.get('contract')} ({top.get('tier_label', 'ATM')})"
        )
    if not factors:
        factors = _format_reasons(reasons)

    suggested_action = "WAIT"
    if action == "BUY_CE":
        suggested_action = "BUY_CE"
    elif action == "BUY_PE":
        suggested_action = "BUY_PE"
    elif profile.trade_kind == "futures":
        if call_conf_pct >= put_conf_pct and call_conf_pct >= 55:
            suggested_action = "LONG_FUT"
        elif put_conf_pct > call_conf_pct and put_conf_pct >= 55:
            suggested_action = "SHORT_FUT"

    manual_hint = (
        f"{call_setup['title']}: {call_setup['message']} "
        f"{put_setup['title']}: {put_setup['message']}"
    )

    return {
        "headline": headline,
        "summary": summary,
        "bias": bias,
        "confidence_pct": conf_pct,
        "confidence_tier": tier,
        "suggested_action": suggested_action,
        "signal_action": action,
        "manual_hint": manual_hint,
        "call_setup": call_setup,
        "put_setup": put_setup,
        "market_pattern": market_pattern,
        "trade_suggestions": trade_suggestions,
        "factors": factors,
        "reasons": _format_reasons(reasons),
        "disclaimer": DISCLAIMER,
        "auto_trade_ready": action != "HOLD" and confidence >= min_entry_confidence,
        "ltp": ltp,
    }
