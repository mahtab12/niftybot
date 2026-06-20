"""Margin API endpoints -- available margin and order margin calculation."""

import logging

from fastapi import APIRouter, Depends

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    AvailableMarginResponse,
    BrokerType,
    OrderMarginRequest,
    OrderMarginResponse,
)
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.margin")

router = APIRouter(
    prefix="/api/v1/margin",
    tags=["Margin"],
    dependencies=[Depends(verify_api_key)],
)


@router.get("/available", response_model=AvailableMarginResponse)
async def get_available_margin(broker: BrokerType = BrokerType.GROWW):
    """
    Get available margin across equity, F&O, and commodity segments.

    Returns clear cash, margin used, collateral, and per-segment balances.
    """
    return broker_service.get_available_margin(broker)


@router.post("/order", response_model=OrderMarginResponse)
async def get_order_margin(request: OrderMarginRequest):
    """
    Calculate required margin for one or more orders.

    Basket orders (multiple orders in one call) are supported for the FNO segment.
    """
    logger.info(
        "Order margin: segment=%s, orders=%d, broker=%s",
        request.segment.value,
        len(request.orders),
        request.broker.value,
    )
    return broker_service.get_order_margin(request)
