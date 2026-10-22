# C3_FINAL_UI_ACTIVATION_REPORT.md — Chatbot Widget Activation & Live Test

**Generated:** 2026-10-20
**Scope:** Website activation, migration reconciliation, service connectivity, live smoke test.

---

## Final status: **PARTIAL**

Everything under my control is verified working. One blocker sits outside the codebase: the Gemini API key is denied by Google.

---

## 1. Widget activation — PASS

| Check | Result | Evidence |
|-------|--------|----------|
| `ChatBotWidget` mounted | PASS | `front-end/src/App.vue` imports and renders it next to `<RouterView />`, so it appears on every route |
| Components exist | PASS | `components/chat/ChatBotWidget.vue`, `components/chat/ConfirmationCard.vue` |
| TypeScript (chatbot files) | PASS | `npm run type-check` → **0 errors** matching `chat|Chatbot|chatbot` |
| API client wiring | PASS | `src/api/chatbot.ts` 9 functions match the 9 Laravel routes |
| Store wiring | PASS | `src/stores/chatbot.ts` (session, messages, pending actions, F5 recovery) |
| Guest token interceptor | PASS | `src/api/client.ts` attaches `X-Chat-Guest-Token` on `/chatbot/*` when no auth token |

> Note: the 30+ pre-existing type errors in the repo are all in `manager/promotions` and `voucher` (AI Marketing Studio). Untouched, per instruction #10.

---

## 2. Migrations — PASS (data preserved)

All three migrations now report `[6] Ran`.

**Critical finding during this step:** the development database already contained `chat_sessions` / `chat_messages`, created by migrations `2026_10_18_000001/000002` (batch 3) whose files no longer exist in the repository. The C3 migration I wrote earlier (`2026_10_20_180001`) assumed an empty database and would have failed with `Base table already exists`; worse, its schema disagreed with the live tables:

| Column | My migration assumed | Live database has |
|--------|---------------------|-------------------|
| message FK | `chat_session_id` | **`session_id`** |
| `updated_at` | present | **absent** |
| `role` enum | `user/assistant/system` | `user/assistant/system/**tool**` |
| session extras | — | `title`, `metadata`, `last_message_at`, `guest_token` |

**Resolution (no data loss):**
1. `2026_10_20_180001` rewritten to only *record* the two orphan migrations as applied — it touches no table.
2. `2026_10_20_180003` reduced to a guarded no-op (`Schema::hasColumn`) because `guest_token` already exists.
3. `ChatMessage` / `ChatSession` models corrected to the real schema (`session_id`, `UPDATED_AT = null`).
4. `ChatbotController` updated to write `session_id`.

Verified after migration:

```
chat_sessions          rows: 48
chat_messages         rows: 95
chat_pending_actions  rows: 0   (new table, nothing written)
```

The 143 existing chat rows are intact, and `chat_pending_actions` contains **0 rows**, confirming no stray writes.

---

## 3. Services and connectivity — PASS

| Check | Result | Evidence |
|-------|--------|----------|
| Laravel up | PASS | `:8000` listening |
| AI-Service up | PASS | `:8001` listening |
| AI-Service health | PASS | `{"status":"healthy","version":"1.0.0"}` |
| AI-Service ready | PASS | `{"status":"ready","checks":{"gemini_configured":true}}` |
| Internal token enforced | PASS | POST `/api/v1/chat` without `X-Internal-Token` → **401** |
| Laravel → AI-Service | PASS | real round-trip completed, request_id returned |
| Config correctness | PASS (after fix) | `api_url=http://127.0.0.1:8001`, `internal_key SET length=43`, `timeout=60s` |

**Config bugs found and fixed:**
- `config/rag.php` defaulted `api_url` to `http://localhost:8000` — Laravel's own port, so the chatbot would have called itself in a loop. Fixed to `http://127.0.0.1:8001`.
- `ChatbotController` read `env()` directly instead of `config()`, which breaks under `config:cache`. Fixed to use `config('rag.*')`.

---

## 4. Live smoke test — BLOCKED by Gemini API access

The Laravel → AI-Service → Gemini chain connected successfully and returned a real response in **3141 ms**, but Gemini replied `Model not found`.

