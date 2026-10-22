# C3_FINAL_AUDIT.md — Chatbot Integration Audit

**Generated:** 2026-10-20  
**Scope:** Audit of existing chatbot infrastructure across Laravel, AI-Service, Website, Mobile before C3-Final integration.

---

## 1. Laravel Backend (back-end)

### Existing API

| Endpoint | Method | Status | Notes |
|----------|--------|--------|-------|
| `/api/chatbot/sessions` | POST | pre-existing | start session (guest/user) |
| `/api/chatbot/sessions` | GET | pre-existing | list user's sessions |
| `/api/chatbot/sessions/{id}` | GET | pre-existing | session + messages |
| `/api/chatbot/sessions/{id}/messages` | POST | pre-existing | send message → AI Service |
| `/api/chatbot/sessions/{id}/close` | POST | pre-existing | close session |
| `/api/chatbot/pending-actions/{id}/confirm` | POST | **NEW (C3-Final)** | confirm write tool |
| `/api/chatbot/pending-actions/{id}/cancel` | POST | **NEW** | cancel write tool |
| `/api/chatbot/pending-actions/{id}` | GET | **NEW** | detail (F5 recovery) |
| `/api/chatbot/sessions/{id}/pending-actions` | GET | **NEW** | list pending (F5 recovery) |

Auth mode: Sanctum optional for read; **required** for all write endpoints. Guest sessions are identified by an HTTP-header `X-Chat-Guest-Token` UUID (added in C3-Final to close a guest-id-only disclosure gap).

### 11 Business Tools (C3)

All 11 tools already registered in `ai-service/core/laravel_client.py`, exercising the existing Laravel services:

| # | Tool | Type | Auth | Confirmation | Endpoint |
|---|------|------|------|--------------|----------|
| 1 | `menu_lookup` | read | no | no | `/api/menu` |
| 2 | `menu_item_detail` | read | no | no | `/api/menu/items/{id}` |
| 3 | `category_list` | read | no | no | `/api/categories` |
| 4 | `promotion_list` | read | no | no | `/api/promotions` |
| 5 | `reservation_options` | read | no | no | `/api/reservations/options` |
| 6 | `cart_view` | read | yes | no | `/api/cart` |
| 7 | `order_list` | read | yes | no | `/api/orders` |
| 8 | `order_detail` | read | yes | no | `/api/orders/{id}` |
| 9 | `voucher_preview` | read | no | no | `/api/vouchers/preview` |
| 10 | `cart_add_item` | **write** | yes | **yes** | `/api/cart/items` |
| 11 | `reservation_create` | **write** | optional | **yes** | `/api/reservations` |

### Sanctum auth

Already wired. The chatbot routes are mounted inside `routes/api.php` with `auth:sanctum` for the new pending-action group. Staff-API tokens never leak here — the front-end client picks auth tokens strictly by URL prefix (`/staff/` vs `/chatbot/`).

### Session Management

- Table `chat_sessions` + `chat_messages` existed from Phase 11 (migration `2026_10_20_180001`).
- New migration `2026_10_20_180003_add_guest_token_to_chat_sessions.php` adds `guest_token` for guest ownership proof.
- New table `chat_pending_actions` from migration `2026_10_20_180002`.

### C3 Gaps found & fixed

| Gap | Severity | Fix |
|-----|----------|-----|
| `ChatSession` / `ChatMessage` / `ChatPendingAction` Eloquent models didn't exist (referenced in controller) | Critical — would fatal at runtime | Added in C3-Final |
| Guest session lookup was unsecured (`where user_id = ?` skipped if guest) | High — conversation disclosure | `ownedSessionsQuery()` helper + guest_token |
| `ChatbotController::confirmPendingAction` + cancel/get/list pending methods didn't exist | High | Added in C3-Final |
| Executor bypassed FormRequest validation (write from non-user input) | Medium | Added `validatePayload()` mirroring `StoreReservationRequest` rules |
| ReservationExecutor didn't supply `request_key` (Laravel requires UUID) | Medium — booking would 422 | Reuse `idempotency_key` from pending action |

