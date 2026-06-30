"""Broker credential verification for per-user connections."""

import logging

from fastapi import APIRouter, Depends

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    BrokerCredentialsOrderBookRequest,
    BrokerCredentialsOrderBookResponse,
    BrokerCredentialsVerifyRequest,
    BrokerCredentialsVerifyResponse,
    BrokerOrderHistoryItem,
    BrokerType,
)
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.broker_credentials")

_APPROVAL_TOKENS = (
    "approve",
    "approval",
    "checksum",
)


def _approval_required(success: bool, *messages: str) -> bool:
    if success:
        return False
    haystack = " ".join(m for m in messages if m).lower()
    return any(token in haystack for token in _APPROVAL_TOKENS)


router = APIRouter(
    prefix="/api/v1/broker",
    tags=["Broker"],
    dependencies=[Depends(verify_api_key)],
)


@router.post("/credentials/verify", response_model=BrokerCredentialsVerifyResponse)
async def verify_broker_credentials(body: BrokerCredentialsVerifyRequest):
    """Validate broker API key + secret and return profile and margin."""
    logger.info("Verifying broker credentials: broker=%s", body.broker.value)
    profile, margin = broker_service.verify_credentials(
        body.broker,
        body.api_key,
        body.api_secret,
    )
    success = bool(profile.success)
    message = "Connected successfully" if success else (
        profile.message or margin.message or "Verification failed"
    )
    approval_required = _approval_required(
        success,
        message,
        profile.message or "",
        margin.message or "",
    )
    return BrokerCredentialsVerifyResponse(
        success=success,
        message=message,
        approval_required=approval_required,
        profile=profile,
        margin=margin,
    )


def _instrument_symbol_match(trading_symbol: str, instrument: str) -> bool:
    symbol = (trading_symbol or "").upper()
    key = (instrument or "").strip().lower()
    if key == "nifty":
        return "NIFTY" in symbol and "SENSEX" not in symbol
    if key == "sensex":
        return "SENSEX" in symbol
    if key in ("crude_oil", "crude"):
        return "CRUDEOIL" in symbol
    if key == "gold":
        return "GOLD" in symbol
    return True


def _parse_option_type(symbol: str) -> str:
    symbol = (symbol or "").upper()
    if symbol.endswith("FUT"):
        return "FUT"
    if symbol.endswith("CE"):
        return "CE"
    if symbol.endswith("PE"):
        return "PE"
    return ""


@router.post("/credentials/order-book", response_model=BrokerCredentialsOrderBookResponse)
async def get_credentials_order_book(body: BrokerCredentialsOrderBookRequest):
    """Return today's F&O orders for the supplied Groww account."""
    logger.info(
        "Fetching user order book: broker=%s instrument=%s",
        body.broker.value,
        body.instrument or "all",
    )
    profile, _margin = broker_service.verify_credentials(
        body.broker,
        body.api_key,
        body.api_secret,
    )
    if not profile.success:
        return BrokerCredentialsOrderBookResponse(
            success=False,
            message=profile.message or "Could not connect with these credentials.",
        )

    orders_raw = broker_service.get_order_book_for_credentials(
        body.broker,
        body.api_key,
        body.api_secret,
    )
    items: list[BrokerOrderHistoryItem] = []
    for order in orders_raw:
        if not isinstance(order, dict):
            continue
        symbol = str(
            order.get("trading_symbol")
            or order.get("tradingSymbol")
            or order.get("symbol")
            or ""
        )
        if body.instrument and not _instrument_symbol_match(symbol, body.instrument):
            continue
        segment = str(order.get("segment") or order.get("order_segment") or "").upper()
        allowed_segments = {"FNO", "FO", "DERIVATIVE"}
        instrument_key = (body.instrument or "").strip().lower()
        if instrument_key in ("crude_oil", "crude", "gold"):
            allowed_segments.add("COMMODITY")
        if segment and segment not in allowed_segments:
            continue
        price = order.get("average_price") or order.get("averagePrice") or order.get("price")
        try:
            entry_price = float(price) if price is not None else None
        except (TypeError, ValueError):
            entry_price = None
        qty = order.get("filled_quantity") or order.get("filledQuantity") or order.get("quantity") or 0
        try:
            quantity = int(qty)
        except (TypeError, ValueError):
            quantity = 0
        items.append(
            BrokerOrderHistoryItem(
                symbol=symbol,
                option_type=_parse_option_type(symbol),
                transaction_type=str(
                    order.get("transaction_type")
                    or order.get("transactionType")
                    or ""
                ).upper(),
                quantity=quantity,
                entry_price=entry_price,
                order_status=str(
                    order.get("order_status")
                    or order.get("orderStatus")
                    or order.get("status")
                    or ""
                ),
                order_time=str(
                    order.get("order_time")
                    or order.get("orderTime")
                    or order.get("created_at")
                    or ""
                ),
                broker_order_id=str(
                    order.get("groww_order_id")
                    or order.get("orderId")
                    or ""
                ),
            )
        )

    items.sort(key=lambda row: row.order_time or "", reverse=True)
    limit = body.limit
    return BrokerCredentialsOrderBookResponse(
        success=True,
        message="Order book loaded",
        broker_user_id=str(profile.vendor_user_id or ""),
        orders=items[:limit],
    )
