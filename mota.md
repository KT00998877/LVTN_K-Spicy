# PROMPT: VIẾT MÔ TẢ MÓN ĂN CHUYÊN NGHIỆP CHO NHÀ HÀNG K-SPICY

Bạn là chuyên gia Content Marketing trong lĩnh vực F&B, am hiểu ẩm thực Hàn Quốc và có kinh nghiệm xây dựng nội dung thực đơn cho các hệ thống nhà hàng trực tuyến.

**Nhiệm vụ:**

Viết lại hoặc bổ sung mô tả cho các món ăn thuộc hệ thống nhà hàng Hàn Quốc K-SPICY, nhằm giúp khách hàng hiểu rõ món ăn, cảm thấy hấp dẫn và dễ dàng quyết định đặt món.

### 1. Yêu cầu nội dung

Với mỗi món ăn, hãy viết một đoạn mô tả đáp ứng các tiêu chí:

- Độ dài khoảng 80-100 từ, tối đa 5 câu.
- Giới thiệu nguyên liệu chính nếu thông tin được cung cấp hoặc có căn cứ xác thực từ database
- Nêu bật phương pháp chế biến và hương vị đặc trưng.
- Mô tả kết cấu của món ăn khi phù hợp: giòn, mềm, dai, béo, mọng nước...
- Làm nổi bật điểm hấp dẫn và sự khác biệt của món ăn.
- Đề cập mức độ cay nếu có dữ liệu xác định.
- Sử dụng tiếng Việt tự nhiên, gần gũi, chuyên nghiệp và kích thích vị giác.
- Nội dung dễ hiểu, phù hợp với thực đơn trực tuyến và thiết bị di động.

### 2. Những điều cần tránh

- Không viết mô tả chung chung như "Món ăn thơm ngon, hấp dẫn, bổ dưỡng".
- Không lặp lại một cấu trúc câu cho tất cả món ăn.
- Không sử dụng ngôn ngữ quảng cáo quá mức.
- Không tự ý bổ sung nguyên liệu, phương pháp chế biến hoặc đặc điểm chưa được xác minh.
- Không tự nhận món ăn là đặc sản, chính gốc, tốt cho sức khỏe nếu không có căn cứ.
- Không tự ý gán mức độ cay hoặc thông tin dị ứng.
- Không thay đổi tên món ăn, giá bán, danh mục hay dữ liệu sản phẩm khác.

### 3. Cá nhân hóa nội dung theo từng nhóm món

- Món chính (mì cay): Tập trung vào hương vị nước dùng, nguyên liệu ăn kèm và cảm giác thưởng thức.
- Món nướng: Nhấn mạnh hương thơm, cách tẩm ướp, độ mềm và nước sốt.
- Món chiên: Mô tả lớp vỏ giòn, phần nhân và nước sốt.
- Món cơm, mì: Làm rõ sự kết hợp giữa tinh bột, nguyên liệu, gia vị và nước sốt.
- Món ăn vặt: Tập trung vào kết cấu, hương vị đặc trưng và trải nghiệm thưởng thức.
- Đồ uống: Mô tả vị ngọt, chua, thanh mát, hương thơm và kết cấu nếu phù hợp.

### 4. Yêu cầu chất lượng

Mỗi mô tả cần:
- Khác biệt với các món còn lại.
- Đúng bản chất món ăn.
- Có giá trị thông tin thực tế.
- Hấp dẫn nhưng không phóng đại.
- Phù hợp để hiển thị trực tiếp trên website.
- Có thể sử dụng làm nguồn thông tin hỗ trợ AI Chatbot tư vấn món ăn.

### 5. Quy trình thực hiện

1. Đọc toàn bộ danh sách món ăn được cung cấp.
2. Phân tích tên món, danh mục, nguyên liệu, mô tả hiện có và thông tin liên quan.
3. Viết mô tả riêng cho từng món theo đúng đặc điểm.
4. Nếu thiếu dữ liệu, chỉ sử dụng những đặc điểm có thể xác định đáng tin cậy; đánh dấu các thông tin cần xác minh.
5. Kiểm tra lỗi chính tả, cách diễn đạt và nội dung trùng lặp.
6. Trả kết quả dưới dạng bảng gồm:
   - Mã món ăn
   - Tên món ăn
   - Mô tả hiện tại
   - Mô tả đề xuất
   - Thông tin cần xác minh (nếu có)

### 6. Kết quả mong muốn

Tạo bộ mô tả món ăn chất lượng cao, thống nhất phong cách thương hiệu K-SPICY, giúp khách hàng dễ lựa chọn, cải thiện trải nghiệm đặt món và hỗ trợ chatbot tư vấn chính xác.

---

# Kết quả viết lại mô tả thực đơn K-SPICY

Đã đối chiếu 53 mục đang hoạt động trong cơ sở dữ liệu, bao gồm cả món tạm hết nếu còn trên thực đơn. Mã món trong bảng là `menu_items.id`. Slug đi kèm giúp tra cứu khi cần áp dụng nội dung. Dấu `—` ở cột mô tả hiện tại nghĩa là chưa có nội dung.

Mô tả đề xuất dựa trên tên món, mô tả đang lưu và nguyên liệu có trong công thức. Cột cần xác minh dùng nội bộ. Những thông tin chưa xác minh được để ngoài phần mô tả hiển thị cho khách.

