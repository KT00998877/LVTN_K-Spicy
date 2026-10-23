/// ============================================================
/// 👤 USER MODEL — phản ánh response từ /api/auth/me và
///               /api/staff/auth/me của Laravel
/// ============================================================
///
/// Role code phải KHỚP với backend:
///   CUSTOMER · MANAGER · CASHIER · WAITER · KITCHEN · SHIPPER
///
enum UserRole {
  customer,
  manager,
  cashier,
  waiter,
  kitchen,
  shipper;

  /// Trả về string code đúng như backend (UPPERCASE)
  String get code => name.toUpperCase();

  /// Nhãn tiếng Việt cho UI
  String get label => switch (this) {
    UserRole.customer => 'Khách hàng',
    UserRole.manager => 'Quản lý',
    UserRole.cashier => 'Thu ngân',
    UserRole.waiter => 'Phục vụ',
    UserRole.kitchen => 'Bếp',
    UserRole.shipper => 'Shipper',
  };

  static UserRole? fromCode(String? code) {
    if (code == null) return null;
    final upper = code.toUpperCase();
    for (final r in UserRole.values) {
      if (r.code == upper) return r;
    }
    return null;
  }

  bool get isCustomer => this == UserRole.customer;
  bool get isShipper => this == UserRole.shipper;
  bool get isStaff => this != UserRole.customer;
}

/// Thông tin user lưu local + gửi kèm request cần auth
class AppUser {
  AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.phone,
    this.avatarUrl,
    this.fullName,
    this.tier,
    this.loyaltyPoints = 0,
    this.totalSpent = 0,
    this.tierCode,
    this.isActive = true,
    this.createdAt,
  });

  final int id;
  final String name;
  final String email;
  final UserRole role;
  final String? phone;
  final String? avatarUrl;
  final String? fullName;

  /// Hạng thành viên (object đầy đủ — ưu tiên dùng).
  final UserTierSnapshot? tier;
  final int loyaltyPoints;
  final int totalSpent;

  /// Hạng dạng mã ngắn (vd: "GOLD") — fallback khi backend chỉ trả mã.
  final String? tierCode;

  /// Backend luôn trả is_active, mặc định true nếu thiếu.
  final bool isActive;
  final DateTime? createdAt;

  factory AppUser.fromJson(Map<String, dynamic> json) {
    // Laravel có thể trả `roles` (list) hoặc `role` (string)
    UserRole role = UserRole.customer;
    final roles = json['roles'];
    if (roles is List && roles.isNotEmpty) {
      // Ưu tiên staff role trước customer
      for (final r in roles) {
        final code = r is Map ? r['code'] : r;
        if (code != null && code.toString().toUpperCase() != 'CUSTOMER') {
          role = UserRole.fromCode(code.toString()) ?? role;
          break;
        }
      }
      if (role == UserRole.customer) {
        final first = roles.first;
        final code = first is Map ? first['code'] : first;
        role = UserRole.fromCode(code?.toString()) ?? UserRole.customer;
      }
    } else {
      role = UserRole.fromCode(json['role']?.toString()) ?? UserRole.customer;
    }

    return AppUser(
      id: json['id'] as int,
      name: (json['name'] ?? '').toString(),
      fullName: json['full_name']?.toString(),
      email: (json['email'] ?? '').toString(),
      role: role,
      phone: json['phone']?.toString(),
      avatarUrl: json['avatar_url']?.toString(),
      tier: json['tier'] is Map
          ? UserTierSnapshot.fromJson(
              Map<String, dynamic>.from(json['tier'] as Map))
          : null,
      loyaltyPoints: (json['loyalty_points'] as num?)?.toInt() ?? 0,
      totalSpent: (json['total_spent'] as num?)?.toInt() ?? 0,
      tierCode: json['loyalty_tier']?.toString(),
      isActive: json['is_active'] is bool ? json['is_active'] as bool : true,
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'full_name': fullName,
    'email': email,
    'role': role.code,
    'phone': phone,
    'avatar_url': avatarUrl,
    'tier': tier?.toJson(),
    'loyalty_points': loyaltyPoints,
    'total_spent': totalSpent,
    'loyalty_tier': tierCode,
    'is_active': isActive,
    'created_at': createdAt?.toIso8601String(),
  };
}

/// Snapshot hạng thành viên nhúng trong response của `/api/auth/me`.
///
/// Đây là bản rút gọn (không có `min_spent`/`description`) — xem
/// `LoyaltyTier` trong `features/account/domain/loyalty_models.dart` cho
/// bản đầy đủ từ `/api/loyalty/tiers`.
class UserTierSnapshot {
  const UserTierSnapshot({
    required this.id,
    required this.code,
    required this.name,
    this.color,
    this.icon,
    this.pointMultiplier = 1.0,
  });

  final int id;
  final String code;
  final String name;
  final String? color;
  final String? icon;
  final double pointMultiplier;

  factory UserTierSnapshot.fromJson(Map<String, dynamic> json) =>
      UserTierSnapshot(
        id: (json['id'] as num?)?.toInt() ?? 0,
        code: (json['code'] ?? '').toString(),
        name: (json['name'] ?? '').toString(),
        color: json['color']?.toString(),
        icon: json['icon']?.toString(),
        pointMultiplier:
            (json['point_multiplier'] as num?)?.toDouble() ?? 1.0,
      );

  Map<String, dynamic> toJson() => {
    'id': id,
    'code': code,
    'name': name,
    'color': color,
    'icon': icon,
    'point_multiplier': pointMultiplier,
  };
}
