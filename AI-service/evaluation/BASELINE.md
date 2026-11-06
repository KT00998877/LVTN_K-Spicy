# Baseline đánh giá chatbot demo

Chạy từ thư mục `AI-SERVICE-DEMO`:

```powershell
python evaluation/run_baseline.py
```

Script chỉ đọc mã và dữ liệu trong bản demo. Không gọi Gemini, Laravel hay ghi dữ liệu nghiệp vụ. Cần cài các gói trong `requirements.txt`, gồm `sentence-transformers` cho vector search. Kết quả hiện tại ghi vào `improved_report.json`.

## Phạm vi và cách chấm

- `baseline_cases.json`: 20 câu hỏi truy xuất với ID chunk đúng được gán thủ công; 15 câu intent với nhãn mong đợi. Đây là tập mẫu khởi đầu, cần rà soát và mở rộng độc lập trước khi dùng để quyết định phát hành.
- RAG chỉ chấm trên 38 chunk tĩnh mà `KnowledgeBaseLoader.load_chunks()` thực sự nạp. Các dữ liệu thực đơn, giá và voucher do công cụ nghiệp vụ cung cấp không được dùng làm đáp án RAG.
- Hit@5: tỷ lệ câu có ít nhất một chunk đúng trong 5 kết quả đầu. Recall@5: trung bình tỷ lệ chunk đúng tìm thấy trên mỗi câu. MRR: trung bình nghịch đảo vị trí chunk đúng đầu tiên; bằng 0 nếu không tìm thấy.
- Độ trễ trong JSON là thời gian xử lý truy xuất trên máy chạy script, chưa gồm mạng, Gemini, Laravel hay dựng câu trả lời.
- Intent accuracy chấm `detect_intent()` đang dùng trong luồng `process_with_tools`, không chấm `IntentClassifier` riêng.

## Mốc ban đầu, K=5

| Phương pháp | Hit@5 | Recall@5 | MRR |
| --- | ---: | ---: | ---: |
| BM25 | 0,950 | 0,925 | 0,892 |
| Vector hiện tại | 0,850 | 0,825 | 0,642 |
| Hybrid RRF | 0,950 | 0,925 | 0,750 |

Intent đúng 14/15 câu (0,933). Ca `i11` là phủ định nhưng bị nhận thành `add_to_cart`. Câu `r02` về phục vụ cuối tuần không tìm được chunk đúng trong top 5 ở cả ba phương pháp. `baseline_report.json` giữ số liệu tổng hợp trước cải tiến; kết quả từng câu của lần chạy cũ không còn được lưu.

**Chưa đo:** độ đúng câu trả lời, tính đúng đắn và quyền hạn khi gọi công cụ, lỗi Gemini, tỷ lệ fallback, độ trễ đầu cuối. Những mục này cần bộ ca tích hợp và môi trường kiểm thử phù hợp; không suy ra từ số liệu truy xuất ngoại tuyến.

## Bước kế tiếp

Rà soát nhãn với người hiểu nghiệp vụ, thêm câu diễn đạt tự nhiên và câu khó. Sau đó mã hóa trực tiếp câu hỏi bằng cùng mô hình embedding của chunk, chạy lại script trên **chính tập câu hỏi này**, và so sánh từng ca trước khi thay đổi luồng đang hoạt động.
