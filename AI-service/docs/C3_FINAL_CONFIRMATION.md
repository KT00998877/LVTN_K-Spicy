# C3_FINAL_CONFIRMATION.md — Write Tool Confirmation Flow

**Generated:** 2026-10-20  
**Scope:** Confirmation Flow spec → implementation reference.

---

## Architectural decisions

All §3 decisions from `archive/docs/PHASE_12_SPEC.md` are honoured:

| Decision | Implementation |
|----------|----------------|
| Store in MySQL (`chat_pending_actions`) | Migration `2026_10_20_180002`. |
| Auth required for write | `auth:sanctum` on all 4 confirm/cancel routes + `user_id` ownership check inside each method (403 on mismatch). |
| Two-shot | First turn creates pending + asks confirm. Second turn user clicks Confirm → DB write. |
| LLM does NOT call write API directly | AI-Service never reaches Laravel with a write request; it returns a payload in `tool_calls[].args` plus `confirmation_required: true`. |
| Idempotency | `idempotency_key` UUID v4 unique per row + reused as `request_key` for `ReservationService::create` (which checks uniqueness). |
| TTL 5 min | `expires_at = created_at + 5min`. Inline check on confirm → 410; cron `chatbot:expire-pending` flips stale rows to `expired`. |
| Idempotent cancel | Cancel on already-cancelled returns 200 with current status (does not error). |
| Service-layer dispatch (no HTTP roundtrip) | `PendingActionExecutor::execute()` calls `CartService::addItem` / `ReservationService::create` directly inside a DB transaction. |
| Multi-action in 1 turn | Laravel creates one row per write tool call; assistant message metadata carries `pending_action_ids[]`. Frontend renders one card per row. |
| F5 recovery | `GET /api/chatbot/sessions/{id}/pending-actions` returns status=pending rows; store calls it on mount. |
| Re-validation | `PendingActionExecutor::validatePayload()` mirrors the public `FormRequest` rules (cart: menu exists, qty 1-99, spicy 0-7, topping exists; reservation: VN phone regex, location enum, schedule window enforced by service). |

---

## State machine

```
        POST /confirm             POST /cancel
pending ─────────────► confirmed       │
   │                                  │
   ├──► expires_at < now() ────────► expired   (cron, every minute)
   │
   ├──────────── validates payload ───► failed (status = failed, error_message set)
   │
   └──────────── service throws    ───► failed
```

Implemented state transitions:

| From | Trigger | To | Side effect |
|------|---------|-----|-------------|
| pending | POST /confirm | confirmed or failed | `confirmed_at` or `error_message` written inside same DB transaction as service call |
| pending | POST /cancel | cancelled | `cancelled_at` set |
| pending | expires_at < now AND confirm fired | 410 Gone returned; row flipped to expired | aborts before dispatch |
| pending (already confirmed/cancelled/expired/failed) | POST /confirm | 409 Conflict returned | idempotency: never double-fire |
| pending | cron job | expired | `chatbot:expire-pending` updates `status`, `error_message` |

---

## API contract

### Confirm — 200 OK
```json
{
  "success": true,
  "data": {
    "pending_action_id": 7,
    "status": "confirmed",
    "action_type": "cart_add_item",
    "result": { "cart_item_id": 145, "menu_item_id": 12, "quantity": 1, "spicy_level": 2 },
    "confirmed_at": "2026-10-20T17:00:00+07:00"
  }
}
```

### Confirm — 410 Gone
```json
{ "success": false, "error": "pending_action_expired", "message": "Yêu cầu đã hết hạn. Vui lòng gửi lại tin nhắn." }
```

### Confirm — 409 Conflict
```json
{ "success": false, "error": "pending_action_not_pending", "message": "Yêu cầu này đã được xử lý trước đó." }
```

### Confirm — 403 Forbidden
```json
{ "success": false, "error": "forbidden", "message": "Bạn không có quyền xác nhận yêu cầu này." }
```

### Confirm — 502 Bad Gateway (service failed validation / threw)
```json
{ "success": false, "error": "action_execution_failed", "message": "Không thể thực hiện: <first validation message>" }
```

### Cancel — 200 (idempotent)
```json
{ "success": true, "data": { "pending_action_id": 7, "status": "cancelled" } }
```

### List pending (F5 recovery) — 200
```json
{ "success": true, "data": { "session_id": 42, "items": [ { "id": 7, "action_type": "cart_add_item", "summary": {…}, "expires_at": "…", "message_id": 102 } ] } }
```

---

## Frontend UX states

`ConfirmationCard` (`front-end/src/components/chat/ConfirmationCard.vue`):

| status | UI | Buttons |
|--------|----|---------|
| pending | neutral cream | [Hủy] [Xác nhận] (optimistic disable) |
| confirmed | green tint, "Đã xác nhận" | none |
| cancelled | grey tint, "Đã hủy" | none |
| expired | amber tint, "Đã hết hạn" | none |
| failed | red tint, "Thất bại" | none |

- On Confirm click → card flips to optimistic confirmed immediately.
- HTTP error → reverted to pending + toast from store `error`.
- 410 → backend marks expired; frontend re-syncs on next store load.

---

## Guest write refusal (AC-4)

```
Guest POST message: "thêm mì cay vào giỏ"
Laravel:
  ChatbotController::sendMessage()
    → AI-Service returns confirmation_required=true + cart_add_item
    → user_id is null
    → controller overwrites content to:
      "Xin quý khách hãy đăng nhập để sử dụng dịch vụ nhé."
    → does NOT call createPendingAction
  Response: content=above, NO pending_action_ids.
```
Verified: covered by `TestScenario5UnauthenticatedRequest` + integration code path.

---

## Live verification hooks

```bash
# After migrating & starting both servers, manual smoke:
php artisan migrate
php artisan chatbot:expire-pending   # no-op initially
# In another shell:
python -m pytest tests/test_c3_final.py -v   # 16/16 pass
```

These tests cover all 15 scenarios in the spec (§10 of PHASE_12) plus an extra Gemini Structured Output scenario (TestScenario16).
