"""NiftyBot Trading Service - FastAPI application entry point."""

import logging
import sys

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.config import settings
from app.routers import health, margin, market, orders, positions, smart_orders, user

logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s [%(name)s] %(levelname)s: %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger("niftybot")

app = FastAPI(
    title="NiftyBot Trading Service",
    description=(
        "Trading API service for NiftyBot platform. "
        "Handles order execution, position tracking, and broker integration."
    ),
    version="1.0.0",
    docs_url="/docs" if settings.debug else None,
    redoc_url="/redoc" if settings.debug else None,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router)
app.include_router(orders.router)
app.include_router(positions.router)
app.include_router(user.router)
app.include_router(market.router)
app.include_router(smart_orders.router)
app.include_router(margin.router)


@app.on_event("startup")
async def startup_event():
    logger.info("NiftyBot Trading Service starting up")
    logger.info("Groww auth method: %s", settings.groww_auth_method)

    if settings.groww_api_key:
        from app.services.broker_service import broker_service
        from app.models.schemas import BrokerType

        try:
            broker_service.get_broker(BrokerType.GROWW)
            logger.info("Groww broker connection established")
        except Exception:
            logger.warning(
                "Groww broker connection failed on startup; will retry on first request"
            )
    else:
        logger.warning("Groww API key not configured; broker will not connect")


@app.on_event("shutdown")
async def shutdown_event():
    logger.info("NiftyBot Trading Service shutting down")
