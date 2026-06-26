"""Persist auto-trade records to shared MariaDB for analytics and ML."""

from __future__ import annotations

import json
import logging
import time
from datetime import datetime
from typing import Any, Optional

from sqlalchemy import text

from app.auto_trade_profiles import AutoTradeProfile
from app.services.db_service import SessionLocal

logger = logging.getLogger("niftybot.auto_trade_db")


def _iso_to_unix(value: Any) -> int:
    if isinstance(value, (int, float)):
        return int(value)
    if isinstance(value, str) and value:
        try:
            return int(datetime.fromisoformat(value).timestamp())
        except ValueError:
            pass
    return int(time.time())


def _json_dump(value: Any) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, str):
        return value
    try:
        return json.dumps(value, default=str)
    except (TypeError, ValueError):
        return None


def insert_auto_trade(
    trade: dict[str, Any],
    profile: AutoTradeProfile,
    underlying_ltp: Optional[float] = None,
    signal: Optional[dict[str, Any]] = None,
) -> Optional[int]:
    """Insert a new open auto-trade row. Returns record_id."""
    now = int(time.time())
    signal = signal or {}
    db = SessionLocal()
    try:
        result = db.execute(
            text(
                "INSERT INTO niftybot_auto_trades ("
                "trade_id, instrument, trade_mode, position_side, option_type, "
                "symbol, exchange, strike, expiry_date, quantity, "
                "entry_price, current_price, stop_loss, target, "
                "sl_points, target_points, pnl, pnl_percentage, "
                "underlying_ltp_at_entry, broker_order_id, smart_order_id, "
                "smart_order_status, signal_action, signal_confidence, "
                "signal_reasons, signal_indicators, status, exit_reason, "
                "recovered_from_broker, opened_at, updated"
                ") VALUES ("
                ":trade_id, :instrument, :trade_mode, :position_side, :option_type, "
                ":symbol, :exchange, :strike, :expiry_date, :quantity, "
                ":entry_price, :current_price, :stop_loss, :target, "
                ":sl_points, :target_points, :pnl, :pnl_percentage, "
                ":underlying_ltp_at_entry, :broker_order_id, :smart_order_id, "
                ":smart_order_status, :signal_action, :signal_confidence, "
                ":signal_reasons, :signal_indicators, :status, :exit_reason, "
                ":recovered_from_broker, :opened_at, :updated"
                ")"
            ),
            {
                "trade_id": trade.get("trade_id", ""),
                "instrument": profile.instrument_id,
                "trade_mode": trade.get("trade_mode", "buy"),
                "position_side": trade.get("position_side", "long"),
                "option_type": trade.get("option_type", ""),
                "symbol": trade.get("symbol", ""),
                "exchange": profile.order_exchange.value,
                "strike": trade.get("strike"),
                "expiry_date": trade.get("expiry_date") or "",
                "quantity": int(trade.get("quantity") or 0),
                "entry_price": trade.get("entry_price"),
                "current_price": trade.get("current_price"),
                "stop_loss": trade.get("stop_loss"),
                "target": trade.get("target"),
                "sl_points": trade.get("sl_points"),
                "target_points": trade.get("target_points"),
                "pnl": trade.get("pnl", 0),
                "pnl_percentage": trade.get("pnl_percentage", 0),
                "underlying_ltp_at_entry": underlying_ltp,
                "broker_order_id": trade.get("broker_order_id"),
                "smart_order_id": trade.get("smart_order_id"),
                "smart_order_status": trade.get("smart_order_status"),
                "signal_action": signal.get("action"),
                "signal_confidence": signal.get("confidence"),
                "signal_reasons": _json_dump(
                    trade.get("signal_reasons") or signal.get("reasons"),
                ),
                "signal_indicators": _json_dump(signal.get("indicators")),
                "status": trade.get("status", "open"),
                "exit_reason": trade.get("exit_reason"),
                "recovered_from_broker": 1 if trade.get("recovered_from_broker") else 0,
                "opened_at": _iso_to_unix(trade.get("opened_at")),
                "updated": now,
            },
        )
        db.commit()
        record_id = result.lastrowid
        logger.info(
            "Persisted auto-trade %s as record_id=%s",
            trade.get("trade_id"),
            record_id,
        )
        return int(record_id) if record_id else None
    except Exception:
        db.rollback()
        logger.exception("Failed to insert auto-trade %s", trade.get("trade_id"))
        return None
    finally:
        db.close()


