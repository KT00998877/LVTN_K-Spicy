import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../domain/live_chat_models.dart';
import 'live_chat_controller.dart';

/// ============================================================
/// 💬 LIVE CHAT SCREEN — tư vấn trực tiếp với nhân viên
/// ============================================================
///
/// Màn hình này CHỈ dành cho khách đã đăng nhập. Khách vãng lai thấy lời mời
/// đăng nhập kèm nút mở form đăng nhập — vì tư vấn trực tiếp cần biết khách là
/// ai để nhân viên phục vụ và lưu lại lịch sử.
///
/// Dòng "Đang chờ nhân viên tư vấn trả lời" CHỈ hiện sau khi khách đã gửi
/// tin nhắn đầu tiên — mở màn hình mà chưa nói gì thì hiện dòng đó là vô nghĩa.
class LiveChatScreen extends ConsumerStatefulWidget {
  const LiveChatScreen({super.key});

  @override
  ConsumerState<LiveChatScreen> createState() => _LiveChatScreenState();
}

class _LiveChatScreenState extends ConsumerState<LiveChatScreen> {
  final _draftController = TextEditingController();
  final _scrollController = ScrollController();

  @override
  void dispose() {
    _draftController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _scrollToBottom() {
    if (!_scrollController.hasClients) return;
    // Sau một nhịp để danh sách kịp dựng lại trước khi cuộn.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;
      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent,
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
      );
    });
  }

  Future<void> _send() async {
    final text = _draftController.text.trim();
    if (text.isEmpty) return;
    _draftController.clear();
    await ref.read(liveChatControllerProvider.notifier).send(text);
    _scrollToBottom();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(liveChatControllerProvider);

    ref.listen(liveChatControllerProvider, (previous, next) {
      if (previous?.messages.length != next.messages.length) {
        _scrollToBottom();
      }
    });

    return Scaffold(
      appBar: AppBar(
        title: const Text('Tư vấn trực tiếp'),
        backgroundColor: AppColors.charcoal,
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          if (state.hasStaff)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: _ConnectionDot(connected: state.isConnected),
            ),
        ],
      ),
      body: _buildBody(context, state),
    );
  }

  Widget _buildBody(BuildContext context, LiveChatState state) {
    switch (state.status) {
      case LiveChatViewStatus.loading:
        return const Center(child: CircularProgressIndicator());

      case LiveChatViewStatus.requiresLogin:
        return const _LoginPrompt();

      case LiveChatViewStatus.failed:
        return _ErrorState(
          message: state.error ?? 'Không thể tải cuộc tư vấn.',
          onRetry: () => ref.read(liveChatControllerProvider.notifier).startNewChat(),
        );

      case LiveChatViewStatus.ready:
        return _buildChat(state);
    }
  }

  Widget _buildChat(LiveChatState state) {
    return Column(
      children: [
        _StatusBanner(state: state),
        Expanded(
          child: state.isEmpty
              ? const _EmptyState()
              : ListView.builder(
                  controller: _scrollController,
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  itemCount: state.messages.length + (state.hasCustomerMessage ? 1 : 0),
                  itemBuilder: (context, index) {
                    if (index >= state.messages.length) {
                      return const _WaitingIndicator();
                    }
                    final message = state.messages[index];
                    return _MessageBubble(
                      message: message,
                      staffName: state.staffName,
                    );
                  },
                ),
        ),
        if (state.error != null)
          _ErrorBanner(
            message: state.error!,
            onDismiss: () => ref.read(liveChatControllerProvider.notifier).clearError(),
          ),
        _Composer(
          controller: _draftController,
          enabled: state.canSend,
          isClosed: state.sessionStatus == LiveChatStatus.closed,
          onSubmit: _send,
          onStartNew: () => ref.read(liveChatControllerProvider.notifier).startNewChat(),
        ),
      ],
    );
  }
}

/// Dòng trạng thái nhỏ dưới thanh tiêu đề.
class _StatusBanner extends StatelessWidget {
  const _StatusBanner({required this.state});

  final LiveChatState state;

