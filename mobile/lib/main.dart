import 'package:flutter/material.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/app.dart';
import 'core/config.dart';

/// ============================================================
/// 🚀 ENTRY POINT
/// ============================================================
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Load .env một lần trước khi runApp — ApiConfig.baseUrl sẽ đọc
  // từ đây. Nếu .env thiếu → ApiConfig tự fallback, không crash.
  try {
    await ApiConfig.load();
  } catch (e) {
    debugPrint('ApiConfig.load() failed: $e');
    // Vẫn cho phép app chạy — ApiConfig.baseUrl sẽ dùng default
    // (127.0.0.1 cho web, 10.0.2.2 cho Android emulator).
    try {
      // Thử load lại không chỉ định fileName (default)
      await dotenv.load();
    } catch (_) {
      // Bỏ qua — app sẽ dùng fallback host.
    }
  }

  runApp(
    const ProviderScope(
      child: MicayApp(),
    ),
  );
}
