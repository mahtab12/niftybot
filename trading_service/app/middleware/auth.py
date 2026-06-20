"""API key authentication middleware for Drupal <-> Python communication."""

import hmac
import logging

from fastapi import HTTPException, Request, Security
from fastapi.security import APIKeyHeader

from app.config import settings

logger = logging.getLogger("niftybot.auth")

api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)


async def verify_api_key(
    request: Request,
    api_key: str = Security(api_key_header),
):
    """Validate the API key from the request header."""
    if not settings.api_key:
        logger.warning("No API key configured; rejecting request")
        raise HTTPException(status_code=500, detail="API key not configured")

    if not api_key:
        raise HTTPException(status_code=401, detail="Missing API key")

    if not hmac.compare_digest(api_key, settings.api_key):
        logger.warning(
            "Invalid API key attempt from %s", request.client.host if request.client else "unknown"
        )
        raise HTTPException(status_code=403, detail="Invalid API key")

    return api_key
