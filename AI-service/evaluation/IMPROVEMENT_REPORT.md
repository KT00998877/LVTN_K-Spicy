# Kết quả cải tiến chatbot trong AI-SERVICE-DEMO

Ngày đo: 03/11/2026. Chỉ mã và dữ liệu trong thư mục demo được thay đổi. `AI-service` hiện tại không bị sửa.

## Bước 2: Vector Search

Vector câu hỏi được tạo trực tiếp bằng `intfloat/multilingual-e5-small`, với tiền tố `query: ` và chuẩn hóa vector. File vector chunk trong demo trùng với bản E5 lưu ở `archive/AI/knowledge_base/embeddings/local/embeddings.jsonl` trên 107 ID chung. Truy xuất vector chạy trong worker thread để không chặn event loop. Hybrid giữ BM25 và RRF; khi vector lỗi, hybrid ghi cảnh báo và dùng kết quả BM25.

| Phương pháp | Recall@5 trước → sau | MRR trước → sau |
| --- | ---: | ---: |
| BM25 | 0,925 → 0,925 | 0,892 → 0,892 |
| Vector | 0,825 → 0,925 | 0,642 → 0,925 |
| Hybrid | 0,925 → 0,925 | 0,750 → 0,925 |

Đo trên 20 câu của `baseline_cases.json`, cùng 38 chunk tĩnh. Câu `r02` vẫn không tìm thấy đáp án trong top 5. Lần truy xuất vector đầu tiên mất 13.076–21.516 ms qua các lần chạy do nạp mô hình; 19 lượt tiếp theo trung bình khoảng 32–36 ms trên máy đo. Đây là thời gian truy xuất cục bộ, không phải độ trễ trả lời chatbot. Chi tiết lần chạy mới nhất nằm trong `improved_report.json`.

## Bước 3: Intent Detection

Chặn phân loại thao tác thêm giỏ đối với câu phủ định rõ ràng. Câu nhiều ý định có liên từ không bị nhánh xem giỏ/gợi ý món trả lời sớm; danh sách công cụ được mở đủ cho Gemini xử lý các phần của câu. Lịch sử hội thoại vẫn được chuyển cho Gemini ở nhánh gọi công cụ.

Độ đúng `detect_intent()` trên 15 câu gán nhãn ban đầu: **14/15 → 15/15**. Sau kiểm thử tích hợp, thêm ca “giờ”/“giỏ” và sửa lỗi nhầm intent; tập hiện tại đạt **16/16**. Kết quả này chỉ áp dụng cho tập mẫu nhỏ và không chứng minh chất lượng với mọi cách diễn đạt hoặc hội thoại nhiều lượt.

## Bước 4: Tool Calling

Kiểm tra tham số theo schema trước khi gọi Laravel hoặc tạo thẻ xác nhận; kiểm tra quyền đăng nhập và danh sách công cụ được phép; từ chối công cụ không được cung cấp trong lượt Gemini hiện tại. Kiểm thử mock xác nhận thao tác ghi hợp lệ chỉ trả `CONFIRMATION_REQUIRED`, không gọi Laravel; thao tác thiếu quyền và tham số sai cũng không gọi Laravel. Đọc giỏ có quyền giữ nguyên dữ liệu trả về từ client mock.

Chưa chạy giao dịch thực hoặc kiểm thử phân quyền đầu cuối với Laravel và Gemini. Không coi kiểm thử mock là bằng chứng cho tính đúng đắn của toàn bộ quy trình xác nhận.

## Bước 5: Độ tin cậy và đo lường

Endpoint nội bộ `GET /api/v1/chat/metrics` trả bộ đếm theo tiến trình: lượt chat, phản hồi live API, fallback sau lỗi Gemini, lỗi HTTP, lỗi công cụ, và độ trễ p50/p95 của tối đa 1.000 lượt gần nhất. Endpoint dùng cùng xác thực nội bộ với chat. Bộ đếm không lưu nội dung tin nhắn, token hoặc ID người dùng. Khi tiến trình khởi động lại, số liệu được đặt lại; nhiều worker có bộ đếm riêng.

`review_responses.py` tính tỷ lệ phản hồi đúng, fallback và lỗi Gemini từ file JSONL đã được người đánh giá gán nhãn. Chưa có tập phản hồi thực được chấm, nên **chưa có tỷ lệ đúng hay tỷ lệ lỗi Gemini thực tế**. Không tạo số liệu giả cho hai chỉ số này.

## Kiểm tra đã chạy và việc còn cần trước khi thay dịch vụ

- `python evaluation/run_baseline.py`: chạy thành công với mô hình E5 có sẵn; kết quả trong `improved_report.json`.
- `python -m pytest tests -q`: 12 ca vector, intent, API, quyền/tham số/xác nhận công cụ, danh sách công cụ và metrics chạy thành công. Xem `INTEGRATION_REPORT.md` cho kiểm thử thật chỉ đọc.
- Cần thử nghiệm tích hợp trên bản demo với Gemini và Laravel, gán nhãn câu trả lời thực, đo lỗi/fallback và độ trễ đầu cuối. Đặc biệt kiểm tra thời gian nạp mô hình sau khởi động và ca `r02`.

Không nên thay `AI-service` hiện tại bằng bản demo chỉ dựa trên bộ dữ liệu 20 câu và kiểm thử mock.