## Mì cay Hàn Quốc (16)

| Mã món ăn | Tên món ăn | Mô tả hiện tại | Mô tả đề xuất | Thông tin cần xác minh |
| --- | --- | --- | --- | --- |
| 1 · `mi-cay-bo-nho` | Mì cay bò nhỏ | Mì cay bò phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Phần nhỏ dành cho lúc bạn muốn một tô mì cay vừa sức ăn mà vẫn có đủ vị của thịt bò, cải thảo và tỏi. Sợi mì Hàn đi cùng nước dùng có tương gochujang và bột ớt Hàn Quốc, tạo nền cay thơm cho vị đậm của bò. Cải thảo giúp mỗi đũa mì có thêm chút nhẹ vị giữa những miếng thịt. Bạn có thể chọn cấp cay khi gọi để tô mì hợp với khẩu vị hôm đó. | Dải cấp cay hiện có trên kênh đặt món. |
| 2 · `mi-cay-bo-lon` | Mì cay bò lớn | Mì cay bò phần lớn — chọn cấp độ cay 1-5 khi đặt | Ở tô phần lớn, thịt bò là điểm nhấn trên nền mì Hàn, cải thảo và nước dùng thơm tỏi. Tương gochujang cùng bột ớt Hàn Quốc tạo độ cay cho nước dùng, còn vị đậm của thịt bò rõ nhất khi ăn cùng một đũa mì vừa thấm nước. Phần lớn hợp với bữa chính khi bạn muốn ăn nhiều hơn hoặc dùng mì làm món chính duy nhất. Chọn cấp cay lúc đặt để giữ được độ cay vừa ý. | Dải cấp cay hiện có trên kênh đặt món. |
| 3 · `mi-cay-ga-nho` | Mì cay gà nhỏ | Mì cay gà phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Thịt gà và cải thảo tạo điểm nhấn cho tô mì Hàn phần nhỏ, phù hợp với người thích vị thịt gà trong bữa mì cay. Nước dùng có tỏi, tương gochujang và bột ớt Hàn Quốc, cho vị cay thơm đi cùng vị dịu của thịt gà. Thử ăn mì với một miếng gà rồi nhấp nước dùng để cảm nhận rõ từng vị. Đây là phần nhỏ, bạn vẫn có thể điều chỉnh cấp cay theo khẩu vị khi đặt. | Dải cấp cay hiện có trên kênh đặt món. |
| 4 · `mi-cay-ga-lon` | Mì cay gà lớn | Mì cay gà phần lớn — chọn cấp độ cay 1-5 khi đặt | Khi muốn một tô mì phần lớn mà vẫn thích vị thịt gà, đây là lựa chọn để dùng làm bữa chính. Mì Hàn đi cùng thịt gà, cải thảo và nước dùng có tương gochujang, bột ớt Hàn Quốc cùng tỏi. Vị cay của nước dùng hiện rõ khi ăn mì, còn miếng gà và cải thảo cho bạn những nhịp đổi vị xen kẽ trong tô. Cấp cay được chọn khi gọi, nên bạn có thể cân nhắc theo mức mình thường ăn. | Dải cấp cay hiện có trên kênh đặt món. |
| 5 · `mi-cay-cha-ca-nho` | Mì cay chả cá nhỏ | Mì cay chả cá phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Điểm thú vị của tô mì phần nhỏ là chả cá Hàn Quốc cho cảm giác dai nhẹ bên cạnh sợi mì và cải thảo. Nước dùng có tương gochujang, bột ớt Hàn Quốc và tỏi, nên vị chả cá dễ nhận ra ngay cả khi ăn cùng nước mì cay. Phần nhỏ hợp khi bạn muốn thử một vị khác với mì bò hoặc mì gà trong thực đơn. Bạn chọn cấp cay lúc gọi để cân bằng giữa vị chả cá và vị ớt. | Dải cấp cay hiện có trên kênh đặt món. |
| 6 · `mi-cay-cha-ca-lon` | Mì cay chả cá lớn | Mì cay chả cá phần lớn — chọn cấp độ cay 1-5 khi đặt | Chả cá Hàn Quốc mang đến độ dai nhẹ cho tô mì phần lớn, rõ nhất khi ăn xen với sợi mì Hàn và cải thảo. Tỏi làm thơm nước dùng, còn tương gochujang và bột ớt Hàn Quốc tạo phần vị cay đi kèm. Đây là tô dành cho bữa ăn cần phần mì lớn và thích chả cá làm nguyên liệu chính. Nếu muốn cảm nhận vị chả cá rõ hơn, hãy chọn cấp cay phù hợp với khả năng ăn cay của mình. | Dải cấp cay hiện có trên kênh đặt món. |
| 7 · `mi-cay-hai-san-nho` | Mì cay hải sản nhỏ | Mì cay hải sản phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Tô mì hải sản phần nhỏ nổi bật với tôm sú trên nền nước dùng cay thơm. Vị ngọt tự nhiên và độ chắc của tôm đi cùng mì Hàn, cải thảo và nước dùng có tương gochujang, bột ớt Hàn Quốc cùng tỏi. Nhấp một chút nước dùng sau miếng tôm để thấy vị ngọt và vị cay nối tiếp nhau. Phần nhỏ phù hợp khi bạn muốn thưởng thức mì tôm theo kiểu mì cay và tự chọn cấp độ khi gọi. | Công thức hiện ghi tôm sú. Xác nhận có thêm loại hải sản nào khác khi phục vụ. |
| 8 · `mi-cay-hai-san-lon` | Mì cay hải sản lớn | Mì cay hải sản phần lớn — chọn cấp độ cay 1-5 khi đặt | Tô phần lớn đưa vị ngọt của tôm sú vào bữa mì cay với mì Hàn, cải thảo và tỏi. Nước dùng có tương gochujang cùng bột ớt Hàn Quốc tạo vị cay thơm, còn thịt tôm cho cảm giác chắc khi ăn xen giữa các đũa mì. Nếu chọn món này để ăn chính, bạn có thể nếm nước dùng rồi thưởng thức tôm và cải thảo luân phiên để rõ vị. Cấp độ cay được chọn theo khẩu vị lúc đặt. | Công thức hiện ghi tôm sú. Xác nhận có thêm loại hải sản nào khác khi phục vụ. |
| 9 · `mi-cay-muc-nho` | Mì cay mực nhỏ | Mì cay mực phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Mực ống tạo độ dai giòn nhẹ cho tô mì cay phần nhỏ, hợp với người thích cảm giác nhai rõ của hải sản. Sợi mì Hàn, cải thảo và tỏi đi cùng nước dùng có tương gochujang và bột ớt Hàn Quốc, tạo nền cay thơm cho vị mực. Một đũa mì ăn kèm miếng mực cho thấy sự khác nhau về kết cấu trong cùng một tô. Bạn có thể chọn cấp cay vừa với mình ngay khi gọi món. | Dải cấp cay hiện có trên kênh đặt món. |
| 10 · `mi-cay-muc-lon` | Mì cay mực lớn | Mì cay mực phần lớn — chọn cấp độ cay 1-5 khi đặt | Nếu thích mực ống và muốn ăn mì làm món chính, phần lớn cho bạn lựa chọn với cùng nền vị quen thuộc. Nước dùng thơm tỏi, có tương gochujang và bột ớt Hàn Quốc, đi cùng sợi mì Hàn và cải thảo. Mực ống có độ dai giòn riêng, nổi bật khi bạn ăn cùng mì thấm nước dùng hoặc thưởng thức từng miếng. Bạn chọn cấp cay lúc đặt để vị cay không lấn át hương vị hải sản theo sở thích. | Dải cấp cay hiện có trên kênh đặt món. |
| 11 · `mi-cay-bach-tuoc-nho` | Mì cay bạch tuộc nhỏ | Mì cay bạch tuộc phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Bạch tuộc là phần bạn sẽ cảm nhận rõ nhất về độ săn và dai trong tô mì cay phần nhỏ. Mì Hàn và cải thảo đi cùng nước dùng thơm tỏi, có tương gochujang và bột ớt Hàn Quốc để tạo vị cay. Ăn một miếng bạch tuộc xen với mì giúp thấy sự tương phản giữa hải sản và sợi mì trong từng đũa. Nếu muốn thử vị bạch tuộc ở khẩu phần nhỏ, bạn có thể chọn cấp cay ngay lúc đặt. | Dải cấp cay hiện có trên kênh đặt món. |
| 12 · `mi-cay-bach-tuoc-lon` | Mì cay bạch tuộc lớn | Mì cay bạch tuộc phần lớn — chọn cấp độ cay 1-5 khi đặt | Tô mì phần lớn lấy bạch tuộc làm điểm nhấn, phù hợp khi bạn muốn một bữa mì cay với hải sản có độ săn dai. Cải thảo góp vị nhẹ, còn tỏi, tương gochujang và bột ớt Hàn Quốc tạo phần nước dùng cay thơm cho mì Hàn. Bạn có thể ăn bạch tuộc riêng để cảm nhận kết cấu rồi trở lại với một đũa mì và nước dùng. Chọn cấp cay theo khẩu vị khi gọi món tại quán hoặc trên thực đơn. | Dải cấp cay hiện có trên kênh đặt món. |
| 13 · `mi-cay-xuc-xich-nho` | Mì cay xúc xích nhỏ | Mì cay xúc xích phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Vị mặn nhẹ của xúc xích Hàn Quốc dễ nhận ra trong tô mì phần nhỏ có cải thảo và tỏi. Nước dùng dùng tương gochujang cùng bột ớt Hàn Quốc để tạo vị cay, còn sợi mì Hàn kết nối các nguyên liệu trong mỗi đũa. Đây là món để chọn khi bạn muốn vị xúc xích quen thuộc xen với vị cay của nước mì. Phần nhỏ hợp với bữa vừa bụng, cấp cay có thể chọn ngay lúc đặt. | Xác nhận phần nhỏ có chả cá hay chỉ phần lớn có thêm chả cá. |
| 14 · `mi-cay-xuc-xich-lon` | Mì cay xúc xích lớn | Mì cay xúc xích phần lớn — chọn cấp độ cay 1-5 khi đặt | Với phần lớn, tô mì xúc xích có thêm chả cá Hàn Quốc trong cùng một tô mì. Miếng xúc xích và chả cá cho hai vị khác nhau khi ăn cùng mì Hàn, cải thảo và nước dùng thơm tỏi. Tương gochujang cùng bột ớt Hàn Quốc làm nền cay cho tô mì, để bạn thưởng thức lần lượt từng nguyên liệu. Nếu chọn tô này làm món chính, bạn có thể tùy chỉnh cấp cay khi đặt để hợp khẩu vị. | Công thức phần lớn có chả cá. Xác nhận đây là khác biệt chủ đích. |
| 15 · `mi-cay-thap-cam-nho` | Mì cay thập cẩm nhỏ | Mì cay thập cẩm phần nhỏ — chọn cấp độ cay 1-5 khi đặt | Thịt bò, tôm sú, chả cá và xúc xích Hàn Quốc cùng có mặt trong tô mì thập cẩm phần nhỏ. Sợi mì Hàn và cải thảo đi với nước dùng thơm tỏi, có tương gochujang và bột ớt Hàn Quốc. Vị đậm của bò, vị ngọt của tôm và độ dai nhẹ của chả cá mang lại những miếng ăn khác nhau trong cùng một tô. Phần nhỏ hợp khi bạn muốn thử nhiều nguyên liệu, với cấp cay do bạn chọn. | Dải cấp cay hiện có trên kênh đặt món. |
| 16 · `mi-cay-thap-cam-lon` | Mì cay thập cẩm lớn | Mì cay thập cẩm phần lớn — chọn cấp độ cay 1-5 khi đặt | Phần lớn dành cho bữa mì thập cẩm với thịt bò, tôm sú, chả cá và xúc xích Hàn Quốc. Bạn có thể ăn xen vị ngọt của tôm với vị đậm của bò hoặc đổi sang chả cá dai nhẹ và xúc xích. Các nguyên liệu đi cùng mì Hàn, cải thảo và nước dùng có tỏi, tương gochujang cùng bột ớt Hàn Quốc. Chọn cấp cay khi đặt để dễ thưởng thức từng loại trong tô mà vẫn giữ vị cay bạn thích. | Dải cấp cay hiện có trên kênh đặt món. |

