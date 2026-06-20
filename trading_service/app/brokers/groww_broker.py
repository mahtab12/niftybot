"""Groww broker adapter using the official growwapi Python SDK."""

import logging
from typing import Optional

import pyotp
from growwapi import GrowwAPI

from app.config import settings
from app.brokers.base import BaseBroker
from app.models.schemas import (
    AvailableMarginResponse,
    CancelOrderResponse,
    CancelSmartOrderRequest,
    CommodityMarginDetails,
    CreateSmartOrderRequest,
    DepthEntry,
    EquityMarginDetails,
    Exchange,
    FnoMarginDetails,
    Greeks,
    GreeksResponse,
    GTTOrderLeg,
    HoldingItem,
    HoldingsResponse,
    LTPResponse,
    MarketDepth,
    ModifyOrderRequest,
    ModifyOrderResponse,
    ModifySmartOrderRequest,
    OCOLeg,
    OHLCData,
    OHLCResponse,
    OptionChainResponse,
    OptionData,
    OrderMarginRequest,
    OrderMarginResponse,
    OrderStatus,
    OrderStatusResponse,
    OrderType,
    PlaceOrderRequest,
    PlaceOrderResponse,
    PositionItem,
    PositionsResponse,
    ProductType,
    QuoteResponse,
    Segment,
    SmartOrderLegResponse,
    SmartOrderListResponse,
    SmartOrderOCOLegResponse,
    SmartOrderResponse,
    SmartOrderSummary,
    SmartOrderType,
    StrikeData,
    TransactionType,
    TriggerDirection,
    UserProfileResponse,
)

logger = logging.getLogger("niftybot.groww")

# Map our enums to Groww SDK constants
EXCHANGE_MAP = {
    Exchange.NSE: "NSE",
    Exchange.BSE: "BSE",
    Exchange.NFO: "NSE",
    Exchange.MCX: "MCX",
}

SEGMENT_MAP = {
    Exchange.NSE: "CASH",
    Exchange.BSE: "CASH",
    Exchange.NFO: "FNO",
    Exchange.MCX: "COMMODITY",
}

PRODUCT_MAP = {
    ProductType.CNC: "CNC",
    ProductType.MIS: "MIS",
    ProductType.NRML: "NRML",
}

ORDER_TYPE_MAP = {
    OrderType.MARKET: "MARKET",
    OrderType.LIMIT: "LIMIT",
    OrderType.SL: "SL",
    OrderType.SL_M: "SL-M",
}

TRANSACTION_MAP = {
    TransactionType.BUY: "BUY",
    TransactionType.SELL: "SELL",
}

SMART_ORDER_TYPE_MAP = {
    OrderType.MARKET: "MARKET",
    OrderType.LIMIT: "LIMIT",
    OrderType.SL: "STOP_LOSS",
    OrderType.SL_M: "STOP_LOSS_MARKET",
}

SEGMENT_DIRECT_MAP = {
    Segment.CASH: "CASH",
    Segment.FNO: "FNO",
}


