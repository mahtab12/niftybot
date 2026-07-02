"""Per-user auto-trade REST API."""

import asyncio
import logging

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field, field_validator

from app.auto_trade_profiles import (
    GROWW_MCX_UNSUPPORTED_MESSAGE,
    LOT_STEPS,
    VALID_INSTRUMENTS,
    get_profile,
)
from app.middleware.auth import verify_api_key
from app.market_hours import index_market_status
from app.models.schemas import AutoTradeActionResponse, AutoTradeStatusResponse
from app.services.signal_suggestions import build_ai_suggestion
from app.services.user_auto_trade_registry import (
    activate_user_session,
    deactivate_user_session,
    exit_user_session,
    get_user_service,
)

logger = logging.getLogger("niftybot.user_auto_trade")

router = APIRouter(
    prefix="/api/v1/user-auto-trade",
    tags=["User Auto Trade"],
    dependencies=[Depends(verify_api_key)],
)


def _attach_ai_suggestion(payload: dict, service, instrument: str) -> dict:
    if service._active or not service._last_signal:
        return payload
    from app.auto_trade_profiles import get_profile

    payload["ai_suggestion"] = build_ai_suggestion(
        service._last_signal,
        get_profile(instrument),
        underlying_ltp=payload.get("underlying_ltp") or getattr(service, "_underlying_ltp", None),
        option_chain=getattr(service, "_last_option_chain", None),
    )
    return payload


class UserAutoTradeActivateBody(BaseModel):
    mode: str = "buy"
    quantity: int = Field(..., gt=0)
    api_key: str
    api_secret: str
    billing_mode: str = "pay_per_trade"
    per_trade_fee: float = 100.0
    webhook_url: str = ""

    @field_validator("mode")
    @classmethod
    def validate_mode(cls, value: str) -> str:
        key = value.strip().lower()
        if key not in ("buy", "sell"):
            raise ValueError("mode must be buy or sell")
        return key


def _lot_step(instrument: str) -> int:
    return LOT_STEPS.get(instrument, LOT_STEPS["nifty"])


def _attach_index_market_status(payload: dict, instrument: str) -> dict:
    if instrument in ("nifty", "sensex"):
        payload.update(index_market_status())
    return payload


def _unsupported_groww_payload(key: str) -> dict:
    profile = get_profile(key)
    return {
        "success": True,
        "instrument": key,
        "instrument_label": profile.label,
        "active": False,
        "message": GROWW_MCX_UNSUPPORTED_MESSAGE,
        "groww_trading_supported": False,
        "underlying_ltp": None,
        "nifty_ltp": None,
        "last_check_at": None,
        "last_signal": None,
        "trade_mode": "buy",
        "current_trade": None,
        "trade_history": [],
        "config": {},
        "ai_suggestion": None,
    }


def _user_auto_trade_status_sync(uid: int, key: str) -> dict:
    profile = get_profile(key)
    if not profile.groww_trading_supported:
        return _unsupported_groww_payload(key)

    service = get_user_service(uid, key)
    if not service:
        from app.services.auto_trade_service import get_auto_trade_service
        from app.services.signal_suggestions import build_ai_suggestion

        scanner = get_auto_trade_service(key)
        scan_status = scanner.get_status()
        signal_info = scan_status.last_signal.model_dump() if scan_status.last_signal else None
        suggestion = None
        if scanner._last_signal:
            suggestion = build_ai_suggestion(
                scanner._last_signal,
                profile,
                underlying_ltp=scan_status.underlying_ltp,
                option_chain=getattr(scanner, "_last_option_chain", None),
            )
        return _attach_index_market_status({
            "success": True,
            "instrument": key,
            "instrument_label": profile.label,
            "active": False,
            "message": "User auto trade is inactive",
            "groww_trading_supported": True,
            "underlying_ltp": scan_status.underlying_ltp,
            "nifty_ltp": scan_status.underlying_ltp,
            "last_check_at": scan_status.last_check_at,
            "last_signal": signal_info,
            "trade_mode": "buy",
            "current_trade": None,
            "trade_history": [],
            "config": scan_status.config,
            "ai_suggestion": suggestion,
        }, key)

    status = service.get_status()
    payload = status.model_dump()
    payload["groww_trading_supported"] = True
    payload = _attach_ai_suggestion(payload, service, key)
    return _attach_index_market_status(payload, key)


@router.get("/{uid}/{instrument}/status")
async def user_auto_trade_status(uid: int, instrument: str):
    key = instrument.strip().lower()
    if key not in VALID_INSTRUMENTS:
        raise HTTPException(status_code=400, detail=f"instrument must be one of: {', '.join(sorted(VALID_INSTRUMENTS))}")
    return await asyncio.to_thread(_user_auto_trade_status_sync, uid, key)


@router.post("/{uid}/{instrument}/activate", response_model=AutoTradeActionResponse)
async def user_auto_trade_activate(
    uid: int,
    instrument: str,
    body: UserAutoTradeActivateBody,
):
    key = instrument.strip().lower()
    if key not in VALID_INSTRUMENTS:
        raise HTTPException(status_code=400, detail=f"instrument must be one of: {', '.join(sorted(VALID_INSTRUMENTS))}")

    step = _lot_step(key)
    if body.quantity < step or body.quantity % step != 0:
        raise HTTPException(
            status_code=400,
            detail=f"quantity must be a multiple of {step} for {key}",
        )

    try:
        service = activate_user_session(
            uid,
            key,
            mode=body.mode,
            quantity=body.quantity,
            api_key=body.api_key,
            api_secret=body.api_secret,
            billing_mode=body.billing_mode,
            webhook_url=body.webhook_url,
        )
    except ValueError as exc:
        return AutoTradeActionResponse(
            success=False,
            active=False,
            trade_mode=body.mode,
            message=str(exc),
        )

    status = service.get_status()
    return AutoTradeActionResponse(
        success=True,
        active=status.active,
        trade_mode=status.trade_mode,
        message=status.message,
    )


@router.post("/{uid}/{instrument}/deactivate", response_model=AutoTradeActionResponse)
async def user_auto_trade_deactivate(uid: int, instrument: str):
    key = instrument.strip().lower()
    service = deactivate_user_session(uid, key)
    if not service:
        return AutoTradeActionResponse(
            success=False,
            active=False,
            message="No active user auto-trade session",
        )
    status = service.get_status()
    return AutoTradeActionResponse(
        success=True,
        active=status.active,
        trade_mode=status.trade_mode,
        message=status.message,
    )


@router.post("/{uid}/{instrument}/exit", response_model=AutoTradeStatusResponse)
async def user_auto_trade_exit(uid: int, instrument: str):
    key = instrument.strip().lower()
    service = exit_user_session(uid, key)
    if not service:
        raise HTTPException(status_code=404, detail="No user auto-trade session")
    return service.get_status()
