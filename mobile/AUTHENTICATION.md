# Xác thực mobile

Mobile dùng chung Laravel API và bảng `users` với Vue. Không cần migration, database hoặc controller riêng. Backend và Vue không thay đổi trong lần triển khai này.

## API đang sử dụng

| Chức năng | API | Dữ liệu |
|---|---|---|
| Đăng nhập | POST `/api/auth/login` | `identifier` (email/số điện thoại), `password` |
| Đăng ký | POST `/api/auth/register` | `name`, `email`, `password`, `password_confirmation`, `phone` tùy chọn |
| Phiên khách hàng | GET `/api/auth/me` | Bearer token; user nằm trong `data` |
| Đăng xuất | POST `/api/auth/logout` | Thu hồi token hiện tại |
| Nhân viên | `/api/staff/auth/login`, `/me`, `/logout` | Giữ API nhân viên hiện có; role nằm trong `data.roles`/`primary_role` |

Backend kiểm tra email/điện thoại trùng. Mật khẩu đăng ký tối thiểu 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt. Không có trường username trong hợp đồng hiện tại.

## Tổ chức mã

- `lib/core/api/dio_client.dart`: cấu hình Dio, Bearer token, chuẩn hóa lỗi và thông báo phiên hết hạn.
- `lib/features/auth/data/auth_repository.dart`: gọi API, đọc response thực tế của Laravel.
- `lib/features/auth/domain/app_user.dart`: thông tin tài khoản và role.
- `lib/features/auth/presentation/auth_controller.dart`: Riverpod state, secure storage, đăng nhập/đăng ký/đăng xuất và khôi phục phiên.
- `lib/features/auth/presentation/login_screen.dart`: biểu mẫu đăng nhập và đăng ký dùng chung; route `/register` bật chế độ đăng ký.
- `lib/app/router.dart`: bảo vệ route, màn chờ/thử lại, điều hướng khách hàng hoặc Shipper. Vai trò nhân viên khác hiển thị hướng dẫn dùng web.
- `lib/app/app.dart`: thông báo lỗi đăng xuất.
- `test/auth_repository_test.dart`, `test/auth_controller_test.dart`: kiểm tra hợp đồng API và vòng đời phiên bằng dữ liệu giả trong test.

Đăng ký thành công tự đăng nhập bằng token server trả về. Khi mở app, token được kiểm tra qua `/me` trước khi vào màn chính. Lỗi mạng giữ token và cho thử lại; 401 xóa phiên. Đăng xuất chỉ xóa phiên cục bộ sau khi API thành công hoặc token đã vô hiệu; nếu mất mạng sẽ hiển thị lỗi và cho thử lại.

## Chạy ứng dụng

Khởi động Laravel trong `back-end`:

```sh
php artisan serve --host=0.0.0.0 --port=8000
```

Sửa duy nhất `BASE_URL` trong `mobile/.env` (bản hiện tại cấu hình cho Chrome).
Nếu mới clone dự án, sao chép `.env.example` thành `.env`.

| Môi trường | BASE_URL |
|---|---|
| Chrome cùng máy Laravel | `http://127.0.0.1:8000/api` |
| Android Emulator | `http://10.0.2.2:8000/api` |
| Điện thoại thật | `http://<IP-LAN-máy-chạy-Laravel>:8000/api` |

API và ảnh cùng dùng địa chỉ này. Điện thoại thật và máy chạy Laravel cần kết nối được với nhau qua mạng LAN. Chạy Chrome:

```sh
flutter run -d chrome
```

Sau khi sửa `.env`, dừng app và chạy lại. Không cần `--dart-define`; cấu hình cũ `API_BASE_URL` không còn được sử dụng. Chạy trên thiết bị đã kết nối và kiểm tra:

```sh
flutter run
flutter analyze --no-pub
flutter test --no-pub
```

Bản phát hành dùng địa chỉ HTTPS của máy chủ. Không đặt thông tin MySQL hay mật khẩu vào Flutter.

## Kiểm tra tích hợp thực tế

Test tự động không thay thế kiểm thử Laravel/MySQL và thiết bị: cần đăng ký một email mới, thử email/điện thoại trùng, đăng nhập sai/đúng, đóng mở ứng dụng, đăng xuất rồi xác nhận token cũ trả 401, và dùng tài khoản vừa tạo đăng nhập Vue. Kiểm tra cả tài khoản Shipper. Không dùng tài khoản production cho các thử nghiệm tạo dữ liệu.