## Topping thêm (13)

| Mã món ăn | Tên món ăn | Mô tả hiện tại | Mô tả đề xuất | Thông tin cần xác minh |
| --- | --- | --- | --- | --- |
| 17 · `them-mi` | Thêm mì | 1 gói | Thêm một gói mì Hàn khi bạn muốn có nhiều sợi mì hơn để ăn cùng phần nước dùng còn lại. Sợi mì là phần trung tính giúp đưa vị của nước dùng vào từng đũa và ăn xen với thịt, chả cá hoặc rau trong tô. Đây là lựa chọn dành cho lúc bạn thích tăng lượng mì thay vì tăng số món ăn kèm. Bạn cũng có thể chọn topping này khi muốn dùng hết phần nước mì theo khẩu vị của mình. | — |
| 18 · `them-trung` | Thêm trứng | — | Trứng gà là topping có vị dịu để ăn xen giữa những đũa mì cay và nước dùng đậm vị. Lựa chọn này hợp khi bạn muốn thêm một thành phần riêng cho tô mì mà vẫn giữ nguyên món chính đã chọn. Vị trứng dễ nhận ra nhất khi ăn cùng một ít mì và nước dùng, nhất là với tô có nhiều vị thịt hoặc hải sản. Bạn có thể chọn thêm trứng cho tô mì nhỏ hay lớn tùy nhu cầu. | Cách chế biến trứng và độ chín khi phục vụ. |
| 19 · `them-ga` | Thêm gà | — | Phần thịt gà thêm dành cho người muốn có nhiều miếng gà hơn trong tô mì hoặc muốn đổi vị bên cạnh các loại topping đã chọn. Vị dịu của thịt gà đi cùng nước dùng, để mỗi đũa có thể xen một chút mì và một miếng thịt. Khi gọi mì gà, đây là cách giữ hương vị chính của tô và bổ sung phần ăn kèm. Với tô thập cẩm, gà thêm mở rộng lựa chọn cho những ai thích vị gà. | Phần thịt và cách sơ chế. |
| 20 · `them-tom` | Thêm tôm | — | Tôm sú thêm cho bạn nhiều vị hải sản hơn mà vẫn giữ nguyên phần mì đã chọn. Thịt tôm có độ chắc và vị ngọt tự nhiên, dễ nhận ra khi ăn một miếng riêng rồi nhấp nước dùng. Topping này hợp với người muốn tăng phần tôm trong tô hải sản, hoặc muốn thêm một vị ngọt bên cạnh những miếng bò hay xúc xích. Bạn có thể dùng tôm xen với mì và cải thảo để cân bằng từng đũa. | Còn vỏ hay bóc vỏ khi phục vụ. |
| 21 · `them-muc` | Thêm mực | — | Mực ống thêm dành cho người thích độ dai giòn nhẹ của mực khi ăn cùng sợi mì. Mực tạo một nhịp đổi kết cấu rõ ràng giữa đũa mì thấm nước và miếng hải sản, vì vậy hợp khi bạn muốn tô mì có thêm một phần để nhai. Nếu đã chọn mì mực, đây là cách tăng phần nguyên liệu bạn thích. Bạn cũng có thể ghép cùng tô tôm để thưởng thức hai kết cấu hải sản trong bữa mì. | Cách cắt và sơ chế mực. |
| 22 · `them-bach-tuoc` | Thêm bạch tuộc | — | Bạch tuộc có kết cấu săn dai, hợp với người muốn tô mì có thêm một topping cho cảm giác nhai rõ. Bạn có thể ăn từng miếng bạch tuộc xen với mì, rồi nhấp nước dùng để thưởng thức vị hải sản và vị cay nối tiếp nhau. Nếu bạch tuộc là nguyên liệu bạn thích nhất trong bữa, chọn thêm phần riêng giúp tăng lượng bạch tuộc. Topping này cũng hợp để phối cùng tô mì có nguyên liệu chính khác. | Cách cắt và sơ chế bạch tuộc. |
| 23 · `them-cha-ca` | Thêm chả cá | — | Chả cá Hàn Quốc cho độ dai nhẹ và vị mặn dịu để ăn xen với sợi mì trong nước dùng. Đây là phần thêm dành cho người thích chả cá hoặc muốn tô mì gà, mì bò có thêm một kết cấu khác. Bạn có thể gắp chả cá cùng một đũa mì để nếm vị nước dùng, hoặc ăn riêng từng miếng cho rõ vị. Với tô mì chả cá, lựa chọn này giúp bổ sung đúng nguyên liệu bạn đã chọn. | — |
| 24 · `them-bo` | Thêm bò | — | Phần thịt bò thêm hợp với người muốn vị bò đậm rõ hơn hoặc muốn có thêm thịt để ăn cùng mì. Bạn có thể gắp bò với cải thảo và một ít mì, để vị nước dùng cay thơm đi cùng vị thịt trong mỗi đũa. Khi gọi tô bò, topping này giúp tăng lượng bò mà vẫn giữ phần mì theo kích cỡ đã chọn. Nếu dùng cùng mì thập cẩm, bạn có thể ăn xen bò với tôm hoặc chả cá. | Phần thịt bò và cách sơ chế. |
| 25 · `them-xuc-xich` | Thêm xúc xích | — | Xúc xích Hàn Quốc là phần thêm có vị mặn dịu, hợp để ăn xen giữa sợi mì và rau trong tô. Nếu thích vị xúc xích trong mì thập cẩm, bạn có thể chọn riêng phần này để có thêm những miếng ăn kèm. Topping cũng hợp khi bạn muốn bổ sung vị xúc xích vào tô có nguyên liệu chính là gà hay bò. Thưởng thức cùng một đũa mì thấm nước giúp vị xúc xích hòa vào vị cay của tô. | — |
| 26 · `them-kim-chi` | Thêm kim chi | — | Kim chi có vị chua đặc trưng của món lên men, phù hợp khi bạn muốn thêm một vị rõ nét vào bữa mì cay. Bạn có thể gắp một ít kim chi giữa các đũa mì để đổi vị, hoặc ăn kèm thịt và chả cá trong tô. Vị chua giúp bữa ăn có thêm điểm tương phản với nước dùng cay và các topping béo hơn. Đây là lựa chọn phù hợp nếu bạn muốn bổ sung riêng phần kim chi. | Độ cay của kim chi đang dùng. |
| 27 · `them-pho-mai` | Thêm phô mai | — | Mozzarella bổ sung vị béo sữa cho tô mì, giúp bạn đổi vị giữa các đũa mì cay. Khi gặp nhiệt, phô mai có thể mềm và kéo sợi, tạo cảm giác khác với thịt, rau hoặc hải sản trong tô. Bạn có thể ăn một ít mozzarella cùng mì để vị béo hiện rõ, rồi tiếp tục với nước dùng theo cấp cay đã chọn. Topping này hợp khi bạn muốn vị phô mai mà vẫn giữ món mì chính mình thích. | — |
| 28 · `them-rau` | Thêm rau | — | Phần rau thêm phối cải thảo, nấm kim châm, súp lơ và bắp cải theo công thức của quán. Mỗi loại góp một kiểu kết cấu, từ sợi nấm mảnh cho tới phần rau có độ giòn nhẹ khi ăn cùng mì. Nếu tô đã có nhiều thịt, chả cá hoặc xúc xích, đây là lựa chọn để thêm vị rau vào từng đũa. Bạn có thể gắp rau với mì và nước dùng, hoặc ăn xen giữa các topping đậm vị hơn. | Phối rau thực tế nếu có thay thế theo ngày. |
| 29 · `them-tokbokki` | Thêm tokbokki | — | Bánh gạo tokbokki thêm mang đến độ dẻo dai, khác với cảm giác của sợi mì trong tô. Tương gochujang có trong công thức góp vị cay, để bánh gạo có vị riêng khi thưởng thức xen giữa mì, rau và topping khác. Đây là lựa chọn dành cho người thích nhai bánh gạo nhưng vẫn muốn giữ tô mì làm món chính. Bạn có thể chọn phần thêm này khi muốn thử tokbokki cùng bữa mì mà chưa cần gọi một phần riêng. | Mức cay và cách phục vụ topping. |

