"""Live market data API endpoints -- Quote, LTP, OHLC, Option Chain, Greeks."""

import logging
import re

from fastapi import APIRouter, Depends, HTTPException, Query

from app.middleware.auth import verify_api_key
from app.models.schemas import (
    BrokerType,
    GreeksResponse,
    LTPResponse,
    OHLCResponse,
    OptionChainResponse,
    QuoteResponse,
)
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.market")

router = APIRouter(
    prefix="/api/v1/market",
    tags=["Market Data"],
    dependencies=[Depends(verify_api_key)],
)

SYMBOL_RE = re.compile(r"^[A-Z0-9_\-]+$")


def _validate_symbol(symbol: str) -> str:
    clean = symbol.strip().upper()
    if not SYMBOL_RE.match(clean):
        raise HTTPException(status_code=400, detail=f"Invalid symbol: {symbol}")
    return clean


def _get_broker(broker: BrokerType):
    try:
        return broker_service.get_broker(broker)
    except (ConnectionError, Exception) as e:
        raise HTTPException(
            status_code=503, detail=f"Broker not available: {e}",
        )


@router.get("/quote", response_model=QuoteResponse)
async def get_quote(
    symbol: str,
    exchange: str = "NSE",
    segment: str = "CASH",
    broker: BrokerType = BrokerType.GROWW,
):
    """
    Get a full real-time quote for a single instrument.

    Includes OHLC, market depth, bid/offer, circuit limits,
    OI, volume, 52-week range, and more.
    """
    clean_symbol = _validate_symbol(symbol)
    clean_exchange = exchange.strip().upper()
    clean_segment = segment.strip().upper()

    try:
        broker_inst = _get_broker(broker)
        return broker_inst.get_quote(clean_symbol, clean_exchange, clean_segment)
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Quote endpoint failed")
        return QuoteResponse(
            success=False, symbol=clean_symbol, exchange=clean_exchange,
            message=f"Quote failed: {e}",
        )


@router.get("/ltp", response_model=LTPResponse)
async def get_ltp(
    symbols: str = Query(
        ...,
        description="Comma-separated exchange_symbol pairs, e.g. 'NSE_RELIANCE,NSE_NIFTY'. Max 50.",
    ),
    segment: str = "CASH",
    broker: BrokerType = BrokerType.GROWW,
):
    """
    Get last traded price for up to 50 instruments in a single call.

    Pass symbols as 'NSE_RELIANCE,NSE_NIFTY,BSE_TCS'.
    """
    symbol_list = [_validate_symbol(s) for s in symbols.split(",") if s.strip()]

    if not symbol_list:
        return LTPResponse(success=False, message="No symbols provided")
    if len(symbol_list) > 50:
        return LTPResponse(success=False, message="Max 50 instruments per call")

    try:
        broker_inst = _get_broker(broker)
        return broker_inst.get_ltp_multi(symbol_list, segment.strip().upper())
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("LTP endpoint failed")
        return LTPResponse(success=False, message=f"LTP failed: {e}")


@router.get("/ohlc", response_model=OHLCResponse)
async def get_ohlc(
    symbols: str = Query(
        ...,
        description="Comma-separated exchange_symbol pairs, e.g. 'NSE_RELIANCE,NSE_NIFTY'. Max 50.",
    ),
    segment: str = "CASH",
    broker: BrokerType = BrokerType.GROWW,
):
    """
    Get real-time OHLC for up to 50 instruments.

    For interval candles (1m, 5m, etc.), use the Historical Data endpoint instead.
    """
    symbol_list = [_validate_symbol(s) for s in symbols.split(",") if s.strip()]

    if not symbol_list:
        return OHLCResponse(success=False, message="No symbols provided")
    if len(symbol_list) > 50:
        return OHLCResponse(success=False, message="Max 50 instruments per call")

    try:
        broker_inst = _get_broker(broker)
        return broker_inst.get_ohlc(symbol_list, segment.strip().upper())
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("OHLC endpoint failed")
        return OHLCResponse(success=False, message=f"OHLC failed: {e}")


@router.get("/option-chain", response_model=OptionChainResponse)
async def get_option_chain(
    underlying: str,
    expiry_date: str = Query(
        ..., description="Expiry date in YYYY-MM-DD format, e.g. '2025-11-28'",
    ),
    exchange: str = "NSE",
    broker: BrokerType = BrokerType.GROWW,
):
    """
    Get the full option chain with Greeks for an underlying symbol and expiry.

    Returns all strikes with CE/PE data including delta, gamma, theta, vega, rho, IV.
    """
    clean_underlying = _validate_symbol(underlying)
    clean_exchange = exchange.strip().upper()

    if not re.match(r"^\d{4}-\d{2}-\d{2}$", expiry_date):
        return OptionChainResponse(
            success=False, underlying=clean_underlying,
            message="expiry_date must be YYYY-MM-DD format",
        )

    try:
        broker_inst = _get_broker(broker)
        return broker_inst.get_option_chain(clean_exchange, clean_underlying, expiry_date)
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Option chain endpoint failed")
        return OptionChainResponse(
            success=False, underlying=clean_underlying,
            message=f"Option chain failed: {e}",
        )


@router.get("/greeks", response_model=GreeksResponse)
async def get_greeks(
    trading_symbol: str,
    underlying: str,
    expiry: str = Query(
        ..., description="Expiry date in YYYY-MM-DD format",
    ),
    exchange: str = "NSE",
    broker: BrokerType = BrokerType.GROWW,
):
    """
    Get Greeks (delta, gamma, theta, vega, rho, IV) for a single options contract.
    """
    clean_ts = _validate_symbol(trading_symbol)
    clean_underlying = _validate_symbol(underlying)
    clean_exchange = exchange.strip().upper()

    if not re.match(r"^\d{4}-\d{2}-\d{2}$", expiry):
        return GreeksResponse(
            success=False, trading_symbol=clean_ts,
            message="expiry must be YYYY-MM-DD format",
        )

    try:
        broker_inst = _get_broker(broker)
        return broker_inst.get_greeks(clean_exchange, clean_underlying, clean_ts, expiry)
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Greeks endpoint failed")
        return GreeksResponse(
            success=False, trading_symbol=clean_ts,
            message=f"Greeks failed: {e}",
        )
