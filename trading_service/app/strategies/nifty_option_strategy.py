"""Professional Nifty/Sensex/MCX option buying — layered indicator confluence."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Optional

from app.models.schemas import OptionChainResponse
from app.strategies.chain_signals import analyze_strike_oi, chain_oi_factor
from app.strategies.indicators import (
    adx_above_threshold,
    atr_percent,
    confirmation_candle_formed,
    confirmation_candle_high_break,
    confirmation_candle_low_break,
    compute_setup_levels,
    day_high_break,
    day_low_break,
    ema_bias,
    ema_pullback_bounce,
    ema_stack_bearish,
    ema_stack_bullish,
    ema_triple_stack_bearish,
    ema_triple_stack_bullish,
    ema_trend_signal,
    ema_trend_strength,
    is_late_session,
    last_candle_confirms,
    latest_adx,
    latest_atr,
    latest_ema_value,
    latest_rsi,
    market_structure_hh_hl,
    market_structure_lh_ll,
    near_swing_extreme,
    price_above_session_vwap,
    price_below_ema,
    price_below_session_vwap,
    price_ranging_around_vwap,
    pullback_retracement_percent,
    pullback_to_ema_or_vwap,
    rejection_candle,
    rsi_divergence_signal,
    rsi_in_entry_zone,
    rsi_in_range,
    rsi_momentum_filter,
    session_first_candle_levels,
    session_vwap,
    volume_above_average,
    volume_above_ma,
    volume_moving_average,
    volume_spike_factor,
    vwap_factor,
)
from app.strategies.oi_signals import classify_oi_buildup, oi_buildup_bias

MIN_SIGNAL_CONFIDENCE = 0.75
MIN_PULLBACK_RETRACEMENT_PCT = 25.0
MIN_ADVANCED_SIGNALS = 2  # of VWAP, volume spike, chain OI

ENTRY_EMA_FAST = 20
ENTRY_EMA_MID = 50
ENTRY_EMA_SLOW = 200
VOLUME_MA_PERIOD = 20
RSI_PERIOD = 14
ADX_PERIOD = 14
ATR_PERIOD = 14
ADX_MIN = 25.0
ADX_REJECT = 20.0
CE_RSI_MIN = 55.0
CE_RSI_MAX = 75.0
PE_RSI_MIN = 25.0
PE_RSI_MAX = 45.0
MIN_ENTRY_BARS = 210


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
    """Legacy factor-count score for option selling (fewer aligned signals)."""
    score = len(factors) / 12.0
    if ema_signal in ("bullish_cross", "bearish_cross"):
        score += 0.06
    return min(1.0, round(score, 4))


def _setup_confidence(
    user_passed: list[str],
    user_total: int,
    oi_factor: Optional[str],
    rsi_factor: Optional[str],
    chain_f: Optional[str],
    ema_signal: str,
    bars: list[dict[str, Any]],
    direction: str,
) -> float:
    """
    Option-buying setup strength: 70% entry-rule pass rate + 30% OI/RSI/chain layers.

    Prevents inflated 100% readings when many individual rule names align but not all
    10 entry rules (and chain OI) required for a trade are satisfied.
    """
    if user_total <= 0:
        return 0.0

    rule_score = len(user_passed) / user_total
    candle_confirm = (
        direction == "bullish" and last_candle_confirms(bars, "bullish")
    ) or (
        direction == "bearish" and last_candle_confirms(bars, "bearish")
    )
    extras = sum([
        oi_factor is not None,
        rsi_factor is not None,
        chain_f is not None,
        ema_signal not in ("neutral",),
        candle_confirm,
    ])
    extra_score = extras / 5.0
    score = rule_score * 0.70 + extra_score * 0.30
    if ema_signal in ("bullish_cross", "bearish_cross"):
        score += 0.05
    return min(1.0, round(score, 4))


def _meets_confidence_threshold(
    factors_or_confidence: list[str] | float,
    ema_signal: str = "neutral",
) -> bool:
    if isinstance(factors_or_confidence, list):
        return _entry_confidence(factors_or_confidence, ema_signal) >= MIN_SIGNAL_CONFIDENCE
    return float(factors_or_confidence) >= MIN_SIGNAL_CONFIDENCE


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


def _global_rejects(
    bars: list[dict[str, Any]],
    *,
    apply_entry_cutoff: bool = True,
) -> list[str]:
    """Hard rejects applied to both CALL and PUT setups."""
    rejects: list[str] = []
    adx_value = latest_adx(bars, ADX_PERIOD)
    if adx_value is not None and adx_value < ADX_REJECT:
        rejects.append("adx_below_20")
    if price_ranging_around_vwap(bars):
        rejects.append("ranging_around_vwap")
    if not volume_above_ma(bars, VOLUME_MA_PERIOD):
        rejects.append("volume_below_average")
    if apply_entry_cutoff and is_late_session(bars):
        rejects.append("after_245pm_cutoff")
    return rejects


def _evaluate_ce_rules(
    bars: list[dict[str, Any]],
    closes: list[float],
) -> tuple[dict[str, bool], list[str]]:
    """CALL (CE) entry — all conditions must pass."""
    rules = {
        "price_above_vwap": price_above_session_vwap(bars),
        "ema20_above_ema50": ema_stack_bullish(
            closes, ENTRY_EMA_FAST, ENTRY_EMA_MID,
        ),
        "ema50_above_ema200": ema_triple_stack_bullish(
            closes, ENTRY_EMA_FAST, ENTRY_EMA_MID, ENTRY_EMA_SLOW,
        ),
        "adx_above_25": adx_above_threshold(bars, ADX_MIN, ADX_PERIOD),
        "rsi_55_75": rsi_in_range(
            closes, CE_RSI_MIN, CE_RSI_MAX, RSI_PERIOD,
        ),
        "volume_above_20ma": volume_above_ma(bars, VOLUME_MA_PERIOD),
        "market_structure_hh_hl": market_structure_hh_hl(bars),
        "pullback_ema20_or_vwap": pullback_to_ema_or_vwap(
            bars, closes, "bullish", ENTRY_EMA_FAST,
        ),
        "bullish_confirmation_candle": confirmation_candle_formed(
            bars, "bullish",
        ),
        "confirmation_high_break": confirmation_candle_high_break(bars),
    }
    passed = [name for name, ok in rules.items() if ok]
    return rules, passed


def _evaluate_pe_rules(
    bars: list[dict[str, Any]],
    closes: list[float],
) -> tuple[dict[str, bool], list[str]]:
    """PUT (PE) entry — all conditions must pass."""
    rules = {
        "price_below_vwap": price_below_session_vwap(bars),
        "ema20_below_ema50": ema_stack_bearish(
            closes, ENTRY_EMA_FAST, ENTRY_EMA_MID,
        ),
        "ema50_below_ema200": ema_triple_stack_bearish(
            closes, ENTRY_EMA_FAST, ENTRY_EMA_MID, ENTRY_EMA_SLOW,
        ),
        "adx_above_25": adx_above_threshold(bars, ADX_MIN, ADX_PERIOD),
        "rsi_25_45": rsi_in_range(
            closes, PE_RSI_MIN, PE_RSI_MAX, RSI_PERIOD,
        ),
        "volume_above_20ma": volume_above_ma(bars, VOLUME_MA_PERIOD),
        "market_structure_lh_ll": market_structure_lh_ll(bars),
        "pullback_ema20_or_vwap": pullback_to_ema_or_vwap(
            bars, closes, "bearish", ENTRY_EMA_FAST,
        ),
        "bearish_confirmation_candle": confirmation_candle_formed(
            bars, "bearish",
        ),
        "confirmation_low_break": confirmation_candle_low_break(bars),
    }
    passed = [name for name, ok in rules.items() if ok]
    return rules, passed


def _first_missing_rule(rules: dict[str, bool]) -> str:
    for name, ok in rules.items():
        if not ok:
            return f"waiting_for_{name}"
    return "waiting_for_setup"


def _rule_confidence(passed_count: int, total: int) -> float:
    if total <= 0:
        return 0.0
    return round(passed_count / total, 4)


def _professional_hold_reason(
    user_rules: dict[str, bool],
    rejects: list[str],
    oi_factor: Optional[str],
    chain_f: Optional[str],
    chain_available: bool,
    at_extreme: bool,
    volatility_block: bool,
    confidence: float,
) -> str:
    if rejects:
        return rejects[0]
    missing = _first_missing_rule(user_rules)
    if missing != "waiting_for_setup":
        return missing
    if volatility_block:
        return "volatility_too_high_for_buying"
    if at_extreme:
        return "at_swing_extreme"
    if oi_factor is None:
        return "waiting_for_oi_alignment"
    if chain_available and chain_f is None:
        return "waiting_for_chain_oi"
    if confidence < MIN_SIGNAL_CONFIDENCE:
        return "confidence_below_75pct"
    return "waiting_for_setup"


def _build_professional_factors(
    direction: str,
    user_passed: list[str],
    oi_factor: Optional[str],
    rsi_factor: Optional[str],
    chain_f: Optional[str],
    ema_signal: str,
    bars: list[dict[str, Any]],
) -> list[str]:
    factors = list(user_passed)
    if oi_factor:
        factors.append(oi_factor)
    if rsi_factor:
        factors.append(rsi_factor)
    if chain_f:
        factors.append(chain_f)
    if ema_signal not in ("neutral",):
        factors.append(ema_signal)
    if direction == "bullish" and last_candle_confirms(bars, "bullish"):
        factors.append("candle_confirm")
    if direction == "bearish" and last_candle_confirms(bars, "bearish"):
        factors.append("candle_confirm")
    return factors


def evaluate_option_buying_signal(
    candles: list,
    futures_price_change: Optional[float],
    futures_oi_change: Optional[float],
    option_chain: Optional[OptionChainResponse] = None,
    strike_step: int = 50,
    apply_entry_cutoff: bool = True,
    live_ltp: Optional[float] = None,
) -> StrategySignal:
    """
    Professional option buying with full indicator stack.

    Indicators: EMA 20/50/200, VWAP, ADX(14), ATR(14), RSI(14), volume MA(20).

    CALL/PUT rule sets (all must pass) plus futures OI and chain OI confirmation.

    apply_entry_cutoff: when True (Nifty/Sensex), reject entries after 2:45 PM IST.
    MCX crude/gold pass False so evening session entries remain allowed.
    """
    bars = parse_candles(candles)
    if len(bars) < MIN_ENTRY_BARS:
        return StrategySignal(
            action="HOLD",
            reasons=[f"insufficient_candle_data ({len(bars)}/{MIN_ENTRY_BARS} bars)"],
            indicators={
                "candle_count": len(bars),
                "min_bars_required": MIN_ENTRY_BARS,
            },
        )

    closes = [b["close"] for b in bars]
    rejects = _global_rejects(bars, apply_entry_cutoff=apply_entry_cutoff)
    ce_rules, ce_passed = _evaluate_ce_rules(bars, closes)
    pe_rules, pe_passed = _evaluate_pe_rules(bars, closes)
    ce_total = len(ce_rules)
    pe_total = len(pe_rules)
    ce_user_ok = len(ce_passed) == ce_total
    pe_user_ok = len(pe_passed) == pe_total

    ema_20 = latest_ema_value(closes, ENTRY_EMA_FAST)
    ema_50 = latest_ema_value(closes, ENTRY_EMA_MID)
    ema_200 = latest_ema_value(closes, ENTRY_EMA_SLOW)
    ema_signal = ema_trend_signal(
        closes, fast=ENTRY_EMA_FAST, slow=ENTRY_EMA_MID,
    )
    ema_dir = ema_bias(ema_signal)
    adx_value = latest_adx(bars, ADX_PERIOD)
    atr_value = latest_atr(bars, ATR_PERIOD)
    atr_pct = atr_percent(bars, ATR_PERIOD)
    vwap = session_vwap(bars)
    vol_ma = volume_moving_average(bars, VOLUME_MA_PERIOD)
    rsi_value = latest_rsi(closes, RSI_PERIOD)
    trend_strength = ema_trend_strength(
        closes, fast=ENTRY_EMA_FAST, slow=ENTRY_EMA_MID,
    )

    oi_type = classify_oi_buildup(futures_price_change, futures_oi_change)
    oi_bias = oi_buildup_bias(oi_type)
    oi_bull = _oi_factor(oi_type, oi_bias, closes, "bullish")
    oi_bear = _oi_factor(oi_type, oi_bias, closes, "bearish")

    rsi_div = rsi_divergence_signal(closes, RSI_PERIOD)
    rsi_bull = rsi_momentum_filter(closes, "bullish")
    rsi_bear = rsi_momentum_filter(closes, "bearish")
    rsi_bull_factor = _rsi_factor("bullish", rsi_div, rsi_bull)
    rsi_bear_factor = _rsi_factor("bearish", rsi_div, rsi_bear)

    chain_analysis = analyze_strike_oi(option_chain, strike_step)
    chain_available = option_chain is not None and option_chain.success
    chain_bull = chain_oi_factor(chain_analysis, "bullish")
    chain_bear = chain_oi_factor(chain_analysis, "bearish")

    at_high = near_swing_extreme(closes, "bullish")
    at_low = near_swing_extreme(closes, "bearish")
    volatility_block = atr_pct is not None and atr_pct > 0.9

    bull_factors = _build_professional_factors(
        "bullish", ce_passed, oi_bull, rsi_bull_factor, chain_bull, ema_signal, bars,
    )
    bear_factors = _build_professional_factors(
        "bearish", pe_passed, oi_bear, rsi_bear_factor, chain_bear, ema_signal, bars,
    )
    bull_confidence = _setup_confidence(
        ce_passed, ce_total, oi_bull, rsi_bull_factor, chain_bull, ema_signal, bars, "bullish",
    )
    bear_confidence = _setup_confidence(
        pe_passed, pe_total, oi_bear, rsi_bear_factor, chain_bear, ema_signal, bars, "bearish",
    )

    pro_bull = oi_bull is not None and (chain_bull is not None if chain_available else True)
    pro_bear = oi_bear is not None and (chain_bear is not None if chain_available else True)

    indicators = {
        "ema_20": ema_20,
        "ema_50": ema_50,
        "ema_200": ema_200,
        "ema_signal": ema_signal,
        "session_vwap": vwap,
        "adx": adx_value,
        "adx_min": ADX_MIN,
        "adx_reject": ADX_REJECT,
        "atr": atr_value,
        "atr_percent": atr_pct,
        "rsi": rsi_value,
        "rsi_divergence": rsi_div,
        "volume_ma_20": vol_ma,
        "volume_above_20ma": volume_above_ma(bars, VOLUME_MA_PERIOD),
        "ce_rules": ce_rules,
        "pe_rules": pe_rules,
        "ce_rules_passed": ce_passed,
        "pe_rules_passed": pe_passed,
        "ce_rules_met": len(ce_passed),
        "ce_rules_total": ce_total,
        "pe_rules_met": len(pe_passed),
        "pe_rules_total": pe_total,
        "global_rejects": rejects,
        "ranging_around_vwap": price_ranging_around_vwap(bars),
        "late_session": apply_entry_cutoff and is_late_session(bars),
        "entry_cutoff_245pm": apply_entry_cutoff,
        "core_setup_bull": ce_user_ok and pro_bull,
        "core_setup_bear": pe_user_ok and pro_bear,
        "oi_buildup": oi_type,
        "oi_aligned_bull": oi_bull is not None,
        "oi_aligned_bear": oi_bear is not None,
        "rsi_bullish": rsi_bull,
        "rsi_bearish": rsi_bear,
        "rsi_aligned_bull": rsi_bull_factor is not None,
        "rsi_aligned_bear": rsi_bear_factor is not None,
        "vwap_bull": "above_vwap" if ce_rules["price_above_vwap"] else None,
        "vwap_bear": "below_vwap" if pe_rules["price_below_vwap"] else None,
        "market_structure_hh_hl": ce_rules["market_structure_hh_hl"],
        "market_structure_lh_ll": pe_rules["market_structure_lh_ll"],
        "pullback_ema20_or_vwap": (
            ce_rules["pullback_ema20_or_vwap"] or pe_rules["pullback_ema20_or_vwap"]
        ),
        "confirmation_high_break": ce_rules["confirmation_high_break"],
        "confirmation_low_break": pe_rules["confirmation_low_break"],
        "chain_oi_summary": chain_analysis.get("chain_oi_summary"),
        "chain_pcr": chain_analysis.get("pcr"),
        "chain_bias": chain_analysis.get("bias"),
        "atm_strike": chain_analysis.get("atm_strike"),
        "advanced_bull": pro_bull,
        "advanced_bear": pro_bear,
        "trend_strength": trend_strength,
        "at_swing_high": at_high,
        "at_swing_low": at_low,
        "min_confidence_required": MIN_SIGNAL_CONFIDENCE,
        "bullish_confidence": bull_confidence,
        "bearish_confidence": bear_confidence,
        "bullish_factors": bull_factors,
        "bearish_factors": bear_factors,
        "last_close": closes[-1],
        "setup_levels": compute_setup_levels(bars, closes, live_ltp=live_ltp),
    }

    if rejects:
        return StrategySignal(
            action="HOLD",
            confidence=max(bull_confidence, bear_confidence),
            reasons=rejects,
            indicators=indicators,
        )

    if volatility_block:
        return StrategySignal(
            action="HOLD",
            confidence=0.0,
            reasons=["volatility_too_high_for_buying"],
            indicators=indicators,
        )

    bullish_ok = (
        ce_user_ok
        and pro_bull
        and not at_high
        and ema_dir in ("bullish", "bullish_cross")
        and (trend_strength is None or trend_strength >= 0.04)
        and _meets_confidence_threshold(bull_confidence)
    )
    if bullish_ok:
        return StrategySignal(
            action="BUY_CE",
            confidence=bull_confidence,
            reasons=bull_factors,
            indicators=indicators,
        )

    bearish_ok = (
        pe_user_ok
        and pro_bear
        and not at_low
        and ema_dir in ("bearish", "bearish_cross")
        and (trend_strength is None or trend_strength >= 0.04)
        and _meets_confidence_threshold(bear_confidence)
    )
    if bearish_ok:
        return StrategySignal(
            action="BUY_PE",
            confidence=bear_confidence,
            reasons=bear_factors,
            indicators=indicators,
        )

    if len(ce_passed) >= len(pe_passed):
        hold_reason = _professional_hold_reason(
            ce_rules, rejects, oi_bull, chain_bull, chain_available,
            at_high, False, bull_confidence,
        )
    else:
        hold_reason = _professional_hold_reason(
            pe_rules, rejects, oi_bear, chain_bear, chain_available,
            at_low, False, bear_confidence,
        )

    return StrategySignal(
        action="HOLD",
        confidence=max(bull_confidence, bear_confidence),
        reasons=[hold_reason],
        indicators=indicators,
    )


evaluate_nifty_option_signal = evaluate_option_buying_signal
