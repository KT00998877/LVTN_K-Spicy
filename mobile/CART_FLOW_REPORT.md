# Báo cáo Menu → Add to Cart → Cart

## Nguyên nhân đã tái hiện

1. `FoodCard._AddButton.onTap` gọi `MenuScreen._quickAdd(item)` → `QuickAddSheet.show()` → `showModalBottomSheet`. Nút `+` chưa gửi POST; phải xác nhận trong sheet mới gửi.
2. `AppTheme.light()` đặt `ElevatedButton.minimumSize = Size.fromHeight(52)`, tức chiều rộng tối thiểu vô hạn. Nút xác nhận trong `QuickAddSheet` nằm trong `Row`, chỉ bọc `SizedBox(height: 52)`, nên nhận chiều rộng không giới hạn. Flutter báo **BoxConstraints forces an infinite width**, `BoxConstraints(w=Infinity, h=52.0)`. Modal barrier đã mở nhưng nội dung không layout được, tạo cảm giác app bị đơ.
3. Cả `CartScreen` và `CartTabScreen` mắc cùng lỗi ở nút “Đặt món”. Test đã đưa cart có dữ liệu vào controller nhưng UI vẫn lỗi layout. Đây là nguyên nhân hiển thị, không phải bằng chứng API không có món. Ngoài ra, nếu popup chưa xác nhận được thì thực sự chưa có POST thêm món.
4. Test quick-add cũ dùng `MaterialApp` không có `AppTheme.light()`, nên không tái hiện được lỗi. Khi chuyển test sang theme thật, lỗi xuất hiện trước khi sửa production code.
5. Ở chiều rộng điện thoại 390 logical pixels, tổng tiền dài và nút đặt món còn gây `RenderFlex overflowed by 20 pixels`. Đã giới hạn vùng tổng tiền bằng `Expanded`.

Log trước sửa: [popup](quick_add_before.log), [Cart](cart_before.log). Log thiết bị Android được kiểm tra bằng adb nhưng không có dòng Flutter phù hợp trong buffer đã đọc; kết luận trên dựa vào test tái hiện có stack trace, không gán cho log thiết bị.

## Kiến trúc và dữ liệu

- Giỏ hàng lưu ở Laravel: `carts`, `cart_items`, `cart_item_toppings`, theo `user_id` đã xác thực Sanctum.
- Flutter dùng Riverpod `StateNotifierProvider<CartController, CartState>`; `state = state.copyWith(...)` phát cập nhật, Cart dùng `ref.watch`, badge dùng `cartBadgeCountProvider` → tổng quantity.
- Provider không `autoDispose`, nằm dưới `ProviderScope` ở entry point. Shell dùng `IndexedStack`; chuyển tab giữ cùng controller. Provider được tạo lại khi `(user.id, token)` thay đổi, nhằm tách phiên đăng nhập.
- `PendingCheckoutStore` chỉ lưu yêu cầu checkout chưa xác định kết quả trong secure storage, không lưu danh sách món. Token cũng ở secure storage. Không có cart mới/local cart song song.
- Hai trang Cart gọi `refresh()` trong post-frame của `initState`. Tab trong `IndexedStack` chỉ init một lần; nay shell còn gọi refresh mỗi khi chọn tab Giỏ.
- `refresh()` đọc pending checkout rồi GET `/cart`; lỗi tải được hiển thị riêng với trạng thái giỏ rỗng. `items: []` hợp lệ vẫn là empty state.
- JSON Laravel hiện tại khớp tên field Dart: `menu_item_id`, `menu_item.name`, `menu_item.image_url`, `quantity`, `spicy_level`, `toppings`, `topping_ids`, `unit_price`, `topping_price`, `subtotal`, `version`. Backend ép ID/quantity về integer và tiền về float; không thấy mismatch field trong implementation hiện tại.
- Giá dạng chuỗi decimal được hỗ trợ thêm. Giá món/topping/dòng cart null, sai kiểu, âm hoặc không hữu hạn báo lỗi dữ liệu, không âm thầm biến thành món miễn phí. Ảnh null/rỗng đi qua placeholder hiện có.
- Size thuộc từng menu item trong API hiện tại; popup chọn độ cay, topping, ghi chú, quantity. Không tự tạo field size hoặc endpoint mới.

## File và hàm thay đổi

Đường dẫn dưới đây tương đối với thư mục `mobile`, trừ file Laravel.

