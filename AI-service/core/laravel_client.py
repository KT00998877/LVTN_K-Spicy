"""
Business Tool Registry - Laravel API integration for AI chatbot.

This module defines the business tools available to the AI chatbot
and provides a client for calling Laravel APIs.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any, Optional
from urllib.parse import urlencode

import httpx
from loguru import logger

from core.config import settings


# ============================================================================
# Tool Specifications
# ============================================================================

@dataclass
class ToolSpec:
    """Specification for a business tool."""
    name: str
    description: str
    parameters: dict[str, Any]
    requires_auth: bool = False
    requires_confirmation: bool = False
    allowed_roles: list[str] = field(default_factory=list)
    laravel_endpoint: str = ""
    laravel_method: str = "GET"
    
    @property
    def is_write(self) -> bool:
        """Tools that modify data require confirmation."""
        return self.laravel_method in ("POST", "PUT", "PATCH", "DELETE")


@dataclass
class ToolResult:
    """Result from tool execution."""
    tool: str
    success: bool
    data: Optional[dict[str, Any]] = None
    error: Optional[str] = None
    error_code: Optional[str] = None
    status_code: int = 200
    latency_ms: int = 0


# ============================================================================
# Tool Registry
# ============================================================================

class ToolRegistry:
    """
    Registry of business tools available to the AI chatbot.
    
    Tools are mapped to Laravel API endpoints with proper validation schemas.
    """
    
    def __init__(self):
        self._tools: dict[str, ToolSpec] = {}
        self._init_tools()
    
    def _init_tools(self):
        """Initialize all available tools."""
        
        # Read-only tools (no auth required)
        self.register(ToolSpec(
            name="menu_lookup",
            description="Search menu items. spicy_level is a dish's default level, not the heat a customer can choose when ordering; do not filter by it for spicy preferences.",
            parameters={
                "type": "object",
                "properties": {
                    "category": {
                        "type": "string",
                        "description": "Category slug (e.g., 'mi-cay', 'tokbokki')"
                    },
                    "search": {
                        "type": "string", 
                        "description": "Search query for dish name or description"
                    },
                    "spicy_level": {
                        "type": "integer",
                        "description": "Filter by spicy level (0-5)",
                        "minimum": 0,
                        "maximum": 5
                    },
                    "min_price": {
                        "type": "number",
                        "description": "Minimum price in VND"
                    },
                    "max_price": {
                        "type": "number", 
                        "description": "Maximum price in VND"
                    },
                    "sort": {
                        "type": "string",
                        "description": "Sort order: price_asc, price_desc, name_asc, name_desc, newest",
                        "enum": ["price_asc", "price_desc", "name_asc", "name_desc", "newest"]
                    },
                    "per_page": {
                        "type": "integer",
                        # Laravel's MenuService reads `per_page` (capped at 50).
                        # The old `limit` param was silently dropped, so every
                        # menu_lookup call returned the default 50-item page.
                        "description": "Maximum items to return (max 50)",
                        "minimum": 1,
                        "maximum": 50,
                        "default": 20
                    }
                },
                "required": []
            },
            requires_auth=False,
            laravel_endpoint="/api/menu",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="menu_item_detail",
            description="Get detailed information about a specific menu item by its slug.",
            parameters={
                "type": "object",
                "properties": {
                    "slug": {
                        "type": "string",
                        "description": "Menu item slug (e.g., 'mi-cay-bo')"
                    }
                },
                "required": ["slug"]
            },
            requires_auth=False,
            laravel_endpoint="/api/menu/{slug}",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="category_list",
            description="Get all available menu categories.",
            parameters={
                "type": "object",
                "properties": {},
                "required": []
            },
            requires_auth=False,
            laravel_endpoint="/api/home/categories",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="promotion_list",
            description="Get current promotions and voucher deals available.",
            parameters={
                "type": "object",
                "properties": {},
                "required": []
            },
            requires_auth=False,
            laravel_endpoint="/api/vouchers",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="reservation_options",
            description="Get reservation configuration including available time slots and locations.",
            parameters={
                "type": "object",
                "properties": {},
                "required": []
            },
            requires_auth=False,
            laravel_endpoint="/api/reservations/options",
            laravel_method="GET"
        ))
        # Authenticated read tools
        self.register(ToolSpec(
            name="cart_view",
            description="View the current user's shopping cart.",
            parameters={
                "type": "object",
                "properties": {},
                "required": []
            },
            requires_auth=True,
            laravel_endpoint="/api/cart",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="order_list",
            description="Get the current user's order history.",
            parameters={
                "type": "object",
                "properties": {
                    "status": {
                        "type": "string",
                        "description": "Filter by order status",
                        "enum": ["PENDING", "CONFIRMED", "PREPARING", "READY", "SERVED", "DELIVERING", "DELIVERED", "COMPLETED", "CANCELLED"]
                    },
                    "per_page": {
                        "type": "integer",
                        # OrderController validates `per_page` (max 50); an unknown
                        # `limit` key is ignored by Laravel's validator, so the
                        # order list always came back at the default page size.
                        "description": "Maximum orders to return (max 50)",
                        "minimum": 1,
                        "maximum": 50,
                        "default": 10
                    }
                },
                "required": []
            },
            requires_auth=True,
            laravel_endpoint="/api/orders/me",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="order_detail",
            description="Get detailed information about a specific order.",
            parameters={
                "type": "object",
                "properties": {
                    "order_id": {
                        "type": "integer",
                        "description": "Order ID"
                    }
                },
                "required": ["order_id"]
            },
            requires_auth=True,
            laravel_endpoint="/api/orders/me/{order_id}",
            laravel_method="GET"
        ))
        
        self.register(ToolSpec(
            name="voucher_preview",
            description="Preview voucher discount eligibility for the current user.",
            parameters={
                "type": "object",
                "properties": {
                    "code": {
                        "type": "string",
                        "description": "Voucher code"
                    }
                },
                "required": ["code"]
            },
            requires_auth=True,
            laravel_endpoint="/api/vouchers/preview",
            laravel_method="POST"
        ))
        
        # Write tools (require confirmation)
        self.register(ToolSpec(
            name="cart_add_item",
            description="Add an item to the shopping cart. REQUIRES USER CONFIRMATION before execution.",
            parameters={
                "type": "object",
                "properties": {
                    "menu_item_id": {
                        "type": "integer",
                        "description": "Menu item ID to add"
                    },
                    "quantity": {
                        "type": "integer",
                        "description": "Quantity",
                        "minimum": 1,
                        "maximum": 99
                    },
                    "spicy_level": {
                        "type": "integer",
                        "description": "Spicy level (0-5, 0 = no spicy)",
                        "minimum": 0,
                        "maximum": 5,
                        "default": 0
                    },
                    "topping_ids": {
                        "type": "array",
                        "items": {"type": "integer"},
                        "description": "Array of topping item IDs"
                    },
                    "note": {
                        "type": "string",
                        "description": "Special instructions",
                        "maxLength": 200
                    }
                },
                "required": ["menu_item_id", "quantity"]
            },
            requires_auth=True,
            requires_confirmation=True,
            laravel_endpoint="/api/cart",
            laravel_method="POST"
        ))
        
        # NOTE: reservation_create is intentionally NOT registered.
        # Booking is served by the reservation form the frontend opens from the
        # chat ("quick_action": reservation_form), which submits straight to
        # POST /api/reservations. A model-generated write tool for bookings
        # only added a second, error-prone path (the model had to guess the
        # date/time format and could not see the restaurant schedule).

    def register(self, tool: ToolSpec):
        """Register a new tool."""
        self._tools[tool.name] = tool
    
    def get(self, name: str) -> Optional[ToolSpec]:
        """Get a tool by name."""
        return self._tools.get(name)
    
    def is_allowed(self, name: str) -> bool:
        """Check if a tool is in the allowlist."""
        return name in self._tools
    
    def is_write_tool(self, name: str) -> bool:
        """Check if a tool is a write operation."""
        tool = self.get(name)
        return tool.is_write if tool else False
    
    def requires_confirmation(self, name: str) -> bool:
        """Check if a tool requires user confirmation."""
        tool = self.get(name)
        return tool.requires_confirmation if tool else False
    
    def get_all_tools(self) -> list[dict[str, Any]]:
        """Get all tools as a list of function declarations for Gemini."""
        return [
            {
                "name": tool.name,
                "description": tool.description,
                "parameters": tool.parameters
            }
            for tool in self._tools.values()
        ]


# ============================================================================
# Laravel API Client
# ============================================================================

class LaravelClient:
    """
    HTTP client for calling Laravel API endpoints.
    
    Handles authentication, request formatting, and error handling.
    """
    
    def __init__(
        self,
        base_url: Optional[str] = None,
        timeout: Optional[int] = None
    ):
        self.base_url = (base_url or settings.laravel_api_url or "http://localhost:8000").rstrip("/")
        # Honour LARAVEL_API_TIMEOUT instead of pinning 30s. A hardcoded 30s
        # turned a slow-but-healthy Laravel (a loaded dev box, first-call warm-up)
        # into a TIMEOUT tool error that the bot then reported to the customer.
        self.timeout = timeout or settings.laravel_api_timeout
        self._client: Optional[httpx.AsyncClient] = None
    
    async def _get_client(self) -> httpx.AsyncClient:
        """Get or create async HTTP client."""
        if self._client is None:
            self._client = httpx.AsyncClient(
                base_url=self.base_url,
                timeout=self.timeout,
                headers={
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                }
            )
        return self._client
    
    async def close(self):
        """Close the HTTP client."""
        if self._client:
            await self._client.aclose()
            self._client = None
    
    def _build_url(self, endpoint: str, params: Optional[dict[str, Any]] = None) -> str:
        """Build URL with query parameters."""
        if params and endpoint.find("{") == -1:
            query = urlencode({k: v for k, v in params.items() if v is not None})
            return f"{endpoint}?{query}" if query else endpoint
        return endpoint
    
    async def call(
        self,
        endpoint: str,
        method: str = "GET",
        params: Optional[dict[str, Any]] = None,
        json_data: Optional[dict[str, Any]] = None,
        user_token: Optional[str] = None
    ) -> ToolResult:
        """
        Call a Laravel API endpoint.
        
        Args:
            endpoint: API endpoint path
            method: HTTP method
            params: Query parameters for GET requests
            json_data: JSON body for POST/PUT requests
            user_token: Sanctum token for authenticated requests
            
        Returns:
            ToolResult with success status and data/error
        """
        import time
        start = time.perf_counter()
        
        # Build URL with path parameters substitution
        url = self._build_url(endpoint, params if method == "GET" else None)
        
        # Headers
        headers = {}
        if user_token:
            headers["Authorization"] = f"Bearer {user_token}"
        
        try:
            client = await self._get_client()
            
            response = await client.request(
                method=method,
                url=url,
                params=params if method == "GET" else None,
                json=json_data if method in ("POST", "PUT", "PATCH") else None,
                headers=headers
            )
            
            latency_ms = int((time.perf_counter() - start) * 1000)
            
            # 200 OK and 201 Created are both success for REST endpoints
            # (e.g. POST /api/cart returns 201 with the created item).
            if response.status_code in (200, 201):
                try:
                    data = response.json()
                    return ToolResult(
                        tool=endpoint,
                        success=True,
                        data=data,
                        status_code=200,
                        latency_ms=latency_ms
                    )
                except json.JSONDecodeError:
                    return ToolResult(
                        tool=endpoint,
                        success=True,
                        data={"raw": response.text},
                        status_code=200,
                        latency_ms=latency_ms
                    )
            
            elif response.status_code == 401:
                return ToolResult(
                    tool=endpoint,
                    success=False,
                    error="Vui lòng đăng nhập để thực hiện thao tác này.",
                    error_code="UNAUTHORIZED",
                    status_code=401,
                    latency_ms=latency_ms
                )
            
            elif response.status_code == 403:
                return ToolResult(
                    tool=endpoint,
                    success=False,
                    error="Bạn không có quyền thực hiện thao tác này.",
                    error_code="FORBIDDEN",
                    status_code=403,
                    latency_ms=latency_ms
                )
            
            elif response.status_code == 404:
                return ToolResult(
                    tool=endpoint,
                    success=False,
                    error="Không tìm thấy thông tin yêu cầu.",
                    error_code="NOT_FOUND",
                    status_code=404,
                    latency_ms=latency_ms
                )
            
            elif response.status_code == 422:
                try:
                    data = response.json()
                    errors = data.get("errors", data.get("message", "Dữ liệu không hợp lệ."))
                    if isinstance(errors, dict):
                        first_error = next(iter(errors.values()), ["Lỗi validation"])[0]
                        error_msg = first_error
                    else:
                        error_msg = str(errors)
                except:
                    error_msg = "Dữ liệu không hợp lệ."
                
                return ToolResult(
                    tool=endpoint,
                    success=False,
                    error=error_msg,
                    error_code="VALIDATION_ERROR",
                    status_code=422,
                    latency_ms=latency_ms
                )
            
            elif response.status_code == 409:
                try:
                    data = response.json()
                    error_msg = data.get("message", "Thông tin đã tồn tại hoặc xung đột.")
                except:
                    error_msg = "Thông tin đã tồn tại hoặc xung đột."
                
                return ToolResult(
                    tool=endpoint,
                    success=False,
                    error=error_msg,
                    error_code="CONFLICT",
                    status_code=409,
                    latency_ms=latency_ms
                )
            
            else:
                try:
                    data = response.json()
                    error_msg = data.get("message", f"Lỗi server ({response.status_code})")
                except:
                    error_msg = f"Lỗi server ({response.status_code})"
                
                return ToolResult(
                    tool=endpoint,
                    success=False,
                    error=error_msg,
                    error_code="INTERNAL_ERROR",
                    status_code=response.status_code,
                    latency_ms=latency_ms
                )
        
        except httpx.TimeoutException:
            latency_ms = int((time.perf_counter() - start) * 1000)
            logger.warning(f"Laravel API timeout: {endpoint}")
            return ToolResult(
                tool=endpoint,
                success=False,
                error="Yêu cầu mất quá lâu. Vui lòng thử lại.",
                error_code="TIMEOUT",
                status_code=408,
                latency_ms=latency_ms
            )
        
        except httpx.ConnectError:
            latency_ms = int((time.perf_counter() - start) * 1000)
            logger.error(f"Laravel API connection error: {endpoint}")
            return ToolResult(
                tool=endpoint,
                success=False,
                error="Không thể kết nối đến máy chủ. Vui lòng thử lại sau.",
                error_code="CONNECTION_ERROR",
                status_code=503,
                latency_ms=latency_ms
            )
        
        except Exception as e:
            latency_ms = int((time.perf_counter() - start) * 1000)
            logger.error(f"Laravel API error: {type(e).__name__}: {str(e)}")
            return ToolResult(
                tool=endpoint,
                success=False,
                error="Đã xảy ra lỗi không mong muốn.",
                error_code="INTERNAL_ERROR",
                status_code=500,
                latency_ms=latency_ms
            )


# ============================================================================
# Tool Executor
# ============================================================================

class ToolExecutor:
    """
    Executes business tools by calling Laravel APIs.
    
    Validates parameters, checks auth requirements, and handles errors.
    """
    
    def __init__(
        self,
        registry: Optional[ToolRegistry] = None,
        client: Optional[LaravelClient] = None
    ):
        self.registry = registry or ToolRegistry()
        self.client = client or LaravelClient()
        # Optional RAG retriever, attached by the service layer when it needs
        # to blend knowledge-base context with live tool data.
        self._retriever: Optional[Any] = None
    
    async def execute(
        self,
        tool_name: str,
        arguments: dict[str, Any],
        user_id: Optional[int] = None,
        user_token: Optional[str] = None
    ) -> ToolResult:
        """
        Execute a tool with given arguments.
        
        Args:
            tool_name: Name of the tool to execute
            arguments: Tool parameters
            user_id: User ID for auth
            user_token: Sanctum token for auth
            
        Returns:
            ToolResult with execution outcome
        """
        import time
        start = time.perf_counter()
        
        # Check tool exists in allowlist
        if not self.registry.is_allowed(tool_name):
            return ToolResult(
                tool=tool_name,
                success=False,
                error=f"Tool '{tool_name}' không được phép sử dụng.",
                error_code="TOOL_NOT_ALLOWED",
                latency_ms=int((time.perf_counter() - start) * 1000)
            )
        
        tool_spec = self.registry.get(tool_name)
        
        # Check authentication
        if tool_spec.requires_auth and not user_token:
            return ToolResult(
                tool=tool_name,
                success=False,
                error="Thao tác này yêu cầu đăng nhập. Vui lòng đăng nhập để tiếp tục.",
                error_code="UNAUTHORIZED",
                latency_ms=int((time.perf_counter() - start) * 1000)
            )
        
        # Check confirmation for write tools
        if tool_spec.requires_confirmation:
            return ToolResult(
                tool=tool_name,
                success=False,
                error="TOOL_REQUIRES_CONFIRMATION",
                error_code="CONFIRMATION_REQUIRED",
                data={
                    "tool": tool_name,
                    "args": arguments,
                    "requires_confirmation": True
                },
                latency_ms=int((time.perf_counter() - start) * 1000)
            )
        
        # Build endpoint URL with path parameters
        endpoint = tool_spec.laravel_endpoint
        url_params = {}
        
        # Extract path parameters from arguments
        for key, value in list(arguments.items()):
            placeholder = f"{{{key}}}"
            if placeholder in endpoint:
                endpoint = endpoint.replace(placeholder, str(value))
                url_params[key] = value
        
        # Remove path params from arguments
        query_params = {k: v for k, v in arguments.items() if f"{{{k}}}" not in tool_spec.laravel_endpoint}
        
        # Execute API call
        result = await self.client.call(
            endpoint=endpoint,
            method=tool_spec.laravel_method,
            params=query_params if tool_spec.laravel_method == "GET" else None,
            json_data=query_params if tool_spec.laravel_method in ("POST", "PUT", "PATCH") else None,
            user_token=user_token
        )
        
        result.latency_ms = int((time.perf_counter() - start) * 1000)
        return result
    
    async def close(self):
        """Close resources."""
        await self.client.close()


# ============================================================================
# Singletons
# ============================================================================

_tool_registry: Optional[ToolRegistry] = None
_tool_executor: Optional[ToolExecutor] = None
_laravel_client: Optional[LaravelClient] = None


def get_tool_registry() -> ToolRegistry:
    """Get or create tool registry singleton."""
    global _tool_registry
    if _tool_registry is None:
        _tool_registry = ToolRegistry()
    return _tool_registry


def get_laravel_client() -> LaravelClient:
    """Get or create Laravel client singleton."""
    global _laravel_client
    if _laravel_client is None:
        _laravel_client = LaravelClient()
    return _laravel_client


def get_tool_executor() -> ToolExecutor:
    """Get or create tool executor singleton."""
    global _tool_executor
    if _tool_executor is None:
        _tool_executor = ToolExecutor(
            registry=get_tool_registry(),
            client=get_laravel_client()
        )
    return _tool_executor
