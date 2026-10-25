# Báo cáo rà soát bảo mật và chức năng dự án

Ngày rà soát: 24/10/2026  
Phạm vi: `back-end` (Laravel), `front-end` (Vue), `mobile` (Flutter), `AI-service` (FastAPI).  
Phương pháp: review tĩnh source code, route/middleware, luồng dữ liệu đầu vào - đầu ra và chạy test hiện có. Đây không phải pentest động trên môi trường production.

## Trạng thái thực hiện kế hoạch (24/10/2026)

| Hạng mục | Trạng thái |
|---|---|
| SEC-01 stored XSS | Đã thay regex bằng HTML Purifier allowlist, thêm lệnh sanitize dữ liệu cũ và regression test payload XSS. |
| CORS và security headers | Đã giới hạn origin/header qua biến môi trường, thêm CSP và các security header cho API; frontend có CSP meta làm lớp bảo vệ bổ sung. |
| Token web / CSRF | Giữ Bearer token để không chuyển nửa chừng sang cookie Sanctum; đã thêm contract test xác nhận API yêu cầu Bearer và không phụ thuộc CSRF cookie. |
| Test SQL injection | Đã thêm payload test cho search và sort whitelist, đồng thời kiểm tra bảng dữ liệu không bị tác động. |
| Hủy đơn trên web | Đã bổ sung API client, store và thao tác hủy có điều kiện/trạng thái lỗi trên trang chi tiết đơn. |
| Khách tự quản lý đặt bàn | Đã bổ sung danh sách, sửa đặt bàn PENDING và hủy đặt bàn thuộc chính khách; web có tab Đặt bàn trong tài khoản. |
| Nhận ưu đãi cá nhân | Đã bổ sung claim idempotent theo tài khoản, tab Ưu đãi và thao tác sao chép mã. |
| 2FA manager | Đã bổ sung TOTP secret mã hóa, challenge đăng nhập ngắn hạn, chống dùng lại mã, API bật/tắt và trang cài đặt cho manager. |
| Google login | Chưa triển khai: cần chốt quy tắc liên kết khi Google trả về email đã tồn tại và cung cấp Client ID/Secret/Redirect URI. |

Các kết quả test ban đầu ở mục 5 là số liệu tại thời điểm audit; kết quả sau triển khai được ghi nhận khi hoàn tất kiểm chứng ở cuối tài liệu.

## 1. Kết luận ngắn

| Hạng mục | Kết quả |
|---|---|
| XSS | **Chưa an toàn hoàn toàn. Có 1 stored XSS mức Cao trong nội dung blog.** |
| SQL injection | **Chưa tìm thấy đường khai thác trực tiếp** trong code hiện tại. Query Builder/Eloquent và whitelist đang được dùng đúng ở các điểm đã rà soát. |
| CSRF | **Không thấy lỗ hổng CSRF trong mô hình hiện tại** vì SPA dùng Bearer token, không dùng cookie credential tự động. Cần đổi thiết kế nếu sau này chuyển sang cookie-based Sanctum. |
| Chức năng | Hệ thống đã khá đầy đủ, nhưng web thiếu hủy đơn; khách chưa tự quản lý đặt bàn; luồng nhận ưu đãi và Google login vẫn chưa hoàn tất theo đặc tả. |

## 2. Phát hiện bảo mật

### SEC-01 - Stored XSS trong bài viết blog (Cao)

Luồng gây lỗi:

1. Manager tạo/sửa bài viết; controller gọi sanitizer tại `back-end/app/Http/Controllers/Api/ManagerBlogController.php:101` và `:144`.
2. `BlogService::sanitizeHtml()` dùng `strip_tags` và regex tại `back-end/app/Services/BlogService.php:59-70`.
3. Regex chỉ loại event handler và `javascript:` khi attribute có dấu nháy và đúng mẫu đơn giản.
4. Frontend render trực tiếp nội dung bằng `v-html` tại `front-end/src/views/CustomerContentView.vue:121`; `safeContent` tại dòng 265 không sanitize thêm.

Đã kiểm chứng trực tiếp hàm hiện tại: các chuỗi sau vẫn được trả về nguyên vẹn:

```html
<img src=x onerror=alert(1)>
<a href=javascript:alert(1)>x</a>
<a href="java&#x73;cript:alert(1)">x</a>
<img src="x" onerror = alert(1)>
```

Tác động: mã JavaScript có thể chạy trên trang bài viết công khai. Hậu quả tăng mạnh vì token customer/staff được lưu trong `localStorage` (`front-end/src/api/client.ts:49-58`, `front-end/src/stores/auth.ts:81-84`), có thể dẫn tới chiếm phiên và gọi API với quyền của nạn nhân.

Khuyến nghị:

- Thay regex tự viết bằng thư viện allowlist HTML đã được kiểm chứng, ví dụ HTML Purifier phía Laravel; whitelist cả tag, attribute và protocol (`http`, `https`).
- Sanitize lại dữ liệu blog đã lưu, không chỉ dữ liệu tạo mới.
- Thêm test với attribute không dấu nháy, khoảng trắng, HTML entity, URL scheme viết hoa/control character và malformed HTML.
- Bổ sung Content Security Policy để giảm tác động nếu một XSS khác xuất hiện.

### SEC-02 - Token trình duyệt làm tăng tác động XSS (Trung bình)

