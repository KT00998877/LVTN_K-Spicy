# Phase C2 — Knowledge Base & RAG Integration

**Ngày:** 2026-10-20  
**Trạng thái:** PLANNING

---

## 1. Bối cảnh

### 1.1 Knowledge Base từ Archive

| Loại | Số lượng | Nội dung |
|------|----------|----------|
| FAQ | 21 | Giờ mở cửa, địa chỉ, hotline, voucher, thành viên... |
| Menu Items | 50+ | Mì cay, topping, kimbap, tokbokki, đồ uống |
| Restaurant Info | 1 | Thông tin nhà hàng |
| Chunks | 78+ | Đã chunk, sẵn sàng retrieval |
| Embeddings | ~900KB | Đã có sẵn vector embeddings |

### 1.2 Mục tiêu Phase C2

1. Khôi phục Knowledge Base vào ai-service
2. Triển khai Hybrid Search (BM25 + Vector)
3. Tích hợp RAG với Gemini
4. Thêm Grounding và Citation
5. Chống hallucination

---

## 2. Kiến trúc đề xuất

```
┌─────────────────────────────────────────────────────────┐
│                    USER QUERY                            │
│            "Quán có món gì ngon?"                      │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│               QUERY PROCESSING                           │
│  1. Vietnamese text normalization                        │
│  2. Keyword extraction                                  │
└─────────────────────┬───────────────────────────────────┘
                      │
         ┌────────────┴────────────┐
         │                         │
         ▼                         ▼
┌─────────────────┐       ┌─────────────────┐
│     BM25        │       │   GEMINI        │
│  (Keyword)      │       │  Embeddings     │
│                 │       │  (Semantic)     │
└────────┬────────┘       └────────┬────────┘
         │                         │
         └────────────┬────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│              RESULT FUSION                               │
│  - Reciprocal Rank Fusion                               │
│  - Top-K selection (k=5)                                │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│              RAG PROMPT                                 │
│  System: "Bạn là trợ lý K-Spicy. Trả lời dựa trên:   │
│           [Context: ...]"                                │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│              GEMINI 2.5 FLASH                          │
│  - Generate response with citations                      │
│  - Mark verified vs. unverified info                    │
└─────────────────────────────────────────────────────────┘
```

---

## 3. Chi tiết từng bước

### C2.1: Khôi phục Knowledge Base

**Input:** `archive/AI/knowledge_base/`  
**Output:** `ai-service/knowledge_base/`

**Files cần copy:**
```
archive/AI/knowledge_base/
├── chunks/chunks.jsonl          # 78+ chunks đã processed
├── embeddings/local/          # Pre-computed embeddings
└── raw/                       # Source data (FAQ, menu, etc.)
```

**Tasks:**
1. Copy thư mục vào ai-service
2. Cập nhật .gitignore (không commit embeddings nếu quá lớn)
3. Tạo module `knowledge_base/loader.py` để load data

### C2.2: Kiểm tra Chunking & Retrieval

**Tasks:**
1. Validate `chunks.jsonl` structure
2. Kiểm tra BM25 index có sẵn chưa
3. Nếu chưa có embeddings, sử dụng Gemini embeddings API

### C2.3: Hybrid Search Implementation

**Module:** `ai-service/core/retrieval.py`

**BM25 Component:**
```python
class BM25Retriever:
    def __init__(self, chunks: list)
    def get_search_keywords(self, query: str) -> list[str]
    def rank(self, query: str, k: int = 5) -> list[Chunk]
```

**Vector Component:**
```python
class VectorRetriever:
    def __init__(self, chunks: list, embeddings: list)
    async def embed(self, text: str) -> list[float]
    def search(self, query_vector: list[float], k: int = 5) -> list[Chunk]
```

**Fusion:**
```python
class HybridRetriever:
    def __init__(self, bm25: BM25Retriever, vector: VectorRetriever)
    async def retrieve(self, query: str, k: int = 5) -> list[RetrievedChunk]
```

### C2.4: RAG Prompt Integration

