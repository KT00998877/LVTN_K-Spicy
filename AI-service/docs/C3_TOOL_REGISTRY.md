# C3 Tool Registry

**Phase**: C3 — Laravel Business Integration  
**Date**: 2026-10-20  
**Status**: Draft

---

## 1. Overview

Tool Registry định nghĩa các business tools mà AI chatbot có thể sử dụng để tương tác với Laravel backend.

### 1.1 Design Principles

- **Safety First**: Write operations require explicit user confirmation
- **Minimal Surface**: Chỉ expose tools thực sự cần thiết
- **Fail Loud**: Clear error messages, never silent failures
- **Stateless**: AI service is stateless, Laravel manages session

---

## 2. Tool Registry

### 2.1 Read-Only Tools (No Confirmation)

#### Tool: `menu_lookup`

**Purpose**: Search and browse menu items

**Parameters**:
```json
{
  "category": "string (optional) - category slug",
  "search": "string (optional) - search query",
  "spicy_level": "integer (optional) - filter by spicy level 0-5",
  "min_price": "number (optional) - minimum price",
  "max_price": "number (optional) - maximum price",
  "sort": "string (optional) - sort order: price_asc|price_desc|name_asc|name_desc|newest",
  "limit": "integer (optional, default=20) - max items to return"
}
```

**API Mapping**: `GET /api/menu`

**Authentication**: None required

**Response**:
```json
{
  "items": [...],
  "count": 10,
  "pagination": {...}
}
```

**Error Handling**:
- 200: Success
- 500: Server error → "Không thể lấy danh sách món. Vui lòng thử lại."

---

#### Tool: `menu_item_detail`

**Purpose**: Get detailed information about a specific menu item

**Parameters**:
```json
{
  "slug": "string (required) - menu item slug"
}
```

**API Mapping**: `GET /api/menu/{slug}`

**Authentication**: None required

**Response**:
```json
{
  "id": 1,
  "name": "Mì Cay Bò",
  "description": "...",
  "price": 65000,
  "spicy_level": 3,
  "is_available": true,
  "image_url": "..."
}
```

**Error Handling**:
- 200: Success
- 404: Item not found → "Không tìm thấy món này."

---

#### Tool: `category_list`

**Purpose**: Get all available menu categories

**Parameters**: None

**API Mapping**: `GET /api/home/categories`

**Authentication**: None required

**Response**:
```json
{
  "categories": [
    {"id": 1, "name": "Mì Cay", "slug": "mi-cay"},
    {"id": 2, "name": "Tokbokki", "slug": "tokbokki"}
  ]
}
```

---

#### Tool: `promotion_list`

**Purpose**: Get current promotions and deals

**Parameters**: None

**API Mapping**: `GET /api/vouchers` (public vouchers)

**Authentication**: None required

**Response**:
```json
{
  "vouchers": [
    {
      "code": "MI10",
      "description": "Giảm 10% cho đơn từ 100k",
      "discount_type": "percentage",
      "discount_value": 10,
      "min_order_amount": 100000
    }
  ]
}
```

---

#### Tool: `reservation_options`

**Purpose**: Get reservation configuration and available times

**Parameters**: None

**API Mapping**: `GET /api/reservations/options`

**Authentication**: None required

**Response**:
```json
{
  "data": {
    "time_slots": ["11:00", "11:30", "12:00", ...],
    "locations": ["Tầng 1", "Tầng 2", "Khu vực VIP"],
    "max_guests": 20,
    "advance_days": 30
  }
}
```

---

### 2.2 Authenticated Read-Only Tools

#### Tool: `cart_view`

**Purpose**: View current user's shopping cart

**Parameters**: None (user_id from session)

**API Mapping**: `GET /api/cart`

**Authentication**: Required (Sanctum)

**Response**:
```json
{
  "id": 1,
  "items": [
    {
      "id": 5,
      "menu_item": {"name": "Mì Cay Bò", "price": 65000},
      "quantity": 2,
      "spicy_level": 2,
      "toppings": [],
      "subtotal": 130000
    }
  ],
  "total_items": 1,
  "total_quantity": 2,
  "subtotal": 130000
}
```

**Error Handling**:
- 401: Not authenticated → "Vui lòng đăng nhập để xem giỏ hàng."
- 500: Server error → "Không thể lấy giỏ hàng."

---

#### Tool: `order_list`

**Purpose**: Get user's order history

**Parameters**:
```json
{
  "status": "string (optional) - filter by status",
  "limit": "integer (optional, default=10) - max orders"
}
```

**API Mapping**: `GET /api/orders/me`

**Authentication**: Required (Sanctum)

**Response**:
```json
{
  "data": [
    {
      "id": 1,
      "code": "ORD-20261020-0001",
      "status": "COMPLETED",
      "total_amount": 130000,
      "item_count": 2,
      "placed_at": "2026-10-20T18:00:00+07:00"
    }
  ],
  "meta": {...}
}
```

---

#### Tool: `order_detail`

