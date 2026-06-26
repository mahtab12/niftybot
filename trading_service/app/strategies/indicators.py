"""Technical indicators for index option strategies."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Any, Optional

IST = timezone(timedelta(hours=5, minutes=30))


def ema(values: list[float], period: int) -> list[Optional[float]]:
    """Exponential moving average."""
    if not values or period <= 0:
        return []
    result: list[Optional[float]] = [None] * len(values)
    multiplier = 2 / (period + 1)
    seed = sum(values[:period]) / period
    result[period - 1] = seed
    prev = seed
    for i in range(period, len(values)):
        prev = (values[i] - prev) * multiplier + prev
        result[i] = prev
    return result


def rsi(closes: list[float], period: int = 14) -> list[Optional[float]]:
    """Wilder RSI."""
    if len(closes) < period + 1:
        return [None] * len(closes)

    rsis: list[Optional[float]] = [None] * len(closes)
    gains: list[float] = []
    losses: list[float] = []
    for i in range(1, len(closes)):
        delta = closes[i] - closes[i - 1]
        gains.append(max(delta, 0.0))
        losses.append(max(-delta, 0.0))

    avg_gain = sum(gains[:period]) / period
    avg_loss = sum(losses[:period]) / period
    rsis[period] = _rsi_from_avgs(avg_gain, avg_loss)

    for i in range(period + 1, len(closes)):
        avg_gain = (avg_gain * (period - 1) + gains[i - 1]) / period
        avg_loss = (avg_loss * (period - 1) + losses[i - 1]) / period
        rsis[i] = _rsi_from_avgs(avg_gain, avg_loss)
    return rsis


def _rsi_from_avgs(avg_gain: float, avg_loss: float) -> float:
    if avg_loss == 0:
        return 100.0
    rs = avg_gain / avg_loss
    return 100.0 - (100.0 / (1.0 + rs))


def latest_rsi(closes: list[float], period: int = 14) -> Optional[float]:
    """Most recent RSI value."""
    values = rsi(closes, period)
    for value in reversed(values):
        if value is not None:
            return value
    return None


def ema_trend_signal(
    closes: list[float],
    fast: int = 9,
    slow: int = 21,
    trend_bars: int = 3,
) -> str:
    """
    Professional trend read: sustained EMA stack plus fresh crossover.

    Returns bullish_cross, bearish_cross, bullish, bearish, or neutral.
    """
    if len(closes) < slow + trend_bars:
        return "neutral"

    fast_ema = ema(closes, fast)
    slow_ema = ema(closes, slow)
    prev_fast = fast_ema[-2]
    prev_slow = slow_ema[-2]
    curr_fast = fast_ema[-1]
    curr_slow = slow_ema[-1]

    if None in (prev_fast, prev_slow, curr_fast, curr_slow):
        return "neutral"

    if prev_fast <= prev_slow and curr_fast > curr_slow:
        return "bullish_cross"
    if prev_fast >= prev_slow and curr_fast < curr_slow:
        return "bearish_cross"

    recent_fast = fast_ema[-trend_bars:]
    recent_slow = slow_ema[-trend_bars:]
    if any(v is None for v in recent_fast + recent_slow):
        return "neutral"

    if all(f > s for f, s in zip(recent_fast, recent_slow)):
        return "bullish"
    if all(f < s for f, s in zip(recent_fast, recent_slow)):
        return "bearish"
    return "neutral"


def ema_bias(ema_signal: str) -> str:
    """Map EMA output to bullish / bearish / neutral."""
    if ema_signal in ("bullish", "bullish_cross"):
        return "bullish"
    if ema_signal in ("bearish", "bearish_cross"):
        return "bearish"
    return "neutral"


def _local_extrema(
    values: list[float],
    window: int = 2,
) -> tuple[list[tuple[int, float]], list[tuple[int, float]]]:
    """Return (index, value) lists for swing lows and highs."""
    lows: list[tuple[int, float]] = []
    highs: list[tuple[int, float]] = []
    for i in range(window, len(values) - window):
        segment = values[i - window : i + window + 1]
        center = values[i]
        if center == min(segment):
            lows.append((i, center))
        if center == max(segment):
            highs.append((i, center))
    return lows, highs


def rsi_divergence_signal(
    closes: list[float],
    period: int = 14,
    lookback: int = 30,
) -> str:
    """
    Swing-based RSI divergence (professional style).

    Bullish: price lower low + RSI higher low.
    Bearish: price higher high + RSI lower high.
    """
    if len(closes) < lookback + period + 5:
        return "none"

    rsis = rsi(closes, period)
    window_closes = closes[-lookback:]
    window_rsis = rsis[-lookback:]
    base = len(closes) - lookback

    lows, highs = _local_extrema(window_closes, window=2)
    if len(lows) >= 2:
        (i1, p1), (i2, p2) = lows[-2], lows[-1]
        r1 = window_rsis[i1]
        r2 = window_rsis[i2]
        if (
            r1 is not None
            and r2 is not None
            and p2 < p1
            and r2 > r1
            and i2 > i1
        ):
            return "bullish"

    if len(highs) >= 2:
        (i1, p1), (i2, p2) = highs[-2], highs[-1]
        r1 = window_rsis[i1]
        r2 = window_rsis[i2]
        if (
            r1 is not None
            and r2 is not None
            and p2 > p1
            and r2 < r1
            and i2 > i1
        ):
            return "bearish"

    return "none"


def rsi_momentum_filter(closes: list[float], direction: str) -> str:
    """
  RSI filter for directional trades.

  For bullish (CE buy / PE sell): avoid overbought exhaustion.
  For bearish (PE buy / CE sell): avoid oversold exhaustion.
  """
    value = latest_rsi(closes)
    if value is None:
        return "neutral"

    if direction == "bullish":
        if value >= 72:
            return "overbought"
        if value >= 48:
            return "supportive"
        if value >= 38:
            return "neutral"
        return "weak"

    if direction == "bearish":
        if value <= 28:
            return "oversold"
        if value <= 52:
            return "supportive"
        if value <= 62:
            return "neutral"
        return "weak"

    return "neutral"


def atr_percent(bars: list[dict[str, float]], period: int = 14) -> Optional[float]:
    """Average true range as % of last close — volatility gauge."""
    if len(bars) < period + 1:
        return None

    trs: list[float] = []
    for i in range(1, len(bars)):
        high = bars[i]["high"]
        low = bars[i]["low"]
        prev_close = bars[i - 1]["close"]
        tr = max(high - low, abs(high - prev_close), abs(low - prev_close))
        trs.append(tr)

    if len(trs) < period:
        return None

    atr = sum(trs[-period:]) / period
    last_close = bars[-1]["close"]
    if last_close <= 0:
        return None
    return round((atr / last_close) * 100, 3)


def ema_trend_strength(
    closes: list[float],
    fast: int = 9,
    slow: int = 21,
) -> Optional[float]:
    """EMA spread as % of price — higher values mean a clearer trend."""
    if len(closes) < slow:
        return None
    fast_ema = ema(closes, fast)
    slow_ema = ema(closes, slow)
    f = fast_ema[-1]
    s = slow_ema[-1]
    last = closes[-1]
    if f is None or s is None or last <= 0:
        return None
    return round(abs(f - s) / last * 100, 4)


def average_candle_range_percent(
    bars: list[dict[str, float]],
    lookback: int = 5,
) -> Optional[float]:
    """Mean (high-low)/close over recent candles — intraday noise gauge."""
    if len(bars) < lookback:
        return None
    segments = bars[-lookback:]
    ratios: list[float] = []
    for bar in segments:
        close = bar["close"]
        if close <= 0:
            continue
        ratios.append((bar["high"] - bar["low"]) / close * 100)
    if not ratios:
        return None
    return round(sum(ratios) / len(ratios), 4)


def volatility_adjusted_sl_points(
    entry_premium: float,
    bars: list[dict[str, float]],
    base_sl: float,
    amplification: float = 3.5,
) -> float:
    """
    Widen SL when recent index candles are volatile.

    Options amplify index moves; use recent range % scaled to premium.
    """
    range_pct = average_candle_range_percent(bars)
    if range_pct is None or entry_premium <= 0:
        return base_sl
    vol_sl = entry_premium * (range_pct / 100) * amplification
    return round(max(base_sl, vol_sl), 2)


def last_candle_confirms(bars: list[dict[str, float]], direction: str) -> bool:
    """Last candle body aligns with the intended trade direction."""
    if not bars:
        return False
    candle = bars[-1]
    body = candle["close"] - candle["open"]
    if direction == "bullish":
        return body > 0
    if direction == "bearish":
        return body < 0
    return False


def rejection_candle(bars: list[dict[str, float]], direction: str) -> bool:
    """
    Pin-bar style rejection — fade tops (sell CE) or bottoms (sell PE).

    Bearish: long upper wick, close in lower half.
    Bullish: long lower wick, close in upper half.
    """
    if not bars:
        return False
    candle = bars[-1]
    body = abs(candle["close"] - candle["open"])
    if body < 0.01:
        body = 0.01
    mid = (candle["high"] + candle["low"]) / 2
    upper_wick = candle["high"] - max(candle["open"], candle["close"])
    lower_wick = min(candle["open"], candle["close"]) - candle["low"]
    if direction == "bearish":
        return upper_wick > body * 1.1 and candle["close"] < mid
    if direction == "bullish":
        return lower_wick > body * 1.1 and candle["close"] > mid
    return False


def pullback_in_trend(
    closes: list[float],
    direction: str,
    fast: int = 9,
    slow: int = 21,
) -> bool:
    """
    Price has pulled back toward fast EMA within an established trend.

    Deprecated for entries — use ema_pullback_bounce() instead.
    """
    if len(closes) < slow + 2:
        return False
    fast_ema = ema(closes, fast)
    slow_ema = ema(closes, slow)
    f = fast_ema[-1]
    s = slow_ema[-1]
    price = closes[-1]
    if f is None or s is None:
        return False
    spread_pct = abs(f - s) / price * 100 if price > 0 else 0
    if spread_pct < 0.04:
        return False
    distance_pct = abs(price - f) / price * 100 if price > 0 else 999
    if direction == "bullish":
        return s < f and distance_pct <= 0.12 and price >= s
    if direction == "bearish":
        return s > f and distance_pct <= 0.12 and price <= s
    return False


def ema_pullback_bounce(
    closes: list[float],
    direction: str,
    fast: int = 9,
    slow: int = 21,
    touch_bars: int = 3,
) -> bool:
    """
    Enter after a dip/rally into fast EMA and a reclaim — not at the extension.

    Bullish: uptrend, price touched/pierced fast EMA recently, last close above it.
    Bearish: downtrend, price tagged fast EMA from below, last close below it.
    """
    if len(closes) < slow + touch_bars + 2:
        return False
    fast_ema = ema(closes, fast)
    slow_ema = ema(closes, slow)
    f_now = fast_ema[-1]
    s_now = slow_ema[-1]
    price = closes[-1]
    if f_now is None or s_now is None:
        return False

    recent_closes = closes[-(touch_bars + 1):-1]
    recent_fast = [v for v in fast_ema[-(touch_bars + 1):-1] if v is not None]
    if len(recent_fast) < touch_bars:
        return False

    if direction == "bullish":
        if not (s_now < f_now and price > f_now):
            return False
        touched = any(
            c <= (fv * 1.0005)
            for c, fv in zip(recent_closes, recent_fast)
        )
        return touched and price > closes[-2]

    if direction == "bearish":
        if not (s_now > f_now and price < f_now):
            return False
        touched = any(
            c >= (fv * 0.9995)
            for c, fv in zip(recent_closes, recent_fast)
        )
        return touched and price < closes[-2]

    return False


def pullback_retracement_percent(
    closes: list[float],
    direction: str,
    lookback: int = 15,
) -> Optional[float]:
    """
    How much of the recent impulse leg price retraced (0–100).

    Bullish: rally leg then dip from the swing high.
    Bearish: decline leg then bounce from the swing low.
    """
    if len(closes) < lookback:
        return None
    segment = closes[-lookback:]

    if direction == "bullish":
        peak = max(segment)
        peak_i = len(segment) - 1 - segment[::-1].index(peak)
        base = min(segment[: peak_i + 1])
        if peak <= base:
            return None
        trough = min(segment[peak_i:])
        return round((peak - trough) / (peak - base) * 100, 2)

    if direction == "bearish":
        trough = min(segment)
        trough_i = len(segment) - 1 - segment[::-1].index(trough)
        crest = max(segment[: trough_i + 1])
        if crest <= trough:
            return None
        bounce = max(segment[trough_i:])
        return round((bounce - trough) / (crest - trough) * 100, 2)

    return None


def near_swing_extreme(
    closes: list[float],
    direction: str,
    lookback: int = 12,
    threshold_pct: float = 0.12,
) -> bool:
    """True when price is at a recent high (CE risk) or low (PE risk)."""
    if len(closes) < lookback:
        return False
    window = closes[-lookback:]
    last = closes[-1]
    if direction == "bullish":
        high = max(window)
        if high <= 0:
            return False
        return last >= high * (1 - threshold_pct / 100)
    if direction == "bearish":
        low = min(window)
        if low <= 0:
            return False
        return last <= low * (1 + threshold_pct / 100)
    return False


def rsi_in_entry_zone(
    closes: list[float],
    direction: str,
    period: int = 14,
) -> Optional[str]:
    """
    RSI window that avoids chasing extended moves.

    Bullish CE: 42–58 (not overbought chase).
    Bearish PE: 42–58 (not oversold chase).
    """
    value = latest_rsi(closes, period)
    if value is None:
        return None
    if direction == "bullish":
        if value > 58:
            return "extended"
        if value < 42:
            return "weak"
        return "ok"
    if direction == "bearish":
        if value < 42:
            return "extended"
        if value > 58:
            return "weak"
        return "ok"
    return None


def _bar_session_date(bar: dict[str, Any]) -> Optional[str]:
    """IST calendar date for a candle bar."""
    ts = bar.get("ts")
    if ts is None:
        return None
    try:
        if isinstance(ts, (int, float)):
            seconds = float(ts)
            if seconds > 1e12:
                seconds /= 1000.0
            dt = datetime.fromtimestamp(seconds, tz=IST)
        else:
            text = str(ts).replace("Z", "+00:00")
            dt = datetime.fromisoformat(text)
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=IST)
        return dt.strftime("%Y-%m-%d")
    except (ValueError, OSError, TypeError):
        return None


def session_first_candle_levels(
    bars: list[dict[str, Any]],
    session_bars: int = 26,
) -> Optional[tuple[float, float]]:
    """
    Low and high of the first 15-minute candle of the latest session.

    Uses candle timestamps when available; otherwise approximates one
    session as the last ~26 fifteen-minute bars (9:15–15:30 IST).
    """
    if not bars:
        return None

    by_date: dict[str, list[dict[str, Any]]] = {}
    for bar in bars:
        day = _bar_session_date(bar)
        if day:
            by_date.setdefault(day, []).append(bar)

    if by_date:
        latest = max(by_date.keys())
        first = by_date[latest][0]
        return float(first["low"]), float(first["high"])

    chunk = bars[-session_bars:] if len(bars) >= session_bars else bars
    first = chunk[0]
    return float(first["low"]), float(first["high"])


def day_low_break(
    bars: list[dict[str, Any]],
    closes: list[float],
) -> bool:
    """Price just crossed below the first 15m candle low of the session."""
    levels = session_first_candle_levels(bars)
    if not levels or len(closes) < 2:
        return False
    day_low, _ = levels
    return closes[-2] >= day_low and closes[-1] < day_low


def day_high_break(
    bars: list[dict[str, Any]],
    closes: list[float],
) -> bool:
    """Price just crossed above the first 15m candle high of the session."""
    levels = session_first_candle_levels(bars)
    if not levels or len(closes) < 2:
        return False
    _, day_high = levels
    return closes[-2] <= day_high and closes[-1] > day_high


def _latest_session_bars(bars: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Bars belonging to the most recent session (by timestamp or tail window)."""
    by_date: dict[str, list[dict[str, Any]]] = {}
    for bar in bars:
        day = _bar_session_date(bar)
        if day:
            by_date.setdefault(day, []).append(bar)
    if by_date:
        return by_date[max(by_date.keys())]
    return bars[-26:] if len(bars) >= 26 else bars


