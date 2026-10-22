# Giao diện khách hàng mobile

Ứng dụng Flutter dùng các token trong `lib/app/theme.dart`: nền kem, điểm nhấn đỏ cay, chữ than và font Be Vietnam Pro. Trang chủ khách hàng giữ nhịp nội dung của web; các tác vụ mua hàng và đặt bàn dùng màn hình hoặc sheet riêng.

Chatbot là tác vụ của khách hàng, mở từ nút “Trợ lý” trong shell khách hàng. Màn chat dùng AppBar, vùng hội thoại cuộn, câu hỏi gợi ý, ô nhập có nhãn, trạng thái tải/lỗi và thẻ xác nhận thao tác. Nút xác nhận và hủy đều hiển thị; thao tác ghi chỉ chạy sau khi khách bấm xác nhận. Dùng màu và khoảng cách từ `AppColors` và `AppSpacing`; giữ vùng nhập trong `SafeArea` để bàn phím không che điều khiển.

Các màn hình hoặc thành phần khách hàng thêm sau này nên dùng lại token, nhãn tiếng Việt rõ nghĩa và kích thước chạm tối thiểu 48 dp.
