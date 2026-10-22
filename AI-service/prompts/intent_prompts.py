"""
Intent classification prompts.
"""

INTENT_CLASSIFICATION_PROMPT = """Phân loại ý định của khách hàng nhà hàng Mì Cay.

INTENT TAXONOMY:
- greet_user: Chào hỏi, xin chào (chỉ khi câu hỏi chỉ là lời chào, không kèm câu hỏi khác)
- knowledge_question: Câu hỏi về thực đơn chung, địa chỉ, giờ mở cửa, hotline, chính sách
- business_read: Cần dữ liệu nghiệp vụ (giá cụ thể, tồn kho, đơn hàng, giỏ hàng, đặt bàn)
- business_write: Yêu cầu thao tác ghi (thêm vào giỏ, đặt bàn)
- unsupported: Yêu cầu không liên quan đến nhà hàng hoặc không thể hỗ trợ
- unknown: Không rõ

Câu hỏi của khách: "{message}"

Phân loại intent, trích xuất slots, đề xuất tool phù hợp."""

INTENT_SCHEMA = {
    "type": "object",
    "properties": {
        "intent": {
            "type": "string",
            "description": (
                "One of: greet_user, knowledge_question, business_read, "
                "business_write, unsupported, unknown"
            ),
        },
        "confidence": {
            "type": "number",
            "description": "Confidence score 0-1",
        },
        "slots": {
            "type": "object",
            "description": "Extracted entities/slots from message",
        },
        "proposed_tool": {
            "type": "string",
            "description": "Suggested tool name",
        },
        "needs_clarification": {
            "type": "boolean",
            "description": "Whether more info needed from user",
        },
        "clarification_slots": {
            "type": "array",
            "items": {"type": "string"},
            "description": "Which slots are missing",
        },
    },
    "required": ["intent", "confidence", "needs_clarification"],
}
