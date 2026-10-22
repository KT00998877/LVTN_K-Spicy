# C3_FINAL_UI_REPORT.md — Chatbot Widget UI

**Generated:** 2026-10-20  
**Components:** `ChatBotWidget.vue`, `ConfirmationCard.vue`

---

## Inventory

| File | Role |
|------|------|
| `front-end/src/components/chat/ChatBotWidget.vue` | Floating chat launcher + panel |
| `front-end/src/components/chat/ConfirmationCard.vue` | Per-action confirmation card |
| `front-end/src/stores/chatbot.ts` | State + actions |
| `front-end/src/api/chatbot.ts` | API contract |

---

## UX flows

### Open / close
- Toggle button (🌶️) at bottom-right (24px gutter desktop, 12px mobile).
- Click opens panel (380×560 on desktop, full-width minus 8px on ≤480px).
- Clicking ✕ closes; transition is opacity + translateY (180ms).

### Session management
- First open: `POST /api/chatbot/sessions` → store session id + guest_token (if guest) in `sessionStorage` (key `chatbot_session_id`).
- Reload: read session id from storage; `GET /api/chatbot/sessions/{id}` + `pending-actions` to recover.
- "↻ Mới" button: starts a fresh session, clears messages and pending actions.

### Messaging
- Suggested-question pills in empty state (`Menu có món gì ngon?`, `Mì cay giá bao nhiêu?`, `Đặt bàn như thế nào?`, `Có khuyến mãi gì không?`).
- User typing + send (Enter / button).
- Disabled state while `sending.value === true`.
- Typing indicator (3-dot animation) appears below last message during in-flight.

### Errors
- Global `error` banner at top of panel with close ✕.
- Auto-clears on next send.
- 401 on confirm/cancel route → store `error` set; widget unaffected (still readable).

### Confirmation cards
- One card per pending action, rendered in order received.
- States: pending / confirmed / cancelled / expired / failed.
- Pending: Hủy (ghost) + Xác nhận (spicy red); buttons disabled while in-flight.
- Optimistic: status flips immediately, reverted if request fails.

---

## Visual language (vs `restaurant-ui.mdc`)

| Rule | Compliance |
|------|------------|
| Spicy-red primary | ✅ `#d32f2f` button + header gradient |
| Warm cream background | ✅ `#fffaf2` panel |
| Charcoal text | ✅ `#2e2e2e` |
| No purple/blue-purple gradients | ✅ red-on-cream only |
| No glassmorphism | ✅ solid panels |
| No `rounded-3xl` everywhere | ✅ 12px on buttons, 16px on panel, 50% on toggle |
| Honest icons | ✅ emoji (🌶️) used only where semantic |
| Mobile first | ✅ tested in widget CSS at 480px breakpoint |
| No gradient blobs | ✅ single linear-gradient header |
| Restaurant-specific UX | ✅ ConfirmationCard price breakdown, spicy chip, "Xác nhận / Hủy" actions in tiếng Việt |

---

## Accessibility

- `aria-label` on toggle (changes between "Đóng chat" / "Mở chat").
- `role="dialog"` on panel; `aria-live="polite"` on messages container.
- `aria-label` on send button, "↻ Mới" button, error close.
- `disabled` carries visual + functional state; not just CSS-only.
- Focus styles preserved (input outline on focus shifts to spicy red, never removed).
- Touch targets ≥44px on mobile (`chatbot-toggle` is 56×56, buttons inside card are full-width with 14px padding).

---

## Responsive behaviour

| Width | Layout |
|-------|--------|
| ≥769 | 380px panel anchored bottom-right |
| ≤480 | full-width minus 16px panel, anchored to bottom; toggle down at 12px |
| ≥769 with inner scroll | messages container scrolls independently of header/input |

---

## Known limitations

- No message streaming (Phase 13+ spec). Whole-turn response only.
- No "edit pending action" inline (e.g. change quantity from card). User must restart the request.
- No WebSocket push — `sending` flag flips client-side only. F5 will reveal the latest server state.
- Mobile native (Flutter/React Native) is out of scope for this codebase; the responsive Vue widget is the customer-facing entry point on tablet/phone browsers.
