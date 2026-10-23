"""
AI Service - FastAPI application entry point.

Korean Spicy Noodle Restaurant AI Chatbot Service.
Uses Google Gemini API for natural language understanding and generation.
"""
from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from loguru import logger

from api import chat_router, health_router, quick_replies_router
from core.config import settings


# Configure logging
def setup_logging() -> None:
    """Configure loguru logging."""
    logger.remove()
    logger.add(
        "ai-service.log",
        rotation="10 MB",
        retention="7 days",
        level=settings.log_level,
        format="<green>{time:YYYY-MM-DD HH:mm:ss}</green> | <level>{level: <8}</level> | <cyan>{name}</cyan>:<cyan>{function}</cyan>:<cyan>{line}</cyan> | <level>{message}</level>",
    )
    logger.add(
        # Suppress logs for cleaner output
        lambda msg: print(msg, end=""),
        level=settings.log_level,
        format="<level>{level: <8}</level> | <level>{message}</level>",
    )


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan handler."""
    # Startup
    logger.info("=" * 60)
    logger.info("AI Service starting...")
    logger.info(f"Version: 1.0.0")
    logger.info(f"Model: {settings.gemini_chat_model}")
    logger.info(f"Host: {settings.ai_service_host}:{settings.ai_service_port}")
    logger.info(f"Gemini configured: {settings.is_gemini_configured}")
    logger.info(f"Internal key configured: {settings.is_internal_key_configured}")
    logger.info("=" * 60)

    yield

    # Shutdown
    logger.info("AI Service shutting down...")


# Create FastAPI application
app = FastAPI(
    title="K-Spicy AI Chatbot Service",
    description="""
## Korean Spicy Noodle Restaurant AI Chatbot Service

A FastAPI-based service using Google Gemini API for natural language 
understanding and generation.

### Features
- Chat endpoint for conversational AI
- Structured output for development testing
- Internal token authentication
- Health and readiness checks

### Authentication
Protected endpoints require `X-Internal-Token` header.
    """,
    version="1.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
    lifespan=lifespan,
)

# CORS - restrictive settings
# Only allow from same origin in production
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:5173", "http://localhost:8000"],
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["*"],
)


# Global exception handler
@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    """Handle uncaught exceptions."""
    logger.error(f"Unhandled exception: {type(exc).__name__}: {str(exc)}")
    return JSONResponse(
        status_code=500,
        content={
            "error": {
                "code": "INTERNAL_ERROR",
                "message": "Internal server error",
            },
        },
    )


# Include routers
app.include_router(health_router, prefix="/api/v1")
app.include_router(quick_replies_router, prefix="/api/v1")


# Root endpoint
@app.get("/", tags=["Root"])
async def root():
    """Root endpoint - basic info."""
    return {
        "service": "K-Spicy AI Chatbot Service",
        "version": "1.0.0",
        "docs": "/docs",
        "health": "/api/v1/health",
    }


if __name__ == "__main__":
    import uvicorn

    setup_logging()

    uvicorn.run(
        "app:app",
        host=settings.ai_service_host,
        port=settings.ai_service_port,
        reload=False,
        log_level=settings.log_level.lower(),
    )