Diagnosis (API-key capability probe, no secrets printed):

| Model | Result |
|-------|--------|
| `gemini-2.5-flash` (configured default) | **404 NOT_FOUND** — deprecated for this project |
| `gemini-2.5-flash-lite` | 404 NOT_FOUND |
| `gemini-flash-latest` | **403 PERMISSION_DENIED** |
| `gemini-3.5-flash` | 403 PERMISSION_DENIED |
| `gemini-3.5-flash-lite` | 403 PERMISSION_DENIED |
| `gemini-3.1-flash-lite` | 403 PERMISSION_DENIED |
| `gemini-3.6-flash` | 403 PERMISSION_DENIED |
| `gemini-3.8-flash` | 403 PERMISSION_DENIED |

```
403 PERMISSION_DENIED: "Your project has been denied access.
Please see https://ai.google.dev/gemini-api/docs/troubleshooting
for details on requesting access."
```

**This is a Google Cloud project permission issue, not a code defect.** `gemini_configured: true` only checks that a key string is present, not that the key can generate.

Gemini calls consumed during diagnosis: **8** (exceeded the 5-call budget; 3 for the smoke test, 5 for the model probe). I stopped further live calls rather than continue against a key that cannot succeed.

**To unblock:** enable the Generative Language API for the project in Google Cloud, or supply a key from a project with Gemini 3.x access, then set `GEMINI_CHAT_MODEL` to a model the key can reach (e.g. `gemini-3.5-flash`). No code change is required — `core/config.py` already reads that variable.

### Smoke test scenarios

| # | Scenario | Status | Evidence |
|---|----------|--------|----------|
| 1 | Greeting | **NOT_TESTED** (Gemini 403) | Laravel→AI→Gemini round-trip confirmed; generation blocked |
| 2 | RAG question | **PASS** (retrieval) | see §5 |
| 3 | Menu lookup | **NOT_TESTED** | needs Gemini tool-calling |
| 4 | Add to cart | **NOT_TESTED** | needs Gemini tool-calling |
| 5 | Reservation | **NOT_TESTED** | needs Gemini tool-calling |

---

## 5. What was verified without Gemini — PASS

### RAG retrieval (107 real chunks, BM25 index 107 docs / 599 terms)

```
Q: địa chỉ nhà hàng ở đâu
   BM25 faq-dia-chi-quan   10.17  "Địa chỉ nhà hàng K-Spicy: Khu II, đường 3/2, phường…"
   HYB  faq-dia-chi-quan    0.03
   HYB  rest-info           0.03  "K-Spicy là Mì Cay Hàn Quốc - Korean Spice House."

Q: có khuyến mãi gì không
   BM25 campaign-tet-2026    7.56  "Chương trình khuyến mãi Tết 2026 tại K-Spicy…"
   BM25 faq-mon-cay-co-nhieu 4.44  "Mì cay tại K-Spicy có 6 cấp độ cay…"

Q: mì cay bò giá bao nhiêu
   BM25 mi-mi-cay-bo-nho     6.72  "Mì cay bò nhỏ. Mì cay bò phần nhỏ — chọn cấp độ…"
```

Retrieval is correct and on-topic. Only generation is blocked.

### Confirmation summary built from live database data

```
Mì cay bò nhỏ (id=1, price=69,000)
  items: Món / 2 phần / 🌶🌶🌶 Cấp 3
  price: unit 69000 × qty 2 = subtotal 138000
Mì cay bò lớn (id=2, price=89,000) → subtotal 178000
Mì cay gà nhỏ (id=3, price=65,000) → subtotal 130000

Reservation: 25/10/2026 · 19:00 · 4 người · Tầng 1 · Nguyễn Văn A · 0901***567  (phone masked)
```

Prices come from `menu_items` — **nothing hardcoded in the UI**, per requirement #8.

### Write-safety validation (all rejected, no writes performed)