**Purpose**: Get detailed information about a specific order

**Parameters**:
```json
{
  "order_id": "integer (required) - order ID"
}
```

**API Mapping**: `GET /api/orders/me/{id}`

**Authentication**: Required (Sanctum) + ownership validation

**Response**:
```json
{
  "id": 1,
  "code": "ORD-20261020-0001",
  "status": "COMPLETED",
  "items": [
    {
      "name": "Mì Cay Bò",
      "quantity": 2,
      "unit_price": 65000,
      "subtotal": 130000
    }
  ],
  "subtotal": 130000,
  "discount": 0,
  "total_amount": 130000
}
```

**Error Handling**:
- 403: Not owner → "Bạn không có quyền xem đơn hàng này."
- 404: Not found → "Không tìm thấy đơn hàng."

---

### 2.3 Write Tools (Require Confirmation)

#### Tool: `cart_add_item`

**Purpose**: Add item to shopping cart

**Parameters**:
```json
{
  "menu_item_id": "integer (required) - menu item ID",
  "quantity": "integer (required, min=1, max=99) - quantity",
  "spicy_level": "integer (optional, default=0) - spicy level 0-5",
  "topping_ids": "array (optional) - array of topping item IDs",
  "note": "string (optional, max=200) - special instructions"
}
```

**API Mapping**: `POST /api/cart` → `CartService::addItem()`

**Authentication**: Required (Sanctum)

**Confirmation Required**: YES

**Summary Fields**:
```json
{
  "dish_name": "Mì Cay Bò",
  "quantity": 2,
  "spicy_level": 2,
  "toppings": ["Phô mai", "Xúc xích"],
  "unit_price": 65000,
  "subtotal": 130000
}
```

**Confirmation Prompt**: 
> "Bạn muốn thêm {quantity} phần {dish_name} {spicy_level_text} vào giỏ, tổng {subtotal_formatted}. Xác nhận nhé?"

**Error Handling**:
- 401: Not authenticated → "Vui lòng đăng nhập để thêm vào giỏ."
- 422: Validation error → Return Laravel validation message
- 500: Server error → "Không thể thêm vào giỏ. Vui lòng thử lại."

**Safety Rules**:
- Must validate menu_item_id exists and is available
- Must check topping_ids are valid toppings
- Must enforce quantity limits (1-99)
- Must check inventory availability

---

#### Tool: `reservation_create`

**Purpose**: Create a table reservation

**Parameters**:
```json
{
  "customer_name": "string (required) - customer's name",
  "customer_phone": "string (required) - customer's phone",
  "guest_count": "integer (required, min=1, max=20) - number of guests",
  "reserved_date": "string (required) - date YYYY-MM-DD",
  "reserved_time": "string (required) - time HH:MM",
  "location": "string (optional) - preferred area",
  "note": "string (optional, max=500) - special requests"
}
```

**API Mapping**: `POST /api/reservations` → `ReservationService::create()`

**Authentication**: Not required (guest can reserve)

**Confirmation Required**: YES

**Summary Fields**:
```json
{
  "customer_name": "Nguyễn Văn A",
  "customer_phone": "0901***567",
  "guest_count": 4,
  "reserved_date": "2026-10-21",
  "reserved_time": "19:00",
  "location": "Tầng 1"
}
```

**Confirmation Prompt**:
> "Bạn muốn đặt bàn {guest_count} người vào lúc {reserved_time} ngày {reserved_date} tại {location}. Thông tin: {customer_name} - {customer_phone}. Xác nhận nhé?"

**Error Handling**:
- 422: Validation error → Return Laravel validation message
- 409: Table not available → "Bàn đã được đặt vào thời gian này. Vui lòng chọn thời gian khác."
- 500: Server error → "Không thể tạo đặt bàn. Vui lòng thử lại."

**Safety Rules**:
- Must validate phone format
- Must check date is not in the past
- Must validate guest count against table capacities
- Must check table availability for the time slot

---

## 3. Tool Selection Logic

### 3.1 Intent → Tool Mapping

| Intent | Primary Tool | Fallback |
|--------|-------------|----------|
| `get_menu` | `menu_lookup` | `menu_item_detail` if specific item |
| `get_menu_item` | `menu_item_detail` | `menu_lookup` search |
| `get_cart` | `cart_view` | error if not auth |
| `add_to_cart` | `cart_add_item` | error if not auth |
| `view_order` | `order_detail` | `order_list` |
| `make_reservation` | `reservation_create` | `reservation_options` first |
| `get_promotions` | `promotion_list` | - |

### 3.2 Parameter Extraction

AI phải extract parameters từ natural language:

**Example**:
```
User: "Thêm 2 phần mì cay bò cấp 3, không topping"
→ Tool: cart_add_item
→ Args: {
    menu_item_id: <looked up from menu>,
    quantity: 2,
    spicy_level: 3,
    topping_ids: []
  }
```

---

## 4. Validation Rules

### 4.1 Input Validation

