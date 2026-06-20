"""Pydantic models for API request/response validation."""

from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field, field_validator


class TransactionType(str, Enum):
    BUY = "BUY"
    SELL = "SELL"


class OrderType(str, Enum):
    MARKET = "MARKET"
    LIMIT = "LIMIT"
    SL = "SL"
    SL_M = "SL-M"


class ProductType(str, Enum):
    CNC = "CNC"
    MIS = "MIS"
    NRML = "NRML"


class Exchange(str, Enum):
    NSE = "NSE"
    BSE = "BSE"
    NFO = "NFO"
    MCX = "MCX"


class Segment(str, Enum):
    CASH = "CASH"
    FNO = "FNO"
    COMMODITY = "COMMODITY"


class OrderStatus(str, Enum):
    PENDING = "pending"
    PLACED = "placed"
    EXECUTED = "executed"
    PARTIALLY_EXECUTED = "partially_executed"
    CANCELLED = "cancelled"
    REJECTED = "rejected"
    FAILED = "failed"


class BrokerType(str, Enum):
    GROWW = "groww"


class PlaceOrderRequest(BaseModel):
    """Request to place a new order."""
    order_id: int = Field(..., description="Internal order ID from Drupal")
    user_id: int = Field(..., description="Drupal user ID")
    broker: BrokerType
    symbol: str = Field(..., min_length=1, max_length=50)
    exchange: Exchange
    transaction_type: TransactionType
    order_type: OrderType
    product_type: ProductType
    quantity: int = Field(..., gt=0)
    price: Optional[float] = Field(None, ge=0)
    trigger_price: Optional[float] = Field(None, ge=0)

    @field_validator("symbol")
    @classmethod
    def validate_symbol(cls, v: str) -> str:
        sanitized = v.strip().upper()
        if not sanitized.replace("-", "").replace("_", "").isalnum():
            raise ValueError("Symbol must be alphanumeric")
        return sanitized

    @field_validator("price")
    @classmethod
    def validate_price_for_limit(cls, v, info):
        order_type = info.data.get("order_type")
        if order_type in (OrderType.LIMIT, OrderType.SL) and v is None:
            raise ValueError("Price is required for LIMIT and SL orders")
        return v

    @field_validator("trigger_price")
    @classmethod
    def validate_trigger_for_sl(cls, v, info):
        order_type = info.data.get("order_type")
        if order_type in (OrderType.SL, OrderType.SL_M) and v is None:
            raise ValueError("Trigger price is required for SL and SL-M orders")
        return v


class PlaceOrderResponse(BaseModel):
    """Response after placing an order."""
    success: bool
    order_id: int
    broker_order_id: Optional[str] = None
    status: OrderStatus
    message: str


class ModifyOrderRequest(BaseModel):
    """Request to modify an open/pending order."""
    broker: BrokerType
    groww_order_id: str = Field(..., min_length=1)
    quantity: Optional[int] = Field(None, gt=0)
    order_type: Optional[OrderType] = None
    price: Optional[float] = Field(None, ge=0)
    trigger_price: Optional[float] = Field(None, ge=0)


class ModifyOrderResponse(BaseModel):
    success: bool
    groww_order_id: str
    order_status: Optional[str] = None
    message: str


class CancelOrderRequest(BaseModel):
    """Request to cancel an order."""
    broker: BrokerType


class CancelOrderResponse(BaseModel):
    success: bool
    order_id: str
    message: str


class OrderStatusRequest(BaseModel):
    broker: BrokerType


class OrderStatusResponse(BaseModel):
    order_id: str
    broker_order_id: Optional[str] = None
    status: OrderStatus
    executed_price: Optional[float] = None
    executed_quantity: Optional[int] = None
    message: Optional[str] = None


class PositionItem(BaseModel):
    symbol: str
    exchange: str
    quantity: int
    average_price: float
    current_price: Optional[float] = None
    pnl: float = 0
    pnl_percentage: float = 0
    product_type: str = "CNC"


class PositionsResponse(BaseModel):
    success: bool
    positions: list[PositionItem] = []
    message: str = ""


class HoldingItem(BaseModel):
    symbol: str
    exchange: str
    quantity: int
    average_price: float
    current_price: Optional[float] = None
    pnl: float = 0


class HoldingsResponse(BaseModel):
    success: bool
    holdings: list[HoldingItem] = []
    message: str = ""


class UserProfileResponse(BaseModel):
    """Broker user profile details."""
    success: bool
    vendor_user_id: Optional[str] = None
    ucc: Optional[str] = None
    nse_enabled: bool = False
    bse_enabled: bool = False
    ddpi_enabled: bool = False
    active_segments: list[str] = []
    message: str = ""


# --- Live Market Data Models ---


class OHLCData(BaseModel):
    open: float = 0
    high: float = 0
    low: float = 0
    close: float = 0


class DepthEntry(BaseModel):
    price: float = 0
    quantity: int = 0


class MarketDepth(BaseModel):
    buy: list[DepthEntry] = []
    sell: list[DepthEntry] = []