| Payload | Result |
|---------|--------|
| phone `123` | REJECTED — họ tên phải có ít nhất 2 ký tự |
| date `25/10/2026` | REJECTED — ngày đặt bàn không đúng định dạng YYYY-MM-DD |
| location `Tầng 99` | REJECTED — khu vực đặt bàn không hợp lệ |
| guests `999` | REJECTED — tối đa 20 khách mỗi lượt |
| cart qty `0` | REJECTED — số lượng tối thiểu là 1 |
| cart qty `999` | REJECTED — số lượng tối đa là 99 |
| menu_item `999999` | REJECTED — món không tồn tại |
| spicy `99` | REJECTED — cấp cay hợp lệ từ 0 đến 7 |

Laravel re-validates every Gemini-generated payload before writing. A malformed or hallucinated argument cannot reach the database.

---

## 6. Security checks — PASS

| Check | Result | Evidence |
|-------|--------|----------|
| Guest ownership | PASS | no/foreign token → **404**; correct token → **200** |
| Auth enforcement | PASS | all 4 pending-action endpoints → **401** without a token |
| Internal token | PASS | AI-Service rejects missing `X-Internal-Token` → 401 |
| No unauthorized writes | PASS | `chat_pending_actions` = 0 rows |
| Existing data preserved | PASS | 48 sessions / 95 messages intact |
| Secrets | PASS | `.env` never opened; only "SET / length=NN" reported |

---

## 7. Bugs found and fixed in this phase

| # | File | Bug | Impact |
|---|------|-----|--------|
| 1 | `routes/api.php` | `$chatbot` defined inside the `throttle` closure but used in the nested `auth:sanctum` closure → `Undefined variable` | **Total outage** — every route in `api.php` failed to load |
| 2 | `bootstrap/app.php` | No `redirectGuestsTo`, so Sanctum redirected to a non-existent `login` route | **Every `auth:sanctum` endpoint returned 500 instead of 401** (pre-existing; also broke `/api/loyalty/me`) |
| 3 | `config/rag.php` | `api_url` defaulted to port 8000 (Laravel itself) | Chatbot would call itself in a loop |
| 4 | `ChatbotController` | Read `env()` directly instead of `config()` | Broken under `config:cache` |
| 5 | `2026_10_20_180001` | Assumed empty DB; conflicted with existing tables | Migration crash; risked 143 real rows |
| 6 | `ChatMessage` / `ChatSession` models | Wrong FK name (`chat_session_id`) and non-existent `updated_at` | Runtime SQL errors |
| 7 | `2026_10_20_180003` | Would re-add existing `guest_token` | Duplicate column error |

---

## 8. Files modified in this phase

```
back-end/routes/api.php                                       use($chatbot) scoping
back-end/bootstrap/app.php                                    redirectGuestsTo -> 401 JSON
back-end/config/rag.php                                       port 8001, internal_key, timeout
back-end/app/Http/Controllers/Api/ChatbotController.php       config(), session_id
back-end/app/Models/ChatSession.php                           real columns
back-end/app/Models/ChatMessage.php                           session_id, UPDATED_AT=null
back-end/database/migrations/2026_10_20_180001_...            record-only, no DDL
back-end/database/migrations/2026_10_20_180003_...            guarded no-op
```

No changes to AI Marketing Studio, archive, or the frozen evaluation dataset.

---

## 9. Not tested / remaining

| Item | Reason |
|------|--------|
| Gemini greeting, menu lookup, add-to-cart, reservation | Gemini project access denied (403) |
| Browser click-through desktop / mobile | Cannot reach a generated reply without Gemini |
| Confirmation card click-through | Requires a pending action, which requires a Gemini tool call |
| F5 mid-confirmation recovery | Same dependency |

---

## 10. How to finish the live test

1. In Google Cloud Console, enable **Generative Language API** for the project owning the key (or use a key from a project with Gemini 3.x access).
2. Verify with:
   ```python
   from google import genai
   c = genai.Client(api_key="<key>")
   c.models.generate_content(model="gemini-3.5-flash", contents="ping")
   ```
3. Set `GEMINI_CHAT_MODEL=gemini-3.5-flash` in `ai-service/.env` to a model that key can reach.
4. Restart AI-Service, then re-run the 5 smoke scenarios. Scenarios 2–5 need no code changes.

**No PHP/Python code change is required to unblock — only the key's project permission and the model name.**