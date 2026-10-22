# Phase C1 — API Contract

**Ngày:** 2026-10-20

---

## 1. Base URL

```
http://127.0.0.1:8001
```

---

## 2. Authentication

All protected endpoints require:

```
Header: X-Internal-Token: <AI_SERVICE_INTERNAL_KEY>
```

---

## 3. Endpoints

### 3.1 Health Check

**GET** `/api/v1/health`

Check if the AI Service process is running.

**Request:**
```
GET /api/v1/health
```

**Response (200 OK):**
```json
{
  "status": "healthy",
  "version": "1.0.0",
  "timestamp": "2026-10-20T17:00:00Z"
}
```

---

### 3.2 Readiness Check

**GET** `/api/v1/ready`

Check if the service is ready to handle requests.

**Request:**
```
GET /api/v1/ready
```

**Response (200 OK) - Ready:**
```json
{
  "status": "ready",
  "checks": {
    "gemini_configured": true
  },
  "message": null,
  "version": "1.0.0",
  "timestamp": "2026-10-20T17:00:00Z"
}
```

**Response (200 OK) - Not Ready:**
```json
{
  "status": "not_ready",
  "checks": {
    "gemini_configured": false
  },
  "message": "Gemini API key not configured",
  "version": "1.0.0",
  "timestamp": "2026-10-20T17:00:00Z"
}
```

---

### 3.3 Chat

**POST** `/api/v1/chat`

Send a chat message and get AI response.

**Headers:**
```
X-Internal-Token: <token>
Content-Type: application/json
```

**Request:**
```json
{
  "session_id": "sess_abc123",
  "message": "Chào bạn, quán có món gì ngon?",
  "request_id": "req_xyz789"  // optional
}
```

**Request Validation:**
| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| session_id | string | Yes | 1-64 chars |
| message | string | Yes | 1-2000 chars |
| request_id | string | No | max 64 chars |

**Response (200 OK):**
```json
{
  "request_id": "req_xyz789",
  "session_id": "sess_abc123",
  "intent": "greet_user",
  "reply": "Xin chào bạn! Mình là trợ lý của quán Mì Cay Hàn Quốc. Mình giúp gì cho bạn hôm nay?",
  "provider": "gemini",
  "model": "gemini-2.5-flash",
  "latency_ms": 450,
  "timestamp": "2026-10-20T17:00:00Z"
}
```

**Error Responses:**

401 Unauthorized:
```json
{
  "detail": {
    "code": "AUTH_ERROR",
    "message": "Missing internal token"
  }
}
```

422 Validation Error:
```json
{
  "detail": [
    {
      "loc": ["body", "message"],
      "msg": "String should have at least 1 character",
      "type": "string_too_short"
    }
  ]
}
```

503 Service Unavailable:
```json
{
  "detail": {
    "code": "GEMINI_ERROR",
    "message": "Gemini API key not configured"
  }
}
```

---

### 3.4 Chat Structured (Development Only)

**POST** `/api/v1/chat/structured`

Test structured JSON output from Gemini.

**Headers:**
```
X-Internal-Token: <token>
Content-Type: application/json
```

**Request:**
```json
{
  "session_id": "sess_abc123",
  "message": "Cho tôi xem món mì cay",
  "request_id": "req_xyz789"  // optional
}
```

**Response (200 OK):**
```json
{
  "request_id": "req_xyz789",
  "session_id": "sess_abc123",
  "intent": "get_menu",
  "reply": "Quán có nhiều món mì cay ngon...",
  "confidence": 0.95,
  "model": "gemini-2.5-flash",
  "latency_ms": 380,
  "timestamp": "2026-10-20T17:00:00Z"
}
```

---

## 4. Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| AUTH_ERROR | 401 | Authentication failed |
| CONFIG_ERROR | 503 | Service misconfigured |
| GEMINI_ERROR | 503 | Gemini API error |
| VALIDATION_ERROR | 422 | Request validation failed |
| INTERNAL_ERROR | 500 | Unexpected error |

---

## 5. Rate Limits

Currently not implemented (Phase C2).

---

## 6. CORS

Allowed origins:
- http://localhost:5173 (frontend dev)
- http://localhost:8000 (Laravel)

---

## 7. Example Usage

### cURL

```bash
# Health check
curl http://127.0.0.1:8001/api/v1/health

# Chat
curl -X POST http://127.0.0.1:8001/api/v1/chat \
  -H "X-Internal-Token: your-token" \
  -H "Content-Type: application/json" \
  -d '{"session_id": "test", "message": "Chào bạn"}'
```

### Python

```python
import httpx

response = httpx.post(
    "http://127.0.0.1:8001/api/v1/chat",
    headers={"X-Internal-Token": "your-token"},
    json={
        "session_id": "user-123",
        "message": "Chào bạn, quán có món gì ngon?",
    },
    timeout=30.0,
)

print(response.json())
```

### JavaScript

```javascript
const response = await fetch('http://127.0.0.1:8001/api/v1/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Internal-Token': 'your-token',
  },
  body: JSON.stringify({
    session_id: 'user-123',
    message: 'Chào bạn, quán có món gì ngon?',
  }),
});

const data = await response.json();
console.log(data);
```

---
*API Contract for Phase C1*
