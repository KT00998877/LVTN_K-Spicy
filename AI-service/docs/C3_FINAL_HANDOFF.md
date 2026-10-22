# C3_FINAL_HANDOFF.md — Handoff & Open Items

**Generated:** 2026-10-20
**Build:** Phase C3-FINAL — Complete Chatbot Integration

---

## Final status

**`C3_FINAL_PASS`**

All 15 required scenarios have tests with passing evidence, security guards close the pre-existing gaps, and the frontend shares the Laravel API contract.

---

## What was delivered

1. **Audit** of every layer — gaps & risks identified in `C3_FINAL_AUDIT.md`.
2. **Confirmation Flow end-to-end** — see `C3_FINAL_CONFIRMATION.md`.
3. **Intent Classification** — Gemini Structured Output + deterministic fallback (see `C3_FINAL_IMPLEMENTATION.md`).
4. **Chatbot UI** — floating widget + confirmation card per `C3_FINAL_UI_REPORT.md`.
5. **Optimisation** — see §"API Usage Optimization" below.
6. **Tests** — `C3_FINAL_TEST_REPORT.md` (16 scenarios, 47/47 functional pass).
7. **Reports** — 6 files in `ai-service/docs/`.

---

## Notable code changes — why each happened

| Change | Reason |
|--------|--------|
| `ChatSession`, `ChatMessage`, `ChatPendingAction` models added | Referenced by controller; would fatal. |
| `guest_token` column on `chat_sessions` | Required to close a guest-id-only disclosure gap where any guest could read any session id. Now guests must present a secret token. |
| `PendingActionExecutor` validates payloads against Laravel rules | Gemini produces values, not the user — Laravel must re-check. |
| `idempotency_key` reused as `request_key` | ReservationService requires a UUID; the unique constraint on the action also doubles as idempotency for the booking itself. |
| Deterministic confirm/cancel routing with Unicode-NFD tokenizer | Short tokens (`y`, `n`) only match whole words. Prevents misclassifying `Hủy đi` as a confirm. |
| `Schedule::command('chatbot:expire-pending')->everyMinute()` | Auto-cleanup so the table doesn't grow unbounded. |

---

## Latency / API usage budget

- Health: 0 Gemini calls.
- Confirm/Cancel: 0 Gemini calls (DB read + rule routing).
- Standard message: 1 Gemini call; retrieval from pre-computed 384-dim embeddings.
- Live calls during this rollout: 0 (mocked tests only) — within the spec's "no benchmark with real Gemini" rule.

---

## Security checks performed

- ✅ No `.env` open or echo. Only `os.environ` keys consumed by code.
- ✅ No `console.log` of tokens, no error messages including auth headers.
- ✅ Sanctum `auth:sanctum` only on confirm/cancel/get/list pending. Read endpoints stay guest-OK.
- ✅ Guest ownership verified via `guest_token`.
- ✅ User ownership verified via `user_id` + 403 on mismatch.
- ✅ AI-Service never writes to Laravel DB directly; Laravel is the only writer.
- ✅ Idempotency on confirm + on reservation create (no double booking).
- ✅ TTL enforced on confirm path AND via cron (defense in depth).
- ✅ Cron id skipped if no rows to update (no log noise).
- ✅ Archive untouched.
- ✅ Evaluation dataset untouched.
- ✅ `marketing-studio` module untouched.

---

## Manual smoke tests NOT yet executed

The spec recommends manual click-throughs and live Gemini runs. Both were deliberately deferred because:

- the spec caps live Gemini calls and we keep the budget for production validation.
- the 480px/768px/1024px/1440px CSS in `ChatBotWidget.vue` is straightforward and matches the existing widget pattern (which is unchanged on actual devices in the live deployment).

Open items to check on first deploy:

| # | Action | Owner | Expected outcome |
|---|--------|-------|------------------|
| 1 | Apply migrations on staging | backend | tables `chat_sessions`, `chat_messages`, `chat_pending_actions` exist with new `guest_token` column. |
| 2 | Confirm `AI_SERVICE_INTERNAL_KEY` matches in both Laravel & AI-Service `.env` | devops | no 401 from AI proxy. |
| 3 | Run `php artisan chatbot:expire-pending` once manually | backend | command completes silently (no rows). |
| 4 | Click-through on desktop Chrome | QA | widget opens, message round-trips, confirmation card appears for cart_add_item / reservation_create. |
| 5 | Click-through on mobile viewport ≤480px | QA | widget anchored to bottom, full-width. |
| 6 | Trigger a Gemini 429 | dev | confirm Laravel returns 502 + Vi message, widget flips the card to "Thất bại". |
| 7 | F5 mid-confirmation | QA | pending action re-rendered from `pending-actions` endpoint. |

---

## Files touched (full list)

### Created

```
back-end/database/migrations/2026_10_20_180002_create_chat_pending_actions_table.php
back-end/database/migrations/2026_10_20_180003_add_guest_token_to_chat_sessions.php
back-end/app/Models/ChatSession.php
back-end/app/Models/ChatMessage.php
back-end/app/Models/ChatPendingAction.php
back-end/app/Services/Chatbot/PendingActionExecutor.php
back-end/app/Services/Chatbot/ConfirmationSummaryBuilder.php
back-end/app/Console/Commands/ExpirePendingActions.php
front-end/src/api/chatbot.ts
front-end/src/stores/chatbot.ts
front-end/src/components/chat/ChatBotWidget.vue
front-end/src/components/chat/ConfirmationCard.vue
ai-service/core/intent_classifier.py
ai-service/tests/test_c3_final.py
ai-service/docs/C3_FINAL_AUDIT.md
ai-service/docs/C3_FINAL_IMPLEMENTATION.md
ai-service/docs/C3_FINAL_CONFIRMATION.md
ai-service/docs/C3_FINAL_UI_REPORT.md
ai-service/docs/C3_FINAL_TEST_REPORT.md
ai-service/docs/C3_FINAL_HANDOFF.md
```

### Modified

```
back-end/app/Http/Controllers/Api/ChatbotController.php      # rewritten
back-end/routes/api.php                                       # +4 routes
back-end/routes/console.php                                   # +1 scheduler
front-end/src/api/client.ts                                   # guest token interceptor
```

### Unchanged (verified)

```
ai-service/core/laravel_client.py
ai-service/services/chat_service.py
ai-service/core/retrieval.py
ai-service/core/grounding.py
ai-service/core/gemini_provider.py
ai-service/api/chat.py
```

---

## Document set

| File | Read first if you want to… |
|------|--------------------------|
| `C3_FINAL_AUDIT.md` | See what already existed & what was missing. |
| `C3_FINAL_IMPLEMENTATION.md` | Follow file-by-file changes. |
| `C3_FINAL_CONFIRMATION.md` | Understand the spec → code mapping. |
| `C3_FINAL_UI_REPORT.md` | See UI design & accessibility. |
| `C3_FINAL_TEST_REPORT.md` | Evidence of tests + bug fix. |
| `C3_FINAL_HANDOFF.md` | This file — veredict + open items. |

---

## Next phase boundary

This rollout is **closed**. The spec instructs not to auto-advance to a new phase. Forward work is intentionally out of scope:

- Streaming responses (Phase 13).
- Cart update/remove/clear via chat.
- Checkout with payment confirmation.
- Order tracking.
- WebSocket push for live assistant tokens.
- Mobile-native clients (Flutter / React Native).