  @override
  Widget build(BuildContext context) {
    final status = state.sessionStatus;
    if (status == null) return const SizedBox.shrink();

    final (text, color) = switch (status) {
      LiveChatStatus.waiting => ('Đang chờ nhân viên nhận cuộc tư vấn', AppColors.warning),
      LiveChatStatus.accepted => (
          state.staffName != null && state.staffName!.isNotEmpty
              ? '${state.staffName} đang phục vụ bạn'
              : 'Nhân viên đang phục vụ bạn',
          AppColors.success,
        ),
      LiveChatStatus.closed => ('Cuộc tư vấn đã kết thúc', AppColors.textMuted),
    };

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      color: color.withValues(alpha: 0.10),
      child: Row(
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: color,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message, this.staffName});

  final LiveChatMessage message;
  final String? staffName;

  @override
  Widget build(BuildContext context) {
    final isStaff = message.isStaff;
    final isSystem = message.sender == LiveChatSender.system;

    if (isSystem) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Center(
          child: Text(
            message.content,
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: AppColors.textMuted),
          ),
        ),
      );
    }

    final time = message.createdAt;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment:
            isStaff ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          if (isStaff)
            Padding(
              padding: const EdgeInsets.only(bottom: 3, right: 4),
              child: Text(
                staffName?.isNotEmpty == true ? staffName! : 'Nhân viên',
                style: Theme.of(context)
                    .textTheme
                    .labelSmall
                    ?.copyWith(color: AppColors.textMuted),
              ),
            ),
          Container(
            constraints: BoxConstraints(
              maxWidth: MediaQuery.of(context).size.width * 0.78,
            ),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: isStaff ? AppColors.charcoal : Colors.white,
              borderRadius: BorderRadius.only(
                topLeft: const Radius.circular(16),
                topRight: const Radius.circular(16),
                bottomLeft: Radius.circular(isStaff ? 16 : 4),
                bottomRight: Radius.circular(isStaff ? 4 : 16),
              ),
              border: isStaff ? null : Border.all(color: AppColors.creamDark),
              boxShadow: isStaff
                  ? null
                  : [
                      BoxShadow(
                        color: AppColors.charcoal.withValues(alpha: 0.05),
                        blurRadius: 6,
                        offset: const Offset(0, 2),
                      ),
                    ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  message.content,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: isStaff ? Colors.white : AppColors.textPrimary,
                        height: 1.45,
                      ),
                ),
                if (time != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4, left: 2),
                    child: Text(
                      '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}',
                      style: Theme.of(context).textTheme.labelSmall?.copyWith(
                            color: isStaff
                                ? Colors.white70
                                : AppColors.textMuted,
                            fontSize: 10.5,
                          ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// "Đang chờ nhân viên tư vấn trả lời" — chỉ hiện khi khách đã nói.
class _WaitingIndicator extends StatelessWidget {
  const _WaitingIndicator();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            decoration: BoxDecoration(
              color: AppColors.creamSoft,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const _TypingDots(),
                const SizedBox(width: 8),
                Text(
                  'Đang chờ nhân viên tư vấn trả lời',
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(color: AppColors.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TypingDots extends StatefulWidget {
  const _TypingDots();

  @override
  State<_TypingDots> createState() => _TypingDotsState();
}

class _TypingDotsState extends State<_TypingDots>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (index) {
            final phase = (_controller.value - index * 0.2) % 1.0;
            final lift = phase < 0.5 ? phase * 2 : (1 - phase) * 2;
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 1.5),
              child: Transform.translate(
                offset: Offset(0, -lift * 3),
                child: Container(
                  width: 5,
                  height: 5,
                  decoration: const BoxDecoration(
                    color: AppColors.textMuted,
                    shape: BoxShape.circle,
                  ),
                ),
              ),
            );
          }),
        );
      },
    );
  }
}

class _Composer extends StatelessWidget {
  const _Composer({
    required this.controller,
    required this.enabled,
    required this.isClosed,
    required this.onSubmit,
    required this.onStartNew,
  });

