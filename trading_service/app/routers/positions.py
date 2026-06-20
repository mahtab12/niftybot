"""Portfolio positions and holdings API endpoints."""

import logging

from fastapi import APIRouter, Depends

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    BrokerType,
    HoldingsResponse,
    PositionsResponse,
)
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.positions")

router = APIRouter(
    prefix="/api/v1",
    tags=["Portfolio"],
    dependencies=[Depends(verify_api_key)],
)


@router.get("/positions/{user_id}", response_model=PositionsResponse)
async def get_positions(user_id: int, broker: BrokerType):
    """Get current open positions for a user from the broker."""
    logger.info("Get positions: user=%d, broker=%s", user_id, broker.value)

    return broker_service.get_positions(broker)


@router.get("/holdings/{user_id}", response_model=HoldingsResponse)
async def get_holdings(user_id: int, broker: BrokerType):
    """Get portfolio holdings for a user from the broker."""
    logger.info("Get holdings: user=%d, broker=%s", user_id, broker.value)

    return broker_service.get_holdings(broker)


@router.get("/ltp")
async def get_ltp(symbol: str, exchange: str, broker: BrokerType):
    """Get last traded price for a symbol."""
    if not symbol or not symbol.strip().replace("-", "").replace("_", "").isalnum():
        return {"success": False, "ltp": None, "message": "Invalid symbol"}

    clean_symbol = symbol.strip().upper()
    clean_exchange = exchange.strip().upper()

    price = broker_service.get_ltp(clean_symbol, clean_exchange, broker)

    return {
        "success": price is not None,
        "symbol": clean_symbol,
        "exchange": clean_exchange,
        "ltp": price,
        "message": "" if price else "Failed to fetch LTP",
    }
