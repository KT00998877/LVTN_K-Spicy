import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/utils/money_vnd.dart';
import '../../../core/utils/validators.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../checkout/data/checkout_repository.dart';
import '../../checkout/domain/delivery_point.dart';
import '../../checkout/presentation/delivery_map_picker_screen.dart';
import '../../orders/presentation/order_controller.dart';
import '../../voucher/data/voucher_models.dart';
import '../../voucher/presentation/voucher_picker_field.dart';
import 'cart_controller.dart';

class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key});
  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _note = TextEditingController();
  final _points = TextEditingController(text: '0');
  String _type = 'ONLINE';
  String _method = 'COD';
  DeliveryPoint? _point;
  CheckoutQuote? _quote;
  String? _error;
  bool _quoting = false;
  bool _submitting = false;
  bool _openingMap = false;
  int _revision = 0;
  int? _quotedVersion;
  Timer? _debounce;
  Map<String, List<String>> _fieldErrors = {};

  /// Voucher đang áp dụng (lưu local thay vì chỉ text để show preview).
  VoucherPreview? _voucherPreview;

  @override
  void initState() {
    super.initState();
    final user = ref.read(authControllerProvider).user;
    _name.text = user?.name ?? '';
    _phone.text = user?.phone ?? '';
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      await ref.read(cartControllerProvider.notifier).refresh();
      if (mounted) _scheduleQuote();
    });
  }

  @override
  void dispose() {
    _revision++;
    _debounce?.cancel();
    for (final c in [_name, _phone, _address, _note, _points]) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _pricingInput => {
    'order_type': _type,
    'points_redeemed': int.tryParse(_points.text) ?? 0,
    if (_type == 'ONLINE' && _point != null) ...{
      'delivery_latitude': _point!.latitude,
      'delivery_longitude': _point!.longitude,
    },
    if (_voucherPreview != null) 'voucher_code': _voucherPreview!.code,
  };

  void _onVoucherChanged(VoucherPreview? preview) {
    // code == null → user bỏ voucher
    if (preview == null) {
      setState(() => _voucherPreview = null);
      _scheduleQuote();
      return;
    }
    // Khi user chọn mã mới (từ picker), đã có preview sẵn — set luôn
    // và trigger quote. Nếu user gõ tay (không qua picker), preview sẽ
    // được set khi nhận được kết quả từ quote (nếu backend trả data).
    setState(() {
      _voucherPreview = preview;
    });
    _scheduleQuote();
  }

  void _scheduleQuote() {
    final revision = ++_revision;
    _debounce?.cancel();
    setState(() {
      _quote = null;
      _error = null;
      _quoting = false;
      _fieldErrors = {};
    });
    final cart = ref.read(cartControllerProvider);
    if (cart.isEmpty ||
        cart.pendingCheckout != null ||
        (_type == 'ONLINE' && _point == null))
      return;
    if (int.tryParse(_points.text) == null || int.parse(_points.text) < 0)
      return;
    setState(() => _quoting = true);
    _debounce = Timer(const Duration(milliseconds: 300), () async {
      try {
        final version = ref.read(cartControllerProvider).cart?.version;
        final quote = await ref
            .read(checkoutRepositoryProvider)
            .quote(_pricingInput);
        if (!mounted || revision != _revision) return;
        // Sync VoucherPreview từ quote response (nếu backend trả về data).
        // Khi quote thành công → voucher hợp lệ → update preview.
        // Khi quote có voucher_error → preview vẫn giữ (để user thấy mã đã chọn).
        final voucherJson = quote.json['voucher'];
        VoucherPreview? freshPreview;
        if (voucherJson is Map<String, dynamic>) {
          try {
            freshPreview = VoucherPreview.fromJson(voucherJson);
          } catch (_) {
            /* ignore parse error */
          }
        }
        setState(() {
          if (freshPreview != null) _voucherPreview = freshPreview;
          _quote = quote;
          _quotedVersion = version;
          _error = quote.voucherError;
        });
      } on ApiException catch (e) {
        if (!mounted || revision != _revision) return;
        setState(() {
          _error = e.message;
          _fieldErrors = e.errors ?? {};
        });
      } catch (_) {
        if (mounted && revision == _revision)
          setState(
            () => _error = 'Không lấy được tổng tiền. Vui lòng thử lại.',
          );
      } finally {
        if (mounted && revision == _revision) setState(() => _quoting = false);
      }
    });
  }

  Future<void> _pickPoint({bool useCurrentLocation = false}) async {
    if (_openingMap) return;
    setState(() => _openingMap = true);
    try {
      final config = await ref.read(checkoutRepositoryProvider).config();
      if (!mounted) return;
      final initial =
          _point ??
          DeliveryPoint(
            latitude: (config['latitude'] as num).toDouble(),
            longitude: (config['longitude'] as num).toDouble(),
            address: config['restaurant_address']?.toString() ?? '',
          );
      final point = await DeliveryMapPickerScreen.open(
        context,
        initial: initial,
        locateOnOpen: useCurrentLocation,
      );
      if (!mounted || point == null) return;
      setState(() {
        _point = point;
        if (point.address.isNotEmpty) _address.text = point.address;
      });
      _scheduleQuote();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted)
        setState(() => _error = 'Không mở được bản đồ. Vui lòng thử lại.');
    } finally {
      if (mounted) setState(() => _openingMap = false);
    }
  }

  Future<void> _submit() async {
    if (_submitting) return;
    final state = ref.read(cartControllerProvider);
    if (state.isLoading || state.isSubmitting) return;
    if (state.pendingCheckout == null) {
      if (!_form.currentState!.validate()) return;
      if (_type == 'ONLINE' && _point == null) {
        setState(() => _error = 'Vui lòng chọn vị trí giao hàng trên bản đồ.');
        return;
      }
      if (_quote == null ||
          _quoting ||
          _quote!.voucherError != null ||
          _quotedVersion != state.cart?.version) {
        setState(
          () => _error =
              'Vui lòng cập nhật tổng tiền và kiểm tra ưu đãi trước khi đặt.',
        );
        return;
      }
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    final result = await ref.read(cartControllerProvider.notifier).checkout({
      ..._pricingInput,
      'payment_method': _method,
      'recipient_name': _name.text.trim(),
      'recipient_phone': _phone.text.trim(),
      if (_type == 'ONLINE') 'delivery_address': _address.text.trim(),
      if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
    });
    if (!mounted) return;
    setState(() => _submitting = false);
    if (result == null) {
      _scheduleQuote();
      return;
    }
    ref.invalidate(orderListControllerProvider);
    if (result.paymentMethod == 'PREPAID') {
      context.go('/orders/${result.orderId}/payment');
    } else {
      // COD / success: qua màn ăn mừng trước, từ đó mới tới OrderDetail
      context.go('/orders/${result.orderId}/success');
    }
  }

  Widget _heading(String text) => Padding(
    padding: const EdgeInsets.only(top: 24, bottom: 12),
    child: Text(text, style: Theme.of(context).textTheme.titleMedium),
  );

  Widget _money(String label, double value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      children: [
        Expanded(child: Text(label)),
        Text(MoneyVnd.format(value)),
      ],
    ),
  );

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(cartControllerProvider);
    ref.listen(cartControllerProvider.select((s) => s.cart?.version), (
      previous,
      next,
    ) {
      if (previous != next && !_submitting) _scheduleQuote();
    });
    final pending = state.pendingCheckout != null;
    final locked = pending || _submitting || state.isSubmitting;
    return Scaffold(
      appBar: AppBar(title: const Text('Xác nhận đặt món')),
      body: state.isLoading && state.cart == null
          ? const Center(child: CircularProgressIndicator())
          : Form(
              key: _form,
              child: ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  if (pending) ...[
                    const Text(
                      'Lần đặt trước chưa xác định kết quả. Kiểm tra lại trước khi sửa giỏ hoặc đặt đơn mới.',
                    ),
                    FilledButton(
                      onPressed: _submitting || state.isLoading
                          ? null
                          : _submit,
                      child: Text(
                        _submitting
                            ? 'Đang kiểm tra…'
                            : 'Kiểm tra kết quả đặt hàng',
                      ),
                    ),
                  ],
                  if (state.errorMessage != null)
                    Text(
                      state.errorMessage!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  if (state.isEmpty && !pending) ...[
                    const Text('Giỏ hàng trống hoặc chưa tải được.'),
                    OutlinedButton(
                      onPressed: () =>
                          ref.read(cartControllerProvider.notifier).refresh(),
                      child: const Text('Tải lại giỏ hàng'),
                    ),
                    TextButton(
                      onPressed: () => context.go('/home'),
                      child: const Text('Tiếp tục chọn món'),
                    ),
                  ],
                  if (state.isNotEmpty && !pending) ...[
                    AbsorbPointer(
                      absorbing: locked,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _heading('Hình thức nhận món'),
                          SegmentedButton<String>(
                            segments: const [
                              ButtonSegment(
                                value: 'ONLINE',
                                label: Text('Giao hàng'),
                                icon: Icon(Icons.delivery_dining),
                              ),
                              ButtonSegment(
                                value: 'TAKEAWAY',
                                label: Text('Mang đi'),
                                icon: Icon(Icons.shopping_bag_outlined),
                              ),
                            ],
                            selected: {_type},
                            onSelectionChanged: (v) {
                              setState(() => _type = v.first);
                              _scheduleQuote();
                            },
                          ),
                          _heading('Ưu đãi'),
                          VoucherPickerField(
                            appliedCode: _voucherPreview?.code,
                            preview: _voucherPreview,
                            subtotal: state.subtotal,
                            onChanged: _onVoucherChanged,
                            errorText: _quote?.voucherError,
                            enabled: !locked,
                          ),
                          TextFormField(
                            controller: _points,
                            key: const ValueKey('checkout-points'),
                            inputFormatters: [
                              FilteringTextInputFormatter.digitsOnly,
                            ],
                            autovalidateMode:
                                AutovalidateMode.onUserInteraction,
                            keyboardType: TextInputType.number,
                            decoration: InputDecoration(
                              labelText: 'Điểm muốn sử dụng',
                              helperText: _quote == null
                                  ? 'Bạn có ${ref.watch(authControllerProvider).user?.loyaltyPoints ?? 0} điểm. Chọn địa điểm để tính giới hạn sử dụng.'
                                  : 'Có ${_quote!.balance} điểm • Dùng tối đa ${_quote!.maxPoints} điểm',
                              errorText: _fieldErrors['points_redeemed']?.join(
                                '\n',
                              ),
                            ),
                            validator: (v) =>
                                int.tryParse(v ?? '') == null ||
                                    int.parse(v!) < 0
                                ? 'Nhập số điểm nguyên từ 0'
                                : null,
                            onChanged: (_) => _scheduleQuote(),
                          ),
                          _heading('Thông tin người nhận'),
                          TextFormField(
                            controller: _name,
                            maxLength: 100,
                            decoration: const InputDecoration(
                              labelText: 'Họ tên',
                            ),
                            validator: Validators.recipientName,
                          ),
                          TextFormField(
                            controller: _phone,
                            keyboardType: TextInputType.phone,
                            decoration: const InputDecoration(
                              labelText: 'Số điện thoại',
                            ),
                            validator: Validators.vietnamPhone,
                          ),
                          if (_type == 'ONLINE') ...[
                            const SizedBox(height: 12),
                            TextFormField(
                              controller: _address,
                              maxLength: 500,
                              maxLines: 2,
                              decoration: const InputDecoration(
                                labelText: 'Địa chỉ giao hàng',
                              ),
                              validator: Validators.deliveryAddress,
                            ),
                            OutlinedButton.icon(
                              onPressed: _openingMap
                                  ? null
                                  : () => _pickPoint(),
                              icon: const Icon(Icons.map_outlined),
                              label: Text(
                                _point == null
                                    ? 'Chọn vị trí giao hàng (bắt buộc)'
                                    : 'Đã ghim vị trí • Thay đổi',
                              ),
                            ),
                            TextButton.icon(
                              onPressed: _openingMap
                                  ? null
                                  : () => _pickPoint(useCurrentLocation: true),
                              icon: const Icon(Icons.my_location_rounded),
                              label: Text(
                                _openingMap
                                    ? 'Đang mở bản đồ…'
                                    : 'Dùng vị trí hiện tại của tôi',
                              ),
                            ),
                          ],
                          _heading('Thanh toán'),
                          SegmentedButton<String>(
                            segments: const [
                              ButtonSegment(
                                value: 'COD',
                                label: Text('Khi nhận món'),
                              ),
                              ButtonSegment(
                                value: 'PREPAID',
                                label: Text('VNPay'),
                              ),
                            ],
                            selected: {_method},
                            onSelectionChanged: (v) =>
                                setState(() => _method = v.first),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            _method == 'PREPAID'
                                ? 'Thanh toán qua ngân hàng / QR trên VNPay Sandbox.'
                                : 'Trả tiền khi nhận món hoặc đến quán lấy.',
                          ),
                          _heading('Ghi chú (tùy chọn)'),
                          TextFormField(
                            controller: _note,
                            maxLength: 500,
                            maxLines: 2,
                            decoration: const InputDecoration(
                              labelText: 'Yêu cầu thêm cho đơn hàng',
                            ),
                          ),
                        ],
                      ),
                    ),
                    _heading('Món đã chọn'),
                    for (final item in state.cart!.items)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text('${item.quantity} × ${item.menuItemName}'),
                        subtitle: Text(
                          [
                            'Cấp cay ${item.spicyLevel}',
                            ...item.toppings.map((t) => t.name),
                            if (item.note?.isNotEmpty == true) item.note!,
                          ].join(' • '),
                        ),
                        trailing: Text(MoneyVnd.format(item.subtotal)),
                      ),
                    _money('Tạm tính', state.subtotal),
                    if (_quoting) const LinearProgressIndicator(),
                    if (_quote != null) ...[
                      _money('Phí giao hàng', _quote!.amount('shipping_fee')),
                      _money(
                        'Giảm voucher',
                        -_quote!.amount('voucher_discount'),
                      ),
                      _money(
                        'Giảm điểm thưởng',
                        -_quote!.amount('points_discount'),
                      ),
                      const Divider(),
                      _money('Tổng thanh toán', _quote!.amount('total')),
                    ],
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        child: Text(
                          _error!,
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                      ),
                    if (_type == 'ONLINE' && _point == null)
                      const Text(
                        'Chọn vị trí trên bản đồ để tính phí giao hàng.',
                      ),
                    OutlinedButton(
                      onPressed: locked || _quoting ? null : _scheduleQuote,
                      child: const Text('Cập nhật tổng tiền'),
                    ),
                    FilledButton(
                      onPressed: locked || state.isLoading ? null : _submit,
                      child: Text(
                        _submitting ? 'Đang đặt món…' : 'Xác nhận đặt món',
                      ),
                    ),
                  ],
                ],
              ),
            ),
    );
  }
}
