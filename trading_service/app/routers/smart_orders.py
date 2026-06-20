"""Smart Orders API endpoints -- GTT and OCO automation."""

import logging

from fastapi import APIRouter, Depends, Query

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    BrokerType,
    CancelSmartOrderRequest,
    CreateSmartOrderRequest,
    ModifySmartOrderRequest,
    Segment,
    SmartOrderListResponse,
    SmartOrderResponse,
    SmartOrderStatus,
    SmartOrderType,
)
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.smart_orders")

router = APIRouter(
    prefix="/api/v1/smart-orders",
    tags=["Smart Orders"],
    dependencies=[Depends(verify_api_key)],
)


@router.post("", response_model=SmartOrderResponse)
async def create_smart_order(request: CreateSmartOrderRequest):
    """
    Create a GTT or OCO smart order.

    GTT arms a single order when a trigger condition is met.
    OCO protects/exits positions with target and stop-loss legs.
  """
    logger.info(
        "Create smart order: type=%s, symbol=%s, broker=%s",
        request.smart_order_type.value,
        request.trading_symbol,
        request.broker.value,
    )
    return broker_service.create_smart_order(request)


@router.put("/modify", response_model=SmartOrderResponse)
async def modify_smart_order(request: ModifySmartOrderRequest):
    """Modify an active GTT or OCO smart order."""
    logger.info(
        "Modify smart order: id=%s, type=%s",
        request.smart_order_id,
        request.smart_order_type.value,
    )
    return broker_service.modify_smart_order(request)


@router.delete("/{smart_order_id}", response_model=SmartOrderResponse)
async def cancel_smart_order(smart_order_id: str, request: CancelSmartOrderRequest):
    """Cancel an active smart order."""
    logger.info(
        "Cancel smart order: id=%s, type=%s",
        smart_order_id,
        request.smart_order_type.value,
    )
    return broker_service.cancel_smart_order(smart_order_id, request)


@router.get("/{smart_order_id}", response_model=SmartOrderResponse)
async def get_smart_order(
    smart_order_id: str,
    smart_order_type: SmartOrderType,
    segment: Segment,
    broker: BrokerType = BrokerType.GROWW,
):
    """Get details of a specific smart order by ID."""
    return broker_service.get_smart_order(
        smart_order_id=smart_order_id,
        smart_order_type=smart_order_type.value,
        segment=segment.value,
        broker_type=broker,
    )


@router.get("", response_model=SmartOrderListResponse)
async def list_smart_orders(
    smart_order_type: SmartOrderType,
    segment: Segment,
    broker: BrokerType = BrokerType.GROWW,
    status: SmartOrderStatus | None = None,
    page: int = Query(0, ge=0),
    page_size: int = Query(10, ge=1, le=50),
    start_date_time: str | None = Query(
        None, description="ISO datetime, e.g. 2025-01-16T09:15:00",
    ),
    end_date_time: str | None = Query(
        None, description="ISO datetime, e.g. 2025-01-16T15:30:00",
    ),
):
    """
    List smart orders filtered by type, segment, status, and time window.

    Use status=ACTIVE for live untriggered orders, or TRIGGERED/CANCELLED
    for historical ones.
    """
    if segment == Segment.COMMODITY:
        return SmartOrderListResponse(
            success=False,
            message="COMMODITY segment is not supported for Smart Orders",
        )

    return broker_service.list_smart_orders(
        broker_type=broker,
        segment=segment.value,
        smart_order_type=smart_order_type.value,
        status=status.value if status else None,
        page=page,
        page_size=page_size,
        start_date_time=start_date_time,
        end_date_time=end_date_time,
    )
