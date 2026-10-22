"""
Configuration management for AI Service.
Loads settings from environment variables with Pydantic.
"""
from __future__ import annotations

import json
import os
import secrets
from pathlib import Path
from typing import Optional

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

# Load .env file at module import time
_env_path = Path(__file__).parent.parent / ".env"


class Settings(BaseSettings):
    """Application settings loaded from environment variables."""

    model_config = SettingsConfigDict(
        env_file=_env_path,
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )

    # Google Gemini API
    gemini_api_key: str = Field(
        default="",
        description="Google Gemini API key",
    )
    gemini_chat_model: str = Field(
        default="gemini-3.8-flash",
        description="Gemini model for chat",
    )
    gemini_fallback_model: str = ""

    gemini_fallback_models: str = Field(
        default="",
        description=(
            "Extra models tried in order when the primary model is out of quota "
            "(429), retired (404) or overloaded (5xx). Gemini free-tier quota is "
            "counted per model, so an exhausted model does not imply the key is "
            "unusable. Accepts a comma-separated list or a JSON array."
        ),
    )

    # AI Service Security
    ai_service_internal_key: str = Field(
        default="",
        description="Internal token for Laravel authentication",
    )

    # Laravel API Configuration
    laravel_api_url: str = Field(
        default="http://127.0.0.1:8000",
        description="Laravel backend API URL",
    )
    laravel_api_timeout: int = Field(
        default=30,
        ge=5,
        le=120,
        description="Timeout for Laravel API calls in seconds",
    )

    # FastAPI Server
    ai_service_host: str = Field(
        default="127.0.0.1",
        description="Host to bind the server",
    )
    ai_service_port: int = Field(
        default=8001,
        description="Port to bind the server",
    )

    # Gemini Configuration
    gemini_timeout_seconds: int = Field(
        default=30,
        ge=10,
        le=120,
        description="Timeout for Gemini API calls in seconds",
    )
    gemini_max_output_tokens: int = Field(
        default=1024,
        ge=1,
        le=8192,
        description="Maximum output tokens",
    )

    chat_timeout_seconds: float = Field(default=70, gt=0, le=120)

    # Logging
    log_level: str = Field(
        default="INFO",
        description="Log level",
    )

    @property
    def is_gemini_configured(self) -> bool:
        """Check if Gemini API is properly configured."""
        return bool(self.gemini_api_key and self.gemini_api_key.strip())

    @property
    def is_internal_key_configured(self) -> bool:
        """Check if internal key is properly configured."""
        return bool(self.ai_service_internal_key and self.ai_service_internal_key.strip())

    @property
    def is_ready(self) -> bool:
        """Check if service is ready to handle requests."""
        return self.is_gemini_configured

    def generate_internal_key(self) -> str:
        """Generate a secure random internal key."""
        return secrets.token_urlsafe(32)

    @field_validator("gemini_fallback_models", mode="before")
    @classmethod
    def parse_fallback_models(cls, v):
        """Accept a comma-separated list as well as a JSON array.

        A .env file is far easier to edit as `a,b,c` than as `["a","b","c"]`,
        and pydantic-settings would otherwise try to JSON-decode a bare
        comma-separated value and reject it.
        """
        if isinstance(v, (list, tuple)):
            return ",".join(str(item) for item in v)
        if isinstance(v, str):
            stripped = v.strip()
            if not stripped:
                return ""
            if stripped.startswith("["):
                try:
                    parsed = json.loads(stripped)
                except json.JSONDecodeError:
                    return stripped
                if isinstance(parsed, list):
                    return ",".join(str(item) for item in parsed)
            return stripped
        return v

    @property
    def gemini_fallback_model_list(self) -> list[str]:
        """The extra fallback models as a clean list of model ids."""
        raw = (self.gemini_fallback_models or "").strip()
        if not raw:
            return []
        return [item.strip() for item in raw.split(",") if item.strip()]

    @field_validator("log_level")
    @classmethod
    def validate_log_level(cls, v: str) -> str:
        """Validate log level."""
        valid_levels = {"DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"}
        if v.upper() not in valid_levels:
            return "INFO"
        return v.upper()


def get_settings() -> Settings:
    """
    Get settings instance.
    Creates new instance each call to pick up .env changes.
    """
    return Settings()


# Convenience function
settings = get_settings()