## Món phụ (9)

| Mã món ăn | Tên món ăn | Mô tả hiện tại | Mô tả đề xuất | Thông tin cần xác minh |
| --- | --- | --- | --- | --- |
| 30 · `kimbap-truyen-thong` | Kimbap truyền thống | — | Cơm cuộn rong biển với cà rốt và hành tây mang đến vị nhẹ, phù hợp để ăn xen trong bữa có mì cay. Lớp rong biển ôm phần cơm, còn cà rốt và hành tây tạo thêm hương vị rau trong từng miếng cuộn. Bản truyền thống hợp khi bạn thích vị cơm và rong biển rõ, hoặc muốn gọi một món phụ dễ chia sẻ. Bạn có thể thưởng thức riêng từng miếng rồi quay lại món chính để đổi vị. | — |
| 31 · `kimbap-chien` | Kimbap chiên | — | Kimbap chiên biến tấu món cơm cuộn rong biển bằng lớp ngoài có độ giòn, làm nổi bật phần cơm mềm bên trong. Cà rốt tạo thêm vị rau, còn bột mì có trong công thức của phiên bản chiên. Nếu thích kết cấu giòn nhưng vẫn muốn ăn cơm cuộn, đây là lựa chọn để thử bên cạnh kimbap truyền thống. Bạn có thể gọi làm món phụ và ăn xen giữa các món có nước dùng hoặc sốt để cảm nhận rõ sự khác biệt. | Cách tạo lớp vỏ chiên và có dùng lớp áo bột riêng hay không. |
| 32 · `kimbap-pho-mai` | Kimbap phô mai | — | Phô mai mozzarella tạo vị béo sữa cho phần cơm cuộn rong biển có cà rốt. Khi ăn, bạn có thể cảm nhận vị rong biển trước rồi đến phần cơm và phô mai trong miếng cuộn. Món hợp với người thích kimbap có vị đậm hơn bản truyền thống và muốn thêm một lựa chọn dễ chia sẻ vào bữa mì. Nếu gọi cùng các món cay, hãy thử một miếng kimbap giữa hai lần nếm nước dùng để đổi vị trong bữa. | — |
| 33 · `tokbokki-pho-mai` | Tokbokki phô mai | — | Bánh gạo dai dẻo đi cùng tương gochujang và phô mai mozzarella, tạo sự kết hợp giữa vị cay và vị béo sữa. Mozzarella cho cảm giác mềm khi ăn cùng bánh gạo, còn tương ớt mang nét đậm vị đặc trưng của tokbokki trong thực đơn. Món hợp khi bạn muốn một phần bánh gạo có phô mai để ăn riêng hoặc chia cùng bàn. Nếu gọi kèm mì, hãy thử ăn xen để thấy độ dẻo của bánh gạo khác với sợi mì. | Mức cay và cách phủ hoặc trộn phô mai. |
| 34 · `tokbokki-cay-cap-3` | Tokbokki cay cấp 3 | — | Tokbokki cay cấp 3 dành cho người muốn thưởng thức bánh gạo dai dẻo với vị cay rõ. Tương gochujang và bột ớt Hàn Quốc có trong công thức, tạo lớp vị cay đậm đi cùng mỗi miếng bánh gạo. Đây là lựa chọn thích hợp khi bạn ưu tiên vị ớt hơn vị béo của phiên bản phô mai. Bạn có thể ăn từng miếng chậm để cảm nhận độ dẻo của bánh và tự cân nhắc cấp cay đã ghi trên thực đơn. | Mức cay cấp 3 theo tên món. Xác nhận thang cấp dùng cho tokbokki nếu cần giải thích thêm. |
| 35 · `ga-ran-han-quoc` | Gà rán Hàn Quốc | — | Gà rán Hàn Quốc mang đến phần vỏ giòn và thịt gà bên trong, với tỏi và gừng tạo hương thơm trong từng miếng gà. Điểm hấp dẫn của món nằm ở sự tương phản giữa miếng gà rán và hương thơm nhẹ của gia vị khi ăn. Bạn có thể gọi gà như một món phụ để chia sẻ hoặc ăn xen giữa các đũa mì. Đây là lựa chọn phù hợp nếu bạn muốn thưởng thức vị gà rán cùng hương tỏi, gừng. | Phần thịt, thành phần lớp áo và có sốt ăn kèm hay không. |
| 36 · `ga-ran-sot-cay` | Gà rán sốt cay | — | Miếng gà rán đi cùng sốt cay có tương gochujang và tỏi, tạo vị đậm ngay từ phần sốt bám bên ngoài. Trong cùng một miếng, bạn có thể cảm nhận lớp vỏ rán thấm sốt rồi đến thịt gà bên trong. Món hợp khi muốn thêm một phần gà có vị cay nổi bật vào bữa ăn, thay vì chọn vị gà rán với tỏi và gừng. Nếu dùng chung với mì, hãy cân nhắc theo khẩu vị cay của cả bàn. | Mức cay, thành phần lớp áo và phần thịt. |
| 37 · `mandu-banh-xep-chien` | Mandu (bánh xếp) chiên | — | Mandu là bánh xếp được chiên để lớp vỏ có độ giòn, phù hợp với người thích món phụ nhỏ dễ gắp. Vỏ bánh và phần nhân mang hai kết cấu trong một miếng ăn, giúp đổi vị giữa các món chính có nước dùng. Bạn có thể gọi một phần để chia sẻ hoặc thưởng thức riêng như món ăn kèm trong bữa mì. Thử một miếng mandu giữa hai đũa mì để cảm nhận rõ hơn độ giòn của lớp vỏ chiên. | Loại nhân của mandu thành phẩm và món ăn kèm nếu có. |
| 38 · `bap-xao-pho-mai-han-quoc` | Bắp xào phô mai Hàn Quốc | — | Bắp ngô được xào cùng mozzarella, để vị ngọt của hạt bắp đi với vị béo sữa của phô mai. Khi ăn, bạn sẽ cảm nhận độ mọng của hạt bắp và độ mềm của phô mai trong cùng một muỗng. Đây là món phụ hợp để chia sẻ, đặc biệt khi bàn đã gọi những món có vị cay hoặc vị mặn đậm. Bạn có thể dùng bắp xen giữa các món chính để có thêm một nhịp ngọt béo trong bữa. | Cách phục vụ và gia vị bổ sung nếu có. |

