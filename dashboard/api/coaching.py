"""AI coaching endpoint."""

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Request, status

from auth.models import CurrentUser
from auth.token_validator import get_current_user
from config import settings
from db.migrations import ensure_coaching_table
from models.responses import CoachingResponse, CoachingStats
from services.coaching import get_or_generate_coaching

router = APIRouter(prefix="/api", tags=["coaching"])


@router.get(
    "/coaching",
    response_model=CoachingResponse,
    responses={
        401: {"description": "Invalid or expired token"},
        503: {"description": "Coaching service unavailable"},
    },
)
async def get_coaching(
    request: Request,
    current_user: Annotated[CurrentUser, Depends(get_current_user)],
) -> CoachingResponse:
    """Get AI coaching tips for the current user's weekly usage.

    Returns cached results if available (generated once per day),
    or triggers a fresh two-stage analysis pipeline.
    """
    pool = request.app.state.pool
    openwebui_pool = getattr(request.app.state, "openwebui_pool", None)
    litellm_client = getattr(request.app.state, "litellm_client", None)

    try:
        return await get_or_generate_coaching(
            pool, openwebui_pool, litellm_client, current_user, settings,
        )
    except Exception as e:
        if "dashboard_coaching_cache" in str(e) and "does not exist" in str(e):
            # Self-heal: table was dropped (e.g. by LiteLLM migrations)
            print(f"Coaching cache table missing — recreating...")
            try:
                await ensure_coaching_table(pool)
                return await get_or_generate_coaching(
                    pool, openwebui_pool, litellm_client, current_user, settings,
                )
            except Exception as retry_err:
                print(f"Coaching retry failed for {current_user.email}: {retry_err}")

        print(f"Coaching error for {current_user.email}: {e}")
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Coaching service temporarily unavailable",
        )
