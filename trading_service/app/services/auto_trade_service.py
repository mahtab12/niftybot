"""Auto-trade engine for Nifty, Sensex options and MCX futures."""

from __future__ import annotations

import logging
import threading
import time
import uuid
from datetime import datetime, timedelta
from typing import Any, Callable, Optional

from app.auto_trade_profiles import (
    AutoTradeProfile,
    GROWW_MCX_UNSUPPORTED_MESSAGE,
    PROFILES,
    get_profile,
)
from app.config import settings
from app.models.schemas import (
    AutoTradePosition,
    AutoTradeSignalInfo,
    AutoTradeStatusResponse,
    BrokerType,
    CancelSmartOrderRequest,
    CreateSmartOrderRequest,
    Exchange,
    OCOLeg,
    OptionChainResponse,
    OrderType,
    PlaceOrderRequest,
    ProductType,
    Segment,
    SmartOrderResponse,
    SmartOrderType,
    TransactionType,
)
from app.option_expiries import weekly_expiries
from app.services.auto_trade_db import (
    close_auto_trade,
    close_other_open_trades,
    get_open_auto_trade,
    get_recent_closed_trades,
    insert_auto_trade,
    update_auto_trade,
    void_untracked_open_trades,
)
from app.services.trade_alerts_db import maybe_insert_trade_alert
from app.services.broker_service import broker_service
from app.strategies.nifty_option_strategy import (
    MIN_ENTRY_BARS,
    evaluate_option_buying_signal,
    parse_candles,
)
from app.strategies.option_selling_strategy import evaluate_option_selling_signal
from app.strategies.indicators import volatility_adjusted_sl_points

logger = logging.getLogger("niftybot.auto_trade")

MIN_ENTRY_CONFIDENCE = 0.75
# ~26 fifteen-minute session bars per Indian trading day; EMA 200 needs MIN_ENTRY_BARS.
CANDLES_PER_TRADING_DAY = 26
CANDLE_HISTORY_BUFFER_DAYS = 5

MONTH_CODES = (
    "JAN", "FEB", "MAR", "APR", "MAY", "JUN",
    "JUL", "AUG", "SEP", "OCT", "NOV", "DEC",
)

# Option premiums are never index-scale; guards against index LTP fallback bugs.
MAX_OPTION_PREMIUM = 5000.0
MAX_FUTURES_PRICE = 500_000.0


def resolve_strike_price(
    underlying_ltp: float,
    strike_step: int,
    strike_offset: int = 0,
) -> float:
    """ATM rounded to step, then shifted by offset strikes."""
    atm = round(underlying_ltp / strike_step) * strike_step
    return atm + (strike_offset * strike_step)


def resolve_chain_strike(
    underlying_ltp: float,
    strike_step: int,
    available_strikes: list[str],
    strike_offset: int = 0,
) -> Optional[str]:
    """Pick chain strike nearest to ATM + offset."""
    if not available_strikes or underlying_ltp <= 0:
        return None

    target = resolve_strike_price(underlying_ltp, strike_step, strike_offset)
    candidates = [
        str(int(target)),
        f"{target:.0f}",
        f"{target:.1f}",
    ]
    strike_set = set(available_strikes)
    for candidate in candidates:
        if candidate in strike_set:
            return candidate

    return min(available_strikes, key=lambda k: abs(float(k) - target))


def resolve_atm_strike(
    underlying_ltp: float,
    strike_step: int,
    available_strikes: list[str],
) -> Optional[str]:
    """Pick the ATM strike rounded to the index step (50 Nifty, 100 Sensex)."""
    return resolve_chain_strike(
        underlying_ltp, strike_step, available_strikes, strike_offset=0,
    )


