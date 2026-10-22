import asyncio
import unittest

from core.laravel_client import ToolResult
from services.chat_service import BusinessToolService, is_high_spice_preference


class MenuExecutor:
    def __init__(self):
        self.arguments = None

    async def execute(self, *, tool_name, arguments, user_id, user_token):
        assert tool_name == "menu_lookup"
        self.arguments = arguments
        return ToolResult(
            tool=tool_name,
            success=True,
            data={"data": [
                {"name": "Mì cay bò nhỏ", "spicy_level": 0, "is_available": True},
                {"name": "Mì cay bò lớn", "spicy_level": 0, "is_available": True},
                {"name": "Mì cay gà nhỏ", "spicy_level": 0, "is_available": True},
            ]},
        )


class SpicyPreferenceTest(unittest.TestCase):
    def test_spicy_preference_uses_menu_without_default_level_filter(self):
        executor = MenuExecutor()
        service = BusinessToolService(gemini=object(), tool_registry=object(), tool_executor=executor)

        result = asyncio.run(service.process_with_tools("tôi ăn cay nhiều lắm"))

        self.assertEqual(executor.arguments, {"category": "mi-cay-han-quoc", "per_page": 20})
        self.assertIn("Mì cay bò nhỏ", result["reply"])
        self.assertIn("Mì cay gà nhỏ", result["reply"])
        self.assertNotIn("Mì cay bò lớn", result["reply"])
        self.assertIn("cấp 7", result["reply"])
        self.assertNotIn("Không có món nào phù hợp", result["reply"])

    def test_negative_preference_is_not_treated_as_high_spice(self):
        self.assertFalse(is_high_spice_preference("tui ăn cay hông được"))
        self.assertFalse(is_high_spice_preference("không ăn cay nhiều"))
        self.assertTrue(is_high_spice_preference("tôi ăn cay nhiều lắm"))


if __name__ == "__main__":
    unittest.main()
