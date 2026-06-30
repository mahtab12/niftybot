"""WebSocket stream for live auto-trade status and P&L."""

import asyncio
import hmac
import logging
from concurrent.futures import ThreadPoolExecutor

from fastapi import APIRouter, WebSocket, WebSocketDisconnect

from app.auto_trade_profiles import VALID_INSTRUMENTS
from app.config import settings
from app.services.auto_trade_service import get_auto_trade_service

logger = logging.getLogger("niftybot.auto_trade_stream")

router = APIRouter(tags=["Auto Trade"])

_executor = ThreadPoolExecutor(max_workers=4)
PUSH_INTERVAL_SECONDS = 3


def _is_valid_api_key(api_key: str | None) -> bool:
    if not settings.api_key or not api_key:
        return False
    return hmac.compare_digest(api_key, settings.api_key)


@router.websocket("/ws/auto-trade")
async def auto_trade_websocket(websocket: WebSocket):
    """Stream auto-trade status for nifty or sensex (?instrument=sensex)."""
    api_key = websocket.query_params.get("api_key")
    instrument = websocket.query_params.get("instrument", "nifty").lower()

    if not _is_valid_api_key(api_key):
        await websocket.close(code=4401, reason="Invalid API key")
        return

    if instrument not in VALID_INSTRUMENTS:
        await websocket.close(
            code=4400,
            reason=f"instrument must be one of: {', '.join(sorted(VALID_INSTRUMENTS))}",
        )
        return

    await websocket.accept()
    logger.info("Auto-trade WebSocket connected: %s", instrument)

    loop = asyncio.get_running_loop()
    service = get_auto_trade_service(instrument)

    try:
        while True:
            payload = await loop.run_in_executor(
                _executor, lambda: service.get_status().model_dump(),
            )
            await websocket.send_json(payload)
            await asyncio.sleep(PUSH_INTERVAL_SECONDS)
    except WebSocketDisconnect:
        logger.info("Auto-trade WebSocket disconnected: %s", instrument)
    except Exception:
        logger.exception("Auto-trade WebSocket error")
        await websocket.close(code=1011, reason="Stream error")
