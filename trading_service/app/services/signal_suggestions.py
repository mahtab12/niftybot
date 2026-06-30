"""Human-readable AI-style trade suggestions from live signal output."""

from __future__ import annotations

from typing import Any

from app.auto_trade_profiles import AutoTradeProfile

DISCLAIMER = (
    "AI suggestion for planning only — not financial advice. "
    "Place trades manually on Groww at your own risk."
)

SUGGESTION_REFRESH_SECONDS = 30


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


def _manual_hint(action: str, profile: AutoTradeProfile, confidence: float) -> str:
    label = profile.label
    is_futures = profile.trade_kind == "futures"
    conf_pct = f"{confidence * 100:.0f}%"

    if action == "HOLD":
        return (
            f"No high-conviction {label} setup yet. Watch the live signal panel "
            "and wait for OI, EMA, and RSI to align before planning a manual trade."
        )

    if is_futures:
        if action in ("BUY_CE", "SELL_PE"):
            return (
                f"Bullish bias on {label} ({conf_pct} confidence). "
                "If trading manually, consider a long position in the nearest mini futures contract."
            )
        return (
            f"Bearish bias on {label} ({conf_pct} confidence). "
            "If trading manually, consider a short position in the nearest mini futures contract."
        )

    if action == "BUY_CE":
        return (
            f"Bullish option bias on {label} ({conf_pct} confidence). "
            "Manual idea: buy ATM or slightly ITM Call (CE) with defined stop-loss."
        )
    if action == "BUY_PE":
        return (
            f"Bearish option bias on {label} ({conf_pct} confidence). "
            "Manual idea: buy ATM or slightly ITM Put (PE) with defined stop-loss."
        )
    if action == "SELL_CE":
        return (
            f"Bearish premium bias on {label} ({conf_pct} confidence). "
            "Manual idea: sell OTM Call (CE) only if you carry margin and strict risk limits."
        )
    return (
        f"Bullish premium bias on {label} ({conf_pct} confidence). "
        "Manual idea: sell OTM Put (PE) only if you carry margin and strict risk limits."
    )


def build_ai_suggestion(
    signal: dict[str, Any],
    profile: AutoTradeProfile,
    *,
    min_entry_confidence: float = 0.75,
) -> dict[str, Any]:
    """Turn raw signal dict into a user-facing suggestion payload."""
    action = str(signal.get("action") or "HOLD").upper()
    confidence = float(signal.get("confidence") or 0.0)
    reasons = signal.get("reasons") if isinstance(signal.get("reasons"), list) else []
    indicators = signal.get("indicators") if isinstance(signal.get("indicators"), dict) else {}
    tier = _confidence_tier(confidence, action)
    conf_pct = round(confidence * 100)

    is_futures = profile.trade_kind == "futures"

    if action == "HOLD":
        bias = "neutral"
        headline = f"Wait — no clear {profile.label} setup yet"
        if reasons:
            summary = (
                f"GrassRed AI sees mixed signals on {profile.label}. "
                f"Key note: {_format_reasons(reasons)[0].lower()}."
            )
        else:
            summary = (
                f"GrassRed AI is scanning {profile.label}. "
                "Conditions are not aligned for a confident manual trade plan yet."
            )
        suggested_action = "WAIT"
    elif action in ("BUY_CE", "SELL_PE"):
        bias = "bullish"
        if is_futures:
            headline = f"Bullish bias — {profile.label} long idea"
            suggested_action = "LONG_FUT"
        else:
            headline = f"Bullish bias — consider Call (CE)"
            suggested_action = "BUY_CE"
        summary = (
            f"Multi-layer analysis points bullish on {profile.label} "
            f"({conf_pct}% confidence)."
        )
    elif action in ("BUY_PE", "SELL_CE"):
        bias = "bearish"
        if is_futures:
            headline = f"Bearish bias — {profile.label} short idea"
            suggested_action = "SHORT_FUT"
        else:
            headline = f"Bearish bias — consider Put (PE)"
            suggested_action = "BUY_PE"
        summary = (
            f"Multi-layer analysis points bearish on {profile.label} "
            f"({conf_pct}% confidence)."
        )
    else:
        bias = "neutral"
        headline = f"Monitoring {profile.label}"
        summary = f"GrassRed AI is evaluating {profile.label}."
        suggested_action = "WAIT"

    if action != "HOLD" and confidence >= min_entry_confidence:
        summary += " Meets the same 75%+ threshold used for auto-trade entries."
    elif action != "HOLD" and tier == "medium":
        summary += " Setup is developing — confirm on Groww before entering."
    elif action != "HOLD":
        summary += " Confidence is still building — patience may be better."

    factors: list[str] = []
    if indicators.get("oi_buildup"):
        factors.append(f"OI: {indicators['oi_buildup']}")
    if indicators.get("ema_signal"):
        factors.append(f"EMA: {indicators['ema_signal']}")
    rsi = indicators.get("rsi_divergence") or indicators.get("rsi")
    if rsi is not None:
        factors.append(f"RSI: {rsi}")
    if indicators.get("chain_oi_summary") and indicators.get("chain_oi_summary") != "unavailable":
        factors.append(f"Chain OI: {indicators['chain_oi_summary']}")
    if not factors:
        factors = _format_reasons(reasons)

    return {
        "headline": headline,
        "summary": summary,
        "bias": bias,
        "confidence_pct": conf_pct,
        "confidence_tier": tier,
        "suggested_action": suggested_action,
        "signal_action": action,
        "manual_hint": _manual_hint(action, profile, confidence),
        "factors": factors,
        "reasons": _format_reasons(reasons),
        "disclaimer": DISCLAIMER,
        "auto_trade_ready": action != "HOLD" and confidence >= min_entry_confidence,
    }
