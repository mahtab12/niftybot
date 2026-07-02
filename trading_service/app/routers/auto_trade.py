"""Auto-trade REST API for Nifty and Sensex."""

import asyncio
import logging

from fastapi import APIRouter, Depends, HTTPException

from app.auto_trade_profiles import VALID_INSTRUMENTS
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


def _service(instrument: str):
    key = instrument.strip().lower()
    if key not in VALID_INSTRUMENTS:
        raise HTTPException(
            status_code=400,
            detail=f"instrument must be one of: {', '.join(sorted(VALID_INSTRUMENTS))}",
        )
    return get_auto_trade_service(key)


@router.get("/{instrument}/status", response_model=AutoTradeStatusResponse)
async def get_auto_trade_status(instrument: str):
    """Current auto-trade state for Nifty or Sensex."""
    return await asyncio.to_thread(_service(instrument).get_status)


@router.get("/{instrument}/suggestions")
async def get_auto_trade_suggestions(instrument: str):
    """Live AI-style market suggestion without activating auto-trade."""
    from app.auto_trade_profiles import get_profile, GROWW_MCX_UNSUPPORTED_MESSAGE
    from app.services.signal_suggestions import build_ai_suggestion, signal_is_stale

    profile = get_profile(instrument)
    if not profile.groww_trading_supported:
        return {
            "success": False,
            "message": GROWW_MCX_UNSUPPORTED_MESSAGE,
            "groww_trading_supported": False,
            "instrument": profile.instrument_id,
            "instrument_label": profile.label,
        }

    service = _service(instrument)
    if signal_is_stale(service._last_check_at) or not service._last_signal:
        await asyncio.to_thread(service.refresh_market_signal)
    profile = get_profile(instrument)
    signal = service._last_signal or {
        "action": "HOLD",
        "confidence": 0.0,
        "reasons": [],
        "indicators": {},
    }
    suggestion = build_ai_suggestion(
        signal,
        profile,
        underlying_ltp=service._underlying_ltp,
        option_chain=getattr(service, "_last_option_chain", None),
    )
    signal_info = None
    if service._last_signal:
        from app.models.schemas import AutoTradeSignalInfo
        signal_info = AutoTradeSignalInfo(**service._last_signal).model_dump()
    return {
        "success": True,
        "instrument": profile.instrument_id,
        "instrument_label": profile.label,
        "underlying_ltp": service._underlying_ltp,
        "last_check_at": service._last_check_at,
        "last_signal": signal_info,
        "suggestion": suggestion,
        "ai_suggestion": suggestion,
    }


@router.get("/{instrument}/history", response_model=AutoTradeHistoryResponse)
async def get_auto_trade_history(instrument: str, limit: int = 50):
    """Closed auto-trades from database (for analytics and ML)."""
    key = instrument.strip().lower()
    if key not in VALID_INSTRUMENTS:
        raise HTTPException(
            status_code=400,
            detail=f"instrument must be one of: {', '.join(sorted(VALID_INSTRUMENTS))}",
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
