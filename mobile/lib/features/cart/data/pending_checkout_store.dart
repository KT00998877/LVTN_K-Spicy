import 'dart:convert';
import '../../../core/api/dio_client.dart';

/// Persist the exact request before sending, scoped to the signed-in customer.
class PendingCheckoutStore {
  PendingCheckoutStore(this.userId);
  final int userId;
  String get key => 'checkout_pending:$userId';

  Future<Map<String, dynamic>?> read() async {
    final raw = await secureStorage.read(key: key);
    return raw == null
        ? null
        : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  Future<void> write(Map<String, dynamic> value) =>
      secureStorage.write(key: key, value: jsonEncode(value));

  Future<void> clear() => secureStorage.delete(key: key);
}
