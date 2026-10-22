# C3_FINAL_IMPLEMENTATION.md — Implementation Log

**Generated:** 2026-10-20  
**Scope:** Files created / modified during C3-Final.

---

## Files Created (14)

### Backend — Database

| File | Purpose |
|------|---------|
| `back-end/database/migrations/2026_10_20_180002_create_chat_pending_actions_table.php` | New `chat_pending_actions` table: idempotency, ownership, TTL, atomic state machine. |
| `back-end/database/migrations/2026_10_20_180003_add_guest_token_to_chat_sessions.php` | Adds `guest_token` to `chat_sessions` for guest-session ownership proof. |

### Backend — Models

| File | Purpose |
|------|---------|
| `back-end/app/Models/ChatSession.php` | Eloquent: `user()`, `messages()`, `pendingActions()`, scopes. |
| `back-end/app/Models/ChatMessage.php` | Eloquent: `pendingAction()`, metadata cast, helpers. |
| `back-end/app/Models/ChatPendingAction.php` | Eloquent: scopes `pending()`, `expired()`, `isOwnedBy()`. |

### Backend — Services / Console

| File | Purpose |
|------|---------|
| `back-end/app/Services/Chatbot/PendingActionExecutor.php` | Dispatch write tool to `CartService::addItem` / `ReservationService::create` inside DB transaction, with re-validation. |
| `back-end/app/Services/Chatbot/ConfirmationSummaryBuilder.php` | Build UI summary JSON (price breakdown, spicy display, masked phone). |
| `back-end/app/Console/Commands/ExpirePendingActions.php` | `php artisan chatbot:expire-pending` — mark TTL-expired rows. |

### Backend — Controller

| File | Purpose |
|------|---------|
| `back-end/app/Http/Controllers/Api/ChatbotController.php` | Rewritten: session CRUD, sendMessage (proxies to AI-Service + creates pending actions), confirm/cancel/get/list pending. |

### Frontend

| File | Purpose |
|------|---------|
| `front-end/src/api/chatbot.ts` | Typed API client: `startSession`, `sendMessage`, `confirmPendingAction`, etc. |
| `front-end/src/stores/chatbot.ts` | Pinia store: session mgmt, messages, pending actions, F5 recovery. |
| `front-end/src/components/chat/ChatBotWidget.vue` | Floating widget; mobile responsive; typed/loading/empty/error states; suggested questions. |
| `front-end/src/components/chat/ConfirmationCard.vue` | Per-action card with Confirm/Cancel; status indicators; price breakdown. |

### AI Service

| File | Purpose |
|------|---------|
| `ai-service/core/intent_classifier.py` | Gemini Structured Output intent classifier with deterministic confirm/cancel. |
| `ai-service/tests/test_c3_final.py` | 15 test scenarios + intent-classification tests; all use mocks. |

---

## Files Modified (3)

| File | Change |
|------|--------|
| `back-end/routes/api.php` | Added 4 pending-action routes inside `auth:sanctum` subgroup. |
| `back-end/routes/console.php` | Added `Schedule::command('chatbot:expire-pending')->everyMinute()->withoutOverlapping()`. |
| `front-end/src/api/client.ts` | Interceptor attaches `X-Chat-Guest-Token` to `/chatbot/*` calls when no auth token present. |

---

## Functional Inventory

### Endpoints added

```
POST   /api/chatbot/pending-actions/{id}/confirm
POST   /api/chatbot/pending-actions/{id}/cancel
GET    /api/chatbot/pending-actions/{id}
GET    /api/chatbot/sessions/{id}/pending-actions
```

### Confirmation Flow

1. User → `POST /api/chatbot/sessions/{id}/messages`
2. Laravel → AI-Service (`/api/v1/chat`)
3. AI-Service returns `confirmation_required: true` + `tool_calls[]`
4. Laravel creates one `chat_pending_actions` row per write tool (with TTL=5min)
5. Response includes `pending_actions[]` with summary
6. Frontend renders `ConfirmationCard` per pending action
7. User clicks Confirm → `POST /api/chatbot/pending-actions/{id}/confirm`
8. Laravel (auth:sanctum) → guard (ownership, status, expires_at) → wrap in DB transaction:
   - mark confirmed
   - dispatch `CartService::addItem` / `ReservationService::create` (with re-validation)
   - store result + return
9. Card flips to status "Đã xác nhận".

### Security model

- **Auth:** Sanctum required for confirm/cancel; user_id ownership check (403 otherwise).
- **Idempotency:** `idempotency_key` UUID on every row + `UNIQUE` index (replay-proof).
- **TTL:** 5-minute window enforced inline in confirm + by cron `chatbot:expire-pending` (defense in depth).
- **Guests:** identified via `guest_token` UUID; cannot read another guest's (or logged-in user's) session.
- **Write tool without login:** refused by controller; AI-Service still returns the request, but Laravel responds with `"Xin quý khách hãy đăng nhập …"` and **does not** create a pending action.
- **No DB write in AI-Service.** AI-Service returns action summary; Laravel is the only writer.
- **No `.env` access.** Only env vars loaded into runtime are consumed.

### Intent Classification

- Gemini Structured Output schema (intent, confidence, slots, proposed_tool, needs_clarification, clarification_slots).
- **Deterministic** confirm/cancel: tokenised (Unicode-NFD + Vietnamese diacritic strip + word boundary) — short keys match whole words only.
- No Gemini call when the message is a confirm/cancel, saving one round-trip per approval.
- Fallback to keyword router if Gemini fails.

### API Usage Optimization

- Health endpoints do not call Gemini.
- Confirm/Cancel do not call Gemini (rule-based + DB read).
- Bounded chat history (last 20 messages) reused.
- Pre-computed knowledge-base embeddings (no per-query generation).
- Test suite uses `AsyncMock` + `MagicMock`; never hits Gemini/Laravel in CI.
- Live smoke tests are optional and budgeted (not run inside this implementation).
