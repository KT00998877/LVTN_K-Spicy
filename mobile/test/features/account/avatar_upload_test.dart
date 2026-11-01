import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:micay_app/core/utils/backend_image_url.dart';
import 'package:micay_app/features/account/data/account_repository.dart';

void main() {
  test('upload avatar dùng bytes của XFile thay vì đường dẫn thiết bị', () async {
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost:8000/api'));
    FormData? sentForm;
    dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      sentForm = options.data as FormData;
      handler.resolve(Response<void>(requestOptions: options, statusCode: 200));
    }));

    final image = XFile.fromData(
      Uint8List.fromList([1, 2, 3]),
      name: 'avatar.png',
      mimeType: 'image/png',
    );
    await AccountRepository(dio).uploadAvatar(image);

    expect(sentForm?.files.single.key, 'avatar');
    expect(sentForm?.files.single.value.filename, 'avatar.jpg');
    expect(await sentForm?.files.single.value.finalize().toList(), isNotEmpty);
  });

  test('URL avatar localhost dùng host API trên điện thoại', () {
    expect(
      backendImageUrl(
        'http://localhost:8000/storage/avatars/1.png',
        baseUrl: 'http://172.20.10.4:8000/api',
      ),
      'http://172.20.10.4:8000/storage/avatars/1.png',
    );
  });
}
