# Phase C1 — Next Phase Handoff

**Ngày:** 2026-10-20  
**Status:** C1_CODE_READY — LIVE_API_NOT_VERIFIED

---

## 1. Phase C1 Summary

### 1.1 Delivered

- FastAPI application structure
- Gemini provider with google-genai SDK
- Chat endpoint with internal token auth
- Health and readiness checks
- Unit tests (mock-based)
- Configuration via environment variables

### 1.2 NOT Delivered

- Live API verification
- RAG retrieval
- Laravel integration
- Business tools
- Session management
- Rate limiting

---

## 2. Current State

### 2.1 Files Structure

```
ai-service/
├── api/           # FastAPI endpoints
├── core/          # Config, Gemini provider
├── schemas/       # Pydantic models
├── services/      # Chat service
├── tests/         # Unit tests
├── app.py         # Entry point
├── .env           # User fills this
└── requirements.txt
```

### 2.2 Configuration Required

User must fill in `.env`:

```env
GEMINI_API_KEY=<user fills>
AI_SERVICE_INTERNAL_KEY=<user generates>
```

### 2.3 Running the Service

```powershell
cd d:\LVTN-QLNH-MiCay\LVTN-QLNH\ai-service
.\venv\Scripts\activate
python app.py
```

Service runs at: http://127.0.0.1:8001

---

## 3. Phase C2 Tasks

### 3.1 Priority 1: Live Verification

1. Add GEMINI_API_KEY to `.env`
2. Test chat endpoint with real Gemini
3. Test structured output
4. Verify latency and quality

### 3.2 Priority 2: Laravel Integration

1. Create Laravel client for API calls
2. Add endpoints for:
   - Menu lookup
   - Cart operations
   - Order creation
3. Test business tool execution

### 3.3 Priority 3: RAG Retrieval

1. Copy knowledge base from archive:
   - `archive/AI/knowledge_base/raw/faq.jsonl`
   - `archive/AI/knowledge_base/chunks/chunks.jsonl`
2. Implement simple retrieval (keyword match for MVP)
3. Add context to chat prompts

### 3.4 Priority 4: Session Management

1. Add Redis for session storage
2. Implement chat history
3. Add context window

---

## 4. API Contract for Integration

### 4.1 AI Service → Laravel

```
POST http://localhost:8000/api/v1/chat
Header: X-Internal-Token: <ai-service-key>
Body: {
  "session_id": "...",
  "message": "...",
  "user_id": 123  // if authenticated
}
```

### 4.2 Laravel → AI Service

```
GET /api/v1/health
GET /api/v1/ready
POST /api/v1/chat
Header: X-Internal-Token: <ai-service-key>
```

---

## 5. Configuration to Add

### 5.1 AI Service `.env`

```env
# Existing
GEMINI_API_KEY=<from user>
AI_SERVICE_INTERNAL_KEY=<from user>

# Add for Laravel
LARAVEL_BASE_URL=http://localhost:8000
LARAVEL_API_TIMEOUT=30
```

### 5.2 Laravel `.env`

```env
# Add
AI_SERVICE_URL=http://127.0.0.1:8001
AI_SERVICE_TOKEN=<same as AI_SERVICE_INTERNAL_KEY>
```

---

## 6. Knowledge Base Files

Copy these from archive:

```
archive/AI/config/
├── 03_INTENT_TAXONOMY.json
├── 04_SLOT_SCHEMA.json
├── 06_ROUTING_RULES.json
└── 05_TOOL_SCHEMA.json

archive/AI/knowledge_base/
├── raw/faq.jsonl
└── chunks/chunks.jsonl
```

---

## 7. Open Questions

| Question | Status | Action |
|----------|--------|--------|
| Which Laravel endpoints for tools? | Open | Define in C2 |
| Session storage mechanism? | Open | Redis vs DB |
| Rate limits? | Open | Define in C2 |
| Streaming requirement? | Open | Depends on UI |

---

## 8. Risks

| Risk | Mitigation |
|------|-----------|
| API key not working | Verify key permissions |
| Latency too high | Optimize prompts, add caching |
| Gemini cost | Set budget alerts |
| Laravel integration fails | Add error handling |

---

## 9. Handoff Checklist

- [x] Code complete
- [x] Tests written
- [x] Documentation complete
- [ ] Live API verified (PENDING)
- [ ] Laravel integration (C2)
- [ ] RAG retrieval (C2)
- [ ] Business tools (C2/C3)

---

## 10. Contact Points

### For Laravel Integration

Files to modify:
- `back-end/app/Http/Controllers/Api/ChatbotController.php`
- `back-end/config/services.php`

### For AI Service

Files ready:
- `ai-service/app.py` - Entry point
- `ai-service/core/gemini_provider.py` - Gemini wrapper
- `ai-service/api/chat.py` - Chat endpoint

---

## 11. Next Action

**User must:**

1. Add GEMINI_API_KEY to `ai-service/.env`
2. Generate AI_SERVICE_INTERNAL_KEY
3. Run service and verify endpoints
4. Report results

---

*Next Phase Handoff for Phase C1*
