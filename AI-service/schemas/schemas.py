"""
Pydantic schemas for API request/response contracts.
"""
from __future__ import annotations

from datetime import datetime
from typing import Any, Optional

from pydantic import BaseModel, Field


# ============================================================================
# Chat Schemas
# ============================================================================

class ChatMessage(BaseModel):
    """Single chat message in conversation history."""
    role: str = Field(
        ...,
        description="Message role: 'user' or 'assistant'",
        pattern="^(user|assistant)$",
    )
    content: str = Field(
        ...,
        description="Message content",
        min_length=1,
        max_length=2000,
    )


class ToolCall(BaseModel):
    """A business tool invocation requested by the model."""
    tool: str = Field(..., description="Tool name")
    args: dict[str, Any] = Field(default_factory=dict, description="Tool arguments")
    result: Optional[Any] = Field(default=None, description="Tool execution result")
    error: Optional[str] = Field(default=None, description="Tool execution error")
    error_code: Optional[str] = Field(default=None, description="Machine-readable error code")


class ChatRequest(BaseModel):
    """Request schema for chat endpoint."""
    session_id: str = Field(
        ...,
        description="Unique session identifier",
        min_length=1,
        max_length=64,
        examples=["sess_abc123"],
    )
    message: str = Field(
        ...,
        description="User message",
        min_length=1,
        max_length=2000,
        examples=["Cho tôi 2 mì cay bò cấp 3"],
    )
    request_id: Optional[str] = Field(
        default=None,
        description="Optional request ID for tracing",
        max_length=64,
    )
    messages: Optional[list[ChatMessage]] = Field(
        default=None,
        # Laravel sends the last 10 session messages plus the current turn, so a
        # long-lived session posts 11. Capping at 10 rejected every turn past the
        # 10th with a 422 before the model was ever reached.
        description="Conversation history for multi-turn context (last 20 messages)",
        max_length=20,
    )
    use_tools: bool = Field(
        default=False,
        description="Enable business tool calling (menu lookup, cart, orders, reservations)",
    )
    user_id: Optional[int] = Field(
        default=None,
        description="Authenticated user ID (for tool authorization)",
    )
    user_token: Optional[str] = Field(
        default=None,
        description="Sanctum access token of the authenticated user, forwarded so "
                    "AI Service can call authenticated Laravel endpoints",
        max_length=4096,
    )

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "session_id": "sess_abc123",
                    "message": "Chào bạn, quán có món gì ngon?",
                }
            ]
        }
    }


class Citation(BaseModel):
    """Citation for a retrieved chunk."""
    id: int = Field(..., description="Citation ID")
    text: str = Field(..., description="Text excerpt")
    source: str = Field(..., description="Source type (faq, menu_item, etc.)")
    score: float = Field(..., description="Relevance score")


class RetrievalInfo(BaseModel):
    """Retrieval metadata."""
    chunks_used: int = Field(..., description="Number of chunks used")
    sources: list[str] = Field(default_factory=list, description="Source types")


class QuickAction(BaseModel):
    """A UI action the frontend should offer for this reply.

    The AI decides WHICH action fits the turn; the frontend decides HOW to
    render it. The backend forwards the action type verbatim and never trusts
    a model-provided payload.
    """
    type: str = Field(
        ...,
        description="Action type, e.g. 'reservation_form'",
    )
    label: str = Field(
        ...,
        description="Button label shown to the customer",
    )
    title: str = Field(
        default="",
        description="Optional heading for the opened surface",
    )


class ChatResponse(BaseModel):
    """Response schema for chat endpoint."""
    request_id: str = Field(
        ...,
        description="Request identifier",
    )
    session_id: str = Field(
        ...,
        description="Session identifier",
    )
    intent: str = Field(
        ...,
        description="Detected intent",
        examples=["greet_user", "get_restaurant_info"],
    )
    reply: str = Field(
        ...,
        description="Generated reply text",
    )
    provider: str = Field(
        default="gemini",
        description="AI provider name",
    )
    model: str = Field(
        ...,
        description="Model used",
        examples=["gemini-2.5-flash"],
    )
    latency_ms: int = Field(
        ...,
        description="Processing latency in milliseconds",
        ge=0,
    )
    retrieval: Optional[RetrievalInfo] = Field(
        default=None,
        description="RAG retrieval metadata",
    )
    citations: Optional[list[Citation]] = Field(
        default=None,
        description="Retrieved chunks with citations",
    )
    tool_calls: Optional[list[ToolCall]] = Field(
        default=None,
        description="Business tool calls executed during this turn",
    )
    confirmation_required: bool = Field(
        default=False,
        description="True when a write tool needs customer confirmation before execution",
    )
    quick_action: Optional[QuickAction] = Field(
        default=None,
        description="UI action the frontend should offer (e.g. open the reservation form)",
    )
    timestamp: datetime = Field(
        default_factory=datetime.utcnow,
        description="Response timestamp",
    )

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "request_id": "req_xyz789",
                    "session_id": "sess_abc123",
                    "intent": "greet_user",
                    "reply": "Xin chào bạn! Mình là trợ lý của quán Mì Cay Hàn Quốc. Mình giúp gì cho bạn hôm nay?",
                    "provider": "gemini",
                    "model": "gemini-2.5-flash",
                    "latency_ms": 450,
                    "timestamp": "2026-10-20T17:00:00Z",
                }
            ]
        }
    }