class AutoTradeService:
    """Manages auto-trade lifecycle for one index: signal, entry, OCO exit, P&L."""

    def __init__(
        self,
        profile: AutoTradeProfile,
        *,
        user_broker: Any | None = None,
        user_id: int = 0,
        on_trade_entry: Callable[[dict[str, Any]], None] | None = None,
    ) -> None:
        self.profile = profile
        self._user_broker = user_broker
        self.user_id = user_id
        self._on_trade_entry = on_trade_entry
        self._lock = threading.RLock()
        self._active = False
        self._thread: Optional[threading.Thread] = None
        self._stop_event = threading.Event()
        self._current_trade: Optional[dict[str, Any]] = None
        self._trade_history: list[dict[str, Any]] = []
        self._last_signal: Optional[dict[str, Any]] = None
        self._last_check_at: Optional[str] = None
        self._underlying_ltp: Optional[float] = None
        self._status_message = f"{profile.label} auto trade is inactive"
        self._entry_in_progress = False
        self._trade_mode = "buy"
        self._last_signal_bars: list[dict[str, float]] = []
        self._last_option_chain: Optional[OptionChainResponse] = None

    def _groww_broker(self):
        if self._user_broker is not None:
            return self._user_broker
        return broker_service.get_broker(BrokerType.GROWW)

    def _place_order(self, request: PlaceOrderRequest):
        if self._user_broker is not None:
            return self._user_broker.place_order(request)
        return broker_service.place_order(request)

    def _create_smart_order(self, request: CreateSmartOrderRequest):
        if self._user_broker is not None:
            return self._user_broker.create_smart_order(request)
        return broker_service.create_smart_order(request)

    def _cancel_smart_order(self, smart_order_id: str, request: CancelSmartOrderRequest):
        if self._user_broker is not None:
            return self._user_broker.cancel_smart_order(smart_order_id, request)
        return broker_service.cancel_smart_order(smart_order_id, request)

    def _get_smart_order(
        self,
        smart_order_id: str,
        smart_order_type: str,
        segment: str,
    ):
        if self._user_broker is not None:
            return self._user_broker.get_smart_order(
                smart_order_id,
                smart_order_type,
                segment,
            )
        return broker_service.get_smart_order(
            smart_order_id,
            smart_order_type,
            segment,
            BrokerType.GROWW,
        )

    def _list_smart_orders(self, **kwargs):
        if self._user_broker is not None:
            kwargs.pop("broker_type", None)
            return self._user_broker.list_smart_orders(**kwargs)
        return broker_service.list_smart_orders(**kwargs)

    def _get_positions(self):
        if self._user_broker is not None:
            return self._user_broker.get_positions()
        return broker_service.get_positions(BrokerType.GROWW)

    def _notify_trade_entry(self, trade: dict[str, Any]) -> None:
        if not self._on_trade_entry:
            return
        try:
            self._on_trade_entry(trade)
        except Exception:
            logger.exception(
                "Trade entry callback failed uid=%s instrument=%s",
                self.user_id,
                self.profile.instrument_id,
            )

    def activate(self, mode: str = "buy") -> AutoTradeStatusResponse:
        if not self.profile.groww_trading_supported:
            return self.get_status(GROWW_MCX_UNSUPPORTED_MESSAGE)

        trade_mode = mode.strip().lower()
        if trade_mode not in ("buy", "sell"):
            return self.get_status("Invalid trade mode — use buy or sell")

        with self._lock:
            if self._active:
                return self.get_status(f"{self.profile.label} auto trade is already active")
            if self._has_open_position():
                return self.get_status(
                    f"{self.profile.label} auto trade cannot start — "
                    "close the open position first",
                )
            blocked_by = None
            if self._user_broker is None:
                blocked_by = self._global_open_instrument()
            if blocked_by:
                return self.get_status(
                    f"Cannot activate {self.profile.label} — "
                    f"{blocked_by} auto-trade already has an open position",
                )
            self._trade_mode = trade_mode
            self._active = True
            self._entry_in_progress = False
            self._stop_event.clear()
            mode_label = "option buying" if trade_mode == "buy" else "option selling (OTM)"
            self._status_message = (
                f"{self.profile.label} {mode_label} activated — "
                "one trade max; will stop after exit"
            )
            self._thread = threading.Thread(
                target=self._run_loop,
                name=f"niftybot-auto-trade-{self.profile.instrument_id}",
                daemon=True,
            )
            self._thread.start()
            return self.get_status(self._status_message)

    def deactivate(self) -> AutoTradeStatusResponse:
        with self._lock:
            self._active = False
            self._stop_event.set()
            self._status_message = f"{self.profile.label} auto trade deactivated"
            return self.get_status(self._status_message)

    def manual_exit(self) -> AutoTradeStatusResponse:
        """Close the open auto-trade position at market (cancels OCO first)."""
        with self._lock:
            trade = self._current_trade
            if not trade or trade.get("status") != "open":
                return self.get_status("No open position to exit")

        broker = self._groww_broker()
        oco_id = trade.get("smart_order_id")
        if oco_id:
            self._cancel_oco(oco_id)

        with self._lock:
            if self._current_trade and self._current_trade.get("status") == "open":
                self._exit_trade(broker, "manual_exit")

        return self.get_status()

    def refresh_market_signal(self) -> dict[str, Any]:
        """Evaluate live market signal without activating auto-trade."""
        with self._lock:
            broker = self._groww_broker()
            self._underlying_ltp = self._fetch_index_ltp(broker)
            if self._current_trade and self._current_trade.get("status") == "open":
                self._last_check_at = datetime.now().isoformat(timespec="seconds")
                return self._last_signal or {
                    "action": "HOLD",
                    "confidence": 0.0,
                    "reasons": ["open_position"],
                    "indicators": {},
                }

            signal = self._evaluate_signal(broker)
            self._last_signal = {
                "action": signal.action,
                "confidence": signal.confidence,
                "reasons": signal.reasons,
                "indicators": signal.indicators,
            }
            self._last_check_at = datetime.now().isoformat(timespec="seconds")
            maybe_insert_trade_alert(
                self.profile.instrument_id,
                signal.action,
                signal.confidence,
                self._underlying_ltp,
                signal.reasons,
                signal.indicators,
            )
            return dict(self._last_signal)

    def get_status(self, message: str = "") -> AutoTradeStatusResponse:
        acquired = self._lock.acquire(timeout=2.0)
        if not acquired:
            logger.debug(
                "%s status lock busy; returning cached snapshot",
                self.profile.instrument_id,
            )
            return self._build_status_response(message=message, live_refresh=False)
        try:
            return self._build_status_response(message=message, live_refresh=True)
        finally:
            self._lock.release()

    def _build_status_response(
        self,
        message: str = "",
        *,
        live_refresh: bool = True,
    ) -> AutoTradeStatusResponse:
        has_open_trade = bool(
            self._current_trade
            and self._current_trade.get("status") == "open"
        )
        if live_refresh and (self._active or has_open_trade):
            try:
                broker = self._groww_broker()
                self._underlying_ltp = self._fetch_index_ltp(broker)
                self._sync_with_broker_positions(broker)
                if has_open_trade:
                    try:
                        self._monitor_open_trade(broker)
                    except Exception:
                        logger.exception(
                            "Monitor failed for %s open trade",
                            self.profile.instrument_id,
                        )
            except Exception:
                logger.exception("Live status refresh failed")
        elif live_refresh and self._underlying_ltp is None:
            try:
                broker = self._groww_broker()
                self._underlying_ltp = self._fetch_index_ltp(broker)
            except Exception:
                logger.exception(
                    "Inactive LTP refresh failed for %s",
                    self.profile.instrument_id,
                )

        trade = self._serialize_trade(self._current_trade)
        db_history = get_recent_closed_trades(
            self.profile.instrument_id, limit=10,
        )
        seen_ids = {t.get("trade_id") for t in db_history}
        for t in self._trade_history[-10:]:
            if t.get("trade_id") not in seen_ids:
                db_history.append(self._sanitize_history_trade(t))
        history = [
            self._serialize_trade(t)
            for t in db_history[:10]
        ]
        signal = None
        if self._last_signal:
            signal = AutoTradeSignalInfo(**self._last_signal)

        return AutoTradeStatusResponse(
            success=True,
            instrument=self.profile.instrument_id,
            instrument_label=self.profile.label,
            trade_mode=self._trade_mode,
            active=self._active,
            message=message or self._status_message,
            underlying_ltp=self._underlying_ltp,
            nifty_ltp=self._underlying_ltp,
            last_check_at=self._last_check_at,
            last_signal=signal,
            current_trade=trade,
            trade_history=history,
            config={
                "trade_mode": self._trade_mode,
                "lot_size": self.profile.lot_size,
                "buy_sl_points": self.profile.buy_sl_points,
                "buy_target_points": self.profile.buy_target_points,
                "sell_sl_points": self.profile.sell_sl_points,
                "sell_target_points": self.profile.sell_target_points,
                "sell_otm_offset": self.profile.sell_otm_offset,
                "strike_step": self.profile.strike_step,
                "weekly_expiry_weekday": self.profile.weekly_expiry_weekday,
                "poll_seconds": settings.auto_trade_poll_seconds,
                "min_entry_confidence": MIN_ENTRY_CONFIDENCE,
            },
        )

    def _run_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                self._tick()
            except Exception:
                logger.exception("%s auto trade tick failed", self.profile.instrument_id)
            self._stop_event.wait(settings.auto_trade_poll_seconds)

    def _is_futures_profile(self) -> bool:
        return self.profile.trade_kind == "futures"

    def _quote_segment(self) -> str:
        return self.profile.market_segment

    def _candle_segment_attr(self, groww) -> str:
        segment = self.profile.candle_segment.upper()
        attr = f"SEGMENT_{segment}"
        return getattr(groww, attr, groww.SEGMENT_CASH)

    def _candle_history_days(self) -> int:
        """Calendar days of 15m history to satisfy EMA 200 and other indicators."""
        trading_days_needed = (
            MIN_ENTRY_BARS // CANDLES_PER_TRADING_DAY
        ) + CANDLE_HISTORY_BUFFER_DAYS
        return max(30, trading_days_needed * 2)

    def _tick(self) -> None:
        broker = self._groww_broker()
        self._underlying_ltp = self._fetch_index_ltp(broker)

        if self._current_trade and self._current_trade.get("status") == "open":
            try:
                self._monitor_open_trade(broker)
            except Exception:
                logger.exception(
                    "Monitor failed for %s open trade",
                    self.profile.instrument_id,
                )
            self._last_check_at = datetime.now().isoformat(timespec="seconds")
            return

        signal = self._evaluate_signal(broker)
        self._last_signal = {
            "action": signal.action,
            "confidence": signal.confidence,
            "reasons": signal.reasons,
            "indicators": signal.indicators,
        }
        self._last_check_at = datetime.now().isoformat(timespec="seconds")
        maybe_insert_trade_alert(
            self.profile.instrument_id,
            signal.action,
            signal.confidence,
            self._underlying_ltp,
            signal.reasons,
            signal.indicators,
        )

        if not self._active:
            return

        if signal.action == "HOLD" and signal.reasons:
            reason = signal.reasons[0].replace("_", " ")
            self._status_message = (
                f"{self.profile.label} scanning — {reason} "
                f"(need {MIN_ENTRY_CONFIDENCE:.0%} confidence after pullback)"
            )

        if signal.action in ("BUY_CE", "BUY_PE", "SELL_CE", "SELL_PE"):
            if signal.confidence < MIN_ENTRY_CONFIDENCE:
                self._status_message = (
                    f"{self.profile.label} signal {signal.action} skipped — "
                    f"confidence {signal.confidence:.0%} below "
                    f"{MIN_ENTRY_CONFIDENCE:.0%} threshold"
                )
                return
            blocked_by = self._global_open_instrument()
            if blocked_by:
                self._status_message = (
                    f"{self.profile.label} signal {signal.action} skipped — "
                    f"{blocked_by} auto-trade already has an open position"
                )
                return
            with self._lock:
                if not self._active or self._has_open_position() or self._entry_in_progress:
                    return
            if signal.action in ("BUY_CE", "SELL_CE"):
                option_type = "CE"
            else:
                option_type = "PE"
            position_side = (
                "short" if signal.action.startswith("SELL") else "long"
            )
            self._enter_trade(broker, option_type, signal.reasons, position_side)

    def _global_open_instrument(self) -> Optional[str]:
        """Return instrument id if any auto-trader already has an open position."""
        for inst_id in PROFILES:
            if inst_id == self.profile.instrument_id:
                continue
            if get_open_auto_trade(inst_id):
                return inst_id
            other = AUTO_TRADE_SERVICES[inst_id]
            with other._lock:
                if other._has_open_position():
                    return inst_id
        return None

    def _evaluate_signal(self, broker) -> Any:
        groww = broker._client
        end = datetime.now()
        history_days = self._candle_history_days()
        start = end - timedelta(days=history_days)
        candles: list = []
        try:
            data = groww.get_historical_candles(
                exchange=getattr(
                    groww, f"EXCHANGE_{self.profile.cash_exchange}",
                ),
                segment=self._candle_segment_attr(groww),
                groww_symbol=self.profile.candle_groww_symbol,
                start_time=start.strftime("%Y-%m-%d %H:%M:%S"),
                end_time=end.strftime("%Y-%m-%d %H:%M:%S"),
                candle_interval=groww.CANDLE_INTERVAL_MIN_15,
                timeout=25,
            )
            candles = data.get("candles", []) if isinstance(data, dict) else []
        except Exception:
            logger.exception(
                "Failed to fetch candles for %s", self.profile.instrument_id,
            )

        self._last_signal_bars = parse_candles(candles)
        if len(self._last_signal_bars) < MIN_ENTRY_BARS:
            logger.info(
                "%s candle history short: %s bars (need %s, requested %s days)",
                self.profile.instrument_id,
                len(self._last_signal_bars),
                MIN_ENTRY_BARS,
                history_days,
            )

        fut_symbol = self._resolve_index_future_symbol(broker)
        price_change: Optional[float] = None
        oi_change: Optional[float] = None
        if fut_symbol:
            quote = broker.get_quote(
                fut_symbol, self.profile.option_exchange, self._quote_segment(),
            )
            if quote.success:
                price_change = quote.day_change
                oi_change = quote.oi_day_change

        if self._trade_mode == "sell" and not self._is_futures_profile():
            return evaluate_option_selling_signal(
                candles, price_change, oi_change,
            )
        chain = None if self._is_futures_profile() else self._fetch_weekly_option_chain(broker)
        self._last_option_chain = chain
        return evaluate_option_buying_signal(
            candles,
            price_change,
            oi_change,
            option_chain=chain,
            strike_step=self.profile.strike_step,
            apply_entry_cutoff=self.profile.entry_cutoff_245pm,
            live_ltp=self._underlying_ltp,
        )

    def _fetch_weekly_option_chain(self, broker) -> Optional[OptionChainResponse]:
        """Nearest weekly expiry chain for strike-level OI confirmation."""
        expiries = weekly_expiries(self.profile.option_exchange, 3)
        for expiry in expiries:
            try:
                chain = broker.get_option_chain(
                    self.profile.option_exchange,
                    self.profile.underlying,
                    expiry,
                )
            except Exception:
                logger.exception(
                    "Failed to fetch option chain for %s %s",
                    self.profile.instrument_id,
                    expiry,
                )
                continue
            if chain.success and chain.strikes:
                return chain
        return None

    def _has_open_position(self) -> bool:
        trade = self._current_trade
        if not trade:
            return False
        return trade.get("status") in ("open", "exiting")

    def _enter_trade(
        self,
        broker,
        option_type: str,
        reasons: list[str],
        position_side: str = "long",
    ) -> None:
        with self._lock:
            if not self._active or self._has_open_position() or self._entry_in_progress:
                return
            self._entry_in_progress = True

        try:
            self._place_entry_trade(broker, option_type, reasons, position_side)
        finally:
            with self._lock:
                self._entry_in_progress = False

    def _strike_offset_for_trade(
        self, option_type: str, position_side: str,
    ) -> int:
        """ATM for buys; ATM±3 OTM for sells (PE below, CE above)."""
        if position_side == "long":
            return 0
        offset = self.profile.sell_otm_offset
        if option_type == "PE":
            return -offset
        return offset

    def _sl_target_points(self, position_side: str) -> tuple[float, float]:
        if position_side == "short":
            return self.profile.sell_sl_points, self.profile.sell_target_points
        return self.profile.buy_sl_points, self.profile.buy_target_points

    @staticmethod
    def _product_type_for_position(position_side: str) -> ProductType:
        """Long option buys use NRML (carry/delivery); shorts stay MIS intraday."""
        if position_side == "long":
            return ProductType.NRML
        return ProductType.MIS

    def _resolve_trade_product_type(
        self, trade: dict[str, Any], position_side: str,
    ) -> ProductType:
        """Product type for exits — must match the open position."""
        stored = trade.get("product_type")
        if stored:
            try:
                return ProductType(stored)
            except ValueError:
                pass
        return self._product_type_for_position(position_side)

    def _place_entry_trade(
        self,
        broker,
        option_type: str,
        reasons: list[str],
        position_side: str = "long",
    ) -> None:
        if self._is_futures_profile():
            self._place_futures_entry_trade(broker, option_type, reasons, position_side)
            return

        strike_offset = self._strike_offset_for_trade(option_type, position_side)
        contract = self._pick_option_contract(broker, option_type, strike_offset)
        if not contract:
            strike_desc = "ATM" if strike_offset == 0 else f"OTM ({strike_offset:+d} strikes)"
            self._status_message = (
                f"Could not resolve {strike_desc} {option_type} contract for "
                f"{self.profile.label}"
            )
            return

        symbol, strike, expiry, chain_ltp = contract
        qty = self.profile.lot_size
        order_id = int(time.time()) % 2_000_000_000
        entry_txn = (
            TransactionType.SELL if position_side == "short" else TransactionType.BUY
        )
        product_type = self._product_type_for_position(position_side)

        request = PlaceOrderRequest(
            order_id=order_id,
            user_id=0,
            broker=BrokerType.GROWW,
            symbol=symbol,
            exchange=self.profile.order_exchange,
            transaction_type=entry_txn,
            order_type=OrderType.MARKET,
            product_type=product_type,
            quantity=qty,
        )
        result = self._place_order(request)
        if not result.success:
            self._status_message = f"Entry order failed: {result.message}"
            return

        entry_price = self._fetch_option_price(broker, symbol, hint=chain_ltp)
        if entry_price is None:
            self._status_message = (
                f"Order placed on {symbol} but option price could not be verified — "
                "exits disabled until price is available. Check position manually."
            )
            logger.error(
                "Invalid option entry price for %s (chain hint=%s, underlying=%s)",
                symbol,
                chain_ltp,
                self._underlying_ltp,
            )
            trade = {
                "trade_id": str(uuid.uuid4())[:8],
                "status": "open",
                "trade_mode": self._trade_mode,
                "position_side": position_side,
                "product_type": product_type.value,
                "instrument": self.profile.instrument_id,
                "option_type": option_type,
                "symbol": symbol,
                "strike": strike,
                "expiry_date": expiry,
                "quantity": qty,
                "entry_price": None,
                "current_price": None,
                "stop_loss": None,
                "target": None,
                "sl_points": sl_pts,
                "target_points": target_pts,
                "pnl": 0.0,
                "pnl_percentage": 0.0,
                "broker_order_id": result.broker_order_id,
                "smart_order_id": None,
                "smart_order_status": None,
                "signal_reasons": reasons,
                "opened_at": datetime.now().isoformat(timespec="seconds"),
                "closed_at": None,
                "exit_reason": None,
                "pricing_pending": True,
            }
            with self._lock:
                self._current_trade = trade
            if not self._user_broker:
                record_id = insert_auto_trade(
                    trade, self.profile, self._underlying_ltp, self._last_signal,
                )
                if record_id:
                    trade["record_id"] = record_id
                close_other_open_trades(self.profile.instrument_id, trade["trade_id"])
            self._notify_trade_entry(trade)
            return

        sl_pts, target_pts = self._sl_target_points(position_side)
        if position_side == "short":
            sl = round(entry_price + sl_pts, 2)
            target = round(max(entry_price - target_pts, 0.05), 2)
        else:
            sl = round(max(entry_price - sl_pts, 0.05), 2)
            target = round(entry_price + target_pts, 2)

        trade = {
            "trade_id": str(uuid.uuid4())[:8],
            "status": "open",
            "trade_mode": self._trade_mode,
            "position_side": position_side,
            "product_type": product_type.value,
            "instrument": self.profile.instrument_id,
            "option_type": option_type,
            "symbol": symbol,
            "strike": strike,
            "expiry_date": expiry,
            "quantity": qty,
            "entry_price": entry_price,
            "current_price": entry_price,
            "stop_loss": sl,
            "target": target,
            "sl_points": sl_pts,
            "target_points": target_pts,
            "sl_distance": sl_pts,
            "target_distance": target_pts,
            "pnl": 0.0,
            "pnl_percentage": 0.0,
            "broker_order_id": result.broker_order_id,
            "smart_order_id": None,
            "smart_order_status": None,
            "signal_reasons": reasons,
            "opened_at": datetime.now().isoformat(timespec="seconds"),
            "closed_at": None,
            "exit_reason": None,
            "pricing_pending": False,
        }

        oco_id, oco_status = self._place_oco_exit(
            symbol, qty, sl, target, position_side,
        )
        if oco_id:
            trade["smart_order_id"] = oco_id
            trade["smart_order_status"] = oco_status
        else:
            logger.warning("OCO failed for %s: %s", symbol, oco_status)

        with self._lock:
            self._current_trade = trade
        if not self._user_broker:
            record_id = insert_auto_trade(
                trade, self.profile, self._underlying_ltp, self._last_signal,
            )
            if record_id:
                trade["record_id"] = record_id
            close_other_open_trades(self.profile.instrument_id, trade["trade_id"])
        self._notify_trade_entry(trade)
        side_label = "SHORT" if position_side == "short" else "LONG"
        strike_label = "ATM" if strike_offset == 0 else f"OTM offset {strike_offset:+d}"
        oco_note = f" OCO {oco_id}" if oco_id else " (manual SL/target)"
        self._status_message = (
            f"{self.profile.label} {side_label} {option_type} {symbol} @ {entry_price:.2f} "
            f"({strike_label} strike {strike}, SL {sl:.2f}, Target {target:.2f}){oco_note}"
        )
        logger.info(self._status_message)

    def _place_futures_entry_trade(
        self,
        broker,
        option_type: str,
        reasons: list[str],
        position_side: str = "long",
    ) -> None:
        """Enter nearest MCX futures contract (manual SL/target monitoring)."""
        contract = self._pick_future_contract(broker)
        if not contract:
            self._status_message = (
                f"Could not resolve nearest {self.profile.label} futures contract"
            )
            return

        symbol, expiry, chain_ltp = contract
        qty = self.profile.lot_size
        order_id = int(time.time()) % 2_000_000_000
        entry_txn = (
            TransactionType.SELL if position_side == "short" else TransactionType.BUY
        )
        product_type = ProductType.NRML

        request = PlaceOrderRequest(
            order_id=order_id,
            user_id=0,
            broker=BrokerType.GROWW,
            symbol=symbol,
            exchange=self.profile.order_exchange,
            transaction_type=entry_txn,
            order_type=OrderType.MARKET,
            product_type=product_type,
            quantity=qty,
        )
        result = self._place_order(request)
        if not result.success:
            self._status_message = f"Entry order failed: {result.message}"
            return

        entry_price = self._fetch_future_price(broker, symbol, hint=chain_ltp)
        if entry_price is None:
            self._status_message = (
                f"Order placed on {symbol} but price could not be verified — "
                "exits disabled until price is available. Check position manually."
            )
            trade = {
                "trade_id": str(uuid.uuid4())[:8],
                "status": "open",
                "trade_mode": self._trade_mode,
                "position_side": position_side,
                "option_type": "FUT",
                "symbol": symbol,
                "strike": None,
                "expiry_date": expiry,
                "quantity": qty,
                "product_type": product_type.value,
                "broker_order_id": result.order_id,
                "signal_reasons": reasons,
                "opened_at": datetime.now().isoformat(timespec="seconds"),
                "entry_price": None,
                "current_price": None,
                "stop_loss": None,
                "target": None,
            }
            with self._lock:
                self._current_trade = trade
            return

        trade = {
            "trade_id": str(uuid.uuid4())[:8],
            "status": "open",
            "trade_mode": self._trade_mode,
            "position_side": position_side,
            "option_type": "FUT",
            "symbol": symbol,
            "strike": None,
            "expiry_date": expiry,
            "quantity": qty,
            "product_type": product_type.value,
            "broker_order_id": result.order_id,
            "signal_reasons": reasons,
            "opened_at": datetime.now().isoformat(timespec="seconds"),
        }
        self._apply_trade_levels(trade, entry_price, position_side)

        with self._lock:
            self._current_trade = trade
        if not self._user_broker:
            record_id = insert_auto_trade(
                trade, self.profile, self._underlying_ltp, self._last_signal,
            )
            if record_id:
                trade["record_id"] = record_id
            close_other_open_trades(self.profile.instrument_id, trade["trade_id"])
        self._notify_trade_entry(trade)
        side_label = "SHORT" if position_side == "short" else "LONG"
        self._status_message = (
            f"{self.profile.label} {side_label} FUT {symbol} @ {entry_price:.2f} "
            f"(SL {trade['stop_loss']:.2f}, Target {trade['target']:.2f}) "
            "(software-managed SL/target)"
        )
        logger.info(self._status_message)

    def _pick_future_contract(
        self, broker,
    ) -> Optional[tuple[str, str, Optional[float]]]:
        """Resolve nearest tradable futures symbol for MCX commodities."""
        if hasattr(broker, "resolve_nearest_mcx_future"):
            resolved = broker.resolve_nearest_mcx_future(self.profile.underlying)
            if resolved:
                return resolved

        symbol = self._resolve_index_future_symbol(broker)
        if not symbol:
            return None
        quote = broker.get_quote(
            symbol, self.profile.option_exchange, self._quote_segment(),
        )
        ltp = quote.last_price if quote.success else None
        expiry = ""
        if len(symbol) >= 7:
            expiry = symbol[-7:]
        return symbol, expiry, ltp

    def _fetch_future_price(
        self,
        broker,
        symbol: str,
        hint: Optional[float] = None,
        retries: int = 5,
        delay: float = 1.0,
    ) -> Optional[float]:
        if self._is_valid_futures_price(hint):
            return float(hint)
        for attempt in range(retries):
            quote = broker.get_quote(
                symbol, self.profile.option_exchange, self._quote_segment(),
            )
            if quote.success and self._is_valid_futures_price(quote.last_price):
                return float(quote.last_price)
            if attempt < retries - 1:
                time.sleep(delay)
        return None

    @staticmethod
    def _is_valid_futures_price(price: Optional[float]) -> bool:
        if price is None:
            return False
        value = float(price)
        return value > 0 and value <= MAX_FUTURES_PRICE

    def _place_oco_exit(
        self,
        symbol: str,
        quantity: int,
        sl: float,
        target: float,
        position_side: str = "long",
    ) -> tuple[Optional[str], str]:
        if self._is_futures_profile():
            return None, "MCX futures use software-managed SL/target"
        ref_id = f"NB{int(time.time()) % 10**10}"[:12]
        close_txn = (
            TransactionType.BUY if position_side == "short" else TransactionType.SELL
        )
        product_type = self._product_type_for_position(position_side)
        request = CreateSmartOrderRequest(
            broker=BrokerType.GROWW,
            smart_order_type=SmartOrderType.OCO,
            reference_id=ref_id,
            segment=Segment.FNO,
            trading_symbol=symbol,
            quantity=quantity,
            product_type=product_type,
            exchange=self.profile.order_exchange,
            duration="DAY",
            net_position_quantity=quantity,
            transaction_type=close_txn,
            target=OCOLeg(
                trigger_price=f"{target:.2f}",
                order_type=OrderType.SL_M,
                price=f"{target:.2f}",
            ),
            stop_loss=OCOLeg(
                trigger_price=f"{sl:.2f}",
                order_type=OrderType.SL_M,
                price=f"{sl:.2f}",
            ),
        )
        result = self._create_smart_order(request)
        if result.success and result.smart_order_id:
            return result.smart_order_id, result.status or "ACTIVE"
        return None, result.message or "OCO create failed"

    @staticmethod
    def _enrich_trade_levels(trade: dict[str, Any]) -> None:
        """Backfill point fields for trades opened before sl_points was stored."""
        entry = trade.get("entry_price")
        sl = trade.get("stop_loss")
        target = trade.get("target")
        if entry is None or sl is None or target is None:
            return

        entry_f = float(entry)
        sl_f = float(sl)
        target_f = float(target)
        if trade.get("sl_points") is None or trade.get("target_points") is None:
            if trade.get("position_side") == "short":
                trade["sl_points"] = round(sl_f - entry_f, 2)
                trade["target_points"] = round(entry_f - target_f, 2)
            else:
                trade["sl_points"] = round(entry_f - sl_f, 2)
                trade["target_points"] = round(target_f - entry_f, 2)

    def _cancel_oco(self, smart_order_id: str) -> None:
        """Cancel an active OCO smart order before manual exit."""
        try:
            result = self._cancel_smart_order(
                smart_order_id,
                CancelSmartOrderRequest(
                    broker=BrokerType.GROWW,
                    smart_order_type=SmartOrderType.OCO,
                    segment=Segment.FNO,
                ),
            )
            if not result.success:
                logger.warning(
                    "OCO cancel failed for %s: %s",
                    smart_order_id,
                    result.message,
                )
        except Exception:
            logger.exception("OCO cancel failed for %s", smart_order_id)

    @staticmethod
    def _oco_leg_price(leg: Any) -> Optional[float]:
        """Read trigger or limit price from an OCO leg."""
        if leg is None:
            return None
        for attr in ("trigger_price", "price"):
            raw = leg.get(attr) if isinstance(leg, dict) else getattr(leg, attr, None)
            if raw is None or raw == "":
                continue
            try:
                value = float(raw)
                if value > 0:
                    return value
            except (TypeError, ValueError):
                continue
        return None

    def _sync_trade_levels_from_oco(
        self, trade: dict[str, Any], oco: SmartOrderResponse,
    ) -> bool:
        """Apply SL/target from Groww OCO (including user modifications)."""
        sl = self._oco_leg_price(oco.stop_loss)
        target = self._oco_leg_price(oco.target)
        changed = False

        if sl is not None and self._is_valid_option_premium(sl, self._underlying_ltp):
            if trade.get("stop_loss") != sl:
                trade["stop_loss"] = sl
                changed = True
        if target is not None and self._is_valid_option_premium(
            target, self._underlying_ltp,
        ):
            if trade.get("target") != target:
                trade["target"] = target
                changed = True

        if changed:
            entry = trade.get("entry_price")
            if entry is not None and self._is_valid_option_premium(
                float(entry), self._underlying_ltp,
            ):
                self._enrich_trade_levels(trade)
            logger.info(
                "Synced SL/target from Groww OCO %s for %s: SL=%s Target=%s",
                oco.smart_order_id,
                trade.get("symbol"),
                trade.get("stop_loss"),
                trade.get("target"),
            )
            self._persist_levels_update(trade)
        return changed

    def _lookup_groww_oco(
        self, trade: dict[str, Any],
    ) -> Optional[SmartOrderResponse]:
        """
        Fetch the OCO for an open trade.

        Re-links if the user cancelled and created a new OCO on Groww.
        """
        symbol = (trade.get("symbol") or "").upper()
        oco_id = trade.get("smart_order_id")

        if oco_id:
            oco = self._get_smart_order(oco_id, "OCO", "FNO")
            if oco.success:
                status = (oco.status or "").upper()
                if status in ("CANCELLED", "EXPIRED", "FAILED"):
                    trade["smart_order_id"] = None
                    trade["smart_order_status"] = status
                    self._status_message = (
                        f"Groww OCO {oco_id} is {status} — "
                        "monitoring SL/target manually"
                    )
                else:
                    trade["smart_order_status"] = oco.status
                    return oco

        listing = self._list_smart_orders(
            broker_type=BrokerType.GROWW,
            segment="FNO",
            smart_order_type="OCO",
            status="ACTIVE",
            page_size=50,
        )
        if not listing.success:
            return None

        for order in listing.orders:
            if (order.trading_symbol or "").upper() != symbol:
                continue
            if (order.status or "").upper() != "ACTIVE":
                continue

            new_id = order.smart_order_id
            if not new_id:
                continue

            if new_id != oco_id:
                trade["smart_order_id"] = new_id
                logger.info(
                    "Re-linked %s to Groww OCO %s",
                    symbol,
                    new_id,
                )

            detail = self._get_smart_order(new_id, "OCO", "FNO")
            if detail.success:
                trade["smart_order_status"] = detail.status
                if order.stop_loss or order.target:
                    self._sync_trade_levels_from_oco(
                        trade,
                        SmartOrderResponse(
                            success=True,
                            smart_order_id=new_id,
                            stop_loss=order.stop_loss,
                            target=order.target,
                        ),
                    )
                return detail
        return None

    @staticmethod
    def _is_trackable_system_trade(trade: Optional[dict[str, Any]]) -> bool:
        """True only for trades placed by the auto-trade engine."""
        if not trade or trade.get("status") != "open":
            return False
        trade_id = str(trade.get("trade_id") or "")
        if trade_id.startswith("broker-"):
            return False
        if trade.get("recovered_from_broker") and not trade.get("broker_order_id"):
            return False
        return True

    def _sync_with_broker_positions(self, broker) -> None:
        """Restore in-memory state from DB when the service restarts.

        Never adopt arbitrary Groww positions — only trades placed by this
        engine (broker_order_id / DB row) are tracked.
        """
        void_untracked_open_trades(self.profile.instrument_id)

        trade = self._current_trade
        if trade and not self._is_trackable_system_trade(trade):
            logger.warning(
                "Dropping untracked position shown as %s auto-trade: %s",
                self.profile.label,
                trade.get("symbol"),
            )
            trade = None
            self._current_trade = None

        db_trade = get_open_auto_trade(self.profile.instrument_id)
        tracked_symbol = None
        if trade and trade.get("status") == "open":
            tracked_symbol = trade.get("symbol")
        elif db_trade:
            tracked_symbol = db_trade.get("symbol")

        if not tracked_symbol:
            return

        sym_key = (tracked_symbol or "").upper()
        if self._broker_position_qty(sym_key) == 0:
            return

        if (
            trade
            and trade.get("status") == "open"
            and (trade.get("symbol") or "").upper() == sym_key
        ):
            return

        if db_trade and (db_trade.get("symbol") or "").upper() == sym_key:
            self._current_trade = db_trade
            self._status_message = (
                f"Restored {self.profile.label} auto-trade from database: {sym_key}"
            )
            logger.info(self._status_message)

    def _list_profile_fno_positions(self) -> list[Any]:
        """Open positions for this instrument (FNO or MCX commodity)."""
        result = self._get_positions()
        if not result.success:
            return []

        prefix = self.profile.futures_prefix.upper()
        expected_segment = self._quote_segment().upper()
        positions: list[Any] = []
        for pos in result.positions:
            segment = (pos.segment or expected_segment).upper()
            if segment != expected_segment:
                continue
            if pos.quantity == 0:
                continue
            symbol = (pos.symbol or "").upper()
            if not symbol.startswith(prefix):
                continue
            positions.append(pos)
        return positions

    def _find_profile_fno_position(
        self, preferred_symbol: Optional[str] = None,
    ) -> Any:
        """Resolve a broker position for a known auto-trade symbol."""
        positions = self._list_profile_fno_positions()
        if not positions:
            return None

        if preferred_symbol:
            key = preferred_symbol.upper()
            for pos in positions:
                if (pos.symbol or "").upper() == key:
                    return pos
            return None

        if len(positions) == 1:
            return positions[0]
        return None

    def _broker_position_qty(self, symbol: str) -> int:
        """Net open quantity on Groww for a symbol (0 if flat)."""
        result = self._get_positions()
        if not result.success:
            return 0
        key = symbol.upper()
        for pos in result.positions:
            if (pos.symbol or "").upper() == key:
                return int(pos.quantity)
        return 0

    def _monitor_open_trade(self, broker) -> None:
        trade = self._current_trade
        if not trade:
            return

        position_side = trade.get("position_side", "long")
        if not self._ensure_valid_trade_pricing(broker, trade):
            return

        self._enrich_trade_levels(trade)
        ltp = self._instrument_ltp(broker, trade["symbol"])
        if ltp is not None:
            entry = trade.get("entry_price")
            price_valid = (
                self._is_valid_futures_price(float(entry))
                if self._is_futures_profile()
                else self._is_valid_option_premium(float(entry), self._underlying_ltp)
            )
            if entry is None or not price_valid:
                return
            entry_f = float(entry)
            qty = int(trade["quantity"])
            trade["current_price"] = ltp
            if position_side == "short":
                trade["pnl"] = round((entry_f - ltp) * qty, 2)
                trade["pnl_percentage"] = (
                    round(((entry_f - ltp) / entry_f) * 100, 2) if entry_f else 0.0
                )
                trade["sl_distance"] = round(float(trade["stop_loss"]) - ltp, 2)
                trade["target_distance"] = round(ltp - float(trade["target"]), 2)
            else:
                trade["pnl"] = round((ltp - entry_f) * qty, 2)
                trade["pnl_percentage"] = (
                    round(((ltp - entry_f) / entry_f) * 100, 2) if entry_f else 0.0
                )
                trade["sl_distance"] = round(ltp - float(trade["stop_loss"]), 2)
                trade["target_distance"] = round(float(trade["target"]) - ltp, 2)

        oco = self._lookup_groww_oco(trade)
        if oco:
            if self._sync_trade_levels_from_oco(trade, oco):
                self._status_message = (
                    f"{self.profile.label} SL/target synced from Groww OCO"
                )

            status = (oco.status or "").upper()
            trade["smart_order_status"] = oco.status

            if status in ("TRIGGERED", "COMPLETED"):
                broker_qty = self._broker_position_qty(trade["symbol"])
                if broker_qty == 0:
                    if ltp is None:
                        reason = "oco_exit"
                    elif position_side == "short":
                        reason = (
                            "oco_target_hit"
                            if ltp <= float(trade["target"])
                            else "oco_stop_loss_hit"
                        )
                    else:
                        reason = (
                            "oco_target_hit"
                            if ltp >= float(trade["target"])
                            else "oco_stop_loss_hit"
                        )
                    self._exit_trade(broker, reason, skip_close=True)
                    return
                logger.info(
                    "OCO %s for %s but broker qty=%s — keeping trade visible",
                    status,
                    trade.get("symbol"),
                    broker_qty,
                )

        if ltp is None:
            return

        if trade.get("stop_loss") is None or trade.get("target") is None:
            return

        # Backup LTP exit only when broker still shows an open position.
        if self._broker_position_qty(trade["symbol"]) == 0:
            self._exit_trade(broker, "broker_position_closed", skip_close=True)
            return

        # Always check LTP vs SL/target (backup when OCO is slow or inactive).
        if position_side == "short":
            if ltp >= float(trade["stop_loss"]):
                self._exit_trade(broker, "stop_loss_hit")
            elif ltp <= float(trade["target"]):
                self._exit_trade(broker, "target_hit")
        else:
            if ltp <= float(trade["stop_loss"]):
                self._exit_trade(broker, "stop_loss_hit")
            elif ltp >= float(trade["target"]):
                self._exit_trade(broker, "target_hit")

        self._maybe_persist_snapshot(trade)

    def _maybe_persist_snapshot(self, trade: dict[str, Any]) -> None:
        """Throttle DB updates while a trade is open."""
        record_id = trade.get("record_id")
        if not record_id:
            return
        now = time.time()
        last = float(trade.get("_last_db_sync") or 0)
        if now - last < 15:
            return
        update_auto_trade(int(record_id), trade)
        trade["_last_db_sync"] = now

    def _persist_open_trade(self, trade: dict[str, Any]) -> None:
        """Save new trade to DB if not already stored."""
        if trade.get("record_id"):
            return
        record_id = insert_auto_trade(
            trade, self.profile, self._underlying_ltp, self._last_signal,
        )
        if record_id:
            trade["record_id"] = record_id

    def _persist_levels_update(self, trade: dict[str, Any]) -> None:
        """Push SL/target/OCO changes to DB immediately."""
        record_id = trade.get("record_id")
        if record_id:
            update_auto_trade(int(record_id), trade)
            trade["_last_db_sync"] = time.time()
        elif trade.get("status") == "open":
            self._persist_open_trade(trade)
            record_id = trade.get("record_id")
            if record_id:
                update_auto_trade(int(record_id), trade)

    def _exit_trade(self, broker, reason: str, skip_close: bool = False) -> None:
        trade = self._current_trade
        if not trade or trade.get("status") != "open":
            return

        position_side = trade.get("position_side", "long")
        trade["status"] = "exiting"
        result = None
        exit_broker_order_id = None
        if not skip_close:
            close_txn = (
                TransactionType.BUY
                if position_side == "short"
                else TransactionType.SELL
            )
            order_id = int(time.time()) % 2_000_000_000
            product_type = self._resolve_trade_product_type(trade, position_side)
            request = PlaceOrderRequest(
                order_id=order_id,
                user_id=0,
                broker=BrokerType.GROWW,
                symbol=trade["symbol"],
                exchange=self.profile.order_exchange,
                transaction_type=close_txn,
                order_type=OrderType.MARKET,
                product_type=product_type,
                quantity=int(trade["quantity"]),
            )
            result = self._place_order(request)
            if result.success and result.broker_order_id:
                exit_broker_order_id = result.broker_order_id

        exit_price = self._fetch_option_price(
            broker, trade["symbol"], hint=trade.get("current_price"),
        ) or trade.get("current_price")
        entry = trade.get("entry_price")
        qty = int(trade["quantity"])
        if (
            exit_price is not None
            and entry is not None
            and self._is_valid_option_premium(float(entry), self._underlying_ltp)
            and self._is_valid_option_premium(float(exit_price), self._underlying_ltp)
        ):
            entry_f = float(entry)
            exit_f = float(exit_price)
            if position_side == "short":
                trade["pnl"] = round((entry_f - exit_f) * qty, 2)
                trade["pnl_percentage"] = round(
                    ((entry_f - exit_f) / entry_f) * 100, 2,
                )
            else:
                trade["pnl"] = round((exit_f - entry_f) * qty, 2)
                trade["pnl_percentage"] = round(
                    ((exit_f - entry_f) / entry_f) * 100, 2,
                )
        else:
            trade["pnl"] = 0.0
            trade["pnl_percentage"] = 0.0

        trade["status"] = "closed"
        trade["exit_reason"] = reason
        trade["closed_at"] = datetime.now().isoformat(timespec="seconds")
        if exit_price is not None:
            trade["current_price"] = float(exit_price)

        record_id = trade.get("record_id")
        if record_id:
            close_auto_trade(int(record_id), trade, exit_broker_order_id)
        else:
            self._persist_open_trade(trade)
            record_id = trade.get("record_id")
            if record_id:
                close_auto_trade(int(record_id), trade, exit_broker_order_id)

        with self._lock:
            self._trade_history.append(dict(trade))
            self._current_trade = None
            self._active = False
            self._stop_event.set()

        msg = (
            f"{self.profile.label} exited {trade['symbol']} — {reason} "
            f"(P&L ₹{trade.get('pnl', 0):.2f}). "
        )
        if reason == "manual_exit":
            msg += "Position closed manually. "
        msg += "Auto trade deactivated — click Activate for the next trade."
        if result is not None and not result.success:
            msg += f" Exit warning: {result.message}"
        self._status_message = msg
        logger.info(msg)

    def _pick_option_contract(
        self,
        broker,
        option_type: str,
        strike_offset: int = 0,
    ) -> Optional[tuple[str, float, str, Optional[float]]]:
        """Pick weekly expiry contract at ATM or OTM offset."""
        expiries = weekly_expiries(self.profile.option_exchange, 3)
        if not expiries:
            return None

        index_ltp = self._underlying_ltp or self._fetch_index_ltp(broker)
        if index_ltp is None:
            return None

        for expiry in expiries:
            chain = broker.get_option_chain(
                self.profile.option_exchange,
                self.profile.underlying,
                expiry,
            )
            if not chain.success or not chain.strikes:
                continue

            ltp = chain.underlying_ltp or index_ltp
            strike_key = resolve_chain_strike(
                float(ltp),
                self.profile.strike_step,
                list(chain.strikes.keys()),
                strike_offset=strike_offset,
            )
            if strike_key is None or strike_key not in chain.strikes:
                continue

            leg = (
                chain.strikes[strike_key].CE
                if option_type == "CE"
                else chain.strikes[strike_key].PE
            )
            if leg and leg.trading_symbol:
                return leg.trading_symbol, float(strike_key), expiry, leg.ltp

        return None

    @staticmethod
    def _is_valid_option_premium(
        price: Optional[float],
        underlying_ltp: Optional[float] = None,
    ) -> bool:
        """Reject index-scale prices mistakenly used as option premium."""
        if price is None:
            return False
        premium = float(price)
        if premium <= 0 or premium > MAX_OPTION_PREMIUM:
            return False
        if underlying_ltp and underlying_ltp > 0:
            if premium >= float(underlying_ltp) * 0.15:
                return False
        return True

    def _fetch_option_price(
        self,
        broker,
        symbol: str,
        hint: Optional[float] = None,
        retries: int = 5,
        delay: float = 1.0,
    ) -> Optional[float]:
        """Resolve option premium with retries; never fall back to index LTP."""
        underlying = self._underlying_ltp
        if self._is_valid_option_premium(hint, underlying):
            return float(hint)

        for attempt in range(retries):
            ltp = self._option_ltp(broker, symbol)
            if self._is_valid_option_premium(ltp, underlying):
                return float(ltp)
            if attempt < retries - 1:
                time.sleep(delay)
        return None

    def _apply_trade_levels(
        self,
        trade: dict[str, Any],
        entry_price: float,
        position_side: str,
    ) -> None:
        """Set entry, SL, and target from a validated option premium."""
        base_sl, base_target = self._sl_target_points(position_side)
        sl_pts = volatility_adjusted_sl_points(
            entry_price,
            self._last_signal_bars,
            base_sl,
        )
        target_pts = base_target
        if base_sl > 0 and sl_pts > base_sl:
            target_pts = round(base_target * (sl_pts / base_sl), 2)

        if position_side == "short":
            sl = round(entry_price + sl_pts, 2)
            target = round(max(entry_price - target_pts, 0.05), 2)
        else:
            sl = round(max(entry_price - sl_pts, 0.05), 2)
            target = round(entry_price + target_pts, 2)

        trade["entry_price"] = entry_price
        trade["current_price"] = entry_price
        trade["stop_loss"] = sl
        trade["target"] = target
        trade["sl_points"] = sl_pts
        trade["target_points"] = target_pts
        trade["sl_distance"] = sl_pts
        trade["target_distance"] = target_pts
        trade["pricing_pending"] = False

    def _ensure_valid_trade_pricing(self, broker, trade: dict[str, Any]) -> bool:
        """
        Ensure entry/SL/target use option premium, not index LTP.

        Returns False when exits must be skipped (price not yet available).
        """
        entry = trade.get("entry_price")
        underlying = self._underlying_ltp
        symbol = trade.get("symbol", "")
        position_side = trade.get("position_side", "long")

        if self._is_valid_option_premium(entry, underlying):
            if trade.get("stop_loss") is None or trade.get("target") is None:
                self._apply_trade_levels(trade, float(entry), position_side)
            return True

        repaired = self._fetch_option_price(
            broker, symbol, hint=trade.get("current_price"), retries=3, delay=0.5,
        )
        if repaired is None:
            return False

        self._apply_trade_levels(trade, repaired, position_side)
        if not self._is_valid_option_premium(entry, underlying) and entry is not None:
            logger.warning(
                "Repaired %s entry price from %s to %s",
                symbol,
                entry,
                repaired,
            )
        if not trade.get("smart_order_id") and trade.get("stop_loss") is not None:
            oco_id, oco_status = self._place_oco_exit(
                symbol,
                int(trade["quantity"]),
                float(trade["stop_loss"]),
                float(trade["target"]),
                position_side,
            )
            if oco_id:
                trade["smart_order_id"] = oco_id
                trade["smart_order_status"] = oco_status
                self._persist_levels_update(trade)
        return True

    def _fetch_index_ltp(self, broker) -> Optional[float]:
        if self._is_futures_profile():
            symbol = self._resolve_index_future_symbol(broker)
            if symbol:
                quote = broker.get_quote(
                    symbol, self.profile.option_exchange, self._quote_segment(),
                )
                if quote.success and quote.last_price:
                    return float(quote.last_price)
        quote = broker.get_quote(
            self.profile.underlying,
            self.profile.cash_exchange,
            self._quote_segment() if self._is_futures_profile() else "CASH",
        )
        return quote.last_price if quote.success else None

    def _instrument_ltp(self, broker, symbol: str) -> Optional[float]:
        if self._is_futures_profile():
            return self._fetch_future_price(broker, symbol)
        return self._option_ltp(broker, symbol)

    def _option_ltp(self, broker, symbol: str) -> Optional[float]:
        quote = broker.get_quote(
            symbol, self.profile.option_exchange, self._quote_segment(),
        )
        return quote.last_price if quote.success else None

    def _resolve_index_future_symbol(self, broker) -> Optional[str]:
        if self._is_futures_profile() and hasattr(broker, "resolve_nearest_mcx_future"):
            resolved = broker.resolve_nearest_mcx_future(self.profile.underlying)
            if resolved:
                return resolved[0]

        today = datetime.now().date()
        prefix = self.profile.futures_prefix
        candidates: list[str] = []
        for offset in range(0, 3):
            month = today.month + offset
            year = today.year
            while month > 12:
                month -= 12
                year += 1
            yy = str(year)[-2:]
            mon = MONTH_CODES[month - 1]
            candidates.append(f"{prefix}{yy}{mon}FUT")

        for symbol in candidates:
            quote = broker.get_quote(
                symbol, self.profile.option_exchange, self._quote_segment(),
            )
            if quote.success and quote.last_price:
                return symbol
        return candidates[0] if candidates else None

    @staticmethod
    def _sanitize_history_trade(raw: dict[str, Any]) -> dict[str, Any]:
        """Hide bogus P&L when entry was stored at index scale by mistake."""
        trade = dict(raw)
        entry = trade.get("entry_price")
        if entry is not None and float(entry) > MAX_OPTION_PREMIUM:
            trade["pnl"] = None
            trade["pnl_percentage"] = None
            trade["entry_price"] = None
        return trade

    @staticmethod
    def _serialize_trade(raw: Optional[dict[str, Any]]) -> Optional[AutoTradePosition]:
        if not raw:
            return None
        return AutoTradePosition(
            record_id=raw.get("record_id"),
            trade_id=raw.get("trade_id", ""),
            status=raw.get("status", ""),
            trade_mode=raw.get("trade_mode", "buy"),
            position_side=raw.get("position_side", "long"),
            option_type=raw.get("option_type", ""),
            symbol=raw.get("symbol", ""),
            strike=raw.get("strike"),
            expiry_date=raw.get("expiry_date", ""),
            quantity=raw.get("quantity", 0),
            entry_price=raw.get("entry_price"),
            current_price=raw.get("current_price"),
            stop_loss=raw.get("stop_loss"),
            target=raw.get("target"),
            sl_points=raw.get("sl_points"),
            target_points=raw.get("target_points"),
            sl_distance=raw.get("sl_distance"),
            target_distance=raw.get("target_distance"),
            pnl=raw.get("pnl"),
            pnl_percentage=raw.get("pnl_percentage"),
            broker_order_id=raw.get("broker_order_id"),
            smart_order_id=raw.get("smart_order_id"),
            smart_order_status=raw.get("smart_order_status"),
            exit_reason=raw.get("exit_reason"),
            signal_reasons=raw.get("signal_reasons", []),
            opened_at=raw.get("opened_at"),
            closed_at=raw.get("closed_at"),
        )


def _build_services() -> dict[str, AutoTradeService]:
    return {
        profile.instrument_id: AutoTradeService(profile)
        for profile in PROFILES.values()
    }


AUTO_TRADE_SERVICES = _build_services()


def get_auto_trade_service(instrument_id: str) -> AutoTradeService:
    key = instrument_id.strip().lower()
    if key not in AUTO_TRADE_SERVICES:
        raise ValueError(f"Unknown instrument: {instrument_id}")
    return AUTO_TRADE_SERVICES[key]


# Backward-compatible default export.
auto_trade_service = AUTO_TRADE_SERVICES["nifty"]