## Tráng miệng (3)

| Mã món ăn | Tên món ăn | Mô tả hiện tại | Mô tả đề xuất | Thông tin cần xác minh |
| --- | --- | --- | --- | --- |
| 39 · `bingsu-sua-dau` | Bingsu sữa dâu | — | Bingsu sữa dâu kết hợp nền sữa tươi lạnh với dâu tây tươi và siro dâu. Vị chua ngọt của dâu làm phần sữa nổi bật hơn, còn siro đưa hương dâu vào những muỗng ăn có cả sữa và trái cây. Nếu vừa ăn mì cay hoặc gà sốt, đây là món tráng miệng có vị mát và hương trái cây rõ để đổi vị. Bạn có thể thử một muỗng có đủ phần sữa và dâu để cảm nhận sự cân bằng giữa hai vị. | Kết cấu nền sữa và cách trình bày dâu. |
| 40 · `hotteok-banh-kep-ngot` | Hotteok bánh kếp ngọt | — | Hotteok là bánh kếp ngọt có bột mì và men nở, với đường nâu, bột quế cùng óc chó trong công thức. Vị ngọt của đường nâu đi cùng mùi quế, còn óc chó tạo thêm cảm giác bùi khi ăn bánh. Món phù hợp nếu bạn muốn kết thúc bữa bằng một phần bánh thay cho các món bingsu lạnh. Bạn có thể thưởng thức từng miếng nhỏ để cảm nhận lần lượt hương quế và vị bùi đặc trưng của óc chó. | Cách áp chảo và vị trí phần óc chó trong bánh. |
| 41 · `patbingsu-dau-do` | Patbingsu đậu đỏ | — | Patbingsu kết hợp sữa tươi, sữa đặc và đậu đỏ ngọt, thêm xoài cùng bánh gạo ngọt để tạo nhiều vị và kết cấu trong một phần tráng miệng. Đậu đỏ cho vị bùi, xoài mang hương trái cây, còn bánh gạo đem lại độ dẻo khi ăn xen với phần sữa lạnh. Món hợp với người muốn một phần bingsu phong phú hơn kiểu sữa và trái cây đơn thuần. Thử múc một ít từng thành phần để chọn cách phối vị mình thích. | Cách chuẩn bị nền sữa và trình bày topping. |