class GrowwBroker(BaseBroker):
    """Groww broker integration using growwapi SDK."""

    def __init__(self):
        self._client: Optional[GrowwAPI] = None
        self._connected = False

    def connect(self) -> bool:
        """Authenticate with Groww using configured credentials."""
        try:
            if settings.groww_auth_method == "totp":
                access_token = self._auth_totp()
            else:
                access_token = self._auth_api_key()

            self._client = GrowwAPI(access_token)
            self._connected = True
            logger.info("Successfully connected to Groww API")
            return True

        except Exception:
            logger.exception("Failed to connect to Groww API")
            self._connected = False
            return False

    def _auth_api_key(self) -> str:
        """Authenticate using API Key + Secret flow."""
        return GrowwAPI.get_access_token(
            api_key=settings.groww_api_key,
            secret=settings.groww_api_secret,
        )

    def _auth_totp(self) -> str:
        """Authenticate using TOTP flow."""
        totp_gen = pyotp.TOTP(settings.groww_totp_secret)
        totp = totp_gen.now()
        return GrowwAPI.get_access_token(
            api_key=settings.groww_api_key,
            totp=totp,
        )

    def is_connected(self) -> bool:
        return self._connected and self._client is not None

    def _ensure_connected(self):
        """Reconnect if session expired."""
        if not self.is_connected():
            self.connect()
        if not self.is_connected():
            raise ConnectionError("Unable to connect to Groww API")

    def place_order(self, request: PlaceOrderRequest) -> PlaceOrderResponse:
        """Place an order through Groww."""
        self._ensure_connected()

        try:
            groww = self._client

            order_params = {
                "trading_symbol": request.symbol,
                "quantity": request.quantity,
                "validity": groww.VALIDITY_DAY,
                "exchange": getattr(groww, f"EXCHANGE_{EXCHANGE_MAP[request.exchange]}"),
                "segment": getattr(groww, f"SEGMENT_{SEGMENT_MAP[request.exchange]}"),
                "product": getattr(groww, f"PRODUCT_{PRODUCT_MAP[request.product_type]}"),
                "order_type": getattr(groww, f"ORDER_TYPE_{ORDER_TYPE_MAP[request.order_type]}"),
                "transaction_type": getattr(groww, f"TRANSACTION_TYPE_{TRANSACTION_MAP[request.transaction_type]}"),
            }

            if request.price is not None:
                order_params["price"] = request.price

            if request.trigger_price is not None:
                order_params["trigger_price"] = request.trigger_price

            order_params["order_reference_id"] = f"NB-{request.order_id}"

            response = groww.place_order(**order_params)

            logger.info(
                "Order placed: symbol=%s, type=%s, qty=%d, response=%s",
                request.symbol,
                request.transaction_type.value,
                request.quantity,
                response,
            )

            broker_order_id = self._extract_order_id(response)

            return PlaceOrderResponse(
                success=True,
                order_id=request.order_id,
                broker_order_id=broker_order_id,
                status=OrderStatus.PLACED,
                message="Order placed successfully on Groww",
            )

        except Exception as e:
            logger.exception(
                "Failed to place order: symbol=%s", request.symbol
            )
            return PlaceOrderResponse(
                success=False,
                order_id=request.order_id,
                broker_order_id=None,
                status=OrderStatus.FAILED,
                message=f"Order placement failed: {e}",
            )

    def cancel_order(self, broker_order_id: str) -> CancelOrderResponse:
        """Cancel an order on Groww."""
        self._ensure_connected()

        try:
            response = self._client.cancel_order(groww_order_id=broker_order_id)

            logger.info("Order cancelled: %s, response=%s", broker_order_id, response)

            return CancelOrderResponse(
                success=True,
                order_id=broker_order_id,
                message="Order cancelled successfully",
            )

        except Exception as e:
            logger.exception("Failed to cancel order: %s", broker_order_id)
            return CancelOrderResponse(
                success=False,
                order_id=broker_order_id,
                message=f"Cancel failed: {e}",
            )

    def modify_order(self, request: ModifyOrderRequest) -> ModifyOrderResponse:
        """Modify a pending/open order on Groww."""
        self._ensure_connected()

        try:
            groww = self._client

            modify_params = {
                "groww_order_id": request.groww_order_id,
                "segment": groww.SEGMENT_CASH,
            }

            if request.quantity is not None:
                modify_params["quantity"] = request.quantity

            if request.order_type is not None:
                modify_params["order_type"] = getattr(
                    groww, f"ORDER_TYPE_{ORDER_TYPE_MAP[request.order_type]}"
                )

            if request.price is not None:
                modify_params["price"] = request.price

            if request.trigger_price is not None:
                modify_params["trigger_price"] = request.trigger_price

            response = groww.modify_order(**modify_params)

            logger.info(
                "Order modified: %s, response=%s",
                request.groww_order_id,
                response,
            )

            order_status = None
            if isinstance(response, dict):
                order_status = response.get("order_status")

            return ModifyOrderResponse(
                success=True,
                groww_order_id=request.groww_order_id,
                order_status=order_status,
                message="Order modified successfully",
            )

        except Exception as e:
            logger.exception("Failed to modify order: %s", request.groww_order_id)
            return ModifyOrderResponse(
                success=False,
                groww_order_id=request.groww_order_id,
                message=f"Modify failed: {e}",
            )

    def get_order_status(self, broker_order_id: str) -> OrderStatusResponse:
        """Get order status from Groww by searching the order book."""
        self._ensure_connected()

        try:
            order_book = self._client.get_order_book()

            for order in order_book if isinstance(order_book, list) else []:
                groww_id = str(order.get("groww_order_id", "") or order.get("orderId", ""))
                ref_id = str(order.get("order_reference_id", ""))

                if groww_id == str(broker_order_id) or ref_id == str(broker_order_id):
                    return OrderStatusResponse(
                        order_id=broker_order_id,
                        broker_order_id=groww_id,
                        status=self._map_groww_status(
                            order.get("order_status", "") or order.get("orderStatus", "")
                        ),
                        executed_price=order.get("averagePrice"),
                        executed_quantity=order.get("filledQuantity"),
                        message=order.get("remark", "") or order.get("statusMessage", ""),
                    )

            return OrderStatusResponse(
                order_id=broker_order_id,
                status=OrderStatus.PENDING,
                message="Order not found in order book",
            )

        except Exception as e:
            logger.exception("Failed to get order status: %s", broker_order_id)
            return OrderStatusResponse(
                order_id=broker_order_id,
                status=OrderStatus.PENDING,
                message=f"Status check failed: {e}",
            )

    def get_positions(self) -> PositionsResponse:
        """Get open positions from Groww."""
        self._ensure_connected()

        try:
            raw_positions = self._client.get_positions()
            positions = []

            if isinstance(raw_positions, list):
                for pos in raw_positions:
                    qty = int(pos.get("quantity", 0) or 0)
                    if qty == 0:
                        continue

                    avg_price = float(pos.get("averagePrice", 0) or 0)
                    current_price = float(pos.get("lastTradedPrice", 0) or 0)
                    pnl = (current_price - avg_price) * qty
                    pnl_pct = ((current_price - avg_price) / avg_price * 100) if avg_price > 0 else 0

                    positions.append(PositionItem(
                        symbol=pos.get("tradingSymbol", ""),
                        exchange=pos.get("exchange", "NSE"),
                        quantity=qty,
                        average_price=avg_price,
                        current_price=current_price,
                        pnl=round(pnl, 2),
                        pnl_percentage=round(pnl_pct, 2),
                        product_type=pos.get("product", "CNC"),
                    ))

            return PositionsResponse(
                success=True,
                positions=positions,
                message=f"Found {len(positions)} positions",
            )

        except Exception as e:
            logger.exception("Failed to get positions")
            return PositionsResponse(
                success=False,
                positions=[],
                message=f"Failed to fetch positions: {e}",
            )

    def get_holdings(self) -> HoldingsResponse:
        """Get portfolio holdings from Groww."""
        self._ensure_connected()

        try:
            raw_holdings = self._client.get_holdings()
            holdings = []

            if isinstance(raw_holdings, list):
                for holding in raw_holdings:
                    qty = int(holding.get("quantity", 0) or 0)
                    if qty == 0:
                        continue

                    avg_price = float(holding.get("averagePrice", 0) or 0)
                    current_price = float(holding.get("lastTradedPrice", 0) or 0)
                    pnl = (current_price - avg_price) * qty

                    holdings.append(HoldingItem(
                        symbol=holding.get("tradingSymbol", ""),
                        exchange=holding.get("exchange", "NSE"),
                        quantity=qty,
                        average_price=avg_price,
                        current_price=current_price,
                        pnl=round(pnl, 2),
                    ))

            return HoldingsResponse(
                success=True,
                holdings=holdings,
                message=f"Found {len(holdings)} holdings",
            )

        except Exception as e:
            logger.exception("Failed to get holdings")
            return HoldingsResponse(
                success=False,
                holdings=[],
                message=f"Failed to fetch holdings: {e}",
            )

    def get_order_book(self) -> list[dict]:
        """Get all orders for the day."""
        self._ensure_connected()

        try:
            return self._client.get_order_book() or []
        except Exception:
            logger.exception("Failed to get order book")
            return []

    def get_ltp(self, symbol: str, exchange: str) -> Optional[float]:
        """Get last traded price for a single symbol (legacy helper)."""
        result = self.get_ltp_multi([f"{exchange}_{symbol}"])
        if result.success:
            key = f"{exchange}_{symbol}"
            return result.prices.get(key)
        return None

    def get_quote(
        self, symbol: str, exchange: str, segment: str = "CASH"
    ) -> QuoteResponse:
        """Fetch a full real-time quote for a single instrument."""
        self._ensure_connected()

        try:
            groww = self._client
            data = groww.get_quote(
                exchange=getattr(groww, f"EXCHANGE_{exchange}"),
                segment=getattr(groww, f"SEGMENT_{segment}"),
                trading_symbol=symbol,
            )

            if not isinstance(data, dict):
                return QuoteResponse(success=False, message="Unexpected response")

            ohlc = None
            if isinstance(data.get("ohlc"), dict):
                ohlc = OHLCData(**data["ohlc"])

            depth = None
            raw_depth = data.get("depth")
            if isinstance(raw_depth, dict):
                depth = MarketDepth(
                    buy=[DepthEntry(**e) for e in raw_depth.get("buy", []) if isinstance(e, dict)],
                    sell=[DepthEntry(**e) for e in raw_depth.get("sell", []) if isinstance(e, dict)],
                )

            return QuoteResponse(
                success=True,
                symbol=symbol,
                exchange=exchange,
                last_price=data.get("last_price"),
                average_price=data.get("average_price"),
                volume=data.get("volume"),
                bid_price=data.get("bid_price"),
                bid_quantity=data.get("bid_quantity"),
                offer_price=data.get("offer_price"),
                offer_quantity=data.get("offer_quantity"),
                day_change=data.get("day_change"),
                day_change_perc=data.get("day_change_perc"),
                upper_circuit_limit=data.get("upper_circuit_limit"),
                lower_circuit_limit=data.get("lower_circuit_limit"),
                ohlc=ohlc,
                depth=depth,
                total_buy_quantity=data.get("total_buy_quantity"),
                total_sell_quantity=data.get("total_sell_quantity"),
                last_trade_quantity=data.get("last_trade_quantity"),
                last_trade_time=data.get("last_trade_time"),
                open_interest=data.get("open_interest"),
                oi_day_change=data.get("oi_day_change"),
                oi_day_change_percentage=data.get("oi_day_change_percentage"),
                week_52_high=data.get("week_52_high"),
                week_52_low=data.get("week_52_low"),
                market_cap=data.get("market_cap"),
                implied_volatility=data.get("implied_volatility"),
                message="Quote fetched successfully",
            )

        except Exception as e:
            logger.exception("Failed to get quote for %s", symbol)
            return QuoteResponse(
                success=False, symbol=symbol, exchange=exchange,
                message=f"Quote failed: {e}",
            )

    def get_ltp_multi(
        self, exchange_trading_symbols: list[str], segment: str = "CASH"
    ) -> LTPResponse:
        """Fetch LTP for up to 50 instruments. Symbols as 'NSE_RELIANCE'."""
        self._ensure_connected()

        try:
            groww = self._client
            symbols = tuple(exchange_trading_symbols)

            data = groww.get_ltp(
                segment=getattr(groww, f"SEGMENT_{segment}"),
                exchange_trading_symbols=symbols if len(symbols) > 1 else symbols[0],
            )

            if not isinstance(data, dict):
                return LTPResponse(success=False, message="Unexpected response")

            return LTPResponse(
                success=True,
                prices={k: float(v) for k, v in data.items()},
                message=f"LTP for {len(data)} instruments",
            )

        except Exception as e:
            logger.exception("Failed to get LTP")
            return LTPResponse(success=False, message=f"LTP failed: {e}")

    def get_ohlc(
        self, exchange_trading_symbols: list[str], segment: str = "CASH"
    ) -> OHLCResponse:
        """Fetch OHLC for up to 50 instruments."""
        self._ensure_connected()

        try:
            groww = self._client
            symbols = tuple(exchange_trading_symbols)

            data = groww.get_ohlc(
                segment=getattr(groww, f"SEGMENT_{segment}"),
                exchange_trading_symbols=symbols if len(symbols) > 1 else symbols[0],
            )

            if not isinstance(data, dict):
                return OHLCResponse(success=False, message="Unexpected response")

            ohlc_map = {}
            for key, val in data.items():
                if isinstance(val, dict):
                    ohlc_map[key] = OHLCData(**val)

            return OHLCResponse(
                success=True,
                data=ohlc_map,
                message=f"OHLC for {len(ohlc_map)} instruments",
            )

        except Exception as e:
            logger.exception("Failed to get OHLC")
            return OHLCResponse(success=False, message=f"OHLC failed: {e}")

    def get_option_chain(
        self, exchange: str, underlying: str, expiry_date: str
    ) -> OptionChainResponse:
        """Fetch full option chain with Greeks for an underlying + expiry."""
        self._ensure_connected()

        try:
            groww = self._client
            data = groww.get_option_chain(
                exchange=getattr(groww, f"EXCHANGE_{exchange}"),
                underlying=underlying,
                expiry_date=expiry_date,
            )

            if not isinstance(data, dict):
                return OptionChainResponse(
                    success=False, underlying=underlying,
                    message="Unexpected response",
                )

            strikes = {}
            raw_strikes = data.get("strikes", {})
            for strike_price, strike_val in raw_strikes.items():
                if not isinstance(strike_val, dict):
                    continue

                ce = None
                pe = None

                if isinstance(strike_val.get("CE"), dict):
                    ce_raw = strike_val["CE"]
                    ce_greeks = None
                    if isinstance(ce_raw.get("greeks"), dict):
                        ce_greeks = Greeks(**ce_raw["greeks"])
                    ce = OptionData(
                        trading_symbol=ce_raw.get("trading_symbol", ""),
                        ltp=ce_raw.get("ltp"),
                        open_interest=ce_raw.get("open_interest"),
                        volume=ce_raw.get("volume"),
                        greeks=ce_greeks,
                    )

                if isinstance(strike_val.get("PE"), dict):
                    pe_raw = strike_val["PE"]
                    pe_greeks = None
                    if isinstance(pe_raw.get("greeks"), dict):
                        pe_greeks = Greeks(**pe_raw["greeks"])
                    pe = OptionData(
                        trading_symbol=pe_raw.get("trading_symbol", ""),
                        ltp=pe_raw.get("ltp"),
                        open_interest=pe_raw.get("open_interest"),
                        volume=pe_raw.get("volume"),
                        greeks=pe_greeks,
                    )

                strikes[strike_price] = StrikeData(CE=ce, PE=pe)

            return OptionChainResponse(
                success=True,
                underlying=underlying,
                underlying_ltp=data.get("underlying_ltp"),
                strikes=strikes,
                message=f"Option chain with {len(strikes)} strikes",
            )

        except Exception as e:
            logger.exception("Failed to get option chain for %s", underlying)
            return OptionChainResponse(
                success=False, underlying=underlying,
                message=f"Option chain failed: {e}",
            )

    def get_greeks(
        self, exchange: str, underlying: str,
        trading_symbol: str, expiry: str,
    ) -> GreeksResponse:
        """Fetch Greeks for a single options contract."""
        self._ensure_connected()

        try:
            groww = self._client
            data = groww.get_greeks(
                exchange=getattr(groww, f"EXCHANGE_{exchange}"),
                underlying=underlying,
                trading_symbol=trading_symbol,
                expiry=expiry,
            )

            if not isinstance(data, dict):
                return GreeksResponse(
                    success=False, trading_symbol=trading_symbol,
                    message="Unexpected response",
                )

            greeks = None
            if isinstance(data.get("greeks"), dict):
                greeks = Greeks(**data["greeks"])

            return GreeksResponse(
                success=True,
                trading_symbol=trading_symbol,
                greeks=greeks,
                message="Greeks fetched successfully",
            )

        except Exception as e:
            logger.exception("Failed to get greeks for %s", trading_symbol)
            return GreeksResponse(
                success=False, trading_symbol=trading_symbol,
                message=f"Greeks failed: {e}",
            )

    def get_user_profile(self) -> UserProfileResponse:
        """Get authenticated user's profile from Groww."""
        self._ensure_connected()

        try:
            profile = self._client.get_user_profile()

            if not isinstance(profile, dict):
                return UserProfileResponse(
                    success=False,
                    message="Unexpected response from Groww",
                )

            return UserProfileResponse(
                success=True,
                vendor_user_id=profile.get("vendor_user_id"),
                ucc=profile.get("ucc"),
                nse_enabled=profile.get("nse_enabled", False),
                bse_enabled=profile.get("bse_enabled", False),
                ddpi_enabled=profile.get("ddpi_enabled", False),
                active_segments=profile.get("active_segments", []),
                message="Profile fetched successfully",
            )

        except Exception as e:
            logger.exception("Failed to get user profile")
            return UserProfileResponse(
                success=False,
                message=f"Failed to fetch profile: {e}",
            )

    def create_smart_order(self, request: CreateSmartOrderRequest) -> SmartOrderResponse:
        """Create a GTT or OCO smart order."""
        self._ensure_connected()

        try:
            groww = self._client
            params = {
                "smart_order_type": getattr(
                    groww, f"SMART_ORDER_TYPE_{request.smart_order_type.value}"
                ),
                "reference_id": request.reference_id,
                "segment": getattr(groww, f"SEGMENT_{SEGMENT_DIRECT_MAP[request.segment]}"),
                "trading_symbol": request.trading_symbol,
                "quantity": request.quantity,
                "product_type": getattr(groww, f"PRODUCT_{PRODUCT_MAP[request.product_type]}"),
                "exchange": getattr(groww, f"EXCHANGE_{EXCHANGE_MAP[request.exchange]}"),
                "duration": groww.VALIDITY_DAY,
            }

            if request.smart_order_type == SmartOrderType.GTT:
                if not request.trigger_price or not request.trigger_direction or not request.order:
                    return SmartOrderResponse(
                        success=False,
                        message="GTT requires trigger_price, trigger_direction, and order",
                    )
                params["trigger_price"] = request.trigger_price
                params["trigger_direction"] = getattr(
                    groww, f"TRIGGER_DIRECTION_{request.trigger_direction.value}"
                )
                params["order"] = self._build_gtt_order_leg(groww, request.order)

            elif request.smart_order_type == SmartOrderType.OCO:
                if (
                    request.net_position_quantity is None
                    or not request.transaction_type
                    or not request.target
                    or not request.stop_loss
                ):
                    return SmartOrderResponse(
                        success=False,
                        message="OCO requires net_position_quantity, transaction_type, target, and stop_loss",
                    )
                params["net_position_quantity"] = request.net_position_quantity
                params["transaction_type"] = getattr(
                    groww, f"TRANSACTION_TYPE_{TRANSACTION_MAP[request.transaction_type]}"
                )
                params["target"] = self._build_oco_leg(groww, request.target)
                params["stop_loss"] = self._build_oco_leg(groww, request.stop_loss)

            response = groww.create_smart_order(**params)
            return self._parse_smart_order_response(response, "Smart order created")

        except Exception as e:
            logger.exception("Failed to create smart order")
            return SmartOrderResponse(success=False, message=f"Create failed: {e}")

    def modify_smart_order(self, request: ModifySmartOrderRequest) -> SmartOrderResponse:
        """Modify an active GTT or OCO smart order."""
        self._ensure_connected()

        try:
            groww = self._client
            params = {
                "smart_order_id": request.smart_order_id,
                "smart_order_type": getattr(
                    groww, f"SMART_ORDER_TYPE_{request.smart_order_type.value}"
                ),
                "segment": getattr(groww, f"SEGMENT_{SEGMENT_DIRECT_MAP[request.segment]}"),
            }

            if request.quantity is not None:
                params["quantity"] = request.quantity
            if request.duration is not None:
                params["duration"] = groww.VALIDITY_DAY
            if request.product_type is not None:
                params["product_type"] = getattr(
                    groww, f"PRODUCT_{PRODUCT_MAP[request.product_type]}"
                )

            if request.smart_order_type == SmartOrderType.GTT:
                if request.trigger_price is not None:
                    params["trigger_price"] = request.trigger_price
                if request.trigger_direction is not None:
                    params["trigger_direction"] = getattr(
                        groww, f"TRIGGER_DIRECTION_{request.trigger_direction.value}"
                    )
                if request.order is not None:
                    params["order"] = self._build_gtt_order_leg(groww, request.order)

            elif request.smart_order_type == SmartOrderType.OCO:
                if request.target is not None:
                    params["target"] = self._build_oco_leg(groww, request.target, partial=True)
                if request.stop_loss is not None:
                    params["stop_loss"] = self._build_oco_leg(
                        groww, request.stop_loss, partial=True
                    )

            response = groww.modify_smart_order(**params)
            return self._parse_smart_order_response(response, "Smart order modified")

        except Exception as e:
            logger.exception("Failed to modify smart order %s", request.smart_order_id)
            return SmartOrderResponse(success=False, message=f"Modify failed: {e}")

    def cancel_smart_order(
        self, smart_order_id: str, request: CancelSmartOrderRequest
    ) -> SmartOrderResponse:
        """Cancel an active smart order."""
        self._ensure_connected()

        try:
            groww = self._client
            response = groww.cancel_smart_order(
                segment=getattr(groww, f"SEGMENT_{SEGMENT_DIRECT_MAP[request.segment]}"),
                smart_order_type=getattr(
                    groww, f"SMART_ORDER_TYPE_{request.smart_order_type.value}"
                ),
                smart_order_id=smart_order_id,
            )
            return self._parse_smart_order_response(response, "Smart order cancelled")

        except Exception as e:
            logger.exception("Failed to cancel smart order %s", smart_order_id)
            return SmartOrderResponse(success=False, message=f"Cancel failed: {e}")

    def get_smart_order(
        self, smart_order_id: str, smart_order_type: str, segment: str
    ) -> SmartOrderResponse:
        """Get details of a specific smart order."""
        self._ensure_connected()

        try:
            groww = self._client
            response = groww.get_smart_order(
                segment=getattr(groww, f"SEGMENT_{segment}"),
                smart_order_type=getattr(groww, f"SMART_ORDER_TYPE_{smart_order_type}"),
                smart_order_id=smart_order_id,
            )
            return self._parse_smart_order_response(response, "Smart order fetched")

        except Exception as e:
            logger.exception("Failed to get smart order %s", smart_order_id)
            return SmartOrderResponse(success=False, message=f"Get failed: {e}")

    def list_smart_orders(
        self,
        segment: str,
        smart_order_type: str,
        status: Optional[str] = None,
        page: int = 0,
        page_size: int = 10,
        start_date_time: Optional[str] = None,
        end_date_time: Optional[str] = None,
    ) -> SmartOrderListResponse:
        """List smart orders with optional filters."""
        self._ensure_connected()

        try:
            groww = self._client
            params = {
                "segment": getattr(groww, f"SEGMENT_{segment}"),
                "smart_order_type": getattr(groww, f"SMART_ORDER_TYPE_{smart_order_type}"),
                "page": page,
                "page_size": page_size,
            }

            if status:
                params["status"] = getattr(groww, f"SMART_ORDER_STATUS_{status}")
            if start_date_time:
                params["start_date_time"] = start_date_time
            if end_date_time:
                params["end_date_time"] = end_date_time

            response = groww.get_smart_order_list(**params)

            if not isinstance(response, dict):
                return SmartOrderListResponse(success=False, message="Unexpected response")

            orders = []
            for item in response.get("orders", []):
                if isinstance(item, dict):
                    orders.append(SmartOrderSummary(
                        smart_order_id=item.get("smart_order_id", ""),
                        smart_order_type=item.get("smart_order_type", ""),
                        status=item.get("status", ""),
                        trading_symbol=item.get("trading_symbol", ""),
                        exchange=item.get("exchange", ""),
                        quantity=int(item.get("quantity", 0) or 0),
                    ))

            return SmartOrderListResponse(
                success=True,
                orders=orders,
                message=f"Found {len(orders)} smart orders",
            )

        except Exception as e:
            logger.exception("Failed to list smart orders")
            return SmartOrderListResponse(success=False, message=f"List failed: {e}")

    def get_available_margin(self) -> AvailableMarginResponse:
        """Get available margin details across equity, F&O, and commodity."""
        self._ensure_connected()

        try:
            data = self._client.get_available_margin_details()

            if not isinstance(data, dict):
                return AvailableMarginResponse(success=False, message="Unexpected response")

            fno = None
            if isinstance(data.get("fno_margin_details"), dict):
                fno = FnoMarginDetails(**data["fno_margin_details"])

            equity = None
            if isinstance(data.get("equity_margin_details"), dict):
                equity = EquityMarginDetails(**data["equity_margin_details"])

            commodity = None
            if isinstance(data.get("commodity_margin_details"), dict):
                commodity = CommodityMarginDetails(**data["commodity_margin_details"])

            return AvailableMarginResponse(
                success=True,
                clear_cash=data.get("clear_cash"),
                net_margin_used=data.get("net_margin_used"),
                brokerage_and_charges=data.get("brokerage_and_charges"),
                collateral_used=data.get("collateral_used"),
                collateral_available=data.get("collateral_available"),
                adhoc_margin=data.get("adhoc_margin"),
                fno_margin_details=fno,
                equity_margin_details=equity,
                commodity_margin_details=commodity,
                message="Margin details fetched successfully",
            )

        except Exception as e:
            logger.exception("Failed to get available margin")
            return AvailableMarginResponse(
                success=False, message=f"Margin fetch failed: {e}",
            )

    def get_order_margin(self, request: OrderMarginRequest) -> OrderMarginResponse:
        """Calculate required margin for one or more orders."""
        self._ensure_connected()

        try:
            groww = self._client
            order_details = []

            for item in request.orders:
                order = {
                    "trading_symbol": item.trading_symbol,
                    "transaction_type": getattr(
                        groww, f"TRANSACTION_TYPE_{TRANSACTION_MAP[item.transaction_type]}"
                    ),
                    "quantity": item.quantity,
                    "order_type": getattr(
                        groww, f"ORDER_TYPE_{ORDER_TYPE_MAP[item.order_type]}"
                    ),
                    "product": getattr(
                        groww, f"PRODUCT_{PRODUCT_MAP[item.product_type]}"
                    ),
                    "exchange": getattr(
                        groww, f"EXCHANGE_{EXCHANGE_MAP[item.exchange]}"
                    ),
                }
                if item.price is not None:
                    order["price"] = item.price
                order_details.append(order)

            data = groww.get_order_margin_details(
                segment=getattr(groww, f"SEGMENT_{SEGMENT_DIRECT_MAP[request.segment]}"),
                orders=order_details,
            )

            if not isinstance(data, dict):
                return OrderMarginResponse(success=False, message="Unexpected response")

            return OrderMarginResponse(
                success=True,
                exposure_required=data.get("exposure_required"),
                span_required=data.get("span_required"),
                option_buy_premium=data.get("option_buy_premium"),
                brokerage_and_charges=data.get("brokerage_and_charges"),
                total_requirement=data.get("total_requirement"),
                cash_cnc_margin_required=data.get("cash_cnc_margin_required"),
                physical_delivery_margin_requirement=data.get(
                    "physical_delivery_margin_requirement"
                ),
                message="Margin calculated successfully",
            )

        except Exception as e:
            logger.exception("Failed to calculate order margin")
            return OrderMarginResponse(
                success=False, message=f"Margin calculation failed: {e}",
            )

    def _build_gtt_order_leg(self, groww, leg: GTTOrderLeg) -> dict:
        order = {
            "order_type": getattr(
                groww, f"ORDER_TYPE_{SMART_ORDER_TYPE_MAP[leg.order_type]}"
            ),
            "transaction_type": getattr(
                groww, f"TRANSACTION_TYPE_{TRANSACTION_MAP[leg.transaction_type]}"
            ),
        }
        if leg.price is not None:
            order["price"] = leg.price
        return order

    def _build_oco_leg(self, groww, leg: OCOLeg, partial: bool = False) -> dict:
        result = {"trigger_price": leg.trigger_price}
        if not partial or leg.order_type is not None:
            if leg.order_type is not None:
                result["order_type"] = getattr(
                    groww, f"ORDER_TYPE_{SMART_ORDER_TYPE_MAP[leg.order_type]}"
                )
        if leg.price is not None:
            result["price"] = leg.price
        elif not partial:
            result["price"] = None
        return result

    def _parse_smart_order_response(self, data, success_msg: str) -> SmartOrderResponse:
        if not isinstance(data, dict):
            return SmartOrderResponse(success=False, message="Unexpected response")

        order_leg = None
        if isinstance(data.get("order"), dict):
            raw = data["order"]
            order_leg = SmartOrderLegResponse(
                order_type=raw.get("order_type"),
                price=raw.get("price"),
                transaction_type=raw.get("transaction_type"),
            )

        target = None
        if isinstance(data.get("target"), dict):
            raw = data["target"]
            target = SmartOrderOCOLegResponse(
                trigger_price=raw.get("trigger_price"),
                order_type=raw.get("order_type"),
                price=raw.get("price"),
            )

        stop_loss = None
        if isinstance(data.get("stop_loss"), dict):
            raw = data["stop_loss"]
            stop_loss = SmartOrderOCOLegResponse(
                trigger_price=raw.get("trigger_price"),
                order_type=raw.get("order_type"),
                price=raw.get("price"),
            )

        return SmartOrderResponse(
            success=True,
            smart_order_id=data.get("smart_order_id"),
            smart_order_type=data.get("smart_order_type"),
            status=data.get("status"),
            trading_symbol=data.get("trading_symbol"),
            exchange=data.get("exchange"),
            quantity=data.get("quantity"),
            product_type=data.get("product_type"),
            duration=data.get("duration"),
            segment=data.get("segment"),
            ltp=data.get("ltp"),
            remark=data.get("remark"),
            display_name=data.get("display_name"),
            is_cancellation_allowed=data.get("is_cancellation_allowed"),
            is_modification_allowed=data.get("is_modification_allowed"),
            created_at=data.get("created_at"),
            expire_at=data.get("expire_at"),
            triggered_at=data.get("triggered_at"),
            updated_at=data.get("updated_at"),
            trigger_price=data.get("trigger_price"),
            trigger_direction=data.get("trigger_direction"),
            order=order_leg,
            target=target,
            stop_loss=stop_loss,
            message=success_msg,
        )

    def _extract_order_id(self, response) -> Optional[str]:
        """Extract broker order ID from Groww API response."""
        if isinstance(response, dict):
            return str(
                response.get("groww_order_id")
                or response.get("orderId")
                or response.get("order_id")
                or ""
            ) or None
        return None

    def _map_groww_status(self, groww_status: str) -> OrderStatus:
        """Map Groww order status to internal status enum."""
        status_map = {
            "PLACED": OrderStatus.PLACED,
            "OPEN": OrderStatus.PLACED,
            "COMPLETE": OrderStatus.EXECUTED,
            "EXECUTED": OrderStatus.EXECUTED,
            "TRADED": OrderStatus.EXECUTED,
            "PARTIALLY_TRADED": OrderStatus.PARTIALLY_EXECUTED,
            "CANCELLED": OrderStatus.CANCELLED,
            "REJECTED": OrderStatus.REJECTED,
            "FAILED": OrderStatus.FAILED,
        }
        return status_map.get(groww_status.upper(), OrderStatus.PENDING)