# ============================================================================
# Structured Chat Schemas (Development Only)
# ============================================================================

class StructuredChatRequest(BaseModel):
    """Request schema for structured chat endpoint (development only)."""
    session_id: str = Field(
        ...,
        description="Unique session identifier",
        min_length=1,
        max_length=64,
    )
    message: str = Field(
        ...,
        description="User message",
        min_length=1,
        max_length=2000,
    )
    request_id: Optional[str] = Field(
        default=None,
        description="Optional request ID for tracing",
        max_length=64,
    )


class StructuredChatResponse(BaseModel):
    """Response schema for structured chat endpoint."""
    request_id: str
    session_id: str
    intent: str
    reply: str
    confidence: float = Field(
        ...,
        description="Confidence score",
        ge=0.0,
        le=1.0,
    )
    model: str
    latency_ms: int
    timestamp: datetime

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "request_id": "req_xyz789",
                    "session_id": "sess_abc123",
                    "intent": "get_menu_item",
                    "reply": "Mì cay bò giá 89.000đ phần lớn.",
                    "confidence": 0.95,
                    "model": "gemini-2.5-flash",
                    "latency_ms": 380,
                    "timestamp": "2026-10-20T17:00:00Z",
                }
            ]
        }
    }


# ============================================================================
# Health Check Schemas
# ============================================================================

class HealthResponse(BaseModel):
    """Response schema for health endpoint."""
    status: str = Field(
        ...,
        description="Service status",
        examples=["healthy", "unhealthy"],
    )
    version: str = Field(
        ...,
        description="Service version",
        examples=["1.0.0"],
    )
    timestamp: datetime = Field(
        default_factory=datetime.utcnow,
        description="Check timestamp",
    )


class ReadyResponse(BaseModel):
    """Response schema for readiness endpoint."""
    status: str = Field(
        ...,
        description="Ready status",
        examples=["ready", "not_ready"],
    )
    checks: dict[str, bool] = Field(
        ...,
        description="Individual check results",
    )
    message: Optional[str] = Field(
        default=None,
        description="Status message if not ready",
    )
    version: str = Field(
        ...,
        description="Service version",
    )
    timestamp: datetime = Field(
        default_factory=datetime.utcnow,
        description="Check timestamp",
    )


# ============================================================================
# Error Schemas
# ============================================================================

class ErrorDetail(BaseModel):
    """Error detail schema."""
    code: str = Field(
        ...,
        description="Error code",
        examples=["VALIDATION_ERROR", "AUTH_ERROR", "GEMINI_ERROR"],
    )
    message: str = Field(
        ...,
        description="Human-readable error message",
    )
    details: Optional[dict[str, Any]] = Field(
        default=None,
        description="Additional error details",
    )


class ErrorResponse(BaseModel):
    """Error response schema."""
    error: ErrorDetail
    request_id: Optional[str] = Field(
        default=None,
        description="Request ID for debugging",
    )
    timestamp: datetime = Field(
        default_factory=datetime.utcnow,
        description="Error timestamp",
    )

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "error": {
                        "code": "AUTH_ERROR",
                        "message": "Invalid or missing internal token",
                    },
                    "request_id": "req_xyz789",
                    "timestamp": "2026-10-20T17:00:00Z",
                }
            ]
        }
    }


# ============================================================================
# Structured Output Schemas (for validation)
# ============================================================================

class IntentClassification(BaseModel):
    """Schema for intent classification structured output."""
    intent: str = Field(
        ...,
        description="Detected intent name",
        examples=["greet_user", "get_menu_item", "add_to_cart"],
    )
    confidence: float = Field(
        ...,
        description="Confidence score 0-1",
        ge=0.0,
        le=1.0,
    )
    entities: dict[str, Any] = Field(
        default_factory=dict,
        description="Extracted entities",
    )


class RestaurantInfoResponse(BaseModel):
    """Schema for restaurant info structured output."""
    reply: str = Field(..., description="Generated reply")
    intent: str = Field(..., description="Detected intent")
    confidence: float = Field(..., ge=0.0, le=1.0)
    needs_verification: bool = Field(
        default=False,
        description="Whether data needs verification from backend",
    )
