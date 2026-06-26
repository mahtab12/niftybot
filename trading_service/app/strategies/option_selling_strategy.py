"""Index option selling — OI + EMA + RSI align; then sell OTM CE or PE."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Optional

from app.strategies.indicators import (
    atr_percent,
    ema_bias,
    ema_pullback_bounce,
    ema_trend_signal,
    ema_trend_strength,
    last_candle_confirms,
    latest_rsi,
    near_swing_extreme,
    pullback_retracement_percent,
    rejection_candle,
    rsi_divergence_signal,
    rsi_momentum_filter,
)
from app.strategies.nifty_option_strategy import (
    MIN_PULLBACK_RETRACEMENT_PCT,
    MIN_SIGNAL_CONFIDENCE,
    _build_direction_factors,
    _core_setup_met,
    _entry_confidence,
    _has_strong_setup,
    _meets_confidence_threshold,
    _oi_factor,
    _rsi_factor,
    parse_candles,
)
from app.strategies.oi_signals import classify_oi_buildup, oi_buildup_bias


@dataclass
class StrategySignal:
    action: str  # HOLD, SELL_CE, SELL_PE
    confidence: float = 0.0
    reasons: list[str] = field(default_factory=list)
    indicators: dict[str, Any] = field(default_factory=dict)


def evaluate_option_selling_signal(
    candles: list,
    futures_price_change: Optional[float],
    futures_oi_change: Optional[float],
) -> StrategySignal:
    """
    Sell OTM options when OI, EMA, and RSI align — then after pullback/rejection.

    SELL_PE: bullish OI+EMA+RSI, dip completed.
    SELL_CE: bearish OI+EMA+RSI, rally completed.
    """
    bars = parse_candles(candles)
    if len(bars) < 30:
        return StrategySignal(
            action="HOLD",
            reasons=["insufficient_candle_data"],
            indicators={"candle_count": len(bars)},
        )

    closes = [b["close"] for b in bars]
    rsi_value = latest_rsi(closes)
    oi_type = classify_oi_buildup(futures_price_change, futures_oi_change)
    oi_bias = oi_buildup_bias(oi_type)
    ema_signal = ema_trend_signal(closes)
    ema_dir = ema_bias(ema_signal)
    rsi_div = rsi_divergence_signal(closes)
    rsi_bull = rsi_momentum_filter(closes, "bullish")
    rsi_bear = rsi_momentum_filter(closes, "bearish")
    volatility = atr_percent(bars)
    trend_strength = ema_trend_strength(closes)
    bounce_up = ema_pullback_bounce(closes, "bullish")
    bounce_down = ema_pullback_bounce(closes, "bearish")
    pullback_up_pct = pullback_retracement_percent(closes, "bullish")
    pullback_down_pct = pullback_retracement_percent(closes, "bearish")
    at_high = near_swing_extreme(closes, "bullish")
    at_low = near_swing_extreme(closes, "bearish")

    oi_bull = _oi_factor(oi_type, oi_bias, closes, "bullish")
    oi_bear = _oi_factor(oi_type, oi_bias, closes, "bearish")
    rsi_bull_factor = _rsi_factor("bullish", rsi_div, rsi_bull)
    rsi_bear_factor = _rsi_factor("bearish", rsi_div, rsi_bear)
    core_bull = _core_setup_met("bullish", oi_bull, ema_dir, rsi_bull_factor)
    core_bear = _core_setup_met("bearish", oi_bear, ema_dir, rsi_bear_factor)

    bullish = _build_direction_factors(
        "bullish", oi_bull, ema_signal, ema_dir, rsi_bull_factor,
        bars, bounce_up, bounce_down, False, False,
    )
    bearish = _build_direction_factors(
        "bearish", oi_bear, ema_signal, ema_dir, rsi_bear_factor,
        bars, bounce_up, bounce_down, False, False,
    )

    bull_confidence = _entry_confidence(bullish, ema_signal)
    bear_confidence = _entry_confidence(bearish, ema_signal)

    indicators = {
        "oi_buildup": oi_type,
        "ema_signal": ema_signal,
        "rsi": rsi_value,
        "rsi_bullish": rsi_bull,
        "rsi_bearish": rsi_bear,
        "oi_aligned_bull": oi_bull is not None,
        "oi_aligned_bear": oi_bear is not None,
        "ema_aligned_bull": ema_dir == "bullish",
        "ema_aligned_bear": ema_dir == "bearish",
        "rsi_aligned_bull": rsi_bull_factor is not None,
        "rsi_aligned_bear": rsi_bear_factor is not None,
        "core_setup_bull": core_bull,
        "core_setup_bear": core_bear,
        "atr_percent": volatility,
        "trend_strength": trend_strength,
        "ema_bounce_up": bounce_up,
        "ema_bounce_down": bounce_down,
        "pullback_retracement_up_pct": pullback_up_pct,
        "pullback_retracement_down_pct": pullback_down_pct,
        "min_confidence_required": MIN_SIGNAL_CONFIDENCE,
        "bullish_confidence": bull_confidence,
        "bearish_confidence": bear_confidence,
        "last_close": closes[-1],
        "bullish_factors": bullish,
        "bearish_factors": bearish,
    }

    if volatility is not None and volatility > 1.2:
        return StrategySignal(
            action="HOLD",
            confidence=0.0,
            reasons=["volatility_too_high_for_selling"],
            indicators=indicators,
        )

    pullback_up_ok = (
        pullback_up_pct is not None
        and pullback_up_pct >= MIN_PULLBACK_RETRACEMENT_PCT
    )
    pullback_down_ok = (
        pullback_down_pct is not None
        and pullback_down_pct >= MIN_PULLBACK_RETRACEMENT_PCT
    )

    sell_pe_timing = (
        bounce_up
        and pullback_up_ok
        and (
            rejection_candle(bars, "bullish")
            or last_candle_confirms(bars, "bullish")
        )
    )
    sell_ce_timing = (
        bounce_down
        and pullback_down_ok
        and (
            rejection_candle(bars, "bearish")
            or last_candle_confirms(bars, "bearish")
        )
    )

    sell_pe_ok = (
        core_bull
        and len(bearish) == 0
        and not at_high
        and rsi_bull != "overbought"
        and (rsi_value is None or rsi_value < 68)
        and sell_pe_timing
        and (trend_strength is None or trend_strength >= 0.04)
        and _has_strong_setup(bullish, ema_signal)
        and _meets_confidence_threshold(bullish, ema_signal)
    )
    if sell_pe_ok:
        return StrategySignal(
            action="SELL_PE",
            confidence=bull_confidence,
            reasons=bullish + ["pullback_complete"],
            indicators=indicators,
        )

    sell_ce_ok = (
        core_bear
        and len(bullish) == 0
        and not at_low
        and rsi_bear != "oversold"
        and (rsi_value is None or rsi_value > 32)
        and sell_ce_timing
        and (trend_strength is None or trend_strength >= 0.04)
        and _has_strong_setup(bearish, ema_signal)
        and _meets_confidence_threshold(bearish, ema_signal)
    )
    if sell_ce_ok:
        return StrategySignal(
            action="SELL_CE",
            confidence=bear_confidence,
            reasons=bearish + ["pullback_complete"],
            indicators=indicators,
        )

    hold_reason = "waiting_for_oi_ema_rsi_alignment"
    if core_bull and not sell_pe_timing:
        hold_reason = "bullish_oi_ema_rsi_waiting_for_pullback"
    elif core_bear and not sell_ce_timing:
        hold_reason = "bearish_oi_ema_rsi_waiting_for_pullback"
    elif core_bull and not _meets_confidence_threshold(bullish, ema_signal):
        hold_reason = "confidence_below_75pct"
    elif core_bear and not _meets_confidence_threshold(bearish, ema_signal):
        hold_reason = "confidence_below_75pct"

    return StrategySignal(
        action="HOLD",
        confidence=max(bull_confidence, bear_confidence),
        reasons=[hold_reason],
        indicators=indicators,
    )
