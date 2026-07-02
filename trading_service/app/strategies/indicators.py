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


def latest_ema_value(closes: list[float], period: int) -> Optional[float]:
    """Most recent EMA value for the given period."""
    values = ema(closes, period)
    for value in reversed(values):
        if value is not None:
            return value
    return None


def ema_stack_bullish(
    closes: list[float],
    fast: int = 20,
    slow: int = 50,
) -> bool:
    """Fast EMA above slow EMA."""
    fast_value = latest_ema_value(closes, fast)
    slow_value = latest_ema_value(closes, slow)
    if fast_value is None or slow_value is None:
        return False
    return fast_value > slow_value


def ema_stack_bearish(
    closes: list[float],
    fast: int = 20,
    slow: int = 50,
) -> bool:
    """Fast EMA below slow EMA."""
    fast_value = latest_ema_value(closes, fast)
    slow_value = latest_ema_value(closes, slow)
    if fast_value is None or slow_value is None:
        return False
    return fast_value < slow_value


def price_above_ema(closes: list[float], period: int = 20) -> bool:
    ema_value = latest_ema_value(closes, period)
    if ema_value is None or not closes:
        return False
    return closes[-1] > ema_value


def price_below_ema(closes: list[float], period: int = 20) -> bool:
    ema_value = latest_ema_value(closes, period)
    if ema_value is None or not closes:
        return False
    return closes[-1] < ema_value


def price_above_session_vwap(bars: list[dict[str, Any]]) -> bool:
    vwap = session_vwap(bars)
    if vwap is None or not bars:
        return False
    return float(bars[-1]["close"]) > vwap


def price_below_session_vwap(bars: list[dict[str, Any]]) -> bool:
    vwap = session_vwap(bars)
    if vwap is None or not bars:
        return False
    return float(bars[-1]["close"]) < vwap


def volume_above_average(
    bars: list[dict[str, Any]],
    lookback: int = 10,
) -> bool:
    """Last candle volume above the recent average."""
    if len(bars) < lookback + 1:
        return False
    prior = bars[-(lookback + 1):-1]
    vols = [float(b.get("volume") or 0) for b in prior]
    avg_vol = sum(vols) / len(vols) if vols else 0.0
    last_vol = float(bars[-1].get("volume") or 0)
    return avg_vol > 0 and last_vol > avg_vol


def adx(bars: list[dict[str, Any]], period: int = 14) -> list[Optional[float]]:
    """Wilder ADX from OHLC bars."""
    count = len(bars)
    if count < period * 2:
        return [None] * count

    highs = [float(b["high"]) for b in bars]
    lows = [float(b["low"]) for b in bars]
    closes = [float(b["close"]) for b in bars]

    tr_values: list[float] = [0.0] * count
    plus_dm: list[float] = [0.0] * count
    minus_dm: list[float] = [0.0] * count

    for i in range(1, count):
        tr_values[i] = max(
            highs[i] - lows[i],
            abs(highs[i] - closes[i - 1]),
            abs(lows[i] - closes[i - 1]),
        )
        up_move = highs[i] - highs[i - 1]
        down_move = lows[i - 1] - lows[i]
        plus_dm[i] = up_move if up_move > down_move and up_move > 0 else 0.0
        minus_dm[i] = down_move if down_move > up_move and down_move > 0 else 0.0

    tr_smooth = sum(tr_values[1: period + 1])
    plus_smooth = sum(plus_dm[1: period + 1])
    minus_smooth = sum(minus_dm[1: period + 1])

    dx_values: list[Optional[float]] = [None] * count
    for i in range(period, count):
        if i > period:
            tr_smooth = tr_smooth - (tr_smooth / period) + tr_values[i]
            plus_smooth = plus_smooth - (plus_smooth / period) + plus_dm[i]
            minus_smooth = minus_smooth - (minus_smooth / period) + minus_dm[i]

        if tr_smooth <= 0:
            dx_values[i] = 0.0
            continue

        plus_di = 100.0 * plus_smooth / tr_smooth
        minus_di = 100.0 * minus_smooth / tr_smooth
        di_sum = plus_di + minus_di
        dx_values[i] = (
            100.0 * abs(plus_di - minus_di) / di_sum if di_sum > 0 else 0.0
        )

    adx_values: list[Optional[float]] = [None] * count
    seed_start = period * 2 - 1
    if seed_start >= count:
        return adx_values

    seed = [
        value for value in dx_values[period: seed_start + 1] if value is not None
    ]
    if len(seed) < period:
        return adx_values

    adx_smooth = sum(seed[:period]) / period
    adx_values[seed_start] = adx_smooth

    for i in range(seed_start + 1, count):
        current_dx = dx_values[i]
        if current_dx is None:
            continue
        adx_smooth = ((adx_smooth * (period - 1)) + current_dx) / period
        adx_values[i] = adx_smooth

    return adx_values


