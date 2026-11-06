# Hệ thống quản lý nhà hàng Mì Cay

Dự án gồm website cho khách hàng và nhân viên, ứng dụng Flutter cho khách hàng và người giao hàng, Laravel API dùng chung, cùng dịch vụ chatbot AI tùy chọn.

## Cấu trúc dự án

| Thư mục | Thành phần |
| --- | --- |
| [`back-end/`](back-end/) | Laravel 13, API, xác thực, cơ sở dữ liệu và nghiệp vụ nhà hàng |
| [`front-end/`](front-end/) | Vue 3, TypeScript, Vite; giao diện khách hàng và nhân viên |
| [`mobile/`](mobile/) | Ứng dụng Flutter |
| [`AI-service/`](AI-service/) | FastAPI và chatbot Gemini |
| [`docs/`](docs/) | Tài liệu kiến trúc, vận hành và kiểm thử |

## Yêu cầu để chạy web cục bộ

- PHP 8.3+, Composer và các extension PHP mà Composer yêu cầu.
- MySQL và một database đã tạo cho dự án.
- Node.js `^22.18.0` hoặc `>=24.12.0` và npm.
- Flutter SDK nếu chạy ứng dụng mobile; Python 3.11+ nếu chạy chatbot.

Các lệnh dưới đây dùng PowerShell, bắt đầu tại thư mục gốc repository. Mỗi dịch vụ chạy trong một cửa sổ terminal riêng.

### 1. Cấu hình và chạy Laravel API

```powershell
cd back-end
composer install
Copy-Item .env.example .env
```

Sửa `back-end/.env` cho đúng MySQL (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Sau đó:

```powershell
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

`db:seed` tạo dữ liệu và tài khoản mẫu; chỉ chạy khi bạn muốn nạp dữ liệu này vào database đang cấu hình. API chạy tại `http://127.0.0.1:8000/api`.

### 2. Chạy giao diện web

```powershell
cd front-end
npm install
npm run dev
```

Mở `http://127.0.0.1:5173`. Trong chế độ phát triển, Vite chuyển `/api` và `/storage` tới Laravel ở cổng 8000. Chatbot dùng gateway riêng ở cổng 8002 khi được bật; xem phần dưới.

## Ứng dụng mobile

Trong `mobile/`, sao chép `.env.example` thành `.env` và đặt URL API phù hợp với thiết bị. Android Emulator dùng `http://10.0.2.2:8000/api`; điện thoại thật dùng địa chỉ LAN của máy chạy Laravel. Sau đó chạy:

```powershell
cd mobile
flutter pub get
flutter run
```

Xem [hướng dẫn mobile](mobile/README.md) để biết thêm về thiết bị và kiểm thử.

## Chatbot AI (tùy chọn)

Chatbot cần Python 3.11+, Gemini API key và cấu hình khóa nội bộ giống nhau trong `back-end/.env` và `AI-service/.env`. Sao chép `AI-service/.env.example` thành `.env`, cài các gói trong `AI-service/requirements.txt`, rồi làm theo [hướng dẫn chạy chatbot trên Windows](docs/CHATBOT_LOCAL_RUN.md). Từ thư mục gốc, lệnh `python scripts/dev_chatbot.py` khởi động Laravel API (8000), chat gateway (8002), AI service (8001) và Vite (5173) sau khi các dependency đã được cài.

Khi dùng PHP development server, gửi yêu cầu chatbot qua Vite hoặc gateway 8002 để AI service có thể gọi ngược về API ở cổng 8000.

## Kiểm thử

```powershell
# Chạy trong back-end/
php artisan test

# Chạy trong front-end/
npm test
npm run build

# Chạy trong mobile/
flutter analyze
flutter test
```

Các bài kiểm thử chatbot và điều kiện chạy được mô tả trong [tài liệu chatbot](docs/CHATBOT_LOCAL_RUN.md).

> Không commit file `.env` hoặc khóa API. Tích hợp VNPay, Gemini và thông báo thời gian thực cần cấu hình thêm theo các biến môi trường tương ứng trước khi sử dụng.
