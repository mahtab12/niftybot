"""Classify Groww order placement errors for user-facing alerts."""

from __future__ import annotations

INSUFFICIENT_BALANCE_CODE = "INSUFFICIENT_BALANCE"

_INSUFFICIENT_PATTERNS = (
    "insufficient fund",
    "insufficient balance",
    "insufficient margin",
    "not enough fund",
    "not enough margin",
    "not enough balance",
    "not enough money",
    "margin shortfall",
    "margin shortage",
    "low margin",
    "funds not available",
    "fund limit exceeded",
    "required margin exceeds",
    "exceeds available",
    "exceeds the available",
    "rejected by rms",
    "rms reject",
    "margin required",
    "shortfall in",
)

_USER_MESSAGE = (
    "Your Groww account does not have enough balance or margin for this buy order. "
    "Add funds on Groww and try again."
)


def classify_groww_order_error(raw: str) -> tuple[str, str | None]:
    """
    Return a user-friendly message and optional machine-readable error code.
    """
    text = (raw or "").strip()
    if not text:
        return "Order placement failed.", None

    lower = text.lower()
    if any(pattern in lower for pattern in _INSUFFICIENT_PATTERNS):
        return _USER_MESSAGE, INSUFFICIENT_BALANCE_CODE

    if text.lower().startswith("order placement failed:"):
        return text, None
    return f"Order placement failed: {text}", None