def latest_adx(bars: list[dict[str, Any]], period: int = 14) -> Optional[float]:
    """Most recent ADX value."""
    values = adx(bars, period)
    for value in reversed(values):
        if value is not None:
            return round(value, 2)
    return None


def adx_above_threshold(
    bars: list[dict[str, Any]],
    threshold: float = 25.0,
    period: int = 14,
) -> bool:
    value = latest_adx(bars, period)
    return value is not None and value > threshold


def latest_atr(bars: list[dict[str, float]], period: int = 14) -> Optional[float]:
    """Wilder ATR (absolute) on the latest bar."""
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

    atr_value = sum(trs[:period]) / period
    for tr in trs[period:]:
        atr_value = ((atr_value * (period - 1)) + tr) / period
    return round(atr_value, 4)


def volume_moving_average(
    bars: list[dict[str, Any]],
    period: int = 20,
) -> Optional[float]:
    """Simple moving average of bar volume."""
    if len(bars) < period:
        return None
    segment = bars[-period:]
    vols = [float(b.get("volume") or 0) for b in segment]
    if not vols:
        return None
    return round(sum(vols) / len(vols), 2)


def volume_above_ma(
    bars: list[dict[str, Any]],
    period: int = 20,
) -> bool:
    """Current bar volume above the volume moving average."""
    if len(bars) < period + 1:
        return False
    avg = volume_moving_average(bars[:-1], period)
    if avg is None or avg <= 0:
        return False
    last_vol = float(bars[-1].get("volume") or 0)
    return last_vol > avg


def ema_triple_stack_bullish(
    closes: list[float],
    fast: int = 20,
    mid: int = 50,
    slow: int = 200,
) -> bool:
    """EMA20 > EMA50 > EMA200."""
    ema_fast = latest_ema_value(closes, fast)
    ema_mid = latest_ema_value(closes, mid)
    ema_slow = latest_ema_value(closes, slow)
    if None in (ema_fast, ema_mid, ema_slow):
        return False
    return ema_fast > ema_mid > ema_slow


def ema_triple_stack_bearish(
    closes: list[float],
    fast: int = 20,
    mid: int = 50,
    slow: int = 200,
) -> bool:
    """EMA20 < EMA50 < EMA200."""
    ema_fast = latest_ema_value(closes, fast)
    ema_mid = latest_ema_value(closes, mid)
    ema_slow = latest_ema_value(closes, slow)
    if None in (ema_fast, ema_mid, ema_slow):
        return False
    return ema_fast < ema_mid < ema_slow


def rsi_in_range(
    closes: list[float],
    low: float,
    high: float,
    period: int = 14,
) -> bool:
    value = latest_rsi(closes, period)
    if value is None:
        return False
    return low <= value <= high


def _swing_points(
    values: list[float],
    window: int = 2,
) -> tuple[list[tuple[int, float]], list[tuple[int, float]]]:
    """Swing highs and lows as (index, value) within a price series."""
    highs: list[tuple[int, float]] = []
    lows: list[tuple[int, float]] = []
    for i in range(window, len(values) - window):
        segment = values[i - window: i + window + 1]
        center = values[i]
        if center == max(segment):
            highs.append((i, center))
        if center == min(segment):
            lows.append((i, center))
    return highs, lows


