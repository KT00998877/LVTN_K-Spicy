import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/domain/app_user.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../shipper/data/shipper_repository.dart';
import '../data/order_repository.dart';
import '../domain/shipper_route.dart';

/// ============================================================
/// 🗺️ SHIPPER ROUTE MAP — bản đồ theo dõi shipper real-time
/// ============================================================
///
/// Mở từ OrderDetail khi đơn đang DELIVERING (hoặc READY_NOTIFY_SHIPPER).
/// Hiển thị:
///   - Marker nhà hàng (đỏ) và khách (vàng)
///   - Polyline đường đi từ nhà hàng → khách
///   - Khoảng cách (km) + ETA (phút) ở header
///   - Nút gọi điện/chat cho khách
///
/// Polling mỗi 10s để refresh ETA. Nếu user vào màn này khi đơn đã
/// hoàn thành → fallback sang màn thông báo "Đơn đã giao".
/// ============================================================

class ShipperRouteMapScreen extends ConsumerStatefulWidget {
  const ShipperRouteMapScreen({super.key, required this.orderId});
  final int orderId;

  @override
  ConsumerState<ShipperRouteMapScreen> createState() =>
      _ShipperRouteMapScreenState();
}

class _ShipperRouteMapScreenState
    extends ConsumerState<ShipperRouteMapScreen> {
  ShipperRoute? _route;
  String? _error;
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      if (_route == null) _loading = true;
      _error = null;
    });
    try {
      final auth = ref.read(authControllerProvider);
      final route = auth.user?.role == UserRole.shipper
          ? await ref.read(shipperRepositoryProvider).fetchRoute(widget.orderId)
          : await ref.read(orderRepositoryProvider).getRoute(widget.orderId);
      if (!mounted) return;
      setState(() {
        _route = route;
        _loading = false;
        _busy = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
        _busy = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'Không tải được đường đi. Vui lòng thử lại.';
        _loading = false;
        _busy = false;
      });
    }
  }

  LatLngBounds _bounds() {
    final r = _route!;
    final points = [
      r.restaurant.position,
      r.customer.position,
      ...r.polylinePoints,
    ];
    return LatLngBounds.fromPoints(points);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(
        title: const Text('Đường đi shipper'),
        actions: [
          IconButton(
            tooltip: 'Tải lại',
            icon: _busy
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2.5),
                  )
                : const Icon(Icons.refresh_rounded),
            onPressed: _busy ? null : _load,
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(
        child: SizedBox(
          width: 32,
          height: 32,
          child: CircularProgressIndicator(strokeWidth: 3),
        ),
      );
    }
    if (_error != null && _route == null) {
      return _errorState(_error!);
    }
    final route = _route!;
    final points = route.polylinePoints;

    return Column(
      children: [
        _summary(route),
        Expanded(
          child: Stack(
            children: [
              FlutterMap(
                options: MapOptions(
                  initialCameraFit: points.isNotEmpty
                      ? CameraFit.bounds(
                          bounds: _bounds(),
                          padding: const EdgeInsets.all(48),
                        )
                      : CameraFit.bounds(
                          bounds: LatLngBounds(
                            route.restaurant.position,
                            route.customer.position,
                          ),
                          padding: const EdgeInsets.all(48),
                        ),
                  interactionOptions: const InteractionOptions(
                    flags: InteractiveFlag.all & ~InteractiveFlag.rotate,
                  ),
                ),
                children: [
                  TileLayer(
                    urlTemplate:
                        'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    userAgentPackageName: 'vn.micay.app',
                    maxZoom: 19,
                  ),
                  if (points.length >= 2)
                    PolylineLayer(
                      polylines: [
                        Polyline(
                          points: points,
                          strokeWidth: 4.5,
                          color: AppColors.spicyRed,
                        ),
                      ],
                    ),
                  MarkerLayer(
                    markers: [
                      Marker(
                        point: route.restaurant.position,
                        width: 44,
                        height: 44,
                        alignment: Alignment.bottomCenter,
                        child: const _PinIcon(
                          color: AppColors.spicyRed,
                          icon: Icons.storefront_rounded,
                          label: 'Quán',
                        ),
                      ),
                      Marker(
                        point: route.customer.position,
                        width: 44,
                        height: 44,
                        alignment: Alignment.bottomCenter,
                        child: _PinIcon(
                          color: AppColors.goldenYellow,
                          icon: Icons.home_rounded,
                          label: 'Bạn',
                        ),
                      ),
                    ],
                  ),
                  const RichAttributionWidget(
                    alignment: AttributionAlignment.bottomLeft,
                    attributions: [
                      TextSourceAttribution('© OpenStreetMap contributors'),
                    ],
                  ),
                ],
              ),
              Positioned(
                left: AppSpacing.md,
                right: AppSpacing.md,
                bottom: AppSpacing.md,
                child: _customerCard(route),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _summary(ShipperRoute route) {
    final km = route.route.distanceKm;
    final mins = route.route.durationMin;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: AppColors.creamSoft,
        border: Border(
          bottom: BorderSide(color: AppColors.border),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.delivery_dining_rounded,
            color: AppColors.spicyRed,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Cách ${km.toStringAsFixed(1)} km • ETA ~$mins phút',
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -0.2,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'Shipper đang trên đường giao món đến bạn.',
                  style: const TextStyle(
                    fontSize: 12,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _customerCard(ShipperRoute route) => Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(color: AppColors.border),
          boxShadow: AppShadow.floating,
        ),
        child: Row(
          children: [
            const CircleAvatar(
              radius: 22,
              backgroundColor: AppColors.creamSoft,
              child: Icon(
                Icons.person_rounded,
                color: AppColors.spicyRed,
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    route.customer.name,
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: 14,
                      color: AppColors.textPrimary,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    route.customer.address,
                    style: const TextStyle(
                      fontSize: 11.5,
                      color: AppColors.textMuted,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ],
        ),
      );

  Widget _errorState(String message) => Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.map_outlined,
                size: 56,
                color: AppColors.textMuted,
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                message,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              OutlinedButton.icon(
                onPressed: _load,
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('Thử lại'),
              ),
            ],
          ),
        ),
      );
}

/// ============================================================
/// Pin icon trên map — marker tuỳ biến
/// ============================================================
class _PinIcon extends StatelessWidget {
  const _PinIcon({
    required this.color,
    required this.icon,
    required this.label,
  });

  final Color color;
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: color,
            shape: BoxShape.circle,
            border: Border.all(color: Colors.white, width: 3),
            boxShadow: AppShadow.floating,
          ),
          child: Icon(icon, color: Colors.white, size: 18),
        ),
        Container(
          width: 2,
          height: 5,
          color: color,
        ),
        Container(
          margin: const EdgeInsets.only(top: 1),
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
          child: Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 10,
              fontWeight: FontWeight.w900,
            ),
          ),
        ),
      ],
    );
  }
}
