# Mobile redesign: kiểm kê và quyết định nền tảng

## Phạm vi kiểm kê

Đối chiếu từ mã nguồn ngày 01/11/2026; chưa chụp ảnh chạy thực tế trên thiết bị. Nguồn web: `front-end/src/assets/main.css`, `front-end/src/views/HomeView.vue` và `DESIGN_SYSTEM.md`. Nguồn mobile: `lib/app/theme.dart`, `lib/features/customer/customer_home_screen.dart`, các màn trong `lib/features`.

| Luồng | Mobile hiện có | Mốc web / quyết định |
|---|---|---|
| Đăng nhập | Đăng nhập và đăng ký chung màn | Giữ luồng; áp dụng token và form ở giai đoạn 6 |
| Trang chủ | Hero, danh mục, món nổi bật, combo, cấp cay, menu, câu chuyện, đặt bàn | Giữ thứ tự nội dung đang gần web; tinh chỉnh bố cục ở giai đoạn 5 |
| Menu và món | Tìm kiếm, lọc, chi tiết, thêm nhanh | Giữ chức năng; chuẩn hóa thẻ món và bộ lọc ở giai đoạn 5 |
| Giỏ, thanh toán | Giỏ, checkout, thanh toán VNPay | Giữ trạng thái và hợp đồng API; làm rõ thứ bậc thao tác ở giai đoạn 5 |
| Đơn hàng | Danh sách, chi tiết, thành công | Giữ truy cập từ thanh dưới; chuẩn hóa trạng thái ở giai đoạn 5 |
| Đặt bàn | CTA trang chủ, màn đặt và lịch sử đặt bàn | CTA trang chủ mở biểu mẫu; lịch sử có lối vào từ Tài khoản |
| Tài khoản | Hồ sơ, hạng/điểm, đánh giá, bảo mật | Giữ các tab nội bộ; thêm lối vào lịch sử đặt bàn |
| Trợ lý và tư vấn | Hai nút nổi trên mọi tab | Gộp thành một nút Hỗ trợ, mở bottom sheet có hai lựa chọn |
| Shipper | Màn vận hành riêng | Giữ cấu trúc; redesign sau luồng khách hàng |

## Cập nhật trang chủ

Section chọn cấp cay đã được thay bằng banner ưu đãi nổi bật của web. Trang chủ còn hiển thị combo món và nước từ `GET /api/home`, voucher hiệu lực từ `GET /api/vouchers?active=1`, và tiêu đề câu chuyện K-SPICY theo web. Khi API không có combo hoặc ưu đãi, section tương ứng được ẩn; không dựng nội dung khuyến mãi giả.

## Vấn đề và thay đổi của giai đoạn 1–4

1. Mobile dùng đỏ `#D7263D`, nền `#FAF6EE`, chữ `#1A1A1A`; web dùng đỏ hành động `#D71920`, nền `#FFF7F5`, chữ `#241516`. Ánh xạ lại token mobile theo web trong `lib/app/theme.dart`.
2. Thanh điều hướng khách có sáu mục. Giữ năm mục Trang chủ, Menu, Giỏ, Đơn, Tài khoản; Đặt bàn vẫn có CTA trang chủ và lịch sử đặt bàn có nút ở Tài khoản.
3. Hai nút chat nổi chiếm nhiều diện tích. Gộp thành một nút Hỗ trợ và bottom sheet để giữ cả hai chức năng.
4. Bộ chọn cấp cay và nút tăng giảm số lượng có vùng chạm nhỏ. Đặt mức tối thiểu 48dp; bộ chọn cấp cay có ngữ nghĩa được chọn.

## Quy ước triển khai tiếp

- Web là nguồn màu và nhận diện; mobile giữ mẫu tương tác Flutter/Material 3.
- Ưu tiên token và component hiện có. Không đổi API, state management hoặc router trong redesign thị giác.
- Với mỗi màn, kiểm tra trạng thái tải, trống, lỗi, cỡ chữ lớn, bàn phím, safe area và vùng chạm.
- Ảnh trước/sau trên thiết bị và kiểm tra luồng thực tế thuộc giai đoạn triển khai các màn (5–8).