def market_structure_hh_hl(
    bars: list[dict[str, Any]],
    lookback: int = 30,
) -> bool:
    """Higher high and higher low market structure."""
    if len(bars) < lookback:
        return False
    segment = bars[-lookback:]
    highs = [float(b["high"]) for b in segment]
    lows = [float(b["low"]) for b in segment]
    swing_highs, _ = _swing_points(highs)
    _, swing_lows = _swing_points(lows)
    if len(swing_highs) < 2 or len(swing_lows) < 2:
        return False
    return (
        swing_highs[-1][1] > swing_highs[-2][1]
        and swing_lows[-1][1] > swing_lows[-2][1]
    )


def market_structure_lh_ll(
    bars: list[dict[str, Any]],
    lookback: int = 30,
) -> bool:
    """Lower high and lower low market structure."""
    if len(bars) < lookback:
        return False
    segment = bars[-lookback:]
    highs = [float(b["high"]) for b in segment]
    lows = [float(b["low"]) for b in segment]
    swing_highs, _ = _swing_points(highs)
    swing_lows, _ = _swing_points(lows)
    if len(swing_highs) < 2 or len(swing_lows) < 2:
        return False
    return (
        swing_highs[-1][1] < swing_highs[-2][1]
        and swing_lows[-1][1] < swing_lows[-2][1]
    )


def _near_level(price: float, level: Optional[float], tolerance_pct: float) -> bool:
    if level is None or level <= 0:
        return False
    return abs(price - level) / level * 100 <= tolerance_pct


def pullback_to_ema_or_vwap(
    bars: list[dict[str, Any]],
    closes: list[float],
    direction: str,
    ema_period: int = 20,
    touch_bars: int = 5,
    tolerance_pct: float = 0.15,
) -> bool:
    """Price tagged EMA20 or VWAP recently, then reclaimed for the trend."""
    if len(bars) < touch_bars + 2 or len(closes) < touch_bars + 2:
        return False

    vwap = session_vwap(bars)
    ema_value = latest_ema_value(closes, ema_period)
    if vwap is None and ema_value is None:
        return False

    recent_bars = bars[-(touch_bars + 1):-1]
    recent_closes = closes[-(touch_bars + 1):-1]
    touched = any(
        _near_level(close, ema_value, tolerance_pct)
        or _near_level(close, vwap, tolerance_pct)
        or _near_level(bar["low"], ema_value, tolerance_pct)
        or _near_level(bar["low"], vwap, tolerance_pct)
        for bar, close in zip(recent_bars, recent_closes)
    )
    if not touched:
        return False

    last_close = closes[-1]
    if direction == "bullish":
        above_ema = ema_value is None or last_close >= ema_value
        above_vwap = vwap is None or last_close >= vwap
        return above_ema and above_vwap
    if direction == "bearish":
        below_ema = ema_value is None or last_close <= ema_value
        below_vwap = vwap is None or last_close <= vwap
        return below_ema and below_vwap
    return False


def confirmation_candle_at(
    bars: list[dict[str, Any]],
    index: int,
    direction: str,
) -> bool:
    """Directional confirmation candle at a specific index."""
    if index < 0 or index >= len(bars):
        return False
    candle = bars[index]
    body = candle["close"] - candle["open"]
    if direction == "bullish":
        return body > 0
    if direction == "bearish":
        return body < 0
    return False


def _recent_confirmation_index(
    bars: list[dict[str, Any]],
    direction: str,
    lookback: int = 6,
) -> Optional[int]:
    start = len(bars) - 2
    end = max(len(bars) - lookback - 1, -1)
    for index in range(start, end, -1):
        if confirmation_candle_at(bars, index, direction):
            return index
    return None


def confirmation_candle_formed(
    bars: list[dict[str, Any]],
    direction: str,
    lookback: int = 6,
) -> bool:
    """A recent confirmation candle exists (not necessarily the last bar)."""
    return _recent_confirmation_index(bars, direction, lookback) is not None


def confirmation_candle_high_break(
    bars: list[dict[str, Any]],
    direction: str = "bullish",
    lookback: int = 6,
) -> bool:
    """Price broke above the high of a recent bullish confirmation candle."""
    if direction != "bullish" or len(bars) < 3:
        return False
    index = _recent_confirmation_index(bars, "bullish", lookback)
    if index is None or index >= len(bars) - 1:
        return False
    confirm_high = float(bars[index]["high"])
    return float(bars[-1]["close"]) > confirm_high


