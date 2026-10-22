# K-Spicy AI Chatbot Service

Korean Spicy Noodle Restaurant AI Chatbot Service using Google Gemini API.

## Overview

FastAPI-based service that provides conversational AI capabilities for the K-Spicy restaurant chatbot. Uses Google Gemini API for natural language understanding and generation.

## Features

- **Chat API**: Natural language conversation with restaurant chatbot
- **Structured Output**: JSON-formatted responses for development
- **Authentication**: Internal token-based security
- **Health Checks**: Process health and readiness monitoring

## Requirements

- Python 3.11+
- Google Gemini API key

## Installation

For the complete Windows application, use the launcher from the repository root:

```powershell
python scripts/dev_chatbot.py
```

See [local run and testing instructions](../docs/CHATBOT_LOCAL_RUN.md). The launcher separates
the chat gateway (8002) from the business API (8000), so AI tool callbacks cannot deadlock
the single-threaded PHP development server. Running this AI service alone does not start Laravel.

### 1. Create virtual environment

```powershell
cd ai-service
python -m venv venv
.\venv\Scripts\activate
```

### 2. Install dependencies

```powershell
pip install -r requirements.txt
```

### 3. Configure environment

Copy `.env.example` to `.env` and fill in your values:

```powershell
copy .env.example .env
```

Edit `.env` with your settings:

```env
GEMINI_API_KEY=your-api-key-here
GEMINI_CHAT_MODEL=gemini-3.8-flash
AI_SERVICE_INTERNAL_KEY=generate-a-secure-random-key
```

Generate a secure internal key:

```powershell
python -c "import secrets; print(secrets.token_urlsafe(32))"
```

### 4. Run the service

```powershell
python app.py
```

Or with uvicorn directly:

```powershell
uvicorn app:app --host 127.0.0.1 --port 8001 --reload
```

## API Endpoints

### Health Check

```bash
GET /api/v1/health
```

Returns service health status.

### Readiness Check

```bash
GET /api/v1/ready
```

Returns readiness status with configuration checks.

### Chat (Protected)

```bash
POST /api/v1/chat
Header: X-Internal-Token: your-internal-key
Body: {
    "session_id": "user-session-123",
    "message": "Chào bạn, quán có món gì ngon?"
}
```

### Chat Structured (Development Only)

```bash
POST /api/v1/chat/structured
```

Returns JSON-formatted response with intent and confidence scores.

## API Documentation

Once running, access:
- Swagger UI: http://127.0.0.1:8001/docs
- ReDoc: http://127.0.0.1:8001/redoc

## Testing

Run unit tests:

```powershell
pytest tests/ -v
```

## Security

- Internal token authentication for chat endpoints
- CORS restricted to localhost
- No secrets in logs
- Request validation with Pydantic

## Project Structure

```
ai-service/
├── api/              # FastAPI endpoints
├── core/             # Configuration, Gemini provider
├── schemas/          # Pydantic models
├── services/         # Business logic
├── tests/            # Unit tests
├── app.py           # Application entry point
├── requirements.txt
└── pyproject.toml
```

## Model Selection

Set `GEMINI_CHAT_MODEL` to a model available to the project's API key. The local configuration
uses `gemini-3.8-flash`. Model availability and upstream capacity can change; the service
uses live Laravel data for supported read-only questions when generation fails. Write proposals
still require successful model processing and explicit customer confirmation.

## License

MIT