def update_auto_trade(record_id: int, trade: dict[str, Any]) -> None:
    """Update an open auto-trade snapshot (prices, OCO, P&L)."""
    if not record_id:
        return
    now = int(time.time())
    db = SessionLocal()
    try:
        db.execute(
            text(
                "UPDATE niftybot_auto_trades SET "
                "entry_price = :entry_price, "
                "current_price = :current_price, "
                "stop_loss = :stop_loss, "
                "target = :target, "
                "sl_points = :sl_points, "
                "target_points = :target_points, "
                "pnl = :pnl, "
                "pnl_percentage = :pnl_percentage, "
                "smart_order_id = :smart_order_id, "
                "smart_order_status = :smart_order_status, "
                "status = :status, "
                "updated = :updated "
                "WHERE record_id = :record_id"
            ),
            {
                "record_id": record_id,
                "entry_price": trade.get("entry_price"),
                "current_price": trade.get("current_price"),
                "stop_loss": trade.get("stop_loss"),
                "target": trade.get("target"),
                "sl_points": trade.get("sl_points"),
                "target_points": trade.get("target_points"),
                "pnl": trade.get("pnl"),
                "pnl_percentage": trade.get("pnl_percentage"),
                "smart_order_id": trade.get("smart_order_id"),
                "smart_order_status": trade.get("smart_order_status"),
                "status": trade.get("status", "open"),
                "updated": now,
            },
        )
        db.commit()
    except Exception:
        db.rollback()
        logger.exception("Failed to update auto-trade record %s", record_id)
    finally:
        db.close()


def close_auto_trade(
    record_id: int,
    trade: dict[str, Any],
    exit_broker_order_id: Optional[str] = None,
) -> None:
    """Mark auto-trade closed with final exit data."""
    if not record_id:
        return
    now = int(time.time())
    db = SessionLocal()
    try:
        db.execute(
            text(
                "UPDATE niftybot_auto_trades SET "
                "status = 'closed', "
                "exit_price = :exit_price, "
                "current_price = :current_price, "
                "stop_loss = :stop_loss, "
                "target = :target, "
                "sl_points = :sl_points, "
                "target_points = :target_points, "
                "pnl = :pnl, "
                "pnl_percentage = :pnl_percentage, "
                "exit_broker_order_id = :exit_broker_order_id, "
                "smart_order_id = :smart_order_id, "
                "smart_order_status = :smart_order_status, "
                "exit_reason = :exit_reason, "
                "closed_at = :closed_at, "
                "updated = :updated "
                "WHERE record_id = :record_id"
            ),
            {
                "record_id": record_id,
                "exit_price": trade.get("current_price"),
                "current_price": trade.get("current_price"),
                "stop_loss": trade.get("stop_loss"),
                "target": trade.get("target"),
                "sl_points": trade.get("sl_points"),
                "target_points": trade.get("target_points"),
                "pnl": trade.get("pnl"),
                "pnl_percentage": trade.get("pnl_percentage"),
                "exit_broker_order_id": exit_broker_order_id,
                "smart_order_id": trade.get("smart_order_id"),
                "smart_order_status": trade.get("smart_order_status"),
                "exit_reason": trade.get("exit_reason"),
                "closed_at": _iso_to_unix(trade.get("closed_at")) or now,
                "updated": now,
            },
        )
        db.commit()
        logger.info(
            "Closed auto-trade record %s (%s) pnl=%s",
            record_id,
            trade.get("exit_reason"),
            trade.get("pnl"),
        )
    except Exception:
        db.rollback()
        logger.exception("Failed to close auto-trade record %s", record_id)
    finally:
        db.close()


def close_other_open_trades(instrument: str, keep_trade_id: str) -> None:
    """Close stale open rows when a new system trade is recorded."""
    if not keep_trade_id:
        return
    now = int(time.time())
    db = SessionLocal()
    try:
        db.execute(
            text(
                "UPDATE niftybot_auto_trades SET "
                "status = 'closed', exit_reason = 'superseded', "
                "closed_at = :closed_at, updated = :updated "
                "WHERE instrument = :instrument AND status = 'open' "
                "AND trade_id != :keep_trade_id"
            ),
            {
                "instrument": instrument.strip().lower(),
                "keep_trade_id": keep_trade_id,
                "closed_at": now,
                "updated": now,
            },
        )
        db.commit()
    except Exception:
        db.rollback()
        logger.exception(
            "Failed to close superseded trades for %s", instrument,
        )
    finally:
        db.close()


def void_untracked_open_trades(instrument: str) -> None:
    """Close mistaken broker-recovery rows that are not real system trades."""
    now = int(time.time())
    db = SessionLocal()
    try:
        db.execute(
            text(
                "UPDATE niftybot_auto_trades SET "
                "status = 'closed', exit_reason = 'untracked_recovery', "
                "closed_at = :closed_at, updated = :updated "
                "WHERE instrument = :instrument AND status = 'open' "
                "AND recovered_from_broker = 1 AND broker_order_id IS NULL"
            ),
            {
                "instrument": instrument.strip().lower(),
                "closed_at": now,
                "updated": now,
            },
        )
        db.commit()
    except Exception:
        db.rollback()
        logger.exception(
            "Failed to void untracked trades for %s", instrument,
        )
    finally:
        db.close()


