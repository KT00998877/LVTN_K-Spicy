import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/api/dio_client.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../orders/presentation/order_controller.dart';
import '../data/payment_repository.dart';

/// VNPay returns through the mobile deep link. On resume, query backend status.
class PaymentScreen extends ConsumerStatefulWidget {
  const PaymentScreen({super.key, required this.orderId});
  final int orderId;
  @override
  ConsumerState<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends ConsumerState<PaymentScreen>
    with WidgetsBindingObserver {
  String? _code;
  String? _error;
  String _status = 'pending';
  bool _busy = false;
  bool _checking = false;
  bool _canRetry = false;
  Timer? _timer;
  late final String _storageKey;
  late final String? _token;
  bool get _current =>
      mounted && ref.read(authControllerProvider).token == _token;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _token = ref.read(authControllerProvider).token;
    _storageKey =
        'vnpay:${ref.read(authControllerProvider).user?.id}:${widget.orderId}';
    _restore();
  }

  Future<void> _restore() async {
    try {
      _code = await secureStorage.read(key: _storageKey);
      if (!_current) return;
      if (_code == null) {
        await _start();
      } else {
        await _check();
      }
      if (_current)
        _timer = Timer.periodic(const Duration(seconds: 5), (_) => _check());
    } catch (e) {
      if (_current) setState(() => _error = _message(e));
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _check();
  }

  Future<void> _start() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final payment = await ref
          .read(paymentRepositoryProvider)
          .create(widget.orderId, platform: 'mobile');
      if (!_current) return;
      _code = payment['payment_code'] as String;
      await secureStorage.write(key: _storageKey, value: _code);
      if (!_current) return;
      setState(() {
        _status = 'pending';
        _canRetry = false;
      });
      final url = Uri.parse(payment['payment_url'] as String);
      if (url.scheme != 'https' ||
          !await launchUrl(url, mode: LaunchMode.externalApplication)) {
        throw const ApiException(
          message: 'Không mở được VNPay. Bấm mở lại thanh toán.',
        );
      }
    } catch (e) {
      if (_current) setState(() => _error = _message(e));
    } finally {
      if (_current) setState(() => _busy = false);
    }
  }

  Future<void> _check() async {
    if (!_current || _code == null || _checking || _busy || _status == 'paid')
      return;
    _checking = true;
    try {
      final result = await ref.read(paymentRepositoryProvider).status(_code!);
      if (!_current) return;
      setState(() {
        _status = result['status'] as String;
        _canRetry = result['can_retry'] == true;
        _error = null;
      });
      ref.invalidate(orderDetailControllerProvider(widget.orderId));
      ref.invalidate(orderListControllerProvider);
      if (['paid', 'failed', 'cancelled', 'refunded'].contains(_status)) {
        _timer?.cancel();
      }
      // Nếu thanh toán thành công → đưa user qua màn ăn mừng (sẽ tự chuyển tiếp)
      if (_status == 'paid' && mounted) {
        context.go('/orders/${widget.orderId}/success');
      }
    } catch (e) {
      if (_current) setState(() => _error = _message(e));
    } finally {
      _checking = false;
    }
  }

  String _message(Object e) => e is ApiException
      ? e.message
      : 'Không kiểm tra được thanh toán. Vui lòng thử lại.';

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Thanh toán VNPay')),
    body: ListView(
      padding: const EdgeInsets.all(24),
      children: [
        const Icon(Icons.payment, size: 64),
        const SizedBox(height: 24),
        Text(switch (_status) {
          'paid' => 'Thanh toán thành công',
          'failed' => 'Thanh toán thất bại',
          'cancelled' => 'Bạn đã hủy thanh toán',
          'expired' => 'Phiên thanh toán đã hết hạn',
          'refunded' => 'Thanh toán đã được hoàn tiền',
          _ => 'Đơn đã tạo, đang chờ thanh toán',
        }, style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 12),
        if (_status == 'pending')
          const Text(
            'Hoàn tất VNPay trong trình duyệt rồi quay lại ứng dụng. Trạng thái sẽ được cập nhật từ nhà hàng.',
          ),
        if (_error != null) Text(_error!, semanticsLabel: _error),
        if (_busy) const LinearProgressIndicator(),
        if (_status == 'pending' || _canRetry)
          FilledButton(
            onPressed: _busy ? null : _start,
            child: const Text('Mở thanh toán VNPay'),
          ),
        if (_status != 'paid')
          OutlinedButton(
            onPressed: _busy ? null : _check,
            child: const Text('Kiểm tra thanh toán'),
          ),
        TextButton(
          onPressed: () => context.go('/orders/${widget.orderId}'),
          child: const Text('Xem chi tiết đơn'),
        ),
      ],
    ),
  );
}