Bearer token được lưu lâu dài trong `localStorage`. Đây không tự tạo ra XSS/CSRF, nhưng bất kỳ JavaScript độc hại cùng origin nào cũng đọc được token, đặc biệt nguy hiểm với token manager.

Khuyến nghị ưu tiên sau khi sửa SEC-01: cân nhắc Sanctum cookie `HttpOnly`, `Secure`, `SameSite` cho web; khi đó phải bật đầy đủ stateful middleware và CSRF/XSRF flow. Mobile đang dùng secure storage nên phù hợp hơn cho token thiết bị.

### SEC-03 - CORS và security header còn rộng (Thấp, hardening)

`back-end/config/cors.php:22-32` cho phép mọi origin/header, dù `supports_credentials=false`. Cấu hình này chưa tạo CSRF với Bearer token hiện tại, nhưng làm bề mặt API rộng hơn cần thiết. Chưa thấy cấu hình CSP/security headers trong source.

Khuyến nghị: giới hạn origin theo biến môi trường production; thêm CSP, `X-Content-Type-Options`, `Referrer-Policy` và `frame-ancestors`/`X-Frame-Options` ở reverse proxy hoặc middleware.

## 3. Đánh giá SQL injection và CSRF

### SQL injection

Không tìm thấy nối trực tiếp input người dùng vào câu SQL. Điểm sort public đã whitelist tại `back-end/app/Services/MenuService.php:75-84`. Các `selectRaw` động trong `StatisticsService` chỉ nhận biểu thức cố định được chọn theo DB driver (`back-end/app/Services/StatisticsService.php:341-374`, `:858-901`); `ReviewService` dùng binding tại `back-end/app/Services/ReviewService.php:247`.

Kết luận này là “không tìm thấy qua review tĩnh”, không phải cam kết không thể có SQL injection. Nên thêm test payload cho search/sort/filter và tiếp tục cấm đưa trực tiếp request vào `*Raw()`.

### CSRF

Frontend tự gắn `Authorization: Bearer ...` tại `front-end/src/api/client.ts:54-59`; CORS không gửi credential cookie. Do browser không tự đính Bearer token vào request từ website khác, các API thay đổi dữ liệu hiện không phụ thuộc CSRF token là phù hợp với kiến trúc hiện tại.

Nếu chuyển web sang cookie `HttpOnly`, phải đồng thời dùng Sanctum stateful API, `/sanctum/csrf-cookie`, `withCredentials/withXSRFToken`, giới hạn CORS origin và thêm test request thiếu/sai XSRF token. Không được chuyển nửa chừng vì lúc đó kết luận CSRF ở trên không còn đúng.

## 4. Chức năng còn thiếu hoặc nên phát triển

Ưu tiên đề xuất:

1. **Đồng bộ chức năng hủy đơn lên web.** Backend đã có route tại `back-end/routes/api.php:471`, mobile đã gọi tại `mobile/lib/features/orders/data/order_repository.dart:108-120`, nhưng `front-end/src/api/orders.ts:13-20` và trang chi tiết web chưa có thao tác này.
2. **Khách tự quản lý đặt bàn.** Hiện customer chỉ có `GET /reservations/options` và `POST /reservations` (`back-end/routes/api.php:3-4`); chưa có danh sách lịch của tôi, đổi giờ/số khách hoặc hủy. `AccountView` cũng chưa có tab reservation (`front-end/src/views/auth/AccountView.vue:25`).
3. **Hoàn tất luồng nhận ưu đãi cá nhân.** Đặc tả yêu cầu `POST /api/promotions/{id}/claim` tại `yeucau.md:87`, nhưng hiện chỉ có featured promotions (`back-end/routes/api.php:35`). Tab “Ưu đãi” đã nêu trong đặc tả cũng chưa có trong `AccountView`.
4. **Đăng nhập Google.** Đây là hạng mục đã chủ động để Phase cuối tại `yeucau.md:186-194`; trước khi làm cần chốt quy tắc liên kết email đã tồn tại để tránh chiếm tài khoản.
5. **Bảo mật tài khoản quản lý.** Nên thêm 2FA cho manager, danh sách/revoke phiên đăng nhập và cảnh báo đăng nhập bất thường. Giá trị của hạng mục này cao vì manager quản lý nội dung HTML, tài chính, tài khoản và kho.

## 5. Kiểm chứng và giới hạn

- Backend: `php artisan test` đạt **117/117 test, 342 assertions**.
- Frontend: `npm.cmd run type-check` đạt.
- Frontend Node tests: **53/54 đạt**; test `home keeps its hero rendered...` lỗi vì test harness chưa mock import mới `PromoBanner.vue`.
- AI-service pytest không hoàn tất do lỗi capture của môi trường chạy (`ValueError: I/O operation on closed file`), không đủ dữ liệu để kết luận test pass/fail.
- Flutter test không chạy được trong sandbox hiện tại.
- Chưa thực hiện DAST bằng browser/proxy, scan dependency/CVE, kiểm tra hạ tầng TLS/reverse proxy hoặc kiểm thử trên dữ liệu production.

## 6. Thứ tự xử lý đề xuất

1. Sửa SEC-01, sanitize lại blog cũ và thêm regression test XSS trước khi deploy.
2. Siết CORS, thêm CSP/security headers; quyết định chiến lược lưu token web.
3. Thêm test bảo mật cho XSS, SQLi payload và contract auth/CSRF.
4. Hoàn thiện web hủy đơn và self-service đặt bàn.
5. Hoàn thiện claim ưu đãi, Google login và 2FA manager.