  final TextEditingController controller;
  final bool enabled;
  final bool isClosed;
  final VoidCallback onSubmit;
  final VoidCallback onStartNew;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Container(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        decoration: const BoxDecoration(
          color: Colors.white,
          border: Border(top: BorderSide(color: AppColors.creamDark)),
        ),
        child: isClosed
            ? Row(
                children: [
                  Expanded(
                    child: Text(
                      'Phiên tư vấn đã kết thúc.',
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(color: AppColors.textMuted),
                    ),
                  ),
                  const SizedBox(width: 12),
                  FilledButton(
                    onPressed: onStartNew,
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.spicyRed,
                      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
                    ),
                    child: const Text('Tư vấn mới'),
                  ),
                ],
              )
            : Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: controller,
                      enabled: enabled,
                      minLines: 1,
                      maxLines: 4,
                      textInputAction: TextInputAction.newline,
                      onSubmitted: (_) => onSubmit(),
                      decoration: InputDecoration(
                        hintText: 'Nhập tin nhắn...',
                        filled: true,
                        fillColor: AppColors.cream,
                        contentPadding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 12,
                        ),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: const BorderSide(color: AppColors.creamDark),
                        ),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: const BorderSide(color: AppColors.creamDark),
                        ),
                        focusedBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: const BorderSide(color: AppColors.spicyRed, width: 1.6),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    height: 46,
                    child: FilledButton(
                      onPressed: enabled ? onSubmit : null,
                      style: FilledButton.styleFrom(
                        backgroundColor: AppColors.spicyRed,
                        padding: const EdgeInsets.symmetric(horizontal: 18),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                      child: const Text('Gửi'),
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

/// Mời đăng nhập — tư vấn trực tiếp cần biết khách là ai.
class _LoginPrompt extends StatelessWidget {
  const _LoginPrompt();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 68,
              height: 68,
              decoration: BoxDecoration(
                color: AppColors.spicyRedSoft,
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.support_agent_rounded,
                size: 34,
                color: AppColors.spicyRed,
              ),
            ),
            const SizedBox(height: 18),
            Text(
              'Tư vấn trực tiếp cần đăng nhập',
              style: Theme.of(context).textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              'Đăng nhập để nhân viên biết mình đang hỗ trợ bạn và lưu lại lịch sử cuộc trò chuyện.',
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(color: AppColors.textMuted, height: 1.5),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 22),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => context.go('/login'),
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.spicyRed,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                child: const Text('Đăng nhập để tư vấn'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.chat_bubble_outline_rounded,
              size: 46,
              color: AppColors.creamDark,
            ),
            const SizedBox(height: 14),
            Text(
              'Bạn cần tư vấn gì hôm nay?',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            const SizedBox(height: 6),
            Text(
              'Gửi câu hỏi của bạn, nhân viên sẽ phản hồi ngay.',
              textAlign: TextAlign.center,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: AppColors.textMuted),
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(color: AppColors.textMuted),
            ),
            const SizedBox(height: 18),
            OutlinedButton(
              onPressed: onRetry,
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.spicyRed,
                side: const BorderSide(color: AppColors.spicyRed),
              ),
              child: const Text('Thử lại'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message, required this.onDismiss});

  final String message;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.spicyRedSoft,
        border: const Border(top: BorderSide(color: AppColors.spicyRed)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              message,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: AppColors.spicyRedDark),
            ),
          ),
          IconButton(
            onPressed: onDismiss,
            icon: const Icon(Icons.close_rounded, size: 18),
            color: AppColors.spicyRedDark,
            visualDensity: VisualDensity.compact,
            tooltip: 'Đóng',
          ),
        ],
      ),
    );
  }
}

/// Chấm báo hiệu kết nối realtime.
class _ConnectionDot extends StatelessWidget {
  const _ConnectionDot({required this.connected});

  final bool connected;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: connected ? 'Đang kết nối trực tiếp' : 'Đang kết nối...',
      child: Container(
        width: 9,
        height: 9,
        decoration: BoxDecoration(
          color: connected ? AppColors.success : AppColors.textMuted,
          shape: BoxShape.circle,
        ),
      ),
    );
  }
}