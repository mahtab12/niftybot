"""Order management API endpoints."""

import logging

from fastapi import APIRouter, Depends

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    BrokerType,
    CancelOrderRequest,
    CancelOrderResponse,
    ModifyOrderRequest,
    ModifyOrderResponse,
    OrderStatusRequest,
    OrderStatusResponse,
    PlaceOrderRequest,
    PlaceOrderResponse,
)
from app.services.broker_service import broker_service
from app.services.db_service import update_order_status

logger = logging.getLogger("niftybot.orders")

router = APIRouter(
    prefix="/api/v1/orders",
    tags=["Orders"],
    dependencies=[Depends(verify_api_key)],
)


@router.post("", response_model=PlaceOrderResponse)
async def place_order(request: PlaceOrderRequest):
    """Place a new trading order through the specified broker."""
    logger.info(
        "Place order: symbol=%s, type=%s, qty=%d, broker=%s",
        request.symbol,
        request.transaction_type.value,
        request.quantity,
        request.broker.value,
    )

    result = broker_service.place_order(request)

    update_order_status(
        order_id=request.order_id,
        status=result.status.value,
        broker_order_id=result.broker_order_id,
        status_message=result.message,
    )

    return result


@router.put("/modify", response_model=ModifyOrderResponse)
async def modify_order(request: ModifyOrderRequest):
    """Modify a pending or open order (change qty, price, order type)."""
    logger.info(
        "Modify order: %s, broker=%s",
        request.groww_order_id,
        request.broker.value,
    )

    return broker_service.modify_order(request)


@router.delete("/{order_id}", response_model=CancelOrderResponse)
async def cancel_order(order_id: str, request: CancelOrderRequest):
    """Cancel a pending or open order."""
    logger.info("Cancel order: %s, broker=%s", order_id, request.broker.value)

    result = broker_service.cancel_order(order_id, request.broker)

    return result


@router.get("/{order_id}/status", response_model=OrderStatusResponse)
async def get_order_status(order_id: str, broker: BrokerType):
    """Get current status of an order."""
    result = broker_service.get_order_status(order_id, broker)

    if result.status.value in ("executed", "partially_executed"):
        # Sync status back to Drupal DB
        try:
            internal_order_id = int(order_id) if order_id.isdigit() else None
            if internal_order_id:
                update_order_status(
                    order_id=internal_order_id,
                    status=result.status.value,
                    executed_price=result.executed_price,
                    executed_quantity=result.executed_quantity,
                    status_message=result.message,
                )
        except (ValueError, TypeError):
            pass

    return result


@router.get("/book/{broker}", response_model=list[dict])
async def get_order_book(broker: BrokerType):
    """Get all orders for the current trading day."""
    try:
        broker_instance = broker_service.get_broker(broker)
        return broker_instance.get_order_book()
    except Exception as e:
        logger.exception("Failed to get order book")
        return []
