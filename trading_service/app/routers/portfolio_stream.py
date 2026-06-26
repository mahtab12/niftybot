"""WebSocket stream for live portfolio updates."""

import asyncio
import hmac
import logging
from concurrent.futures import ThreadPoolExecutor

from fastapi import APIRouter, WebSocket, WebSocketDisconnect

from app.config import settings
from app.models.schemas import BrokerType
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.portfolio_stream")

router = APIRouter(tags=["Portfolio"])

_executor = ThreadPoolExecutor(max_workers=2)
PUSH_INTERVAL_SECONDS = 3


def _fetch_summary(broker: BrokerType) -> dict:
    """Run portfolio summary in a worker thread (blocking Groww HTTP calls)."""
    return broker_service.get_portfolio_summary(broker).model_dump()


def _is_valid_api_key(api_key: str | None) -> bool:
    if not settings.api_key or not api_key:
        return False
    return hmac.compare_digest(api_key, settings.api_key)


@router.websocket("/ws/portfolio")
async def portfolio_websocket(websocket: WebSocket):
    """
    Stream portfolio summary JSON to the browser.

  Query params:
    - api_key: Trading service API key (required)
    - broker: Broker id, default groww
    """
    api_key = websocket.query_params.get("api_key")
    broker_name = websocket.query_params.get("broker", "groww")

    if not _is_valid_api_key(api_key):
        await websocket.close(code=4401, reason="Invalid API key")
        return

    try:
        broker = BrokerType(broker_name)
    except ValueError:
        await websocket.close(code=4400, reason="Invalid broker")
        return

    await websocket.accept()
    logger.info("Portfolio WebSocket connected: broker=%s", broker.value)

    loop = asyncio.get_running_loop()

    try:
        while True:
            payload = await loop.run_in_executor(_executor, _fetch_summary, broker)
            await websocket.send_json(payload)
            await asyncio.sleep(PUSH_INTERVAL_SECONDS)
    except WebSocketDisconnect:
        logger.info("Portfolio WebSocket disconnected")
    except Exception:
        logger.exception("Portfolio WebSocket error")
        await websocket.close(code=1011, reason="Stream error")