| File | Hàm/phần sửa | Nội dung |
|---|---|---|
| `lib/features/menu/presentation/quick_add_sheet.dart` | `show`, `_confirm`, `build`, `_ToppingPicker.build` | Gỡ minimum width vô hạn tại nút; khóa mở sheet theo Navigator với `finally`; SafeArea/insets bàn phím; nút đóng; lấy messenger trước pop; theo dõi giá topping; chờ topping tải xong trước xác nhận, lỗi có retry |
| `lib/features/menu/presentation/menu_screen.dart` | `initState`, `_fetch`, `_quickAdd` | Guard mounted; bỏ refresh thừa sau khi sheet đóng và callback dùng ref sau dispose |
| `lib/features/cart/presentation/cart_screen.dart` | `initState`, `_confirmClear`, `build` | Sửa constraint nút/tổng tiền; dùng context của dialog để pop; kiểm tra mounted sau await |
| `lib/features/cart/presentation/cart_tab_screen.dart` | `initState`, `_confirmClear`, `build`, `_CartBottomBar.build` | Cùng sửa như CartScreen |
| `lib/features/cart/presentation/cart_controller.dart` | `refresh`, `_mutate` | Debug stack trace; phân biệt ghi thành công với GET sau ghi thất bại, không báo sai là phải thêm lại |
| `lib/features/customer/customer_home_screen.dart` | `_switchTab` | Refresh khi chọn tab Giỏ |
| `lib/core/api/dio_client.dart` | `dioProvider` | Gắn interceptor log Cart sau interceptor authentication hiện tại |
| `lib/core/api/cart_debug_interceptor.dart` (mới) | `onRequest`, `onResponse`, `onError`, `_redact` | Debug URL/method/body/status, Accept/Content-Type và có token hay không; không in token, ẩn note; không log checkout/contact |
| `lib/core/api/json_number.dart` (mới) | `jsonPrice` | Parse tiền từ number/decimal string và từ chối giá sai |
| `lib/features/menu/domain/menu_models.dart` | `MenuItem.fromJson`, `Topping.fromJson` | Dùng kiểm tra giá |
| `lib/features/cart/domain/cart_models.dart` | Các factory `fromJson` | Dùng kiểm tra giá |
| `test/menu_entry_flow_test.dart` | Test popup và hai trang Cart | Dùng theme thật để giữ regression coverage |
| `test/ordering_flow_test.dart` | Fixture response update | Bổ sung monetary fields đúng contract Laravel |
| `test/cart_flow_regression_test.dart` (mới) | Widget/unit tests | Menu → popup → badge → Cart, bấm lặp, lỗi API, reload controller, giá sai và redaction log |
| `test/widget_test.dart` | Setup smoke test | Nạp dotenv, mock storage và tắt tải font runtime; sửa lỗi `NotInitializedError` của setup cũ |
| `../back-end/tests/Feature/CartFlowTest.php` (mới) | Hai feature tests | Contract HTTP thật qua Laravel với Sanctum và database test: nhiều món/options/quantity/xóa/reload/token sai |

Không thay đổi API/controller/service/schema Laravel production, không sửa theme toàn app, login/register hoặc Vue.

## Endpoint, request và response

Base URL vẫn lấy từ `.env` qua `AppConfig.apiBaseUrl` (có `/api`).

| Thao tác | Endpoint | Kết quả |
|---|---|---|
| Danh sách món | `GET /api/menu` | `200`, `data: [...]` |
| Chi tiết món | `GET /api/menu/{slug}` | `200`, `data: {...}` |
| Options | `GET /api/cart/toppings` | `200`, `data: [...]` |
| Thêm | `POST /api/cart` | `201`, `data: CartItem` |
| Tải giỏ | `GET /api/cart` | `200`, `data: Cart` |
| Tăng/giảm/sửa options | `PUT /api/cart/items/{id}` | `200`, `data: CartItem` |
| Xóa dòng | `DELETE /api/cart/items/{id}` | `200`, success/message |
| Xóa hết | `DELETE /api/cart` | `200`, success/message |

`/api/cart/count` có trong repository nhưng badge của flow này lấy `total_quantity` từ cart state, không gọi endpoint count. `/api/cart/checkout` giữ nguyên, ngoài phạm vi sửa nghiệp vụ.

Cart requests dùng `Accept: application/json`, `Content-Type: application/json`, `Authorization: Bearer <token từ secure storage>`. User được Laravel xác định qua token, client không gửi user_id để chọn giỏ. 401 tiếp tục dùng `sessionExpiredProvider` và auth flow hiện có.

**Trước sửa:** Bấm `+` mở barrier rồi lỗi layout; chưa đến `_confirm`, nên chưa có request POST tương ứng. Khi gọi repository trực tiếp, contract API đã đúng. Cart có dữ liệu cũng không render được nút footer.

**Sau sửa:** Xác nhận gửi cùng payload/contract, không đổi API. Ví dụ dưới đây là dữ liệu minh họa theo fixture test, không phải traffic lấy từ tài khoản người dùng:

```json
POST /api/cart
{"menu_item_id":2,"quantity":2,"spicy_level":7,"note":"Less onion","topping_ids":[3]}
```

```json
201
{"success":true,"data":{"id":10,"menu_item_id":2,"menu_item":{"id":2,"name":"Noodles","image_url":null},"quantity":2,"spicy_level":7,"note":"Less onion","toppings":[{"id":3,"name":"Mushroom","price":5000}],"topping_ids":[3],"unit_price":50000,"topping_price":5000,"subtotal":110000}}
```