## Đồ uống (12)

| Mã món ăn | Tên món ăn | Mô tả hiện tại | Mô tả đề xuất | Thông tin cần xác minh |
| --- | --- | --- | --- | --- |
| 42 · `tra-sua-tran-chau` | Trà sữa trân châu | — | Trà đen và sữa tươi tạo nền thơm béo, đi cùng trân châu cho độ dai khi uống. Vị trà vẫn là phần dễ nhận ra giữa những ngụm sữa, còn trân châu mang đến một nhịp nhai nhẹ để đổi kết cấu. Đây là lựa chọn dành cho người thích kiểu trà sữa có topping quen thuộc trong thực đơn. Bạn có thể gọi uống cùng món phụ hoặc dùng riêng khi muốn một ly có cả hương trà và vị sữa. | Loại trân châu và tùy chọn đường, đá nếu có. |
| 43 · `tra-sua-han-quoc-chuseok` | Trà sữa Hàn Quốc (Chuseok) | — | Phiên bản Chuseok phối trà đen, sữa tươi và trân châu cùng đào, tạo điểm khác biệt so với ly trà sữa trân châu thường. Vị béo của sữa đi với hương đào và nền trà đen, còn trân châu thêm độ dai trong từng ngụm. Món hợp khi bạn muốn uống trà sữa nhưng thích có thêm một vị trái cây để đổi vị. Thử thưởng thức chậm để cảm nhận rõ hương đào, vị trà và độ dai của trân châu. | Cách sử dụng đào, loại trân châu và tùy chọn đường, đá. |
| 44 · `tra-dao` | Trà đào | — | Trà đào dùng trà đen kết hợp với đào, để vị trà đậm nhẹ đi cùng hương trái cây dễ nhận ra. Khi uống, phần trà và vị ngọt của đào tạo hai lớp vị rõ, phù hợp nếu bạn thích đồ uống có hương trái cây. Đây là lựa chọn để dùng xen với bữa mì hoặc các món chiên khi muốn một ngụm trà thay cho trà sữa. Vị đào cũng đủ nổi bật để gọi riêng và thưởng thức giữa bữa. | Cách dùng đào và tùy chọn đường, đá nếu có. |
| 45 · `soju-vi-tao` | Soju vị táo | — | Soju vị táo là thức uống có cồn dành cho khách đủ tuổi muốn thưởng thức hương táo trong bữa ăn. Vị táo là điểm khác biệt với các lựa chọn đào, dâu và việt quất trên thực đơn, còn vị rượu vẫn là một phần của trải nghiệm. Bạn có thể dùng từng ngụm nhỏ cùng món phụ để cảm nhận rõ hương trái cây. Đây là lựa chọn phù hợp nếu bạn đã biết mình thích hương táo và muốn dùng soju trong bữa. | Nhãn hiệu, nồng độ cồn và dung tích. |
| 46 · `soju-vi-dao` | Soju vị đào | — | Vị đào mang nét hương trái cây riêng cho dòng soju, thích hợp với người ưu tiên hương đào khi chọn thức uống có cồn. Trong bữa có nhiều món, bạn có thể dùng từng ngụm nhỏ để cảm nhận vị rượu đi cùng hương trái cây giữa các lần đổi món. Soju vị đào là một trong các lựa chọn của thực đơn dành cho khách đủ tuổi. Nếu đang phân vân giữa các vị, hãy chọn theo loại trái cây bạn thích. | Nhãn hiệu, nồng độ cồn và dung tích. |
| 47 · `soju-vi-dau` | Soju vị dâu | — | Soju vị dâu kết hợp vị rượu với hương dâu, mang đến lựa chọn dành cho khách đủ tuổi thích mùi trái cây này. Hương dâu là điểm nhận diện chính của phiên bản, khác với vị đào, táo hoặc việt quất cùng nhóm đồ uống. Bạn có thể thưởng thức từng ngụm nhỏ trong bữa để cảm nhận hương dâu xen với vị của món ăn. Nếu cả bàn có sở thích khác nhau, đây là vị để cân nhắc cho người thích dâu. | Nhãn hiệu, nồng độ cồn và dung tích. |
| 48 · `soju-vi-viet-quat` | Soju vị việt quất | — | Việt quất tạo nét hương quả mọng cho phiên bản soju này, phù hợp với khách đủ tuổi muốn thử vị khác với táo hoặc đào. Khi thưởng thức, bạn vẫn cảm nhận được vị rượu cùng hương trái cây, nên có thể dùng từng ngụm nhỏ trong bữa ăn. Món uống này thích hợp khi bạn ưu tiên hương việt quất thay vì chọn theo nhóm đồ uống có ga. Bạn có thể dùng kèm món phụ nếu muốn xen hương quả mọng giữa các miếng ăn. | Nhãn hiệu, nồng độ cồn và dung tích. |
| 49 · `coca-cola` | Coca Cola | — | Coca Cola là nước ngọt có ga dạng lon, nổi bật với hương cola quen thuộc và vị ngọt rõ. Bọt ga tạo cảm giác sủi nhẹ ở đầu lưỡi, phù hợp nếu bạn thích một thức uống có ga để dùng xen giữa các món ăn. Bạn có thể chọn Coca Cola cùng một phần gà rán, cơm cuộn hoặc mì tùy khẩu vị. Đây là lựa chọn có vị cola cho người muốn đổi giữa các ngụm nước và món chính trong bữa. | Phiên bản và dung tích lon. |
| 50 · `pepsi` | Pepsi | — | Pepsi phục vụ dạng lon, dành cho người thích nước ngọt có ga với hương cola và vị ngọt dễ nhận ra. Cảm giác sủi bọt là nét rõ của đồ uống này, phù hợp khi bạn muốn một lựa chọn có ga để uống kèm món phụ. Thử một ngụm sau miếng mandu hoặc kimbap chiên để đổi vị giữa bữa. Nếu bạn thường chọn Pepsi khi ăn ngoài, thực đơn có sẵn lựa chọn này để gọi cùng món chính mình thích. | Phiên bản và dung tích lon. |
| 51 · `7-up` | 7-Up | — | 7-Up là nước ngọt có ga dạng lon với hương chanh và vị ngọt, phù hợp cho người muốn đổi từ hương cola sang hương trái cây. Bọt ga cho cảm giác sủi nhẹ khi uống, còn hương chanh dễ nhận ra sau từng ngụm. Bạn có thể gọi kèm cơm cuộn, bắp xào phô mai hoặc món mì theo sở thích. Đây là một lựa chọn khi bạn muốn đồ uống có ga và ưu tiên hương chanh trong nhóm nước ngọt của quán. | Phiên bản và dung tích lon. |
| 52 · `sting` | Sting | — | Sting là nước tăng lực có ga phục vụ dạng lon, phù hợp khi bạn muốn chọn nhãn đồ uống quen thuộc trong nhóm nước ngọt. Vị ngọt và cảm giác sủi ga là những nét nổi bật khi uống kèm bữa ăn. Bạn có thể dùng từng ngụm xen giữa các món phụ, hoặc chọn uống cùng một phần mì theo khẩu vị. Nếu bạn thường uống một phiên bản Sting cụ thể, hãy hỏi nhân viên về vị đang phục vụ trước khi gọi. | Hương vị, phiên bản và dung tích lon. |
| 53 · `soda-dau` | Soda dâu | — | Soda dâu kết hợp soda có ga với siro dâu, mang vị ngọt và hương dâu rõ trong từng ngụm. Bọt ga tạo cảm giác sủi nhẹ, khác với kết cấu béo của trà sữa hay cảm giác uống trà đào. Bạn có thể gọi cùng kimbap hoặc các món chiên nếu muốn xen một ngụm có hương trái cây giữa các miếng ăn. Đây là lựa chọn phù hợp khi bạn yêu thích vị dâu và ưu tiên thức uống có ga. | Cách phục vụ và có dùng thêm dâu tươi hay không. |