```python
# All parameters validated against schema
# Invalid params → 422 response with Vietnamese message
# Missing required params → ask user to provide
```

### 4.2 Business Validation

| Tool | Validation |
|------|------------|
| `cart_add_item` | Menu item exists, is_available, toppings valid |
| `reservation_create` | Valid phone, future date, valid time slot, table available |

### 4.3 Authorization Validation

| Tool | Check |
|------|-------|
| `cart_*` | User authenticated, owns the cart |
| `order_*` | User authenticated, owns the order |
| `reservation_create` | No auth required, but valid data |

---

## 5. Error Response Format

### 5.1 Standard Error

```json
{
  "error": {
    "code": "ITEM_UNAVAILABLE",
    "message": "Món 'Mì Cay Bò' hiện không có sẵn. Bạn thử món khác nhé?",
    "details": {
      "item_id": 5,
      "reason": "sold_out"
    }
  }
}
```

### 5.2 Error Codes

| Code | HTTP Status | Meaning |
|------|-------------|---------|
| `UNAUTHORIZED` | 401 | Not logged in |
| `FORBIDDEN` | 403 | No permission |
| `NOT_FOUND` | 404 | Resource not found |
| `VALIDATION_ERROR` | 422 | Invalid parameters |
| `ITEM_UNAVAILABLE` | 422 | Menu item not available |
| `TABLE_UNAVAILABLE` | 409 | Table already booked |
| `INTERNAL_ERROR` | 500 | Server error |

---

## 6. Tool Allowlist

```python
ALLOWED_TOOLS = [
    # Read-only
    "menu_lookup",
    "menu_item_detail", 
    "category_list",
    "promotion_list",
    "reservation_options",
    
    # Authenticated read
    "cart_view",
    "order_list",
    "order_detail",
    
    # Write operations (require confirmation)
    "cart_add_item",
    "reservation_create",
]

# Blocked tools (not implemented / staff-only)
BLOCKED_TOOLS = [
    "cart_update_item",
    "cart_remove_item",
    "cart_clear",
    "cart_checkout",
    "order_cancel",
    # ... staff tools
]
```

---

## 7. Confirmation Flow

### 7.1 Two-Shot UX Pattern

```
Turn 1:
  User → "Thêm mì cay bò vào giỏ"
  AI → Tool: cart_add_item (params extracted)
  AI → Response: "Bạn muốn thêm 1 phần Mì Cay Bò cấp 2 vào giỏ, giá 65.000đ. Xác nhận nhé?"
  AI → [confirmation_required: true]

Turn 2a (confirm):
  User → "Xác nhận"
  Frontend → POST /confirm/{pending_id}
  Laravel → Execute cart_add_item
  AI → "Đã thêm Mì Cay Bò vào giỏ! Bạn còn muốn gì nữa không?"

Turn 2b (cancel):
  User → "Hủy"
  Frontend → POST /cancel/{pending_id}
  AI → "Đã hủy. Bạn cần gì khác không?"
```

### 7.2 Pending Action Storage

```sql
CREATE TABLE chat_pending_actions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    idempotency_key CHAR(36) UNIQUE,
    session_id BIGINT,
    user_id BIGINT,
    action_type VARCHAR(64),  -- 'cart_add_item', 'reservation_create'
    tool_name VARCHAR(64),
    payload JSON,            -- tool arguments
    summary JSON,            -- for UI display
    status ENUM('pending','confirmed','cancelled','expired','failed'),
    result JSON,
    error_message VARCHAR(500),
    expires_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 8. Rate Limits

| Tool | Limit | Window |
|------|-------|--------|
| `menu_lookup` | 60 | /minute |
| `cart_view` | 60 | /minute |
| `cart_add_item` | 30 | /minute |
| `reservation_create` | 10 | /minute |

---

## 9. Appendix: Tool Schemas (JSON Schema)

```json
{
  "cart_add_item": {
    "type": "object",
    "properties": {
      "menu_item_id": {"type": "integer", "minimum": 1},
      "quantity": {"type": "integer", "minimum": 1, "maximum": 99},
      "spicy_level": {"type": "integer", "minimum": 0, "maximum": 5},
      "topping_ids": {"type": "array", "items": {"type": "integer"}},
      "note": {"type": "string", "maxLength": 200}
    },
    "required": ["menu_item_id", "quantity"]
  },
  "reservation_create": {
    "type": "object",
    "properties": {
      "customer_name": {"type": "string", "minLength": 1, "maxLength": 100},
      "customer_phone": {"type": "string", "pattern": "^[0-9]{10,11}$"},
      "guest_count": {"type": "integer", "minimum": 1, "maximum": 20},
      "reserved_date": {"type": "string", "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"},
      "reserved_time": {"type": "string", "pattern": "^[0-9]{2}:[0-9]{2}$"},
      "location": {"type": "string"},
      "note": {"type": "string", "maxLength": 500}
    },
    "required": ["customer_name", "customer_phone", "guest_count", "reserved_date", "reserved_time"]
  }
}
```
