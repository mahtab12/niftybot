"""Broker service - manages broker instances and delegates operations."""

import logging
from typing import Optional

from app.brokers.base import BaseBroker
from app.brokers.groww_broker import GrowwBroker
from app.models.schemas import (
    AvailableMarginResponse,
    BrokerType,
    CancelOrderResponse,
    CancelSmartOrderRequest,
    CreateSmartOrderRequest,
    HoldingsResponse,
    ModifyOrderRequest,
    ModifyOrderResponse,
    ModifySmartOrderRequest,
    OrderMarginRequest,
    OrderMarginResponse,
    OrderStatusResponse,
    PlaceOrderRequest,
    PlaceOrderResponse,
    PositionsResponse,
    SmartOrderListResponse,
    SmartOrderResponse,
    UserProfileResponse,
)

logger = logging.getLogger("niftybot.broker_service")


class BrokerService:
    """Manages broker connections and delegates trading operations."""

    def __init__(self):
        self._brokers: dict[BrokerType, BaseBroker] = {}

    def get_broker(self, broker_type: BrokerType) -> BaseBroker:
        """Get or create a broker instance."""
        if broker_type not in self._brokers:
            self._brokers[broker_type] = self._create_broker(broker_type)

        broker = self._brokers[broker_type]

        if not broker.is_connected():
            broker.connect()

        return broker

    def _create_broker(self, broker_type: BrokerType) -> BaseBroker:
        """Factory method to create broker instances."""
        if broker_type == BrokerType.GROWW:
            return GrowwBroker()

        raise ValueError(f"Unsupported broker: {broker_type}")

    def place_order(self, request: PlaceOrderRequest) -> PlaceOrderResponse:
        """Place an order through the specified broker."""
        try:
            broker = self.get_broker(request.broker)
            return broker.place_order(request)
        except Exception as e:
            logger.exception("Broker service: order placement failed")
            return PlaceOrderResponse(
                success=False,
                order_id=request.order_id,
                status="failed",
                message=f"Service error: {e}",
            )

    def modify_order(self, request: ModifyOrderRequest) -> ModifyOrderResponse:
        """Modify an open/pending order."""
        try:
            broker = self.get_broker(request.broker)
            return broker.modify_order(request)
        except Exception as e:
            logger.exception("Broker service: modify failed")
            return ModifyOrderResponse(
                success=False,
                groww_order_id=request.groww_order_id,
                message=f"Modify error: {e}",
            )

    def cancel_order(
        self, broker_order_id: str, broker_type: BrokerType
    ) -> CancelOrderResponse:
        """Cancel an order."""
        try:
            broker = self.get_broker(broker_type)
            return broker.cancel_order(broker_order_id)
        except Exception as e:
            logger.exception("Broker service: cancel failed")
            return CancelOrderResponse(
                success=False,
                order_id=broker_order_id,
                message=f"Cancel error: {e}",
            )

    def get_order_status(
        self, broker_order_id: str, broker_type: BrokerType
    ) -> OrderStatusResponse:
        """Get order status."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_order_status(broker_order_id)
        except Exception as e:
            logger.exception("Broker service: status check failed")
            return OrderStatusResponse(
                order_id=broker_order_id,
                status="pending",
                message=f"Status check error: {e}",
            )

    def get_positions(self, broker_type: BrokerType) -> PositionsResponse:
        """Get positions."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_positions()
        except Exception as e:
            logger.exception("Broker service: positions failed")
            return PositionsResponse(
                success=False,
                message=f"Positions error: {e}",
            )

    def get_holdings(self, broker_type: BrokerType) -> HoldingsResponse:
        """Get holdings."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_holdings()
        except Exception as e:
            logger.exception("Broker service: holdings failed")
            return HoldingsResponse(
                success=False,
                message=f"Holdings error: {e}",
            )

    def get_ltp(
        self, symbol: str, exchange: str, broker_type: BrokerType
    ) -> Optional[float]:
        """Get last traded price."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_ltp(symbol, exchange)
        except Exception:
            logger.exception("Broker service: LTP failed for %s", symbol)
            return None

    def get_user_profile(self, broker_type: BrokerType) -> UserProfileResponse:
        """Get broker user profile."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_user_profile()
        except Exception as e:
            logger.exception("Broker service: user profile failed")
            return UserProfileResponse(
                success=False,
                message=f"Profile error: {e}",
            )

    def create_smart_order(
        self, request: CreateSmartOrderRequest
    ) -> SmartOrderResponse:
        """Create a GTT or OCO smart order."""
        try:
            broker = self.get_broker(request.broker)
            return broker.create_smart_order(request)
        except Exception as e:
            logger.exception("Broker service: create smart order failed")
            return SmartOrderResponse(
                success=False,
                message=f"Create error: {e}",
            )

    def modify_smart_order(
        self, request: ModifySmartOrderRequest
    ) -> SmartOrderResponse:
        """Modify an active smart order."""
        try:
            broker = self.get_broker(request.broker)
            return broker.modify_smart_order(request)
        except Exception as e:
            logger.exception("Broker service: modify smart order failed")
            return SmartOrderResponse(
                success=False,
                message=f"Modify error: {e}",
            )

    def cancel_smart_order(
        self, smart_order_id: str, request: CancelSmartOrderRequest
    ) -> SmartOrderResponse:
        """Cancel an active smart order."""
        try:
            broker = self.get_broker(request.broker)
            return broker.cancel_smart_order(smart_order_id, request)
        except Exception as e:
            logger.exception("Broker service: cancel smart order failed")
            return SmartOrderResponse(
                success=False,
                message=f"Cancel error: {e}",
            )

    def get_smart_order(
        self,
        smart_order_id: str,
        smart_order_type: str,
        segment: str,
        broker_type: BrokerType,
    ) -> SmartOrderResponse:
        """Get details of a specific smart order."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_smart_order(smart_order_id, smart_order_type, segment)
        except Exception as e:
            logger.exception("Broker service: get smart order failed")
            return SmartOrderResponse(
                success=False,
                message=f"Get error: {e}",
            )

    def list_smart_orders(
        self,
        broker_type: BrokerType,
        segment: str,
        smart_order_type: str,
        status: Optional[str] = None,
        page: int = 0,
        page_size: int = 10,
        start_date_time: Optional[str] = None,
        end_date_time: Optional[str] = None,
    ) -> SmartOrderListResponse:
        """List smart orders with optional filters."""
        try:
            broker = self.get_broker(broker_type)
            return broker.list_smart_orders(
                segment=segment,
                smart_order_type=smart_order_type,
                status=status,
                page=page,
                page_size=page_size,
                start_date_time=start_date_time,
                end_date_time=end_date_time,
            )
        except Exception as e:
            logger.exception("Broker service: list smart orders failed")
            return SmartOrderListResponse(
                success=False,
                message=f"List error: {e}",
            )

    def get_available_margin(self, broker_type: BrokerType) -> AvailableMarginResponse:
        """Get available margin details."""
        try:
            broker = self.get_broker(broker_type)
            return broker.get_available_margin()
        except Exception as e:
            logger.exception("Broker service: available margin failed")
            return AvailableMarginResponse(
                success=False,
                message=f"Margin error: {e}",
            )

    def get_order_margin(
        self, request: OrderMarginRequest
    ) -> OrderMarginResponse:
        """Calculate required margin for orders."""
        try:
            broker = self.get_broker(request.broker)
            return broker.get_order_margin(request)
        except Exception as e:
            logger.exception("Broker service: order margin failed")
            return OrderMarginResponse(
                success=False,
                message=f"Margin calculation error: {e}",
            )

    def check_connection(self, broker_type: BrokerType) -> bool:
        """Check if broker is connected."""
        try:
            broker = self.get_broker(broker_type)
            return broker.is_connected()
        except Exception:
            return False


broker_service = BrokerService()
