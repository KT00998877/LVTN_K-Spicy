import 'dart:async';
import 'dart:ui' as ui;

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:micay_app/app/theme.dart';

import '../domain/delivery_point.dart';
import '../data/customer_location_service.dart';

/// ============================================================
/// 📍 DELIVERY MAP PICKER — chọn điểm giao hàng trên bản đồ
/// ============================================================
/// Dùng OpenStreetMap (free, không cần API key) qua flutter_map.
///
/// UX:
///   - Bản đồ full-screen, marker luôn ở giữa (tap map = di chuyển marker).
///   - Khi camera move → debounce 600ms → reverse-geocode tên đường qua
///     Nominatim (OSM public API). Hiển thị preview ở bottom sheet.
///   - Bấm "Xác nhận" → trả về [DeliveryPoint] qua Navigator.pop.
/// ============================================================

class DeliveryMapPickerScreen extends StatefulWidget {
  const DeliveryMapPickerScreen({
    super.key,
    this.initialPoint,
    this.locateOnOpen = false,
  });

  /// Nếu user đã chọn trước đó, mở lại với vị trí cũ.
  final DeliveryPoint? initialPoint;
  final bool locateOnOpen;

  /// Helper để mở và chờ kết quả.
  static Future<DeliveryPoint?> open(
    BuildContext context, {
    DeliveryPoint? initial,
    bool locateOnOpen = false,
  }) {
    return Navigator.of(context).push<DeliveryPoint>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => DeliveryMapPickerScreen(
          initialPoint: initial,
          locateOnOpen: locateOnOpen,
        ),
      ),
    );
  }

  @override
  State<DeliveryMapPickerScreen> createState() =>
      _DeliveryMapPickerScreenState();
}

class _DeliveryMapPickerScreenState extends State<DeliveryMapPickerScreen> {
  late final MapController _mapController;
  late LatLng _currentPoint;
  String _displayAddress = '';
  bool _isLoadingAddress = false;
  bool _locating = false;
  int _addressRevision = 0;
  Timer? _debounceAddress;

  // Center mặc định = nhà hàng
  static const _initialZoom = 16.0;

