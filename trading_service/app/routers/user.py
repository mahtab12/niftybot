"""User profile and account API endpoints."""

import logging

from fastapi import APIRouter, Depends

from app.middleware.auth import verify_api_key
from app.models.schemas import BrokerType, UserProfileResponse
from app.services.broker_service import broker_service

logger = logging.getLogger("niftybot.user")

router = APIRouter(
    prefix="/api/v1/user",
    tags=["User"],
    dependencies=[Depends(verify_api_key)],
)


@router.get("/profile/{broker}", response_model=UserProfileResponse)
async def get_user_profile(broker: BrokerType):
    """
    Get the authenticated user's broker profile.

    Returns exchange permissions (NSE/BSE), active segments
    (CASH, FNO, COMMODITY), DDPI status, and account identifiers.
    """
    logger.info("Get user profile: broker=%s", broker.value)

    return broker_service.get_user_profile(broker)
