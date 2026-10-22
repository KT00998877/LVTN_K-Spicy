# 📱 Mì Cay — Mobile App (Flutter)

Flutter app cho **Khách hàng** và **Shipper**, dùng chung backend Laravel của dự án LVTN-QLNH.

> Xem toàn bộ lộ trình & thiết kế tại [`../MOBILE_APP_ROADMAP.md`](../MOBILE_APP_ROADMAP.md).

---

## ⚡ Quick Start

```bash
# 1. Cài dependencies
flutter pub get

# 2. Đảm bảo backend Laravel đang chạy
cd ../back-end && php artisan serve   # http://127.0.0.1:8000

# 3. Chạy app (chọn thiết bị đang chạy trước)
flutter devices
flutter run
```

---

## 🌐 Trỏ API đúng thiết bị

Mở `mobile/.env` và sửa `BASE_URL`. Nếu chưa có file, sao chép `.env.example` thành `.env`. Cấu hình áp dụng cho cả API và ảnh; dừng app rồi chạy lại sau khi sửa.

| Thiết bị | URL |
|---|---|
| **Chrome cùng máy Laravel** | `http://127.0.0.1:8000/api` (cấu hình hiện tại) |
| **Android Emulator** | `http://10.0.2.2:8000/api` |
| **iOS Simulator** | `http://127.0.0.1:8000/api` |
| **Điện thoại thật (cùng WiFi)** | `http://<IP-LAN>:8000/api` |

> Ví dụ IP LAN: `192.168.1.10` → `http://192.168.1.10:8000/api`

Xem IP máy Windows: mở `cmd` → `ipconfig` → tìm `IPv4 Address`.

---

## 👤 Tài khoản demo (seed sẵn trong backend)

| Role | Email | Mật khẩu |
|---|---|---|
| **Khách hàng** | `customer@micay.vn` | `password` |
| **Shipper** | `shipper@micay.vn` | `password` |

Bấm **"Dùng tài khoản demo"** trên màn đăng nhập để tự điền.

---

## 🏗 Cấu trúc

```
lib/
├── main.dart                   # Entry: ProviderScope → MicayApp
├── app/
│   ├── app.dart                # MaterialApp + theme + router
│   ├── theme.dart              # Design tokens (Spicy Red + Cream)
│   └── router.dart             # go_router + auth guard theo role
├── core/
│   ├── config.dart             # API URL theo môi trường
│   ├── api/
│   │   ├── dio_client.dart     # Dio + interceptor Bearer + error parser
│   │   └── api_exception.dart  # Exception có cấu trúc (status, errors)
└── features/
    ├── auth/
    │   ├── data/auth_repository.dart       # login customer + login staff
    │   ├── domain/app_user.dart            # UserRole enum + AppUser model
    │   └── presentation/
    │       ├── auth_controller.dart        # Riverpod StateNotifier
    │       └── login_screen.dart           # Login (2 tab: Customer / Shipper)
    ├── customer/customer_home_screen.dart  # Placeholder — sẽ phát triển tiếp
    └── shipper/shipper_home_screen.dart    # Placeholder — sẽ phát triển tiếp
```

---

## 🧪 Test

```bash
flutter analyze       # static analysis (0 issues)
flutter test          # widget test
```

---

## 📦 Build APK

```bash
flutter build apk --release      # → build/app/outputs/flutter-apk/app-release.apk
flutter build appbundle --release # cho Google Play
flutter build ios --release      # cần macOS
```

---

## 🎨 Design system

App tuân thủ [`../DESIGN_SYSTEM.md`](../DESIGN_SYSTEM.md) và rule `.cursor/rules/restaurant-ui.mdc`:

- **Primary**: Spicy Red `#D7263D`
- **Background**: Cream `#FAF6EE` (không dùng `#FFFFFF` thuần)
- **Text**: Charcoal `#1A1A1A`
- **Font**: Be Vietnam Pro (Google Fonts — hỗ trợ tiếng Việt tốt)
- **Radius**: sm 8 / md 12 / lg 16 / pill 999
- **Touch target**: ≥ 48dp

Mọi màu/typography/radius/shadow **phải lấy từ** `lib/app/theme.dart`. Không hard-code trong widget.

---

## 🛠 Stack

| Package | Công dụng |
|---|---|
| `flutter_riverpod` 2.6 | State management |
| `go_router` 14.8 | Routing + auth guard |
| `dio` 5.7 | HTTP client + interceptor |
| `flutter_secure_storage` 9.2 | Lưu token Sanctum an toàn |
| `flutter_map` 7.0 | Bản đồ OpenStreetMap (free) |
| `cached_network_image` 3.4 | Cache ảnh món ăn |
| `image_picker` 1.2 | Upload ảnh review |
| `intl` 0.19 | Format tiền VND, ngày tháng VN |
| `url_launcher` 6.3 | Mở `tel:`, `geo:` |
| `google_fonts` 6.3 | Be Vietnam Pro |

---

## 🆘 Lỗi thường gặp

- **`Connection refused` / timeout** → Backend chưa chạy, hoặc `apiBaseUrl` sai IP. Test trước bằng cách mở URL trên trình duyệt điện thoại.
- **`SocketException: Failed host lookup`** → Điện thoại không cùng mạng WiFi với máy tính, hoặc firewall chặn port 8000.
- **`401 Unauthorized` ngay sau login** → Token không lưu được. Kiểm tra log console, có thể do secure storage chưa sẵn sàng trên thiết bị lạ.
- **Map không hiển thị** → `flutter_map` cần Internet để tải tile OSM. Nếu mạng yếu, đợi 5–10 giây.
- **`google_fonts` không load được** → Cần Internet lần đầu để download font. Sau đó cache local.

---

## 📋 Tính năng đã có (Bước A — Giai đoạn 1)

- ✅ Khởi tạo project Flutter chuẩn cấu trúc
- ✅ Theme theo brand Mì Cay (Spicy Red + Cream + Charcoal)
- ✅ `DioClient` với interceptor tự gắn Bearer token
- ✅ `AuthController` lưu/đọc token qua secure storage
- ✅ Login screen hỗ trợ **cả Customer lẫn Staff**
- ✅ go_router với redirect theo role
- ✅ 2 màn placeholder (Customer Home + Shipper Home)
- ✅ Widget test pass + analyze 0 issues

## 🔜 Sẽ phát triển tiếp (Bước B trở đi)

- [ ] Menu + chi tiết món + giỏ hàng
- [ ] Checkout + chọn điểm giao trên map
- [ ] Theo dõi đơn + timeline + bản đồ shipper
- [ ] Đánh giá món sau `COMPLETED`
- [ ] Hạng thành viên + lịch sử điểm
- [ ] Shipper: 3 tab đơn + 4 nút hành động + bản đồ chỉ đường
- [ ] Local notification cho shipper khi có đơn mới
- [ ] Build APK release để nộp báo cáo LVTN