---

## 2. AI-Service (Python FastAPI)

### Existing

- `gemini_provider.py` — Gemini 2.5 Flash wrapper with structured output.
- `laravel_client.py` — 11-tool registry + async HTTP client + ToolExecutor.
- `services/chat_service.py` — `ChatService` + `BusinessToolService` with RAG context injection.
- `core/retrieval.py` + `core/vector_retriever.py` — BM25 + vector (384-dim `multilingual-e5-small`) + RRF hybrid.
- `core/grounding.py` — `GroundingGuard` (length, factuality, citation, hallucination patterns).
- 107 chunks in knowledge base (100% embedding coverage).
- Existing 12 RAG tests + 19 business-tool tests pass.

### New in C3-Final

- `core/intent_classifier.py` — Gemini Structured Output intent classifier with deterministic confirm/cancel routing.

### Verified

- No secrets written to `.env`; `GEMINI_API_KEY` read from env only by provider.
- No DB write path in AI-Service — all writes delegate to Laravel via the `pending_actions` confirmation flow.
- Prompt caching: chat history is bounded to 20 messages and re-used across turns.

---

## 3. Website Frontend (front-end)

### Existing state

- `front-end/src/api/client.ts` — axios instance with bearer token + 401 handling.
- 22 Pinia stores, no `stores/chatbot.ts` before C3-Final.
- No chatbot widget component before C3-Final (the archive `archive/front-end/components/ChatBotWidget.vue` was reference only).

### New in C3-Final

- `front-end/src/api/chatbot.ts` — typed chatbot client (9 functions).
- `front-end/src/stores/chatbot.ts` — Pinia store with session management + pending actions + F5 recovery.
- `front-end/src/components/chat/ChatBotWidget.vue` — floating widget, mobile-responsive, with typing/suggested/error states.
- `front-end/src/components/chat/ConfirmationCard.vue` — per-action card with Confirm/Cancel + price breakdown + status.
- axios interceptor updated to attach `X-Chat-Guest-Token` for guest sessions.

### Style alignment

Follows `restaurant-ui.mdc`: spicy-red header, cream background, charcoal text. Pill-shaped inline choices, no glassmorphism or purple gradients.

---

## 4. Mobile

This codebase has no separate mobile frontend in `mobile/` (Vue/web is shared across devices). The same `ChatBotWidget` is responsive and renders correctly at 375 / 768 / 1024 / 1440px breakpoints defined in the widget's `<style scoped>` block.

If a Flutter / React Native frontend is added later, it should target the same Laravel API contract in `front-end/src/api/chatbot.ts` and replicate the UX flow (open → messages → confirmation card → toast/error).

---

## 5. Archive specs reviewed

- `archive/docs/PHASE_12_SPEC.md` — full Confirmation Flow spec, schema, contract.
- All architectural decisions in §3 implemented verbatim.
- API contract §5 implemented 1:1 (status codes 200/410/409/403/502/401/422, response shapes).

---

## 6. Summary

| Layer | Pre-existing | Added by C3-Final | Total |
|-------|-------------|-------------------|-------|
| Migrations | 1 | 2 | 3 |
| Models | 0 (referenced names without files) | 3 | 3 |
| Services | many (Cart, Reservation) | 2 (Executor, SummaryBuilder) | +2 |
| Controllers | 0 (ChatbotController referenced as class) | 1 (rebuilt) | 1 |
| Console commands | many | 1 (ExpirePendingActions) | +1 |
| Frontend API | 0 | 1 (chatbot.ts) | 1 |
| Frontend store | 0 | 1 (chatbot.ts) | 1 |
| Frontend components | 0 | 2 (Widget, ConfirmationCard) | 2 |
| AI-Service modules | 9 | 1 (intent_classifier) | 10 |
| AI-Service tests | 31 | 16 (C3-Final scenarios) | 47 |

Audit verdict: the existing skeleton is sound but half-built. C3-Final completes every missing piece end-to-end.
