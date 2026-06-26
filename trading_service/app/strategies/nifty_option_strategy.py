"""Professional Nifty/Sensex option buying — OI, EMA, RSI, VWAP, volume, chain OI."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Optional

from app.models.schemas import OptionChainResponse
from app.strategies.chain_signals import analyze_strike_oi, chain_oi_factor
from app.strategies.indicators import (
    atr_percent,
    day_high_break,
    day_low_break,
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
    rsi_in_entry_zone,
    rsi_momentum_filter,
    session_first_candle_levels,
    session_vwap,
    volume_spike_factor,
    vwap_factor,
)
from app.strategies.oi_signals import classify_oi_buildup, oi_buildup_bias

MIN_SIGNAL_CONFIDENCE = 0.75
MIN_PULLBACK_RETRACEMENT_PCT = 25.0
MIN_ADVANCED_SIGNALS = 2  # of VWAP, volume spike, chain OI


@dataclass
class StrategySignal:
    action: str  # HOLD, BUY_CE, BUY_PE
    confidence: float = 0.0
    reasons: list[str] = field(default_factory=list)
    indicators: dict[str, Any] = field(default_factory=dict)


def parse_candles(raw: list) -> list[dict[str, Any]]:
    """Parse Groww candle rows into dicts (with optional timestamp)."""
    parsed: list[dict[str, Any]] = []
    for row in raw:
        if not isinstance(row, (list, tuple)) or len(row) < 5:
            continue
        try:
            bar: dict[str, Any] = {
                "open": float(row[1]),
                "high": float(row[2]),
                "low": float(row[3]),
                "close": float(row[4]),
            }
            if len(row) >= 6:
                try:
                    bar["volume"] = float(row[5])
                except (TypeError, ValueError):
                    bar["volume"] = 0.0
            if len(row) >= 1 and row[0] is not None:
                bar["ts"] = row[0]
            parsed.append(bar)
        except (TypeError, ValueError):
            continue
    return parsed


def _entry_confidence(factors: list[str], ema_signal: str) -> float:
    """More factors (incl. VWAP, volume, chain OI) → higher confidence."""
    score = len(factors) / 8.0
    if ema_signal in ("bullish_cross", "bearish_cross"):
        score += 0.08
    return min(1.0, round(score, 4))


def _meets_confidence_threshold(factors: list[str], ema_signal: str) -> bool:
    return _entry_confidence(factors, ema_signal) >= MIN_SIGNAL_CONFIDENCE


def _has_strong_setup(factors: list[str], ema_signal: str) -> bool:
    if len(factors) >= 3:
        return True
    if ema_signal in ("bullish_cross", "bearish_cross") and len(factors) >= 2:
        return True
    return False


def _oi_factor(
    oi_type: str,
    oi_bias: str,
    closes: list[float],
    direction: str,
) -> Optional[str]:
    if oi_bias != direction or oi_type == "neutral":
        return None
    if (
        direction == "bullish"
        and oi_type == "short_covering"
        and near_swing_extreme(closes, "bullish")
    ):
        return None
    if (
        direction == "bearish"
        and oi_type == "short_buildup"
        and near_swing_extreme(closes, "bearish")
    ):
        return None
    return oi_type


def _rsi_factor(
    direction: str,
    rsi_div: str,
    rsi_momentum: str,
) -> Optional[str]:
    """RSI confirmation for the trade direction."""
    if direction == "bullish":
        if rsi_div == "bullish":
            return "rsi_divergence"
        if rsi_momentum == "supportive":
            return "rsi_momentum"
        if rsi_momentum == "neutral":
            return "rsi_neutral"
        return None
    if direction == "bearish":
        if rsi_div == "bearish":
            return "rsi_divergence"
        if rsi_momentum == "supportive":
            return "rsi_momentum"
        if rsi_momentum == "neutral":
            return "rsi_neutral"
        return None
    return None


def _core_setup_met(
    direction: str,
    oi_factor: Optional[str],
    ema_dir: str,
    rsi_factor: Optional[str],
) -> bool:
    """All three pillars — OI, EMA, RSI — must align for one direction."""
    if direction == "bullish":
        return (
            oi_factor is not None
            and ema_dir == "bullish"
            and rsi_factor is not None
        )
    if direction == "bearish":
        return (
            oi_factor is not None
            and ema_dir == "bearish"
            and rsi_factor is not None
        )
    return False


def _advanced_confirmation_met(
    direction: str,
    vwap_f: Optional[str],
    volume_f: Optional[str],
    chain_f: Optional[str],
    chain_available: bool,
) -> bool:
    """
    Require VWAP + volume + chain OI alignment (2 of 3 when chain loaded).

    Without chain data, VWAP or volume spike alone is enough.
    """
    hits = sum(1 for f in (vwap_f, volume_f, chain_f) if f)
    if chain_available:
        return hits >= MIN_ADVANCED_SIGNALS
    return vwap_f is not None or volume_f is not None


def _build_direction_factors(
    direction: str,
    oi_factor: Optional[str],
    ema_signal: str,
    ema_dir: str,
    rsi_factor: Optional[str],
    vwap_f: Optional[str],
    volume_f: Optional[str],
    chain_f: Optional[str],
    bars: list[dict[str, Any]],
    bounce_up: bool,
    bounce_down: bool,
    high_break: bool,
    low_break: bool,
) -> list[str]:
    """Collect aligned factors for one direction."""
    if ema_dir != direction:
        return []

    factors: list[str] = []
    if oi_factor:
        factors.append(oi_factor)
    factors.append(ema_signal)
    if rsi_factor:
        factors.append(rsi_factor)
    if vwap_f:
        factors.append(vwap_f)
    if volume_f:
        factors.append(volume_f)
    if chain_f:
        factors.append(chain_f)

    if direction == "bullish":
        if bounce_up:
            factors.append("ema_bounce")
        if high_break:
            factors.append("day_high_break")
        if rejection_candle(bars, "bullish"):
            factors.append("rejection_wick")
        if last_candle_confirms(bars, "bullish"):
            factors.append("candle_confirm")
    else:
        if bounce_down:
            factors.append("ema_bounce")
        if low_break:
            factors.append("day_low_break")
        if rejection_candle(bars, "bearish"):
            factors.append("rejection_wick")
        if last_candle_confirms(bars, "bearish"):
            factors.append("candle_confirm")

    return factors


def _hold_reason(
    core_bull: bool,
    core_bear: bool,
    oi_bull: Optional[str],
    oi_bear: Optional[str],
    ema_dir: str,
    rsi_bull_factor: Optional[str],
    rsi_bear_factor: Optional[str],
    bull_timing: bool,
    bear_timing: bool,
    bull_advanced: bool,
    bear_advanced: bool,
    bullish: list[str],
    bearish: list[str],
    ema_signal: str,
) -> str:
    if not core_bull and not core_bear:
        missing = []
        if oi_bull is None and oi_bear is None:
            missing.append("oi")
        if ema_dir == "neutral":
            missing.append("ema")
        if rsi_bull_factor is None and rsi_bear_factor is None:
            missing.append("rsi")
        if missing:
            return "waiting_for_" + "_".join(missing) + "_alignment"
        return "waiting_for_oi_ema_rsi_alignment"

    if core_bull and not bull_timing:
        return "bullish_setup_waiting_for_entry_timing"
    if core_bear and not bear_timing:
        return "bearish_setup_waiting_for_entry_timing"

    if core_bull and not bull_advanced:
        return "waiting_for_vwap_volume_chain_oi"
    if core_bear and not bear_advanced:
        return "waiting_for_vwap_volume_chain_oi"

    if core_bull and not _meets_confidence_threshold(bullish, ema_signal):
        return "ce_confidence_below_75pct"
    if core_bear and not _meets_confidence_threshold(bearish, ema_signal):
        return "pe_confidence_below_75pct"

    return "waiting_for_setup"


def evaluate_option_buying_signal(
    candles: list,
    futures_price_change: Optional[float],
    futures_oi_change: Optional[float],
    option_chain: Optional[OptionChainResponse] = None,
    strike_step: int = 50,
) -> StrategySignal:
    """
    Buy ATM CE or PE when the full professional stack aligns:

    Core: futures OI + EMA + RSI
    Advanced: VWAP + volume spike + strike-level chain OI (2 of 3)
    Timing: pullback bounce OR first 15m candle high/low break
    """
    bars = parse_candles(candles)
    if len(bars) < 30:
        return StrategySignal(
            action="HOLD",
            reasons=["insufficient_candle_data"],
            indicators={"candle_count": len(bars)},
        )

    closes = [b["close"] for b in bars]
    oi_type = classify_oi_buildup(futures_price_change, futures_oi_change)
    oi_bias = oi_buildup_bias(oi_type)
    ema_signal = ema_trend_signal(closes)
    ema_dir = ema_bias(ema_signal)
    rsi_div = rsi_divergence_signal(closes)
    rsi_bull = rsi_momentum_filter(closes, "bullish")
    rsi_bear = rsi_momentum_filter(closes, "bearish")
    rsi_value = latest_rsi(closes)
    rsi_ce_zone = rsi_in_entry_zone(closes, "bullish")
    rsi_pe_zone = rsi_in_entry_zone(closes, "bearish")
    volatility = atr_percent(bars)
    trend_strength = ema_trend_strength(closes)
    bounce_up = ema_pullback_bounce(closes, "bullish")
    bounce_down = ema_pullback_bounce(closes, "bearish")
    pullback_up_pct = pullback_retracement_percent(closes, "bullish")
    pullback_down_pct = pullback_retracement_percent(closes, "bearish")
    at_high = near_swing_extreme(closes, "bullish")
    at_low = near_swing_extreme(closes, "bearish")
    day_levels = session_first_candle_levels(bars)
    low_break = day_low_break(bars, closes)
    high_break = day_high_break(bars, closes)

    oi_bull = _oi_factor(oi_type, oi_bias, closes, "bullish")
    oi_bear = _oi_factor(oi_type, oi_bias, closes, "bearish")
    rsi_bull_factor = _rsi_factor("bullish", rsi_div, rsi_bull)
    rsi_bear_factor = _rsi_factor("bearish", rsi_div, rsi_bear)
    core_bull = _core_setup_met("bullish", oi_bull, ema_dir, rsi_bull_factor)
    core_bear = _core_setup_met("bearish", oi_bear, ema_dir, rsi_bear_factor)

    chain_analysis = analyze_strike_oi(option_chain, strike_step)
    chain_available = option_chain is not None and option_chain.success
    vwap_bull = vwap_factor(bars, "bullish")
    vwap_bear = vwap_factor(bars, "bearish")
    vol_bull = volume_spike_factor(bars, "bullish")
    vol_bear = volume_spike_factor(bars, "bearish")
    chain_bull = chain_oi_factor(chain_analysis, "bullish")
    chain_bear = chain_oi_factor(chain_analysis, "bearish")
    bull_advanced = _advanced_confirmation_met(
        "bullish", vwap_bull, vol_bull, chain_bull, chain_available,
    )
    bear_advanced = _advanced_confirmation_met(
        "bearish", vwap_bear, vol_bear, chain_bear, chain_available,
    )

    bullish = _build_direction_factors(
        "bullish", oi_bull, ema_signal, ema_dir, rsi_bull_factor,
        vwap_bull, vol_bull, chain_bull,
        bars, bounce_up, bounce_down, high_break, low_break,
    )
    bearish = _build_direction_factors(
        "bearish", oi_bear, ema_signal, ema_dir, rsi_bear_factor,
        vwap_bear, vol_bear, chain_bear,
        bars, bounce_up, bounce_down, high_break, low_break,
    )

    bull_confidence = _entry_confidence(bullish, ema_signal)
    bear_confidence = _entry_confidence(bearish, ema_signal)

    day_low = day_levels[0] if day_levels else None
    day_high = day_levels[1] if day_levels else None

    indicators = {
        "oi_buildup": oi_type,
        "ema_signal": ema_signal,
        "rsi_divergence": rsi_div,
        "rsi": rsi_value,
        "rsi_bullish": rsi_bull,
        "rsi_bearish": rsi_bear,
        "rsi_ce_zone": rsi_ce_zone,
        "rsi_pe_zone": rsi_pe_zone,
        "oi_aligned_bull": oi_bull is not None,
        "oi_aligned_bear": oi_bear is not None,
        "ema_aligned_bull": ema_dir == "bullish",
        "ema_aligned_bear": ema_dir == "bearish",
        "rsi_aligned_bull": rsi_bull_factor is not None,
        "rsi_aligned_bear": rsi_bear_factor is not None,
        "core_setup_bull": core_bull,
        "core_setup_bear": core_bear,
        "session_vwap": session_vwap(bars),
        "vwap_bull": vwap_bull,
        "vwap_bear": vwap_bear,
        "volume_spike_bull": vol_bull,
        "volume_spike_bear": vol_bear,
        "chain_oi_summary": chain_analysis.get("chain_oi_summary"),
        "chain_pcr": chain_analysis.get("pcr"),
        "chain_atm_strike": chain_analysis.get("atm_strike"),
        "chain_atm_ce_oi_change_pct": chain_analysis.get("atm_ce_oi_change_pct"),
        "chain_atm_pe_oi_change_pct": chain_analysis.get("atm_pe_oi_change_pct"),
        "advanced_bull": bull_advanced,
        "advanced_bear": bear_advanced,
        "atr_percent": volatility,
        "trend_strength": trend_strength,
        "at_swing_high": at_high,
        "at_swing_low": at_low,
        "day_first_candle_low": day_low,
        "day_first_candle_high": day_high,
        "day_low_break": low_break,
        "day_high_break": high_break,
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

    if volatility is not None and volatility > 0.9:
        return StrategySignal(
            action="HOLD",
            confidence=0.0,
            reasons=["volatility_too_high_for_buying"],
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

    bull_timing = (
        (bounce_up and pullback_up_ok) or high_break
    ) and (
        last_candle_confirms(bars, "bullish")
        or rejection_candle(bars, "bullish")
    )
    bear_timing = (
        (bounce_down and pullback_down_ok) or low_break
    ) and (
        last_candle_confirms(bars, "bearish")
        or rejection_candle(bars, "bearish")
    )

    bullish_ok = (
        core_bull
        and bull_advanced
        and len(bearish) == 0
        and not at_high
        and rsi_ce_zone in ("ok", "weak")
        and rsi_bull != "overbought"
        and bull_timing
        and (trend_strength is None or trend_strength >= 0.04)
        and _has_strong_setup(bullish, ema_signal)
        and _meets_confidence_threshold(bullish, ema_signal)
    )
    if bullish_ok:
        tag = "day_high_break" if high_break else "pullback_complete"
        return StrategySignal(
            action="BUY_CE",
            confidence=bull_confidence,
            reasons=bullish + [tag],
            indicators=indicators,
        )

    bearish_ok = (
        core_bear
        and bear_advanced
        and len(bullish) == 0
        and not at_low
        and rsi_pe_zone in ("ok", "weak")
        and rsi_bear != "oversold"
        and bear_timing
        and (trend_strength is None or trend_strength >= 0.04)
        and _has_strong_setup(bearish, ema_signal)
        and _meets_confidence_threshold(bearish, ema_signal)
    )
    if bearish_ok:
        tag = "day_low_break" if low_break else "pullback_complete"
        return StrategySignal(
            action="BUY_PE",
            confidence=bear_confidence,
            reasons=bearish + [tag],
            indicators=indicators,
        )

    hold_reason = _hold_reason(
        core_bull, core_bear, oi_bull, oi_bear, ema_dir,
        rsi_bull_factor, rsi_bear_factor,
        bull_timing, bear_timing,
        bull_advanced, bear_advanced,
        bullish, bearish, ema_signal,
    )

    return StrategySignal(
        action="HOLD",
        confidence=max(bull_confidence, bear_confidence),
        reasons=[hold_reason],
        indicators=indicators,
    )


evaluate_nifty_option_signal = evaluate_option_buying_signal
