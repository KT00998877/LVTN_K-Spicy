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
    this.loyaltyPoints = 0,
    this.tierCode,
  });

  final int id;
  final String name;
  final String email;
  final UserRole role;
  final String? phone;
  final String? avatarUrl;

  /// Hạng thành viên (chỉ customer mới có)
  final String? tierCode;
  final int loyaltyPoints;

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
      email: (json['email'] ?? '').toString(),
      role: role,
      phone: json['phone']?.toString(),
      avatarUrl: json['avatar_url']?.toString(),
      loyaltyPoints: (json['loyalty_points'] as num?)?.toInt() ?? 0,
      tierCode: json['loyalty_tier']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'email': email,
    'role': role.code,
    'phone': phone,
    'avatar_url': avatarUrl,
    'loyalty_points': loyaltyPoints,
    'loyalty_tier': tierCode,
  };
}
