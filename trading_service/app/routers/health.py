"""Health check and service status endpoints."""

import logging

from fastapi import APIRouter

from app.models.schemas import BrokerType, HealthResponse
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.health")

router = APIRouter(tags=["Health"])


@router.get("/health", response_model=HealthResponse)
async def health_check():
    """Service health check endpoint."""
    broker_ok = False
    try:
        broker_ok = broker_service.check_connection(BrokerType.GROWW)
    except Exception:
        pass

    return HealthResponse(
        status="healthy",
        service="niftybot-trading-service",
        broker_connected=broker_ok,
        version="1.0.0",
    )


@router.get("/health/broker/{broker}")
async def broker_health(broker: BrokerType):
    """Check specific broker connection status."""
    try:
        connected = broker_service.check_connection(broker)
        return {
            "broker": broker.value,
            "connected": connected,
            "message": "Connected" if connected else "Not connected",
        }
    except Exception as e:
        return {
            "broker": broker.value,
            "connected": False,
            "message": str(e),
        }
