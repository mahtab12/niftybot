"""Auto-trade instrument profiles for Nifty and Sensex F&O."""

from __future__ import annotations

from dataclasses import dataclass

from app.models.schemas import Exchange


@dataclass(frozen=True)
class AutoTradeProfile:
    """Configuration for one index auto-trader."""

    instrument_id: str
    label: str
    underlying: str
    cash_exchange: str
    option_exchange: str
    order_exchange: Exchange
    candle_groww_symbol: str
    futures_prefix: str
    lot_size: int
    buy_sl_points: float
    buy_target_points: float
    sell_sl_points: float
    sell_target_points: float
    strike_step: int
    sell_otm_offset: int = 3
    weekly_expiry_weekday: int = 1  # Monday=0; Nifty Tue=1, Sensex Thu=3


NIFTY_PROFILE = AutoTradeProfile(
    instrument_id="nifty",
    label="Nifty 50",
    underlying="NIFTY",
    cash_exchange="NSE",
    option_exchange="NSE",
    order_exchange=Exchange.NFO,
    candle_groww_symbol="NSE-NIFTY",
    futures_prefix="NIFTY",
    lot_size=65,
    buy_sl_points=12.0,
    buy_target_points=12.0,
    sell_sl_points=28.0,
    sell_target_points=28.0,
    strike_step=50,
    sell_otm_offset=3,
    weekly_expiry_weekday=1,
)

SENSEX_PROFILE = AutoTradeProfile(
    instrument_id="sensex",
    label="Sensex",
    underlying="SENSEX",
    cash_exchange="BSE",
    option_exchange="BSE",
    order_exchange=Exchange.BFO,
    candle_groww_symbol="BSE-SENSEX",
    futures_prefix="SENSEX",
    lot_size=20,
    buy_sl_points=30.0,
    buy_target_points=30.0,
    sell_sl_points=60.0,
    sell_target_points=60.0,
    strike_step=100,
    sell_otm_offset=3,
    weekly_expiry_weekday=3,
)

PROFILES: dict[str, AutoTradeProfile] = {
    NIFTY_PROFILE.instrument_id: NIFTY_PROFILE,
    SENSEX_PROFILE.instrument_id: SENSEX_PROFILE,
}


def get_profile(instrument_id: str) -> AutoTradeProfile:
    key = instrument_id.strip().lower()
    if key not in PROFILES:
        raise ValueError(f"Unknown auto-trade instrument: {instrument_id}")
    return PROFILES[key]
