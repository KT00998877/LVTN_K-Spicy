"""
Dependencies for API security and authentication.
"""
from __future__ import annotations

from typing import Annotated

from fastapi import Depends, Header, HTTPException, status

from core.config import settings


async def verify_internal_token(
    x_internal_token: Annotated[str | None, Header()] = None,
) -> str:
    """
    Verify internal authentication token.
    
    The token is passed via X-Internal-Token header.
    Used for Laravel -> AI Service authentication.
    
    Args:
        x_internal_token: Token from header
        
    Returns:
        The validated token
        
    Raises:
        HTTPException: 401 if token is missing or invalid
    """
    # Check if token is provided
    if not x_internal_token:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={
                "code": "AUTH_ERROR",
                "message": "Missing internal token",
            },
        )

    # Check if service has internal key configured
    if not settings.is_internal_key_configured:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "code": "CONFIG_ERROR",
                "message": "AI Service internal key not configured",
            },
        )

    # Constant-time comparison to prevent timing attacks
    import hmac
    if not hmac.compare_digest(x_internal_token, settings.ai_service_internal_key):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={
                "code": "AUTH_ERROR",
                "message": "Invalid internal token",
            },
        )

    return x_internal_token


# Type alias for dependency injection
InternalToken = Annotated[str, Depends(verify_internal_token)]