class QuoteResponse(BaseModel):
    """Full real-time quote for a single instrument."""
    success: bool
    symbol: str = ""
    exchange: str = ""
    last_price: Optional[float] = None
    average_price: Optional[float] = None
    volume: Optional[int] = None
    bid_price: Optional[float] = None
    bid_quantity: Optional[int] = None
    offer_price: Optional[float] = None
    offer_quantity: Optional[int] = None
    day_change: Optional[float] = None
    day_change_perc: Optional[float] = None
    upper_circuit_limit: Optional[float] = None
    lower_circuit_limit: Optional[float] = None
    ohlc: Optional[OHLCData] = None
    depth: Optional[MarketDepth] = None
    total_buy_quantity: Optional[int] = None
    total_sell_quantity: Optional[int] = None
    last_trade_quantity: Optional[int] = None
    last_trade_time: Optional[int] = None
    open_interest: Optional[int] = None
    oi_day_change: Optional[int] = None
    oi_day_change_percentage: Optional[float] = None
    week_52_high: Optional[float] = None
    week_52_low: Optional[float] = None
    market_cap: Optional[float] = None
    implied_volatility: Optional[float] = None
    message: str = ""


class LTPResponse(BaseModel):
    """Last traded price for one or more instruments."""
    success: bool
    prices: dict[str, float] = {}
    message: str = ""


class OHLCResponse(BaseModel):
    """OHLC data for one or more instruments."""
    success: bool
    data: dict[str, OHLCData] = {}
    message: str = ""


class Greeks(BaseModel):
    delta: float = 0
    gamma: float = 0
    theta: float = 0
    vega: float = 0
    rho: float = 0
    iv: float = 0


class OptionData(BaseModel):
    trading_symbol: str = ""
    ltp: Optional[float] = None
    open_interest: Optional[int] = None
    volume: Optional[int] = None
    greeks: Optional[Greeks] = None


class StrikeData(BaseModel):
    CE: Optional[OptionData] = None
    PE: Optional[OptionData] = None


class OptionChainResponse(BaseModel):
    """Full option chain for an underlying + expiry."""
    success: bool
    underlying: str = ""
    underlying_ltp: Optional[float] = None
    strikes: dict[str, StrikeData] = {}
    message: str = ""


class GreeksResponse(BaseModel):
    """Greeks for a single options contract."""
    success: bool
    trading_symbol: str = ""
    greeks: Optional[Greeks] = None
    message: str = ""


# --- Smart Order Models ---


class SmartOrderType(str, Enum):
    GTT = "GTT"
    OCO = "OCO"


class TriggerDirection(str, Enum):
    UP = "UP"
    DOWN = "DOWN"


class SmartOrderStatus(str, Enum):
    ACTIVE = "ACTIVE"
    TRIGGERED = "TRIGGERED"
    CANCELLED = "CANCELLED"
    EXPIRED = "EXPIRED"
    FAILED = "FAILED"
    COMPLETED = "COMPLETED"


class GTTOrderLeg(BaseModel):
    order_type: OrderType
    price: Optional[str] = None
    transaction_type: TransactionType


class OCOLeg(BaseModel):
    trigger_price: str
    order_type: OrderType
    price: Optional[str] = None


class CreateSmartOrderRequest(BaseModel):
    """Create a GTT or OCO smart order."""
    broker: BrokerType
    smart_order_type: SmartOrderType
    reference_id: str = Field(..., min_length=1, max_length=50)
    segment: Segment
    trading_symbol: str = Field(..., min_length=1, max_length=50)
    quantity: int = Field(..., gt=0)
    product_type: ProductType
    exchange: Exchange
    duration: str = "DAY"

    # GTT fields
    trigger_price: Optional[str] = None
    trigger_direction: Optional[TriggerDirection] = None
    order: Optional[GTTOrderLeg] = None

    # OCO fields
    net_position_quantity: Optional[int] = Field(None, gt=0)
    transaction_type: Optional[TransactionType] = None
    target: Optional[OCOLeg] = None
    stop_loss: Optional[OCOLeg] = None

    @field_validator("trading_symbol")
    @classmethod
    def validate_symbol(cls, v: str) -> str:
        sanitized = v.strip().upper()
        if not sanitized.replace("-", "").replace("_", "").isalnum():
            raise ValueError("Symbol must be alphanumeric")
        return sanitized

    @field_validator("segment")
    @classmethod
    def validate_segment(cls, v: Segment) -> Segment:
        if v == Segment.COMMODITY:
            raise ValueError("COMMODITY segment is not supported for Smart Orders")
        return v


class ModifySmartOrderRequest(BaseModel):
    """Modify an active GTT or OCO smart order."""
    broker: BrokerType
    smart_order_id: str = Field(..., min_length=1)
    smart_order_type: SmartOrderType
    segment: Segment

    quantity: Optional[int] = Field(None, gt=0)
    duration: Optional[str] = None
    product_type: Optional[ProductType] = None

    # GTT modifiable fields
    trigger_price: Optional[str] = None
    trigger_direction: Optional[TriggerDirection] = None
    order: Optional[GTTOrderLeg] = None

    # OCO modifiable fields
    target: Optional[OCOLeg] = None
    stop_loss: Optional[OCOLeg] = None


