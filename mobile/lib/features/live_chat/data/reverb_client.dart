import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import '../../../core/config.dart';
import '../data/live_chat_repository.dart';

final reverbClientProvider = Provider<ReverbClient>(
  (ref) => ReverbClient(ref.watch(liveChatRepositoryProvider)),
);

/// ============================================================
/// 🔌 REVERB CLIENT — nghe kênh realtime của Laravel Reverb
/// ============================================================
///
/// Reverb dùng nghi thức Pusher. Client ở đây chỉ cần làm đúng ba việc:
///
///   1. Mở WebSocket tới host Reverb và chờ `pusher:connection_established`
///      để lấy `socket_id` thật.
///   2. Với mỗi kênh private, gọi API Laravel để lấy chữ ký `auth` rồi mới
///      gửi lệnh `pusher:subscribe`.
///   3. Nhận sự kiện và chuyển tiếp qua stream.
///
/// Không dùng `pusher_channels_flutter`: gói đó kéo theo nhiều phụ thuộc và
/// có API không tương thích với các phiên bản Dart mới, trong khi giao thức ở
/// đây chỉ vài chục dòng và dễ kiểm soát hơn.
class ReverbClient {
  ReverbClient(this._repo);

  final LiveChatRepository _repo;

  WebSocketChannel? _channel;
  StreamSubscription<dynamic>? _subscription;

  /// Sự kiện nhận từ kênh, đã bóc tên event.
  final _events = StreamController<Map<String, dynamic>>.broadcast();

  Stream<Map<String, dynamic>> get events => _events.stream;

  bool _connecting = false;
  bool _disposed = false;

  /// `socket_id` do Reverb cấp khi kết nối — dùng để xác thực kênh.
  String? _socketId;

  /// Các kênh đang muốn nghe; chỉ gửi subscribe sau khi có socket_id.
  final Set<String> _pendingChannels = {};

  bool get isConnected => _channel != null;

  /// Đăng ký nghe một kênh private của Reverb.
  Future<void> subscribe(String channelName) async {
    if (_disposed) return;
    if (_connecting) return;
    _connecting = true;

    try {
      final appKey = ApiConfig.reverbAppKey;
      if (appKey.isEmpty) {
        // Chưa cấu hình realtime → màn hình vẫn dùng được qua HTTP.
        return;
      }

      _pendingChannels.add(channelName);
      await _teardown();

      final scheme = ApiConfig.reverbSecure ? 'wss' : 'ws';
      final uri = Uri(
        scheme: scheme,
        host: ApiConfig.reverbHost,
        port: ApiConfig.reverbPort,
        path: '/app/$appKey',
        queryParameters: {
          'protocol': '7',
          'client': 'flutter',
          'version': '1.0',
        },
      );

      final channel = WebSocketChannel.connect(uri);
      _channel = channel;

      _subscription = channel.stream.listen(
        _handleFrame,
        onDone: _handleClosed,
        onError: (_) => _handleClosed(),
        cancelOnError: false,
      );
    } catch (_) {
      // Realtime là tiện ích bổ sung — không có nó thì vẫn chat qua HTTP được.
      await _teardown();
    } finally {
      _connecting = false;
    }
  }

  /// Ngắng kết nối.
  Future<void> disconnect() async {
    _pendingChannels.clear();
    _socketId = null;
    if (_channel != null) {
      await _send({'event': 'pusher:unsubscribe'});
    }
    await _teardown();
  }

  Future<void> dispose() async {
    _disposed = true;
    await disconnect();
    await _events.close();
  }

  // ── Protocol ───────────────────────────────────────────────

  Future<void> _send(Map<String, dynamic> message) async {
    final channel = _channel;
    if (channel == null) return;
    channel.sink.add(jsonEncode(message));
  }

  void _handleFrame(dynamic raw) {
    if (raw is! String) return;
    final Map<String, dynamic> frame;
    try {
      frame = jsonDecode(raw) as Map<String, dynamic>;
    } catch (_) {
      return;
    }

    final event = frame['event'] as String?;

    switch (event) {
      case 'pusher:connection_established':
        final data = frame['data'];
        if (data is Map) {
          _socketId = data['socket_id'] as String?;
        }
        // Có socket_id rồi thì ký được các kênh đang chờ.
        unawaited(_subscribePendingChannels());

      case 'pusher_internal:subscription_succeeded':
        break;

      case 'pusher:error':
        // Hủy kênh sai hoặc hết hạn → im lặng, không spam log.
        break;

      case null:
        break;

      default:
        // Event của ứng dụng: `live-chat.message.sent` (đã bỏ dấu chấm dẫn).
        if (!event.startsWith('live-chat')) return;
        final data = frame['data'];
        if (data is Map) {
          _events.add(Map<String, dynamic>.from(data));
        }
    }
  }

  /// Ký và subscribe từng kênh đang chờ bằng `socket_id` thật.
  Future<void> _subscribePendingChannels() async {
    final socketId = _socketId;
    if (socketId == null || _channel == null) return;

    for (final channelName in List<String>.from(_pendingChannels)) {
      try {
        final auth = await _repo.authenticateChannel(
          socketId: socketId,
          channelName: channelName,
        );
        await _send({
          'event': 'pusher:subscribe',
          'data': {
            'auth': auth['auth'],
            'channel': 'private-$channelName',
            if (auth['channel_data'] != null)
              'channel_data': auth['channel_data'],
          },
        });
      } catch (_) {
        // Không ký được kênh này → bỏ qua, chat vẫn chạy qua HTTP.
        _pendingChannels.remove(channelName);
      }
    }
  }

  void _handleClosed() {
    _subscription?.cancel();
    _subscription = null;
    _channel = null;
    _socketId = null;
  }

  Future<void> _teardown() async {
    await _subscription?.cancel();
    _subscription = null;
    try {
      await _channel?.sink.close();
    } catch (_) {
      // Kênh đã đóng sẵn — bỏ qua.
    }
    _channel = null;
    _socketId = null;
  }
}
