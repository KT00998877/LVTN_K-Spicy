# Cải tiến định tuyến intent trong AI-SERVICE-DEMO

Ngày 03/11/2026. Chỉ mã của bản demo được sửa; `AI-service` hiện tại được giữ nguyên.

## Đã thực hiện

- Tách các vế câu theo liên từ và dấu câu để nhận biết nhiều intent, rồi cấp hợp các công cụ đọc tương ứng. Câu kết hợp thông tin tĩnh và giá dùng cả RAG và công cụ menu.
- Câu phủ định thao tác thêm giỏ được trả lời trực tiếp, không gọi Gemini hay công cụ ghi. Các từ `mua`/`order` đơn lẻ không còn tự động được hiểu là thêm giỏ.
- Chỉ cấp công cụ `cart_add_item` khi yêu cầu thêm giỏ nêu rõ số lượng và tên món. Câu thiếu thông tin được hỏi lại; câu chưa đăng nhập được yêu cầu đăng nhập. Phía Laravel vẫn yêu cầu xác nhận, kiểm tra chủ sở hữu và xác thực lại payload trước khi ghi.
- Khi không có intent đủ rõ từ quy tắc, gọi `IntentClassifier` dùng Gemini để chọn tuyến RAG/công cụ hoặc hỏi lại. Kết quả phân loại từ Gemini không được phép tự mở khóa thao tác ghi. Lời gọi SDK của bộ phân loại chạy trong worker thread.

## Kết quả kiểm thử

- `python -m pytest tests -q`: **24/24 ca đạt**. Có các ca phủ định, câu mơ hồ, nhiều intent, thiếu món/số lượng, chưa đăng nhập, công cụ không được cấp và trạng thái chờ xác nhận.
- Bộ dữ liệu intent 16 câu từ giai đoạn trước: **16/16 đúng** sau thay đổi.
- Kiểm thử thật chỉ đọc với Gemini và Laravel: “Giá mì cay bò nhỏ và địa chỉ quán” trả cả giá và địa chỉ, gọi `menu_lookup`; “Tôi muốn mua gì nhỉ?” gợi ý từ thực đơn, gọi `menu_lookup`; “Không thêm vào giỏ” không gọi công cụ; câu thiếu số lượng hỏi lại; câu thêm đủ thông tin nhưng chưa đăng nhập yêu cầu đăng nhập. Không ca nào tạo yêu cầu xác nhận hoặc ghi giỏ.
- Câu chưa khớp từ khóa “Quán có chỗ đậu xe không?” được Gemini phân tuyến sang RAG và trả thông tin đậu xe từ FAQ, không gọi công cụ nghiệp vụ.

## Giới hạn và bước tiếp theo

- Bộ 16 câu intent và 24 kiểm thử chưa đại diện cho nhiều cách viết sai chính tả, phủ định phức tạp hoặc câu nhiều ý dài. Cần tạo tập gán nhãn độc lập đủ lớn trước khi kết luận tỷ lệ nhận diện.
- Quy tắc hiện yêu cầu số lượng viết bằng chữ số và tên món rõ trong **câu hiện tại**. Câu nối tiếp như “thêm món đó” sẽ được hỏi lại; cần thiết kế giải tham chiếu lịch sử có kiểm tra món và số lượng trước khi cho phép xác nhận.
- Chưa kiểm thử toàn bộ luồng tạo thẻ xác nhận rồi xác nhận ghi giỏ bằng tài khoản thử nghiệm. Đây là cổng kiểm tra trước khi thay dịch vụ cũ.
- Gemini được gọi thêm cho intent chưa rõ. Cần đo tỷ lệ sử dụng nhánh này, độ trễ p50/p95 và tỷ lệ phân tuyến đúng trên tập gán nhãn; chỉ giữ nhánh phân loại nếu lợi ích vượt chi phí và độ trễ.
