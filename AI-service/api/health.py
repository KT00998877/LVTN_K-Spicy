"""
Health check endpoints.
"""
from fastapi import APIRouter

from schemas import HealthResponse, ReadyResponse

router = APIRouter(tags=["Health"])


@router.get(
    "/health",
    response_model=HealthResponse,
    summary="Health Check",
    description="Check if the AI Service process is running.",
)
async def health_check() -> HealthResponse:
    """
    Basic health check endpoint.
    
    Returns healthy status if the service is running.
    Does NOT check Gemini API connectivity.
    """
    return HealthResponse(
        status="healthy",
        version="1.0.0",
    )


@router.get(
    "/ready",
    response_model=ReadyResponse,
    summary="Readiness Check",
    description="Check if the service is ready to handle requests.",
)
async def readiness_check() -> ReadyResponse:
    """
    Readiness check endpoint.
    
    Checks internal dependencies and configuration:
    - Gemini API key configured
    
    Returns not_ready if configuration is missing.
    Does NOT test actual Gemini API connectivity.
    """
    from core.config import settings

    checks = {
        "gemini_configured": settings.is_gemini_configured,
        "internal_auth_configured": bool(settings.is_internal_key_configured),
    }

    all_ready = all(checks.values())

    return ReadyResponse(
        status="ready" if all_ready else "not_ready",
        checks=checks,
        message=None if all_ready else "AI Service configuration incomplete",
        version="1.0.0",
    )