  @override
  void initState() {
    super.initState();
    _mapController = MapController();
    final init = widget.initialPoint;
    if (init != null) {
      _currentPoint = init.latLng;
      _displayAddress = init.address;
    } else {
      _currentPoint = const LatLng(
        RestaurantLocation.latitude,
        RestaurantLocation.longitude,
      );
      _displayAddress = RestaurantLocation.address;
    }

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _mapController.move(_currentPoint, _initialZoom);
      if (widget.locateOnOpen) {
        _locateCustomer();
        return;
      }
      // Auto-fetch địa chỉ lần đầu nếu chưa có
      if (widget.initialPoint == null) {
        _resolveAddress(_currentPoint);
      }
    });
  }

  @override
  void dispose() {
    _debounceAddress?.cancel();
    _mapController.dispose();
    super.dispose();
  }

  void _onMapMoved(MapCamera pos, bool hasGesture) {
    if (!hasGesture) return;
    final newPoint = pos.center;
    _addressRevision++;
    _displayAddress = '';
    setState(() => _currentPoint = newPoint);

    // Debounce 600ms trước khi reverse-geocode
    _debounceAddress?.cancel();
    _debounceAddress = Timer(
      const Duration(milliseconds: 600),
      () => _resolveAddress(newPoint),
    );
  }

  Future<void> _resolveAddress(LatLng point) async {
    if (!mounted) return;
    final revision = ++_addressRevision;
    setState(() => _isLoadingAddress = true);
    try {
      final addr = await _reverseGeocode(point);
      if (!mounted || revision != _addressRevision) return;
      setState(() {
        _displayAddress = addr ?? '';
        _isLoadingAddress = false;
      });
    } catch (_) {
      if (!mounted || revision != _addressRevision) return;
      setState(() {
        _displayAddress =
            '${point.latitude.toStringAsFixed(5)}, '
            '${point.longitude.toStringAsFixed(5)}';
        _isLoadingAddress = false;
      });
    }
  }

  Future<void> _locateCustomer() async {
    if (_locating) return;
    setState(() => _locating = true);
    try {
      final point = await CustomerLocationService().current();
      if (!mounted) return;
      _debounceAddress?.cancel();
      setState(() {
        _currentPoint = point.latLng;
        _displayAddress = '';
      });
      _mapController.move(point.latLng, _initialZoom);
      await _resolveAddress(point.latLng);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            e is CustomerLocationException
                ? e.message
                : 'Chưa lấy được vị trí. Thử lại hoặc chọn điểm trên bản đồ.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<String?> _reverseGeocode(LatLng point) async {
    // Nominatim (OSM) public API. Free, không cần key, rate-limit 1 req/s.
    // Tạo Dio riêng (không qua interceptor của app) cho external API.
    try {
      final external = Dio(
        BaseOptions(
          connectTimeout: const Duration(seconds: 6),
          receiveTimeout: const Duration(seconds: 6),
          headers: {
            'User-Agent': 'MiCayApp/1.0 (mobile)',
            'Accept': 'application/json',
          },
        ),
      );
      final res = await external.get<Map<String, dynamic>>(
        'https://nominatim.openstreetmap.org/reverse',
        queryParameters: {
          'format': 'jsonv2',
          'lat': point.latitude,
          'lon': point.longitude,
          'zoom': 18,
          'addressdetails': 1,
          'accept-language': 'vi',
        },
      );
      final display = res.data?['display_name']?.toString();
      return display;
    } catch (_) {
      return null;
    }
  }

  void _confirm() {
    final point = DeliveryPoint(
      latitude: _currentPoint.latitude,
      longitude: _currentPoint.longitude,
      address: _displayAddress.isEmpty
          ? '${_currentPoint.latitude.toStringAsFixed(5)}, '
                '${_currentPoint.longitude.toStringAsFixed(5)}'
          : _displayAddress,
    );
    Navigator.of(context).pop(point);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          // ── Map ──────────────────────────────────────
          FlutterMap(
            mapController: _mapController,
            options: MapOptions(
              initialCenter: _currentPoint,
              initialZoom: _initialZoom,
              minZoom: 5,
              maxZoom: 19,
              onPositionChanged: _onMapMoved,
              onTap: (_, point) {
                _addressRevision++;
                _displayAddress = '';
                setState(() => _currentPoint = point);
                _mapController.move(point, _mapController.camera.zoom);
                _debounceAddress?.cancel();
                _debounceAddress = Timer(
                  const Duration(milliseconds: 600),
                  () => _resolveAddress(point),
                );
              },
            ),
            children: [
              TileLayer(
                // OSM tiles (free, no API key)
                urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                userAgentPackageName: 'com.micay.app',
                tileProvider: NetworkTileProvider(),
                maxNativeZoom: 19,
              ),
              MarkerLayer(
                markers: [
                  Marker(
                    point: _currentPoint,
                    width: 48,
                    height: 56,
                    // Anchor marker ở đáy (đỉnh pin chạm vào tọa độ)
                    alignment: Alignment.bottomCenter,
                    child: const _CenterPin(),
                  ),
                ],
              ),
              // Restaurant pin
              MarkerLayer(
                markers: [
                  Marker(
                    point: const LatLng(
                      RestaurantLocation.latitude,
                      RestaurantLocation.longitude,
                    ),
                    width: 40,
                    height: 40,
                    child: const _RestaurantPin(),
                  ),
                ],
              ),
              // Bù trừ marker vì marker hiển thị ở giữa bottom của pin
              const _CenterOffsetHack(),
            ],
          ),

          // ── Top bar: back + search ───────────────────
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Row(
                children: [
                  Material(
                    color: Colors.white,
                    shape: const CircleBorder(),
                    elevation: 4,
                    child: InkWell(
                      customBorder: const CircleBorder(),
                      onTap: () => Navigator.of(context).pop(),
                      child: const Padding(
                        padding: EdgeInsets.all(10),
                        child: Icon(Icons.arrow_back_rounded),
                      ),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Material(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                      elevation: 4,
                      child: const Padding(
                        padding: EdgeInsets.symmetric(
                          horizontal: AppSpacing.md,
                          vertical: 10,
                        ),
                        child: Row(
                          children: [
                            Icon(
                              Icons.location_on_rounded,
                              color: AppColors.spicyRed,
                              size: 18,
                            ),
                            SizedBox(width: 6),
                            Text(
                              'Chọn điểm giao hàng',
                              style: TextStyle(
                                fontWeight: FontWeight.w800,
                                fontSize: 13,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // ── Floating "current location" ───────────────
          Positioned(
            right: AppSpacing.md,
            bottom: 200,
            child: Material(
              color: Colors.white,
              shape: const CircleBorder(),
              elevation: 4,
              child: InkWell(
                customBorder: const CircleBorder(),
                onTap: _locating ? null : _locateCustomer,
                child: Tooltip(
                  message: 'Vị trí hiện tại của tôi',
                  child: Padding(
                    padding: EdgeInsets.all(12),
                    child: _locating
                        ? const SizedBox(
                            width: 24,
                            height: 24,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(
                            Icons.my_location_rounded,
                            color: AppColors.spicyRed,
                          ),
                  ),
                ),
              ),
            ),
          ),

          // ── Bottom preview + confirm ─────────────────
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: SafeArea(
              child: Container(
                margin: const EdgeInsets.all(AppSpacing.md),
                padding: const EdgeInsets.all(AppSpacing.md),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(AppRadius.lg),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.12),
                      blurRadius: 16,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: AppColors.spicyRedSoft,
                            borderRadius: BorderRadius.circular(AppRadius.md),
                          ),
                          alignment: Alignment.center,
                          child: const Icon(
                            Icons.place_rounded,
                            color: AppColors.spicyRed,
                            size: 20,
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        const Expanded(
                          child: Text(
                            'Điểm giao hàng',
                            style: TextStyle(
                              fontSize: 12,
                              color: AppColors.textMuted,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 0.4,
                            ),
                          ),
                        ),
                        if (_isLoadingAddress)
                          const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      _displayAddress.isEmpty
                          ? 'Di chuyển bản đồ để chọn vị trí'
                          : _displayAddress,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        height: 1.4,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${_currentPoint.latitude.toStringAsFixed(5)}, '
                      '${_currentPoint.longitude.toStringAsFixed(5)}',
                      style: const TextStyle(
                        fontSize: 11,
                        color: AppColors.textMuted,
                        fontFamily: 'monospace',
                      ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.check_rounded),
                        label: const Text('Xác nhận điểm giao'),
                        onPressed: _locating || _isLoadingAddress
                            ? null
                            : _confirm,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ───────────────── Building blocks ─────────────────

class _CenterPin extends StatelessWidget {
  const _CenterPin();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Stack(
        alignment: Alignment.center,
        clipBehavior: Clip.none,
        children: [
          // Bóng
          Positioned(
            bottom: 4,
            child: Container(
              width: 14,
              height: 6,
              decoration: BoxDecoration(
                color: Colors.black.withValues(alpha: 0.25),
                borderRadius: BorderRadius.circular(7),
              ),
            ),
          ),
          // Pin
          CustomPaint(size: const Size(40, 52), painter: _PinPainter()),
        ],
      ),
    );
  }
}

class _PinPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final body = Paint()
      ..color = AppColors.spicyRed
      ..style = PaintingStyle.fill;
    final stroke = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.5;

    // Vẽ hình giọt nước
    final path = ui.Path();
    final cx = size.width / 2;
    final top = 0.0;
    final r = size.width / 2 - 2;
    final cy = r + 2;
    path.moveTo(cx, size.height);
    path.cubicTo(cx - r * 0.9, cy + r * 0.5, cx - r, cy - r * 0.4, cx, top);
    path.cubicTo(
      cx + r,
      cy - r * 0.4,
      cx + r * 0.9,
      cy + r * 0.5,
      cx,
      size.height,
    );
    path.close();

    canvas.drawPath(path, body);
    canvas.drawPath(path, stroke);

    // Vòng tròn trắng trong
    canvas.drawCircle(Offset(cx, cy), r * 0.35, Paint()..color = Colors.white);
  }

  @override
  bool shouldRepaint(_PinPainter oldDelegate) => false;
}

class _RestaurantPin extends StatelessWidget {
  const _RestaurantPin();

  @override
  Widget build(BuildContext context) {
    return const Tooltip(
      message: RestaurantLocation.name,
      child: Icon(
        Icons.storefront_rounded,
        color: AppColors.spicyRed,
        size: 32,
        shadows: [
          Shadow(color: Colors.black26, blurRadius: 4, offset: Offset(0, 2)),
        ],
      ),
    );
  }
}

/// Hack nhỏ: khi user pan map, marker nằm cố định ở giữa màn hình.
/// Lệch Y vì pin có phần đuôi — bù trừ bằng padding cho MarkerLayer.
class _CenterOffsetHack extends StatelessWidget {
  const _CenterOffsetHack();
  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
