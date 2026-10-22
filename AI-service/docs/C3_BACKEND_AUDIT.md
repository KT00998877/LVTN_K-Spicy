# C3 Backend Audit Report

**Phase**: C3 — Laravel Business Integration  
**Date**: 2026-10-20  
**Status**: ✅ Complete

---

## 1. Laravel API Inventory

### 1.1 Public APIs (No Auth Required)

| Endpoint | Method | Purpose | AI-Usable |
|----------|--------|---------|-----------|
| `/api/menu` | GET | List menu items with filters | ✅ Yes |
| `/api/menu/{slug}` | GET | Menu item detail | ✅ Yes |
| `/api/home/categories` | GET | Category list | ✅ Yes |
| `/api/home/best-sellers` | GET | Best selling items | ✅ Yes |
| `/api/home/featured` | GET | Featured items | ✅ Yes |
| `/api/reservations/options` | GET | Reservation config | ✅ Yes |
| `/api/reservations` | POST | Create reservation | ⚠️ Confirmation |
| `/api/vouchers` | GET | Voucher list | ✅ Yes |
| `/api/loyalty/tiers` | GET | Loyalty tiers | ✅ Yes |

### 1.2 Authenticated APIs (Sanctum Required)

| Endpoint | Method | Purpose | AI-Usable |
|----------|--------|---------|-----------|
| `/api/cart` | GET | View cart | ✅ Yes |
| `/api/cart` | POST | Add item to cart | ⚠️ Write+Confirm |
| `/api/cart/items/{id}` | PUT | Update cart item | ⚠️ Write+Confirm |
| `/api/cart/items/{id}` | DELETE | Remove cart item | ⚠️ Write+Confirm |
| `/api/cart/checkout` | POST | Checkout order | ⚠️ Write+Confirm |
| `/api/orders/me` | GET | List user orders | ✅ Yes |
| `/api/orders/me/{id}` | GET | Order detail | ✅ Yes |
| `/api/vouchers/preview` | POST | Preview voucher | ✅ Yes |
| `/api/vouchers/loyalty` | GET | User loyalty ledger | ✅ Yes |
| `/api/loyalty/me` | GET | Current user tier | ✅ Yes |

### 1.3 Staff APIs (Staff Role Required)

| Endpoint | Method | Purpose | AI-Usable |
|----------|--------|---------|-----------|
| `/api/staff/tables` | GET | Table list | ❌ Staff only |
| `/api/staff/orders` | GET | All orders | ❌ Staff only |
| `/api/staff/dashboard/*` | Various | Dashboard stats | ❌ Staff only |

---

## 2. Service Layer Audit

### 2.1 Available Services

| Service | Public Methods | Reusable for AI |
|---------|---------------|-----------------|
| `MenuService` | `listItems()`, `getItemBySlug()` | ✅ Yes |
| `CartService` | `getOrCreateCart()`, `addItem()`, `updateItem()`, `removeItem()`, `clearCart()`, `checkout()` | ⚠️ Requires auth |
| `ReservationService` | `create()` | ⚠️ Requires auth + confirmation |
| `PromotionService` | `preview()`, `apply()` | ✅ Yes |
| `OnlineOrderService` | `createInvoice()`, `event()` | ⚠️ Staff only |

### 2.2 Authentication/Authorization

- **Sanctum**: Token-based auth for customers and staff
- **Staff middleware**: Role-based (`staff:WAITER,CASHIER,MANAGER`)
- **Customer auth**: `auth:sanctum` for `/api/cart`, `/api/orders`

### 2.3 Existing Bot Integration

- **Phase 11 routes exist but ChatbotController NOT IMPLEMENTED**
- Routes defined in `routes/api.php` lines 482-492
- No `ChatbotServiceProvider` implementation found
- Archive has `ChatbotServiceProvider.php` and `ChatbotController.php` as reference

---

## 3. AI Service Audit

### 3.1 Current State

| Component | Status | Notes |
|-----------|--------|-------|
| FastAPI server | ✅ Running | Port 8001 |
| Gemini integration | ✅ Working | 2.5-flash model |
| RAG retrieval | ✅ Working | BM25 + Vector hybrid |
| Business tools | ❌ Not implemented | Phase C3 task |
| Laravel integration | ❌ Not implemented | Phase C3 task |

### 3.2 API Endpoints

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `POST /api/v1/chat` | Internal Token | Chat with RAG |
| `GET /api/v1/health` | None | Health check |
| `GET /api/v1/ready` | None | Readiness check |