class CancelSmartOrderRequest(BaseModel):
    broker: BrokerType
    smart_order_type: SmartOrderType
    segment: Segment


class SmartOrderLegResponse(BaseModel):
    order_type: Optional[str] = None
    price: Optional[str] = None
    transaction_type: Optional[str] = None


class SmartOrderOCOLegResponse(BaseModel):
    trigger_price: Optional[str] = None
    order_type: Optional[str] = None
    price: Optional[str] = None


class SmartOrderResponse(BaseModel):
    """Smart order details (GTT or OCO)."""
    success: bool
    smart_order_id: Optional[str] = None
    smart_order_type: Optional[str] = None
    status: Optional[str] = None
    trading_symbol: Optional[str] = None
    exchange: Optional[str] = None
    quantity: Optional[int] = None
    product_type: Optional[str] = None
    duration: Optional[str] = None
    segment: Optional[str] = None
    ltp: Optional[float] = None
    remark: Optional[str] = None
    display_name: Optional[str] = None
    is_cancellation_allowed: Optional[bool] = None
    is_modification_allowed: Optional[bool] = None
    created_at: Optional[str] = None
    expire_at: Optional[str] = None
    triggered_at: Optional[str] = None
    updated_at: Optional[str] = None
    # GTT-specific
    trigger_price: Optional[str] = None
    trigger_direction: Optional[str] = None
    order: Optional[SmartOrderLegResponse] = None
    # OCO-specific
    target: Optional[SmartOrderOCOLegResponse] = None
    stop_loss: Optional[SmartOrderOCOLegResponse] = None
    message: str = ""


class SmartOrderSummary(BaseModel):
    smart_order_id: str = ""
    smart_order_type: str = ""
    status: str = ""
    trading_symbol: str = ""
    exchange: str = ""
    quantity: int = 0


class SmartOrderListResponse(BaseModel):
    success: bool
    orders: list[SmartOrderSummary] = []
    message: str = ""


# --- Margin Models ---


class FnoMarginDetails(BaseModel):
    net_fno_margin_used: float = 0
    span_margin_used: float = 0
    exposure_margin_used: float = 0
    future_balance_available: float = 0
    option_buy_balance_available: float = 0
    option_sell_balance_available: float = 0


class EquityMarginDetails(BaseModel):
    net_equity_margin_used: float = 0
    cnc_margin_used: float = 0
    mis_margin_used: float = 0
    cnc_balance_available: float = 0
    mis_balance_available: float = 0


class CommodityMarginDetails(BaseModel):
    commodity_span_margin: float = 0
    commodity_exposure_margin: float = 0
    commodity_tender_margin: float = 0
    commodity_special_margin: float = 0
    commodity_additional_margin: float = 0
    commodity_unrealised_m2m: float = 0
    commodity_realised_m2m: float = 0


class AvailableMarginResponse(BaseModel):
    """Available margin across equity, F&O, and commodity segments."""
    success: bool
    clear_cash: Optional[float] = None
    net_margin_used: Optional[float] = None
    brokerage_and_charges: Optional[float] = None
    collateral_used: Optional[float] = None
    collateral_available: Optional[float] = None
    adhoc_margin: Optional[float] = None
    fno_margin_details: Optional[FnoMarginDetails] = None
    equity_margin_details: Optional[EquityMarginDetails] = None
    commodity_margin_details: Optional[CommodityMarginDetails] = None
    message: str = ""


class MarginOrderItem(BaseModel):
    """Single order for margin calculation."""
    trading_symbol: str = Field(..., min_length=1, max_length=50)
    transaction_type: TransactionType
    quantity: int = Field(..., gt=0)
    order_type: OrderType
    product_type: ProductType
    exchange: Exchange
    price: Optional[float] = Field(None, ge=0)

    @field_validator("trading_symbol")
    @classmethod
    def validate_symbol(cls, v: str) -> str:
        sanitized = v.strip().upper()
        if not sanitized.replace("-", "").replace("_", "").isalnum():
            raise ValueError("Symbol must be alphanumeric")
        return sanitized


class OrderMarginRequest(BaseModel):
    """Calculate required margin for one or more orders (basket for FNO)."""
    broker: BrokerType
    segment: Segment
    orders: list[MarginOrderItem] = Field(..., min_length=1, max_length=50)


class OrderMarginResponse(BaseModel):
    """Required margin breakdown for submitted orders."""
    success: bool
    exposure_required: Optional[float] = None
    span_required: Optional[float] = None
    option_buy_premium: Optional[float] = None
    brokerage_and_charges: Optional[float] = None
    total_requirement: Optional[float] = None
    cash_cnc_margin_required: Optional[float] = None
    physical_delivery_margin_requirement: Optional[float] = None
    message: str = ""


class HealthResponse(BaseModel):
    status: str
    service: str
    broker_connected: bool
    version: str
