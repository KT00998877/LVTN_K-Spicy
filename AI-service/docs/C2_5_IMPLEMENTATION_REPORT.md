# Phase C2.5 — Implementation Report

**Ngày:** 2026-10-20  
**Trạng thái:** ✅ C2.5_IMPLEMENTED

---

## 1. Tóm tắt

Phase C2.5 hoàn thành RAG Hardening và Validation cho AI Service.

### 1.1 Modules tạo mới

| Module | File | Mô tả |
|--------|------|--------|
| KB Validator | `knowledge_base/validator.py` | Validation logic |
| Grounding Guard | `core/grounding.py` | Hallucination prevention |
| RAG Evaluator | `evaluation/rag_evaluator.py` | Evaluation framework |

### 1.2 Files cập nhật

| File | Thay đổi |
|------|-----------|
| `services/chat_service.py` | Loại bỏ hardcode data |
| `schemas/schemas.py` | Thêm Citation, RetrievalInfo |
| `api/chat.py` | Enable RAG, pass citations |

---

## 2. Knowledge Base Validation

### 2.1 Validation Results

| Metric | Value |
|--------|-------|
| Total Chunks | 107 |
| Total FAQ | 28 |
| Total Menu | 55 |
| Errors | 0 |
| Warnings | 3 |

### 2.2 Warnings (Expected)

| Chunk | Warning |
|-------|---------|
| `campaign-tan-sinh-vien` | Contains hardcoded address_keywords (OK - campaign data) |
| `policy-reservation-rules` | Contains hardcoded hotline (OK - policy data) |
| `policy-delivery-rules` | Contains hardcoded hours (OK - policy data) |

### 2.3 Validation Logic

- ✅ Duplicate ID check
- ✅ Empty chunk detection
- ✅ Missing metadata check
- ✅ Hardcoded data detection
- ✅ Menu consistency check

---

## 3. Grounding & Citation

### 3.1 Grounding Guard Features

1. **Length Check**: Response must be 3-500 words
2. **Factual Grounding**: Detects price/time/distance claims
3. **Hallucination Pattern Detection**: Uncertain language patterns
4. **Context Relevance**: Keyword overlap check

### 3.2 Citation Validation

- Citation ID presence
- Citation text presence
- Citation source presence
- Citation score presence (recommended)

### 3.3 Anti-Hallucination Measures

```python
# System Prompt - No hardcoded data
NGUYÊN TẮC:
1. Dựa trên THÔNG TIN THAM KHẢO
2. Nếu không có thông tin → "Mình chưa có thông tin này"
3. KHÔNG bịa đặt: giá, địa chỉ, giờ, hotline
```

---

## 4. Retrieval Status

### 4.1 BM25 Implementation

| Metric | Value |
|--------|-------|
| Index | 107 docs, 599 terms |
| Algorithm | BM25 (k1=1.5, b=0.75) |
| Vietnamese Tokenization | ✅ Regex-based |
| Top-K Selection | 5 chunks |

### 4.2 Embeddings Status

| Status | Value |
|--------|-------|
| Archive embeddings folder | Empty |
| Pre-computed embeddings | Not available |
| Vector search | NOT_IMPLEMENTED |

**Reason:** Embeddings folder was empty when checked. No valid pre-computed embeddings found.

---

## 5. Evaluation Dataset

### 5.1 Dataset Overview

| Property | Value |
|----------|-------|
| Total Cases | 25 |
| Format | consultation-only |
| Frozen | Yes (based on v1.0) |
| E2E tools | Excluded |

### 5.2 Domain Distribution

| Domain | Count |
|--------|-------|
| MENU | 5 |
| RESTAURANT_INFO | 7 |
| FOOD_INFO | 3 |
| CART | 3 |
| PROMOTION | 4 |
| LOYALTY | 1 |
| GENERAL | 2 |

---

## 6. System Prompt Changes

### 6.1 Before (Hardcoded)

```
THÔNG TIN NHÀ HÀNG:
- Hotline: 0901 234 567
- Địa chỉ: Khu II, đường 3/2...
```

### 6.2 After (Dynamic from KB)

```
NGUYÊN TẮC:
1. Dựa trên THÔNG TIN THAM KHẢO bên dưới
2. KHÔNG bịa đặt: giá, địa chỉ, giờ, hotline
```

---

## 7. API Response Format

### 7.1 New Fields Added

```json
{
  "retrieval": {
    "chunks_used": 5,
    "sources": ["faq", "menu_item"]
  },
  "citations": [
    {"id": 1, "text": "...", "source": "faq", "score": 4.047}
  ]
}
```

---

## 8. Security

- ✅ No secrets in system prompt
- ✅ No hardcoded API keys
- ✅ No secrets in logs
- ✅ Evaluation uses public data only

---

## 9. Issues Remaining

| Issue | Priority | Notes |
|-------|----------|-------|
| Vector Search | Medium | Not implemented, BM25 only |
| E2E Evaluation | Low | Requires Gemini API calls |

---

## 10. Status

**C2.5_IMPLEMENTED**

All core components are in place:
- ✅ KB Validation
- ✅ Grounding Guards
- ✅ Citation Validation
- ✅ No hardcoded business data
- ✅ Evaluation framework ready

---

*Implementation Report for Phase C2.5*
