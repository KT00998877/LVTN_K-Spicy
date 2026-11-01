import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:micay_app/features/cart/data/cart_repository.dart';
import 'package:micay_app/features/menu/domain/menu_models.dart';

void main() {
  test('món trong danh mục combo giữ thông tin nước và giá combo', () {
    final item = MenuItem.fromJson({
      'id': 16,
      'name': 'Mì cay thập cẩm lớn',
      'slug': 'mi-cay-thap-cam-lon',
      'price': 100000,
      'suggestions': [
        {
          'id': 2,
          'title': 'Combo Mì cay thập cẩm lớn + Soda dâu',
          'combo_price_formatted': '110.000 đ',
          'suggested_item': {
            'name': 'Soda dâu',
            'image_url': '/storage/menu/soda-dau.png',
          },
        },
      ],
    });

    expect(item.suggestions.single.suggestedItemName, 'Soda dâu');
    expect(item.suggestions.single.comboPrice, '110.000 đ');
  });

  test('thêm combo gửi suggestion và món chính tới đúng endpoint', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost:8000/api'));
    RequestOptions? sent;
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      sent = options;
      handler.resolve(Response<Map<String, dynamic>>(
        requestOptions: options,
        statusCode: 201,
        data: {'success': true},
      ));
    }));

    await CartRepository(dio).addCombo(
      suggestionId: 2,
      menuItemId: 16,
      quantity: 1,
      spicyLevel: 3,
      note: '',
      toppingIds: [],
    );

    expect(sent?.path, '/cart/combo');
    final body = sent?.data as Map;
    expect(body['suggestion_id'], 2);
    expect((body['main'] as Map)['menu_item_id'], 16);
  });
}