```json
GET /api/cart → 200
{"success":true,"data":{"id":1,"user_id":7,"version":1,"items":["CartItem như trên"],"total_items":1,"total_quantity":2,"subtotal":110000}}
```

Ví dụ đã rút gọn các field phụ; `items` thực tế là object CartItem. Client chỉ gửi ID/options/quantity, không gửi price/name/image làm nguồn giá. Backend tính `(unit_price + topping_price) * quantity`.

Debug log có prefix `[Cart API]`, thể hiện URL thực, method, request body, HTTP status, response body và user_id trong GET cart. Token không xuất hiện. Release không in các log này.

## Flow sau sửa

Menu API → bấm `+` → duy nhất một sheet → tải topping và chọn độ cay/topping/quantity/note → xác nhận (guard chống gửi lặp) → POST → GET cart → gán state → badge và Cart rebuild → đóng sheet và thông báo. Chuyển lại tab Giỏ có refresh; restart app đăng nhập cùng tài khoản đọc lại cart từ backend.

Nếu POST thất bại: dừng loading, hiển thị lỗi, sheet giữ lại để sửa/thử lại. Nếu POST thành công nhưng GET thất bại: controller nhận biết thay đổi đã ghi, đóng sheet thay vì mời gửi lại POST; Cart có thông báo tải lại và nút retry. Không dùng dữ liệu cũ để giả làm tổng tiền mới.

## Kết quả test

- `flutter test`: **61 passed**. Bao gồm auth/login/register, menu entry, ordering và regression mới.
- `php artisan test --filter CartFlowTest`: **2 passed, 29 assertions**.
- `php artisan test --filter CustomerCheckoutTest`: **9 passed, 66 assertions**.
- `flutter analyze`: không error/warning; **7 info lint** `curly_braces_in_flow_control_structures` ở checkout_screen/order_controller/payment_screen ngoài phần sửa. Analyzer vẫn trả nonzero vì các lint này.
- [Log toàn bộ Flutter test](cart_all_tests.log), [log analyzer](cart_analyze.log).

| Case yêu cầu | Bằng chứng/phạm vi |
|---|---|
| 1. Thêm lần đầu | Widget test bấm `+` Menu và xác nhận; Laravel POST/GET |
| 2. Cùng món nhiều lần | Widget test hai lượt; Laravel POST lặp tạo các dòng riêng theo behavior hiện có |
| 3. Nhiều món khác nhau | Laravel feature test nhiều ID + topping |
| 4. Mở Cart ngay sau thêm | Widget test badge và danh sách ở cùng ProviderScope |
| 5. Menu → Cart → Menu → Cart | Widget test IndexedStack, không reset cart |
| 6. Tăng/giảm quantity | Widget + controller + Laravel PUT, kiểm tra count/subtotal |
| 7. Xóa một món | Controller quantity về 0; Laravel DELETE một dòng |
| 8. Xóa hết | Widget dialog xác nhận và badge 0; Laravel DELETE cart |
| 9. Reload | Tạo lại controller đọc repository; Laravel bỏ cache auth guard rồi GET lại dữ liệu đã lưu. Chưa cold-restart điện thoại để kiểm chứng end-to-end |
| 10. API/mất mạng | Dio connectionError; controller 422/500; POST thành công rồi GET offline và retry |
| 11. Token hết hạn | Laravel token sai → 401; controller 401 và auth tests về xóa token/phiên. Chưa thao tác expire token thật trên điện thoại |
| 12. Backend cart rỗng | Laravel items=[] và widget empty state/badge 0 |
| 13. Không ảnh | Menu/Cart widget fixtures không ảnh; Laravel menu image_url=null; image tests hiện có |
| 14. Giá null/sai field | Model null, chuỗi sai, NaN, âm, object bị từ chối; chuỗi decimal hợp lệ được parse |
| 15. Bấm + nhanh | Gọi mở 11 lần trước khi pump → một sheet; đóng/mở lại; controller chặn mutation đồng thời |

## Giới hạn còn lại

- Chưa cài bản sửa và chạy đủ 15 case trên điện thoại với API LAN/tài khoản thật. Các test Flutter dùng fixture/repository test; Laravel dùng SQLite database test riêng. Không tuyên bố đã xác minh request/response thực tế của phiên người dùng.
- Khi POST bị timeout và không nhận response, client chưa biết backend đã ghi hay chưa; endpoint thêm món hiện tại không có idempotency key. Không tự đổi contract Laravel trong bản sửa này. Người dùng nên tải lại Cart trước khi thêm lại trong tình huống này.
- API hiện tại tạo dòng riêng cho mỗi lần thêm, dù comment trong CartService nói merge. Đã test và giữ behavior thực tế này, không tự đổi nghiệp vụ.
- Chưa chạy tương tác bàn phím, cỡ chữ lớn, mọi kích thước điện thoại và accessibility trên thiết bị thật. Sheet đã xử lý keyboard insets và có phần nội dung cuộn.

Sau khi lấy thay đổi, restart Flutter app để chạy code mới. Không cần migration Laravel.
