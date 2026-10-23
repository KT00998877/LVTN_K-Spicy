/// ============================================================
/// 💬 LIVE CHAT MODELS — kiểu dữ liệu cho tư vấn trực tiếp
/// ============================================================
///
/// Tách khỏi model chatbot: tư vấn trực tiếp có vòng đời phiên riêng
/// (khách chờ nhân viên nhận → được phục vụ → kết thúc) và tin nhắn có
/// người gửi thật chứ không phải user/assistant.
library;

/// Trạng thái của một phiên tư vấn trực tiếp.
enum LiveChatStatus { waiting, accepted, closed }

/// Người gửi một tin nhắn.
enum LiveChatSender { customer, staff, system }

extension LiveChatStatusX on LiveChatStatus {
  /// Đọc trạng thái từ chuỗi của backend.
  static LiveChatStatus parse(String? raw) {
    switch (raw) {
      case 'waiting':
        return LiveChatStatus.waiting;
      case 'accepted':
        return LiveChatStatus.accepted;
      default:
        return LiveChatStatus.closed;
    }
  }

  /// Nhãn tiếng Việt hiển thị trên giao diện.
  String get label {
    switch (this) {
      case LiveChatStatus.waiting:
        return 'Đang chờ nhân viên';
      case LiveChatStatus.accepted:
        return 'Đang được phục vụ';
      case LiveChatStatus.closed:
        return 'Đã kết thúc';
    }
  }

  bool get isClosed => this == LiveChatStatus.closed;
}

/// Một tin nhắn trong phiên tư vấn trực tiếp.
class LiveChatMessage {
  const LiveChatMessage({
    required this.sender,
    required this.content,
    this.id,
    this.senderName,
    this.createdAt,
  });

  final int? id;
  final LiveChatSender sender;
  final String content;
  final String? senderName;
  final DateTime? createdAt;

  bool get isCustomer => sender == LiveChatSender.customer;
  bool get isStaff => sender == LiveChatSender.staff;

  factory LiveChatMessage.fromJson(Map<String, dynamic> json) {
    return LiveChatMessage(
      id: json['id'] as int?,
      sender: _parseSender(json['sender_type'] as String?),
      content: (json['content'] ?? '').toString(),
      senderName: json['sender_name'] as String?,
      createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()),
    );
  }

  static LiveChatSender _parseSender(String? raw) {
    switch (raw) {
      case 'staff':
        return LiveChatSender.staff;
      case 'system':
        return LiveChatSender.system;
      default:
        return LiveChatSender.customer;
    }
  }
}

/// Một phiên tư vấn trực tiếp.
class LiveChatSession {
  const LiveChatSession({
    required this.id,
    required this.status,
    required this.customerName,
    this.assignedStaffName,
    this.topic,
    this.lastMessageAt,
    this.messages = const [],
  });

  final int id;
  final LiveChatStatus status;
  final String customerName;
  final String? assignedStaffName;
  final String? topic;
  final DateTime? lastMessageAt;
  final List<LiveChatMessage> messages;

  /// Nhân viên đã nhận phiên.
  bool get hasStaff => assignedStaffName != null && assignedStaffName!.isNotEmpty;

  factory LiveChatSession.fromJson(Map<String, dynamic> json) {
    final rawMessages = json['messages'];
    final messages = rawMessages is List
        ? rawMessages
            .whereType<Map>()
            .map((m) => LiveChatMessage.fromJson(Map<String, dynamic>.from(m)))
            .toList()
        : const <LiveChatMessage>[];

    return LiveChatSession(
      id: json['id'] as int,
      status: LiveChatStatusX.parse(json['status'] as String?),
      customerName: (json['customer_name'] ?? '').toString(),
      assignedStaffName: json['assigned_staff_name'] as String?,
      topic: json['subject'] as String? ?? json['topic'] as String?,
      lastMessageAt: DateTime.tryParse((json['last_message_at'] ?? '').toString()),
      messages: messages,
    );
  }
}