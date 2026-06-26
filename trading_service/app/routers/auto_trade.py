"""Auto-trade REST API for Nifty and Sensex."""

import logging

from fastapi import APIRouter, Depends, HTTPException

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    AutoTradeActionResponse,
    AutoTradeActivateRequest,
    AutoTradeHistoryResponse,
    AutoTradePosition,
    AutoTradeStatusResponse,
)
from app.services.auto_trade_db import get_recent_closed_trades
from app.services.auto_trade_service import get_auto_trade_service

logger = logging.getLogger("niftybot.auto_trade")

router = APIRouter(
    prefix="/api/v1/auto-trade",
    tags=["Auto Trade"],
    dependencies=[Depends(verify_api_key)],
)

VALID_INSTRUMENTS = frozenset({"nifty", "sensex"})


def _service(instrument: str):
    key = instrument.strip().lower()
    if key not in VALID_INSTRUMENTS:
        raise HTTPException(
            status_code=400,
            detail="instrument must be nifty or sensex",
        )
    return get_auto_trade_service(key)


@router.get("/{instrument}/status", response_model=AutoTradeStatusResponse)
async def get_auto_trade_status(instrument: str):
    """Current auto-trade state for Nifty or Sensex."""
    return _service(instrument).get_status()


@router.get("/{instrument}/history", response_model=AutoTradeHistoryResponse)
async def get_auto_trade_history(instrument: str, limit: int = 50):
    """Closed auto-trades from database (for analytics and ML)."""
    key = instrument.strip().lower()
    if key not in VALID_INSTRUMENTS:
        raise HTTPException(
            status_code=400,
            detail="instrument must be nifty or sensex",
        )
    rows = get_recent_closed_trades(key, limit=limit)
    trades = [
        AutoTradePosition(
            record_id=r.get("record_id"),
            trade_id=r.get("trade_id", ""),
            status=r.get("status", "closed"),
            trade_mode=r.get("trade_mode", "buy"),
            position_side=r.get("position_side", "long"),
            option_type=r.get("option_type", ""),
            symbol=r.get("symbol", ""),
            strike=r.get("strike"),
            expiry_date=r.get("expiry_date", ""),
            quantity=r.get("quantity", 0),
            entry_price=r.get("entry_price"),
            current_price=r.get("current_price"),
            stop_loss=r.get("stop_loss"),
            target=r.get("target"),
            sl_points=r.get("sl_points"),
            target_points=r.get("target_points"),
            pnl=r.get("pnl"),
            pnl_percentage=r.get("pnl_percentage"),
            broker_order_id=r.get("broker_order_id"),
            smart_order_id=r.get("smart_order_id"),
            smart_order_status=r.get("smart_order_status"),
            exit_reason=r.get("exit_reason"),
            signal_reasons=r.get("signal_reasons", []),
            opened_at=r.get("opened_at"),
            closed_at=r.get("closed_at"),
        )
        for r in rows
    ]
    return AutoTradeHistoryResponse(
        success=True,
        instrument=key,
        count=len(trades),
        trades=trades,
    )


@router.post("/{instrument}/activate", response_model=AutoTradeActionResponse)
async def activate_auto_trade(instrument: str, body: AutoTradeActivateRequest):
    """Start scanning — mode buy (ATM) or sell (OTM ±3 strikes)."""
    result = _service(instrument).activate(mode=body.mode)
    return AutoTradeActionResponse(
        success=True,
        active=result.active,
        trade_mode=result.trade_mode,
        message=result.message,
    )


@router.post("/{instrument}/deactivate", response_model=AutoTradeActionResponse)
async def deactivate_auto_trade(instrument: str):
    """Stop new entries; open trade continues to be monitored."""
    result = _service(instrument).deactivate()
    return AutoTradeActionResponse(
        success=True,
        active=result.active,
        trade_mode=result.trade_mode,
        message=result.message,
    )


@router.post("/{instrument}/exit", response_model=AutoTradeStatusResponse)
async def manual_exit_auto_trade(instrument: str):
    """Manually close the open auto-trade position at market."""
    return _service(instrument).manual_exit()


# Legacy Nifty-only routes (backward compatible).
@router.get("/status", response_model=AutoTradeStatusResponse)
async def get_nifty_auto_trade_status():
    return get_auto_trade_service("nifty").get_status()


@router.post("/activate", response_model=AutoTradeActionResponse)
async def activate_nifty_auto_trade(body: AutoTradeActivateRequest):
    result = get_auto_trade_service("nifty").activate(mode=body.mode)
    return AutoTradeActionResponse(
        success=True,
        active=result.active,
        trade_mode=result.trade_mode,
        message=result.message,
    )


@router.post("/deactivate", response_model=AutoTradeActionResponse)
async def deactivate_nifty_auto_trade():
    result = get_auto_trade_service("nifty").deactivate()
    return AutoTradeActionResponse(
        success=True,
        active=result.active,
        trade_mode=result.trade_mode,
        message=result.message,
    )
