"""Market watchlist instrument definitions."""

from typing import Any

WATCHLIST: list[dict[str, Any]] = [
    {
        "id": "nifty",
        "label": "Nifty 50",
        "symbol": "NIFTY",
        "exchange": "NSE",
        "segment": "CASH",
        "has_option_chain": True,
        "option_exchange": "NSE",
    },
    {
        "id": "sensex",
        "label": "Sensex",
        "symbol": "SENSEX",
        "exchange": "BSE",
        "segment": "CASH",
        "has_option_chain": True,
        "option_exchange": "BSE",
    },
    {
        "id": "crude",
        "label": "Crude Oil Mini",
        "symbol": "CRUDEOILM",
        "exchange": "MCX",
        "segment": "COMMODITY",
        "has_option_chain": False,
        "commodity_underlying": "CRUDEOILM",
    },
    {
        "id": "gold",
        "label": "Gold Mini",
        "symbol": "GOLDM",
        "exchange": "MCX",
        "segment": "COMMODITY",
        "has_option_chain": False,
        "commodity_underlying": "GOLDM",
    },
]
