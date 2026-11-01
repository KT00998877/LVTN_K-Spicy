import unittest
from unittest.mock import AsyncMock, patch

from api.chat import chat
from schemas import ChatRequest
from services.chat_service import detect_intent


class ReservationReplyTest(unittest.IsolatedAsyncioTestCase):
    async def test_booking_reply_uses_page_label_instead_of_raw_path(self):
        request = ChatRequest(session_id="booking-test", message="Đặt bàn như thế nào?")
        answer = {
            "intent": "reservation_info",
            "reply": "Bạn đặt bàn tại mục /dat-ban hoặc /datban trong khung chat này.",
        }
        with patch("api.chat._process_chat", new=AsyncMock(return_value=answer)):
            response = await chat(request, _token="test")

        self.assertNotIn("/dat-ban", response.reply)
        self.assertNotIn("/datban", response.reply)
        self.assertIn("mục Đặt bàn", response.reply)

    def test_booking_question_is_not_a_booking_request(self):
        self.assertEqual(detect_intent("Đặt bàn như thế nào?"), "reservation_info")
        self.assertEqual(detect_intent("Tôi muốn đặt bàn cho 4 người"), "make_reservation")


if __name__ == "__main__":
    unittest.main()
