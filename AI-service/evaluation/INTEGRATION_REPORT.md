# Kiểm thử tích hợp AI-SERVICE-DEMO

Ngày chạy: 03/11/2026. Bản cũ `AI-service` được giữ nguyên. Demo chạy riêng trên `127.0.0.1:8003`, Laravel thử nghiệm trên `127.0.0.1:8000`. Không đọc hoặc in nội dung `.env`; script chỉ nạp cấu hình qua ứng dụng để gửi khóa nội bộ trong HTTP header.

## Kiểm thử tự động

`python -m pytest tests -q`: **12/12 ca đạt**. Phạm vi gồm xác thực API, hợp đồng phản hồi, lỗi 503 có kiểm soát, ánh xạ kết quả Laravel bằng mock, kiểm tra quyền, tham số, xác nhận thao tác ghi, công cụ không được cung cấp, vector câu hỏi, intent và metrics. Hai cảnh báo thư viện/deprecation không làm ca kiểm thử thất bại.

## Kiểm thử thật chỉ đọc

Chạy `python evaluation/smoke_live_readonly.py` với demo và Laravel đang hoạt động. Lần đầu Gemini gặp `ConnectError` do tiến trình thử nghiệm không có kết nối HTTPS ra ngoài; sau khi chạy demo trong môi trường có kết nối, các lượt sau đạt:

| Ca | Kết quả |
| --- | --- |
| Health / ready | `200`; cấu hình Gemini và xác thực nội bộ đều hiện diện. |
| Chat không có khóa nội bộ | `401`. |
| Hỏi giờ mở cửa qua RAG + Gemini | `200`, intent `get_info`, trả 10:00–22:00 mỗi ngày theo chunk FAQ. Sau khi phát hiện nhầm “giờ” với “giỏ”, đã sửa và chạy lại. |
| Hỏi voucher qua Gemini + Laravel | `200`, gọi `promotion_list`, không yêu cầu xác nhận hoặc gọi công cụ ghi. |
| Hỏi giá Mì cay bò nhỏ | `200`, gọi `menu_lookup`, trả 69.000đ; đối chiếu API Laravel cho cùng món cũng là 69.000đ. |
| Xem giỏ khi chưa đăng nhập | `200`, yêu cầu đăng nhập, không trả dữ liệu giỏ. |
| Metrics nội bộ | `200`, đếm lượt chat và công cụ; không chứa nội dung tin nhắn hoặc token. |

Ở lần chạy đầu của tiến trình sau khởi động, metrics ghi p95 khoảng 23,7 giây do nạp mô hình embedding. Khi mô hình đã nạp, các lượt thử tiếp theo nhanh hơn, nhưng tập 6 lượt quá nhỏ để kết luận p95 vận hành. Trường `latency_ms` trong phản hồi chủ yếu phản ánh thời gian Gemini; endpoint metrics đo toàn bộ lượt ở API.

## Chưa được chứng minh

- Chưa chạy luồng xác nhận/thực thi thao tác ghi đầu cuối bằng tài khoản thử nghiệm. Kiểm thử mock xác nhận công cụ ghi không tự gọi Laravel, nhưng chưa kiểm tra thẻ xác nhận và thao tác sau khi người dùng chấp thuận.
- Chưa có tập câu trả lời thực đủ lớn được người đánh giá chấm đúng/sai; chưa có tỷ lệ lỗi/fallback trong thời gian vận hành dài.
- Chưa kiểm tra nhiều worker, khởi động lại, hoặc thời gian nạp mô hình trên máy triển khai khác.

Bản demo đã vượt qua các phép thử chỉ đọc trọng yếu, nhưng vẫn cần các mục trên trước khi thay dịch vụ cũ.

## Bổ sung: hotline và địa chỉ từ giao diện

Giao diện luôn gửi `use_tools=true`. Trước khi sửa, intent `get_info` đi qua luồng công cụ với RAG tắt, nên câu “số điện thoại là số mấy” và “địa chỉ” nhận câu trả lời thiếu dữ liệu dù các chunk FAQ đã có thông tin. Demo hiện đưa `get_info` vào luồng RAG, còn câu giá tiếp tục dùng công cụ. Kiểm thử tự động đạt **14/14**; kiểm thử thật với Gemini và `use_tools=true` trả `200`, intent `get_info`, chứa hotline **0901 234 567** và địa chỉ **Khu II, đường 3/2, phường Ninh Kiều, thành phố Cần Thơ**. Script chạy lại: `python evaluation/check_static_info_live.py`.

Ảnh giao diện sau đó cho thấy “dia chi” không dấu vẫn thất bại, còn “địa chỉ quán” thành công. Nguyên nhân có hai lớp: bộ nhận diện intent không chuẩn hóa các cụm thông tin chung và truy vấn RAG quá ngắn/viết tắt không tìm đúng chunk. Đã bổ sung nhận diện `dia chi`/`sdt` và mở rộng truy vấn tra cứu cho hai dạng ngắn này mà không đổi nội dung người dùng. Lần kiểm thử thật cuối cùng với bốn câu độc lập **“số điện thoại quán”, “sdt”, “địa chỉ quán”, “dia chi”** đều trả `200`, intent `get_info`, và chứa dữ kiện đúng trong FAQ.