def session_vwap(bars: list[dict[str, Any]]) -> Optional[float]:
    """Session VWAP for the latest trading day in the candle series."""
    session = _latest_session_bars(bars)
    if not session:
        return None
    cum_tp_vol = 0.0
    cum_vol = 0.0
    for bar in session:
        vol = float(bar.get("volume") or 0)
        if vol <= 0:
            continue
        typical = (bar["high"] + bar["low"] + bar["close"]) / 3.0
        cum_tp_vol += typical * vol
        cum_vol += vol
    if cum_vol <= 0:
        return None
    return round(cum_tp_vol / cum_vol, 4)


def vwap_factor(bars: list[dict[str, Any]], direction: str) -> Optional[str]:
    """Price vs session VWAP — institutional bias filter."""
    vwap = session_vwap(bars)
    if vwap is None or not bars:
        return None
    close = float(bars[-1]["close"])
    if direction == "bullish" and close > vwap:
        return "above_vwap"
    if direction == "bearish" and close < vwap:
        return "below_vwap"
    return None


def volume_spike_factor(
    bars: list[dict[str, Any]],
    direction: str,
    lookback: int = 10,
    multiplier: float = 1.5,
) -> Optional[str]:
    """Detect conviction volume on the last candle vs recent average."""
    if len(bars) < lookback + 1:
        return None
    prior = bars[-(lookback + 1):-1]
    vols = [float(b.get("volume") or 0) for b in prior]
    avg_vol = sum(vols) / len(vols) if vols else 0.0
    last = bars[-1]
    last_vol = float(last.get("volume") or 0)
    if avg_vol <= 0 or last_vol < avg_vol * multiplier:
        return None
    bullish_candle = last["close"] > last["open"]
    if direction == "bullish" and bullish_candle:
        return "volume_spike"
    if direction == "bearish" and not bullish_candle:
        return "volume_spike"
    return None
