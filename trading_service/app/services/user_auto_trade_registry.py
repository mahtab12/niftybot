"""Per-user auto-trade session registry."""

from __future__ import annotations

import logging
from dataclasses import replace
from typing import Any, Callable

import httpx

from app.auto_trade_profiles import get_profile
from app.brokers.groww_broker import GrowwBroker
from app.config import settings
from app.services.auto_trade_service import AutoTradeService

logger = logging.getLogger("niftybot.user_auto_trade")

_REGISTRY: dict[str, AutoTradeService] = {}


def _session_key(uid: int, instrument: str) -> str:
    return f"{uid}:{instrument.strip().lower()}"


def _billing_callback(
    uid: int,
    instrument: str,
    webhook_url: str,
) -> Callable[[dict[str, Any]], None]:
    """POST to Drupal to debit pay-per-trade wallet fee."""

    def _callback(trade: dict[str, Any]) -> None:
        trade_id = str(trade.get("trade_id") or "")
        if not trade_id or not webhook_url:
            return
        try:
            httpx.post(
                webhook_url,
                json={
                    "uid": uid,
                    "instrument": instrument,
                    "trade_id": trade_id,
                },
                headers={"X-API-Key": settings.api_key or ""},
                timeout=15.0,
            )
        except Exception:
            logger.exception(
                "Billing webhook failed uid=%s instrument=%s trade=%s",
                uid,
                instrument,
                trade_id,
            )

    return _callback


def _groww_from_credentials(api_key: str, api_secret: str) -> GrowwBroker | None:
    broker = GrowwBroker()
    api_key = GrowwBroker._normalize_secret(api_key)
    api_secret = GrowwBroker._normalize_secret(api_secret)
    try:
        from growwapi import GrowwAPI

        if GrowwBroker._looks_like_access_token(api_key):
            access_token = api_key
        else:
            if not api_key or not api_secret:
                return None
            access_token = GrowwAPI.get_access_token(api_key=api_key, secret=api_secret)

        broker._client = GrowwAPI(access_token)
        broker._connected = True
        profile = broker.get_user_profile()
        if not profile.success:
            return None
        return broker
    except Exception:
        logger.exception("Failed to connect user Groww broker")
        return None


def get_user_service(uid: int, instrument: str) -> AutoTradeService | None:
    return _REGISTRY.get(_session_key(uid, instrument))


def activate_user_session(
    uid: int,
    instrument: str,
    *,
    mode: str,
    quantity: int,
    api_key: str,
    api_secret: str,
    billing_mode: str,
    webhook_url: str,
) -> AutoTradeService:
    key = _session_key(uid, instrument)
    existing = _REGISTRY.get(key)
    if existing and existing._active:
        return existing

    broker = _groww_from_credentials(api_key, api_secret)
    if broker is None:
        raise ValueError("Could not connect with your Groww credentials.")

    profile = replace(get_profile(instrument), lot_size=quantity)
    on_entry = None
    if billing_mode == "pay_per_trade" and webhook_url:
        on_entry = _billing_callback(uid, instrument, webhook_url)

    service = AutoTradeService(
        profile,
        user_broker=broker,
        user_id=uid,
        on_trade_entry=on_entry,
    )
    service.activate(mode=mode)
    _REGISTRY[key] = service
    return service


def deactivate_user_session(uid: int, instrument: str) -> AutoTradeService | None:
    key = _session_key(uid, instrument)
    service = _REGISTRY.get(key)
    if not service:
        return None
    service.deactivate()
    return service


def exit_user_session(uid: int, instrument: str) -> AutoTradeService | None:
    service = get_user_service(uid, instrument)
    if not service:
        return None
    service.manual_exit()
    return service
