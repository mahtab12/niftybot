"""Platform trade-setup alerts for in-app user notifications."""

from __future__ import annotations

import json
import logging
import time
from typing import Any, Optional

from sqlalchemy import text

from app.services.db_service import SessionLocal

logger = logging.getLogger("niftybot.trade_alerts")

ALERT_ACTIONS = frozenset({"BUY_CE", "BUY_PE", "SELL_CE", "SELL_PE"})
DEDUPE_SECONDS = 1800
MIN_ALERT_CONFIDENCE = 0.75


def maybe_insert_trade_alert(
    instrument: str,
    signal_action: str,
    confidence: float,
    underlying_ltp: Optional[float],
    reasons: Optional[list[str]] = None,
    indicators: Optional[dict[str, Any]] = None,
) -> Optional[int]:
    """Insert a trade-setup alert when confidence meets threshold (deduped)."""
    action = (signal_action or "").strip().upper()
    if action not in ALERT_ACTIONS:
        return None
    if confidence < MIN_ALERT_CONFIDENCE:
        return None

    instrument_key = instrument.strip().lower()
    now = int(time.time())
    reasons = reasons or []
    indicators = indicators or {}

    label = instrument_key.upper()
    option = "CE" if action.endswith("CE") else "PE"
    side = "Buy" if action.startswith("BUY") else "Sell"
    title = f"{label} {side} setup — {option}"
    message = (
        f"{label} {side.lower()} {option} setup detected "
        f"({confidence:.0%} confidence). "
        f"Place the trade manually on Groww if you want to act on this signal."
    )
    if reasons:
        message += " Reasons: " + ", ".join(str(r).replace("_", " ") for r in reasons[:4])

    payload = {
        "reasons": reasons,
        "indicators": indicators,
        "underlying_ltp": underlying_ltp,
    }

    db = SessionLocal()
    try:
        recent = db.execute(
            text(
                "SELECT alert_id FROM niftybot_trade_alerts "
                "WHERE instrument = :instrument AND signal_action = :action "
                "AND created > :since ORDER BY alert_id DESC LIMIT 1"
            ),
            {
                "instrument": instrument_key,
                "action": action,
                "since": now - DEDUPE_SECONDS,
            },
        ).first()
        if recent:
            return None

        result = db.execute(
            text(
                "INSERT INTO niftybot_trade_alerts ("
                "instrument, signal_action, confidence, title, message, "
                "underlying_ltp, payload, created"
                ") VALUES ("
                ":instrument, :signal_action, :confidence, :title, :message, "
                ":underlying_ltp, :payload, :created"
                ")"
            ),
            {
                "instrument": instrument_key,
                "signal_action": action,
                "confidence": confidence,
                "title": title,
                "message": message,
                "underlying_ltp": underlying_ltp,
                "payload": json.dumps(payload, default=str),
                "created": now,
            },
        )
        db.commit()
        alert_id = result.lastrowid
        logger.info(
            "Trade alert created id=%s instrument=%s action=%s confidence=%.2f",
            alert_id,
            instrument_key,
            action,
            confidence,
        )
        return int(alert_id) if alert_id else None
    except Exception:
        db.rollback()
        logger.exception("Failed to insert trade alert for %s", instrument_key)
        return None
    finally:
        db.close()
