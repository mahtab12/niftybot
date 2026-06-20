"""Database service for updating order status in the shared MariaDB."""

import logging
import time

from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker

from app.config import settings

logger = logging.getLogger("niftybot.db")

engine = create_engine(
    settings.database_url,
    pool_pre_ping=True,
    pool_recycle=3600,
    echo=settings.debug,
)

SessionLocal = sessionmaker(bind=engine)


def get_db():
    """FastAPI dependency for database sessions."""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def update_order_status(
    order_id: int,
    status: str,
    broker_order_id: str | None = None,
    executed_price: float | None = None,
    executed_quantity: int | None = None,
    status_message: str | None = None,
):
    """Update order status in the Drupal niftybot_orders table."""
    now = int(time.time())
    db = SessionLocal()

    try:
        params = {
            "status": status,
            "updated": now,
            "order_id": order_id,
        }

        set_clauses = ["status = :status", "updated = :updated"]

        if broker_order_id:
            set_clauses.append("broker_order_id = :broker_order_id")
            params["broker_order_id"] = broker_order_id

        if executed_price is not None:
            set_clauses.append("executed_price = :executed_price")
            params["executed_price"] = executed_price

        if executed_quantity is not None:
            set_clauses.append("executed_quantity = :executed_quantity")
            params["executed_quantity"] = executed_quantity

        if status_message:
            set_clauses.append("status_message = :status_message")
            params["status_message"] = status_message

        if status == "executed":
            set_clauses.append("executed_at = :executed_at")
            params["executed_at"] = now

        query = text(
            f"UPDATE niftybot_orders SET {', '.join(set_clauses)} "
            f"WHERE order_id = :order_id"
        )

        db.execute(query, params)
        db.commit()

        logger.info("Updated order %d status to %s", order_id, status)

    except Exception:
        db.rollback()
        logger.exception("Failed to update order %d", order_id)
        raise
    finally:
        db.close()


def update_position(
    uid: int,
    symbol: str,
    exchange: str,
    broker: str,
    quantity: int,
    average_price: float,
    current_price: float | None = None,
    product_type: str = "CNC",
):
    """Update or create a position in niftybot_positions table."""
    now = int(time.time())
    db = SessionLocal()

    try:
        existing = db.execute(
            text(
                "SELECT position_id, quantity FROM niftybot_positions "
                "WHERE uid = :uid AND symbol = :symbol AND broker = :broker AND status = 'open'"
            ),
            {"uid": uid, "symbol": symbol, "broker": broker},
        ).fetchone()

        if existing:
            pnl = 0.0
            pnl_pct = 0.0
            if current_price and average_price > 0:
                pnl = (current_price - average_price) * quantity
                pnl_pct = (current_price - average_price) / average_price * 100

            if quantity == 0:
                db.execute(
                    text(
                        "UPDATE niftybot_positions SET status = 'closed', "
                        "closed_at = :now, updated = :now "
                        "WHERE position_id = :pid"
                    ),
                    {"now": now, "pid": existing[0]},
                )
            else:
                db.execute(
                    text(
                        "UPDATE niftybot_positions SET "
                        "quantity = :qty, average_price = :avg, "
                        "current_price = :cur, pnl = :pnl, "
                        "pnl_percentage = :pnl_pct, updated = :now "
                        "WHERE position_id = :pid"
                    ),
                    {
                        "qty": quantity,
                        "avg": average_price,
                        "cur": current_price,
                        "pnl": round(pnl, 2),
                        "pnl_pct": round(pnl_pct, 2),
                        "now": now,
                        "pid": existing[0],
                    },
                )
        else:
            pnl = 0.0
            pnl_pct = 0.0
            if current_price and average_price > 0:
                pnl = (current_price - average_price) * quantity
                pnl_pct = (current_price - average_price) / average_price * 100

            db.execute(
                text(
                    "INSERT INTO niftybot_positions "
                    "(uid, broker, symbol, exchange, instrument_type, product_type, "
                    "quantity, average_price, current_price, pnl, pnl_percentage, "
                    "status, opened_at, updated) "
                    "VALUES (:uid, :broker, :symbol, :exchange, 'equity', :product, "
                    ":qty, :avg, :cur, :pnl, :pnl_pct, 'open', :now, :now)"
                ),
                {
                    "uid": uid,
                    "broker": broker,
                    "symbol": symbol,
                    "exchange": exchange,
                    "product": product_type,
                    "qty": quantity,
                    "avg": average_price,
                    "cur": current_price,
                    "pnl": round(pnl, 2),
                    "pnl_pct": round(pnl_pct, 2),
                    "now": now,
                },
            )

        db.commit()
    except Exception:
        db.rollback()
        logger.exception("Failed to update position for %s", symbol)
        raise
    finally:
        db.close()
