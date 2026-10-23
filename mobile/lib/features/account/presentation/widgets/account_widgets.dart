import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../../../../app/theme.dart';
import '../../../../core/utils/backend_image_url.dart';
import '../../../auth/domain/app_user.dart';

/// ============================================================
/// 👤 USER AVATAR — ảnh đại diện tròn, có nút camera overlay
/// ============================================================
///
/// Dùng cho header màn Tài khoản. Nếu user chưa upload ảnh thì hiện
/// chữ cái đầu trên nền Spicy Red — không dùng icon người dùng chung
/// chung vì mất cảm giác "cá nhân" (rule §6 food-first / §30 anti-slop).
///
class UserAvatar extends StatelessWidget {
  const UserAvatar({
    super.key,
    required this.name,
    this.avatarUrl,
    this.size = 96,
    this.showCameraBadge = false,
    this.onTap,
  });

  final String name;
  final String? avatarUrl;

  /// Đường kính avatar (px).
  final double size;

  /// Hiện nút máy ảnh góc dưới — dùng cho chế độ chỉnh sửa.
  final bool showCameraBadge;
  final VoidCallback? onTap;

  /// Chữ cái đầu dùng làm fallback. Với tên tiếng Việt có dấu, "Nguyễn"
  /// → "N". Ưu tiên `full_name` rồi mới tới `name`.
  static String _initial(String rawName) {
    final trimmed = rawName.trim();
    if (trimmed.isEmpty) return '?';
    return trimmed.characters.first.toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final url = backendImageUrl(avatarUrl);

    Widget avatar = ClipOval(
      child: SizedBox(
        width: size,
        height: size,
        child: url.isNotEmpty
            ? CachedNetworkImage(
                imageUrl: url,
                fit: BoxFit.cover,
                placeholder: (_, __) => ColoredBox(
                  color: AppColors.creamSoft,
                  child: const Center(
                    child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: AppColors.spicyRed,
                      ),
                    ),
                  ),
                ),
                errorWidget: (_, __, ___) => _Fallback(name: name, size: size),
              )
            : _Fallback(name: name, size: size),
      ),
    );

    if (showCameraBadge) {
      avatar = Stack(
        clipBehavior: Clip.none,
        children: [
          avatar,
          Positioned(
            right: 0,
            bottom: 0,
            child: Container(
              width: size * 0.32,
              height: size * 0.32,
              decoration: BoxDecoration(
                color: AppColors.spicyRed,
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white, width: 2.5),
              ),
              child: Icon(
                Icons.photo_camera_rounded,
                size: size * 0.17,
                color: Colors.white,
              ),
            ),
          ),
        ],
      );
    }

    if (onTap == null) return avatar;

    return Semantics(
      button: true,
      label: 'Đổi ảnh đại diện',
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: avatar,
      ),
    );
  }
}

class _Fallback extends StatelessWidget {
  const _Fallback({required this.name, required this.size});

  final String name;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.spicyRed,
      alignment: Alignment.center,
      child: Text(
        UserAvatar._initial(name),
        style: TextStyle(
          color: Colors.white,
          fontSize: size * 0.4,
          fontWeight: FontWeight.w800,
          height: 1,
        ),
      ),
    );
  }
}

/// ============================================================
/// 🏆 TIER BADGE — nhãn hạng thành viên
/// ============================================================
///
/// Màu lấy từ `tier.color` do backend cấu hình (mỗi hạng một màu riêng),
/// fallback sang palette mặc định nếu backend chưa có màu.
///
class TierBadge extends StatelessWidget {
  const TierBadge({
    super.key,
    this.name,
    this.colorHex,
    this.icon,
    this.compact = false,
  });

  final String? name;

  /// Màu hex từ backend (vd "#D4AF37").
  final String? colorHex;
  final String? icon;

  /// Bản nhỏ dùng cho chip lọc / header.
  final bool compact;

  static const _fallbackColor = Color(0xFFB08D57);

  static Color resolveColor(String? hex) {
    if (hex == null || hex.isEmpty) return _fallbackColor;
    var value = hex.replaceAll('#', '').trim();
    if (value.length == 6) value = 'FF$value';
    final parsed = int.tryParse(value, radix: 16);
    if (parsed == null) return _fallbackColor;
    return Color(parsed);
  }

  @override
  Widget build(BuildContext context) {
    if (name == null && icon == null) return const SizedBox.shrink();

    final color = resolveColor(colorHex);
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? AppSpacing.xs : AppSpacing.sm,
        vertical: compact ? 2 : AppSpacing.xxs,
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: color.withValues(alpha: 0.45)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null && icon!.isNotEmpty) ...[
            Text(icon!, style: TextStyle(fontSize: compact ? 11 : 14)),
            SizedBox(width: compact ? 3 : AppSpacing.xxs),
          ],
          if (name != null)
            Text(
              name!,
              style: TextStyle(
                color: color,
                fontSize: compact ? 11 : 13,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.2,
              ),
            ),
        ],
      ),
    );
  }
}

/// Tiện ích đọc nhanh hạng từ [AppUser].
extension AppUserTierX on AppUser {
  String? get tierName => tier?.name;

  Color? get tierColorRaw {
    final hex = tier?.color;
    if (hex == null || hex.isEmpty) return null;
    return TierBadge.resolveColor(hex);
  }
}