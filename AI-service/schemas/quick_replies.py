"""
Quick reply suggestion schemas for live chat staff assistance.

The AI proposes short replies a staff member can one-click send to a customer.
They are ADVICE, never auto-sent content — the model must not decide what a
human sends to a customer.
"""
from __future__ import annotations

from datetime import datetime
from typing import Optional

from pydantic import BaseModel, Field


class QuickReplyRequest(BaseModel):
    """Request for suggested replies."""

    session_id: str = Field(
        ...,
        description="Live chat session identifier",
        min_length=1,
        max_length=64,
        examples=["sess_42"],
    )
    message: str = Field(
        ...,
        description="Latest customer message the staff needs to respond to",
        min_length=1,
        max_length=2000,
    )
    messages: Optional[list[dict[str, str]]] = Field(
        default=None,
        description="Recent conversation turns for context",
        max_length=20,
    )


class QuickReplyResponse(BaseModel):
    """Suggested replies for staff."""

    request_id: str
    session_id: str
    suggestions: list[str] = Field(
        default_factory=list,
        description="Short suggested replies, ready to send",
    )
    provider: str = Field(
        default="gemini",
        description="AI provider that produced the suggestions",
    )
    model: str = Field(
        default="gemini-2.5-flash",
        description="Model used",
    )
    latency_ms: int = Field(
        default=0,
        ge=0,
        description="Processing latency in milliseconds",
    )
    timestamp: datetime = Field(
        default_factory=datetime.utcnow,
        description="Response timestamp",
    )