def get_open_auto_trade(instrument: str) -> Optional[dict[str, Any]]:
    """Load the latest open system auto-trade for an instrument from DB."""
    db = SessionLocal()
    try:
        row = db.execute(
            text(
                "SELECT * FROM niftybot_auto_trades "
                "WHERE instrument = :instrument AND status = 'open' "
                "AND recovered_from_broker = 0 "
                "ORDER BY opened_at DESC LIMIT 1"
            ),
            {"instrument": instrument.strip().lower()},
        ).mappings().first()
        if not row:
            return None
        return trade_dict_from_row(dict(row))
    except Exception:
        logger.exception("Failed to load open auto-trade for %s", instrument)
        return None
    finally:
        db.close()


def find_open_auto_trade_by_symbol(symbol: str) -> Optional[dict[str, Any]]:
    """Load open system auto-trade by option symbol."""
    db = SessionLocal()
    try:
        row = db.execute(
            text(
                "SELECT * FROM niftybot_auto_trades "
                "WHERE symbol = :symbol AND status = 'open' "
                "AND recovered_from_broker = 0 "
                "ORDER BY opened_at DESC LIMIT 1"
            ),
            {"symbol": symbol},
        ).mappings().first()
        if not row:
            return None
        return trade_dict_from_row(dict(row))
    except Exception:
        logger.exception("Failed to load auto-trade for symbol %s", symbol)
        return None
    finally:
        db.close()


def get_recent_closed_trades(
    instrument: str,
    limit: int = 10,
) -> list[dict[str, Any]]:
    """Load recent closed auto-trades for an instrument."""
    limit = max(1, min(int(limit), 500))
    db = SessionLocal()
    try:
        rows = db.execute(
            text(
                "SELECT * FROM niftybot_auto_trades "
                "WHERE instrument = :instrument AND status = 'closed' "
                "ORDER BY closed_at DESC LIMIT :limit"
            ),
            {"instrument": instrument.strip().lower(), "limit": limit},
        ).mappings().all()
        return [trade_dict_from_row(dict(row)) for row in rows]
    except Exception:
        logger.exception("Failed to load closed trades for %s", instrument)
        return []
    finally:
        db.close()


def trade_dict_from_row(row: dict[str, Any]) -> dict[str, Any]:
    """Convert a DB row to in-memory trade dict."""
    reasons_raw = row.get("signal_reasons")
    reasons: list[str] = []
    if reasons_raw:
        try:
            parsed = json.loads(reasons_raw)
            if isinstance(parsed, list):
                reasons = [str(r) for r in parsed]
        except json.JSONDecodeError:
            reasons = []

    opened = row.get("opened_at")
    closed = row.get("closed_at")
    return {
        "record_id": row.get("record_id"),
        "trade_id": row.get("trade_id", ""),
        "status": row.get("status", "open"),
        "trade_mode": row.get("trade_mode", "buy"),
        "position_side": row.get("position_side", "long"),
        "instrument": row.get("instrument", ""),
        "option_type": row.get("option_type", ""),
        "symbol": row.get("symbol", ""),
        "strike": float(row["strike"]) if row.get("strike") is not None else None,
        "expiry_date": row.get("expiry_date") or "",
        "quantity": int(row.get("quantity") or 0),
        "entry_price": (
            float(row["entry_price"]) if row.get("entry_price") is not None else None
        ),
        "current_price": (
            float(row["current_price"])
            if row.get("current_price") is not None
            else None
        ),
        "stop_loss": (
            float(row["stop_loss"]) if row.get("stop_loss") is not None else None
        ),
        "target": float(row["target"]) if row.get("target") is not None else None,
        "sl_points": (
            float(row["sl_points"]) if row.get("sl_points") is not None else None
        ),
        "target_points": (
            float(row["target_points"])
            if row.get("target_points") is not None
            else None
        ),
        "pnl": float(row.get("pnl") or 0),
        "pnl_percentage": float(row.get("pnl_percentage") or 0),
        "broker_order_id": row.get("broker_order_id"),
        "smart_order_id": row.get("smart_order_id"),
        "smart_order_status": row.get("smart_order_status"),
        "signal_reasons": reasons,
        "opened_at": (
            datetime.fromtimestamp(int(opened)).isoformat(timespec="seconds")
            if opened
            else None
        ),
        "closed_at": (
            datetime.fromtimestamp(int(closed)).isoformat(timespec="seconds")
            if closed
            else None
        ),
        "exit_reason": row.get("exit_reason"),
        "recovered_from_broker": bool(row.get("recovered_from_broker")),
        "pricing_pending": row.get("entry_price") is None,
    }