### 3.3 Security Configuration

- Internal token: `AI_SERVICE_INTERNAL_KEY` in `.env`
- Both Laravel and AI service share this key
- CORS restricted to localhost

---

## 4. Archive Reference

### 4.1 PHASE_12_SPEC.md

Comprehensive design for write tool confirmation flow:
- Table `chat_pending_actions` schema
- Confirmation flow (two-shot UX)
- API contracts for confirm/cancel
- Frontend component specs

### 4.2 Legacy Schemas

Archive has comprehensive schemas for:
- Intent taxonomy (41 intents)
- Slot definitions per intent
- Tool specifications
- Business rules validation

### 4.3 Archive Services (Reference Only)

- `AiServiceClient.php` - HTTP client for AI service
- `ContextStateRepository.php` - State management
- `ChatSessionRepository.php` - Session management

---

## 5. Integration Points

### 5.1 Data Flow

```
User → Laravel (ChatbotController) → AI Service → Gemini
                                    ↓
                              RAG Context
                                    ↓
User ← Laravel (ChatbotController) ← AI Service
```

### 5.2 Business Tool Execution

```
Gemini Tool Call → AI Service → Validate + Confirm → Laravel API → Business Logic
                                                              ↓
User ← AI Service ← Response ← Laravel API Response ← Business Logic
```

### 5.3 Session Management

- Laravel manages sessions via `chat_sessions` + `chat_messages` tables
- AI service is stateless
- History sent with each request

---

## 6. Security Considerations

### 6.1 Authentication Flow

1. Laravel receives user request (may include Sanctum token)
2. AI Service called with internal token only
3. `user_id` passed in request body (Laravel source of truth)
4. All business operations validated by Laravel

### 6.2 Authorization Rules

| Tool | Auth Required | Confirmation |
|------|---------------|--------------|
| menu_lookup | No | No |
| cart_view | Yes | No |
| cart_add_item | Yes | Yes |
| order_list | Yes | No |
| order_detail | Yes | No |
| reservation_create | No | Yes |
| promotion_list | No | No |

### 6.3 Rate Limiting

- Chat: `60,1` (60 requests/minute)
- Reservations: `30,1` (30 requests/minute)
- Auth: `3,5` register, `5,1` login

---

## 7. Findings Summary

### 7.1 ✅ Can Reuse

- All MenuService methods
- PromotionService::preview()
- ReservationService (with confirmation)
- Laravel authentication/authorization
- Rate limiting infrastructure
- PHASE_12_SPEC.md design patterns

### 7.2 ⚠️ Need Implementation

- ChatbotController (Phase 11 incomplete)
- AI Service tool calling
- Laravel HTTP client for AI service
- Business Tool Registry
- Tool execution orchestration

### 7.3 ❌ Not Applicable

- Staff-only APIs (not for customer chatbot)
- AI Marketing Studio (separate system)
- Inventory management (staff only)

---

## 8. Recommended Tool Set for Phase C3

### 8.1 Priority 1 (Read-Only, No Auth)

1. `menu_lookup` - Search/browse menu
2. `menu_item_detail` - Get item details
3. `category_list` - List categories
4. `promotion_list` - Current promotions

### 8.2 Priority 2 (Read-Only, Auth Required)

5. `cart_view` - View user's cart
6. `order_list` - User's order history
7. `order_detail` - Specific order details
8. `voucher_preview` - Check voucher eligibility

### 8.3 Priority 3 (Write Operations, Confirmation Required)

9. `cart_add_item` - Add to cart
10. `reservation_create` - Book table

---

## 9. Appendix: API Response Formats

### 9.1 Menu Item Response

```json
{
  "id": 1,
  "name": "Mì Cay Bò",
  "slug": "mi-cay-bo",
  "price": 65000,
  "price_formatted": "65.000 đ",
  "spicy_level": 3,
  "is_available": true,
  "category_name": "Mì Cay",
  "image_url": "..."
}
```

### 9.2 Cart Response

```json
{
  "id": 1,
  "user_id": 42,
  "items": [
    {
      "id": 5,
      "menu_item": {...},
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

### 9.3 Order Response

```json
{
  "id": 1,
  "code": "ORD-20261020-0001",
  "status": "PENDING",
  "order_type": "DINE_IN",
  "total_amount": 130000,
  "item_count": 2,
  "placed_at": "2026-10-20T18:00:00+07:00"
}
```
