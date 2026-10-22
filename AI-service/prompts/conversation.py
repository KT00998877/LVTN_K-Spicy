"""
Conversation history prompt helpers.
"""

def build_conversation_turn(role: str, content: str) -> str:
    """Build a single conversation turn."""
    role_label = "Khách" if role == "user" else "Trợ lý"
    return f"{role_label}: {content}"


def format_conversation_history(messages: list[dict]) -> str:
    """
    Format conversation history for multi-turn context.
    
    Args:
        messages: List of {"role": "user"|"assistant", "content": str}
    
    Returns:
        Formatted conversation history string
    """
    if not messages:
        return ""
    
    turns = []
    for msg in messages[-10:]:  # Keep last 10 turns for context
        role = msg.get("role", "user")
        content = msg.get("content", "")
        if content:
            turns.append(build_conversation_turn(role, content))
    
    if not turns:
        return ""
    
    return "\n\nLịch sử hội thoại gần đây:\n" + "\n".join(turns)


CONVERSATION_CONTEXT_TEMPLATE = """
Lịch sử hội thoại:
{history}

Câu hỏi hiện tại: {query}
"""
