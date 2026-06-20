"""Abstract base class for broker adapters."""

from abc import ABC, abstractmethod
from typing import Optional

from app.models.schemas import (
    AvailableMarginResponse,
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


class BaseBroker(ABC):
    """Interface that every broker adapter must implement."""

    @abstractmethod
    def connect(self) -> bool:
        """Authenticate and establish connection with the broker."""

    @abstractmethod
    def is_connected(self) -> bool:
        """Check if the broker session is active."""

    @abstractmethod
    def place_order(self, request: PlaceOrderRequest) -> PlaceOrderResponse:
        """Place an order through the broker."""

    @abstractmethod
    def modify_order(self, request: ModifyOrderRequest) -> ModifyOrderResponse:
        """Modify a pending/open order."""

    @abstractmethod
    def cancel_order(self, broker_order_id: str) -> CancelOrderResponse:
        """Cancel a pending/open order."""

    @abstractmethod
    def get_order_status(self, broker_order_id: str) -> OrderStatusResponse:
        """Get current status of an order."""

    @abstractmethod
    def get_positions(self) -> PositionsResponse:
        """Get current open positions."""

    @abstractmethod
    def get_holdings(self) -> HoldingsResponse:
        """Get portfolio holdings."""

    @abstractmethod
    def get_order_book(self) -> list[dict]:
        """Get all orders for the day."""

    @abstractmethod
    def get_ltp(self, symbol: str, exchange: str) -> Optional[float]:
        """Get last traded price for a symbol."""

    @abstractmethod
    def get_user_profile(self) -> UserProfileResponse:
        """Get authenticated user's broker profile and permissions."""

    @abstractmethod
    def create_smart_order(self, request: CreateSmartOrderRequest) -> SmartOrderResponse:
        """Create a GTT or OCO smart order."""

    @abstractmethod
    def modify_smart_order(self, request: ModifySmartOrderRequest) -> SmartOrderResponse:
        """Modify an active smart order."""

    @abstractmethod
    def cancel_smart_order(
        self, smart_order_id: str, request: CancelSmartOrderRequest
    ) -> SmartOrderResponse:
        """Cancel an active smart order."""

    @abstractmethod
    def get_smart_order(
        self, smart_order_id: str, smart_order_type: str, segment: str
    ) -> SmartOrderResponse:
        """Get details of a specific smart order."""

    @abstractmethod
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

    @abstractmethod
    def get_available_margin(self) -> AvailableMarginResponse:
        """Get available margin details across all segments."""

    @abstractmethod
    def get_order_margin(self, request: OrderMarginRequest) -> OrderMarginResponse:
        """Calculate required margin for one or more orders."""