def confirmation_candle_low_break(
    bars: list[dict[str, Any]],
    direction: str = "bearish",
    lookback: int = 6,
) -> bool:
    """Price broke below the low of a recent bearish confirmation candle."""
    if direction != "bearish" or len(bars) < 3:
        return False
    index = _recent_confirmation_index(bars, "bearish", lookback)
    if index is None or index >= len(bars) - 1:
        return False
    confirm_low = float(bars[index]["low"])
    return float(bars[-1]["close"]) < confirm_low


def confirmation_candle_levels(
    bars: list[dict[str, Any]],
    direction: str,
    lookback: int = 6,
) -> Optional[tuple[float, float]]:
    """Low and high of the most recent confirmation candle for a direction."""
    index = _recent_confirmation_index(bars, direction, lookback)
    if index is None:
        return None
    candle = bars[index]
    return float(candle["low"]), float(candle["high"])


def _level_source(
    value: float,
    *,
    vwap: Optional[float],
    ema20: Optional[float],
    ema50: Optional[float],
    session: Optional[tuple[float, float]],
    direction: str,
) -> str:
    if direction == "overhead":
        if vwap is not None and value == vwap:
            return "vwap"
        if ema20 is not None and value == ema20:
            return "ema20"
        if ema50 is not None and value == ema50:
            return "ema50"
        if session is not None and value == session[1]:
            return "session_open_high"
        return "nearest_overhead"
    if vwap is not None and value == vwap:
        return "vwap"
    if ema20 is not None and value == ema20:
        return "ema20"
    if ema50 is not None and value == ema50:
        return "ema50"
    if session is not None and value == session[0]:
        return "session_open_low"
    return "nearest_support"


def _next_ce_trigger(
    ltp: float,
    *,
    vwap: Optional[float],
    ema20: Optional[float],
    ema50: Optional[float],
    session: Optional[tuple[float, float]],
    atr_val: Optional[float],
    bull_confirm: Optional[tuple[float, float]],
    include_confirmation: bool,
) -> tuple[Optional[float], str]:
    """Nearest level strictly above LTP for a fresh CE entry."""
    candidates: list[tuple[float, str]] = []
    if include_confirmation and bull_confirm is not None:
        bull_high = bull_confirm[1]
        if bull_high > ltp:
            candidates.append((bull_high, "confirmation_high"))
    for val in (vwap, ema20, ema50, session[1] if session else None):
        if val is not None and float(val) > ltp:
            candidates.append((float(val), _level_source(
                float(val),
                vwap=vwap,
                ema20=ema20,
                ema50=ema50,
                session=session,
                direction="overhead",
            )))
    if candidates:
        price, source = min(candidates, key=lambda item: item[0])
        return price, source
    if atr_val:
        return ltp + atr_val * 0.35, "atr_buffer"
    return None, "none"


def _next_pe_trigger(
    ltp: float,
    *,
    vwap: Optional[float],
    ema20: Optional[float],
    ema50: Optional[float],
    session: Optional[tuple[float, float]],
    atr_val: Optional[float],
    bear_confirm: Optional[tuple[float, float]],
    include_confirmation: bool,
) -> tuple[Optional[float], str]:
    """Nearest level strictly below LTP for a fresh PE entry."""
    candidates: list[tuple[float, str]] = []
    if include_confirmation and bear_confirm is not None:
        bear_low = bear_confirm[0]
        if bear_low < ltp:
            candidates.append((bear_low, "confirmation_low"))
    for val in (vwap, ema20, ema50, session[0] if session else None):
        if val is not None and float(val) < ltp:
            candidates.append((float(val), _level_source(
                float(val),
                vwap=vwap,
                ema20=ema20,
                ema50=ema50,
                session=session,
                direction="support",
            )))
    if candidates:
        price, source = max(candidates, key=lambda item: item[0])
        return price, source
    if atr_val:
        return ltp - atr_val * 0.35, "atr_buffer"
    return None, "none"