**Update System Prompt:**
```
Bạn là trợ lý ảo của nhà hàng Mì Cay Hàn Quốc K-Spicy.

THÔNG TIN NHÀ HÀNG:
{restaurant_context}

CÁC CÂU HỎI THƯỜNG GẶP:
{faq_context}

THỰC ĐƠN:
{menu_context}

NGUYÊN TẮC TRẢ LỜI:
1. Dựa trên thông tin được cung cấp ở trên để trả lời
2. Nếu không có thông tin trong context, nói rõ: "Mình chưa có thông tin này"
3. Không bịa đặt giá, địa chỉ, giờ mở cửa
4. Tham chiếu nguồn khi có thể: (theo FAQ, theo thực đơn...)
```

### C2.5: Grounding & Citation

**Citation Format:**
```
"Mì cay bò lớn giá 89.000đ [1]"
[1] - Thực đơn: Mì cay bò lớn
```

**Anti-Hallucination:**
1. Thêm instruction trong prompt
2. Sử dụng `finish_reason` từ Gemini
3. Đánh dấu "needs_verification" cho thông tin không có trong KB

### C2.6: Evaluation

**Test Cases:**
1. Query có kết quả trong KB → Kiểm tra reply chính xác
2. Query không có trong KB → Kiểm tra "không biết" response
3. Mixed query → Kiểm tra hybrid search quality

---

## 4. Cấu trúc file mới

```
ai-service/
├── knowledge_base/           # NEW: Copied from archive
│   ├── chunks/
│   │   └── chunks.jsonl
│   ├── embeddings/
│   │   └── local/
│   └── raw/
│       ├── faq.jsonl
│       ├── menu.jsonl
│       └── restaurant.jsonl
├── core/
│   ├── config.py            # EXISTING
│   ├── gemini_provider.py   # EXISTING
│   └── retrieval.py         # NEW: Hybrid search
├── services/
│   ├── chat_service.py      # UPDATE: Add RAG
│   └── rag_service.py        # NEW: RAG orchestration
├── schemas/
│   └── schemas.py           # UPDATE: Add retrieval schemas
└── tests/
    ├── test_retrieval.py    # NEW
    └── test_rag.py          # NEW
```

---

## 5. Dependencies mới

```txt
# Add to requirements.txt
rank-bm25>=0.2.2          # BM25 implementation
numpy>=1.24.0            # Vector operations
scikit-learn>=1.3.0      # For similarity calculations
```

---

## 6. API Changes

### POST /api/v1/chat (Update)

**Request:** (giữ nguyên)
```json
{
  "session_id": "sess_abc",
  "message": "Mì cay giá bao nhiêu?"
}
```

**Response:** (thêm fields)
```json
{
  "request_id": "req_xyz",
  "session_id": "sess_abc",
  "intent": "get_price",
  "reply": "Mì cay bò nhỏ 69K, lớn 89K...",
  "provider": "gemini",
  "model": "gemini-2.5-flash",
  "latency_ms": 1500,
  "retrieval": {
    "chunks_used": 3,
    "sources": ["faq", "menu"]
  },
  "citations": [
    {"id": 1, "text": "Mì cay bò lớn", "source": "menu"}
  ],
  "timestamp": "2026-10-20T18:00:00Z"
}
```

---

## 7. Kế hoạch thực hiện

| Bước | Thời gian ước tính | Deliverable |
|------|---------------------|-------------|
| C2.1 | 30 phút | Knowledge base loaded |
| C2.2 | 30 phút | Chunk validation report |
| C2.3 | 2 giờ | Hybrid search working |
| C2.4 | 1 giờ | RAG integrated |
| C2.5 | 1 giờ | Citations in response |
| C2.6 | 1 giờ | Tests & evaluation |
| **Tổng** | **~6 giờ** | |

---

## 8. Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Embeddings quá lớn | Lazy load, cache |
| Gemini embeddings API timeout | Retry with exponential backoff |
| Quality regression | A/B test với baseline |
| Vietnamese tokenization | Sử dụng pre-built Vietnamese tokenizer |

---

## 9. Questions for User

1. Sử dụng pre-computed embeddings từ archive hay tính lại bằng Gemini?
2. Có cần caching layer (Redis) không?
3. Giới hạn chunks per query là bao nhiêu (recommend: 5)?
4. Citation format có phù hợp không?

---

*Phase C2 Planning Document*