def compute_setup_levels(
    bars: list[dict[str, Any]],
    closes: list[float],
    live_ltp: Optional[float] = None,
) -> dict[str, Any]:
    """
    Price triggers for manual CE/PE planning relative to live LTP (or last close).

    CE: buy when price crosses above the trigger (points up from LTP).
    PE: buy when price falls to the trigger (points down from LTP).
    """
    if not closes:
        return {}

    bar_close = float(closes[-1])
    ltp = float(live_ltp) if live_ltp is not None and float(live_ltp) > 0 else bar_close
    vwap = session_vwap(bars)
    ema20 = latest_ema_value(closes, 20)
    ema50 = latest_ema_value(closes, 50)
    atr_val = latest_atr(bars, 14)
    session = session_first_candle_levels(bars)
    bull_confirm = confirmation_candle_levels(bars, "bullish")
    bear_confirm = confirmation_candle_levels(bars, "bearish")

    ce_triggered = confirmation_candle_high_break(bars)
    pe_triggered = confirmation_candle_low_break(bars)

    level_kwargs = {
        "vwap": vwap,
        "ema20": ema20,
        "ema50": ema50,
        "session": session,
        "atr_val": atr_val,
    }

    if ce_triggered and bull_confirm is not None and ltp <= bull_confirm[1]:
        ce_trigger = bull_confirm[1]
        ce_source = "confirmation_high_triggered"
    else:
        ce_trigger, ce_source = _next_ce_trigger(
            ltp,
            bull_confirm=bull_confirm,
            include_confirmation=not ce_triggered,
            **level_kwargs,
        )

    if pe_triggered and bear_confirm is not None and ltp >= bear_confirm[0]:
        pe_trigger = bear_confirm[0]
        pe_source = "confirmation_low_triggered"
    else:
        pe_trigger, pe_source = _next_pe_trigger(
            ltp,
            bear_confirm=bear_confirm,
            include_confirmation=not pe_triggered,
            **level_kwargs,
        )

    ce_points = round(max(0.0, ce_trigger - ltp), 2) if ce_trigger is not None else None
    pe_points = round(max(0.0, ltp - pe_trigger), 2) if pe_trigger is not None else None

    return {
        "ltp": round(ltp, 2),
        "bar_close": round(bar_close, 2),
        "ce_trigger_price": round(ce_trigger, 2) if ce_trigger is not None else None,
        "ce_points_away": ce_points,
        "ce_trigger_source": ce_source,
        "ce_triggered": ce_triggered,
        "pe_trigger_price": round(pe_trigger, 2) if pe_trigger is not None else None,
        "pe_points_away": pe_points,
        "pe_trigger_source": pe_source,
        "pe_triggered": pe_triggered,
    }


def price_ranging_around_vwap(
    bars: list[dict[str, Any]],
    lookback: int = 5,
    band_pct: float = 0.12,
) -> bool:
    """Several recent closes hugging VWAP — choppy, no clear bias."""
    vwap = session_vwap(bars)
    if vwap is None or vwap <= 0 or len(bars) < lookback:
        return False
    recent = bars[-lookback:]
    return all(
        abs(float(bar["close"]) - vwap) / vwap * 100 <= band_pct
        for bar in recent
    )


def _bar_datetime_ist(bar: dict[str, Any]) -> Optional[datetime]:
    ts = bar.get("ts")
    if ts is None:
        return None
    try:
        if isinstance(ts, (int, float)):
            seconds = float(ts)
            if seconds > 1e12:
                seconds /= 1000.0
            return datetime.fromtimestamp(seconds, tz=IST)
        text = str(ts).replace("Z", "+00:00")
        dt = datetime.fromisoformat(text)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=IST)
        return dt.astimezone(IST)
    except (ValueError, OSError, TypeError):
        return None


def is_late_session(
    bars: list[dict[str, Any]],
    cutoff_hour: int = 14,
    cutoff_minute: int = 45,
) -> bool:
    """True when the latest bar is at or after 2:45 PM IST."""
    if not bars:
        return False
    dt = _bar_datetime_ist(bars[-1])
    if dt is None:
        return False
    cutoff = dt.replace(hour=cutoff_hour, minute=cutoff_minute, second=0, microsecond=0)
    return dt >= cutoff
