import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../../shared/widgets/backend_image.dart';
import '../domain/chat_message.dart';
import 'chat_controller.dart';

/// ============================================================
/// 💬 CHAT SCREEN — hội thoại với trợ lý K-Spicy
/// ============================================================
///
/// Màn hình này được thiết kế riêng cho điện thoại: vùng hội thoại chiếm
/// hết màn hình, thao tác nhập luôn nằm trong tầm tay, và mọi trạng thái
/// (đang chờ, lỗi, rỗng) đều có cách hiển thị rõ ràng.
class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({super.key});

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final _inputFocus = FocusNode();
  Timer? _replyScrollTimer;

  static const _suggestions = [
    'Menu có món gì ngon?',
    'Mì cay giá bao nhiêu?',
    'Đặt bàn như thế nào?',
    'Có khuyến mãi gì không?',
  ];

  @override
  void dispose() {
    _replyScrollTimer?.cancel();
    _input.dispose();
    _scroll.dispose();
    _inputFocus.dispose();
    super.dispose();
  }

  void _scrollToBottom({bool animate = true}) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scroll.hasClients) return;
      final target = _scroll.position.maxScrollExtent;
      if (animate) {
        _scroll.animateTo(
          target,
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOut,
        );
      } else {
        _scroll.jumpTo(target);
      }
    });
  }

  /// Giữ khung nhìn bám theo câu trả lời đang gõ.
  void _followTyping() {
    if (!_scroll.hasClients) return;
    if (_scroll.position.extentAfter < 120) {
      _scroll.jumpTo(_scroll.position.maxScrollExtent);
    }
  }

  Future<void> _send([String? suggestion]) async {
    final text = (suggestion ?? _input.text).trim();
    if (text.isEmpty) return;
    _input.clear();
    _scrollToBottom();
    await ref.read(chatControllerProvider.notifier).send(text);
    _scrollToBottom();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(chatControllerProvider);
    final controller = ref.read(chatControllerProvider.notifier);

    ref.listen<ChatState>(chatControllerProvider, (previous, next) {
      if (previous?.messages.length != next.messages.length) {
        _scrollToBottom();
      }
    });

    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: _buildAppBar(context, state, controller),
      body: SafeArea(
        top: false,
        child: Column(
          children: [
            Expanded(child: _buildConversation(context, state, controller)),
            _buildComposer(context, state, controller),
          ],
        ),
      ),
    );
  }

  // ── AppBar ────────────────────────────────────────────────
  PreferredSizeWidget _buildAppBar(
    BuildContext context,
    ChatState state,
    ChatController controller,
  ) {
    final (subtitle, showSpinner) = switch (state) {
      ChatStatus() when state.status == ChatStatus.loading => ('Đang tải…', true),
      ChatStatus() when state.status == ChatStatus.failed => ('Chưa kết nối được', false),
      ChatStatus() when state.isSending => ('Đang trả lời…', true),
      _ => ('Sẵn sàng hỗ trợ', false),
    };

    return AppBar(
      backgroundColor: AppColors.charcoal,
      foregroundColor: AppColors.textOnDark,
      titleSpacing: AppSpacing.md,
      title: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: AppColors.spicyRed,
              borderRadius: BorderRadius.circular(AppRadius.pill),
            ),
            child: const Icon(
              Icons.local_fire_department_rounded,
              color: Colors.white,
              size: 20,
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'Trợ lý K-Spicy',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        color: AppColors.textOnDark,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: 1),
                Row(
                  children: [
                    if (showSpinner) ...[
                      const SizedBox(
                        width: 9,
                        height: 9,
                        child: CircularProgressIndicator(
                          strokeWidth: 1.6,
                          color: AppColors.goldenYellow,
                        ),
                      ),
                      const SizedBox(width: 5),
                    ],
                    Flexible(
                      child: Text(
                        subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: AppColors.textOnDark.withValues(alpha: 0.75),
                            ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
      actions: [
        IconButton(
          tooltip: 'Hội thoại mới',
          onPressed: state.isSending ? null : controller.startNewChat,
          icon: const Icon(Icons.refresh_rounded),
          color: AppColors.textOnDark,
        ),
      ],
    );
  }

  // ── Vùng hội thoại ───────────────────────────────────────
  Widget _buildConversation(
    BuildContext context,
    ChatState state,
    ChatController controller,
  ) {
    if (state.status == ChatStatus.loading && state.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.status == ChatStatus.failed && state.isEmpty) {
      return _buildConnectionError(context, state, controller);
    }

    return ListView(
      controller: _scroll,
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.md,
        AppSpacing.sm,
      ),
      children: [
        _buildHandoffBanner(context),
        if (state.isEmpty) _buildWelcome(context, controller),
        for (final message in state.messages) _buildMessage(context, message),
        for (final action in state.pendingActions) _buildActionCard(context, action),
        if (state.isSending) _buildTypingIndicator(context, state),
        if (state.error != null) _buildInlineError(context, state, controller),
      ],
    );
  }

  /// Lối thoát khỏi trợ lý ảo: khi chatbot không giải quyết được, khách chuyển
  /// sang tư vấn trực tiếp. Luôn hiện — đây là đường dẫn thoát được khỏi AI,
  /// không nên đẩy vào ô nhập hay chỉ lộ ra khi trợ lý trả lời sai.
  Widget _buildHandoffBanner(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Material(
        color: AppColors.charcoal,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          onTap: () => context.push('/live-chat'),
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.sm + 2),
            child: Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: AppColors.spicyRed,
                    borderRadius: BorderRadius.circular(AppRadius.sm),
                  ),
                  child: const Icon(
                    Icons.support_agent_rounded,
                    color: Colors.white,
                    size: 20,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Chat với nhân viên',
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                              color: AppColors.textOnDark,
                            ),
                      ),
                      const SizedBox(height: 1),
                      Text(
                        'Trợ lý chưa giúp được? Nhân viên nhà hàng trực tiếp ngay.',
                        style:
                            Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: AppColors.textOnDark
                                      .withValues(alpha: 0.75),
                                  fontSize: 12,
                                ),
                      ),
                    ],
                  ),
                ),
                const Icon(
                  Icons.arrow_forward_rounded,
                  size: 18,
                  color: AppColors.textOnDark,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ── Chào mừng + câu hỏi gợi ý ───────────────────────────
  Widget _buildWelcome(BuildContext context, ChatController controller) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: AppColors.border),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('🌶️', style: TextStyle(fontSize: 24)),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  'Xin chào! Mình có thể giúp bạn xem thực đơn, giá món, '
                  'đơn hàng hoặc đặt bàn. Bạn muốn hỏi gì nè?',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AppColors.textPrimary,
                        height: 1.5,
                      ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        Text(
          'Gợi ý câu hỏi',
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                color: AppColors.textSecondary,
              ),
        ),
        const SizedBox(height: AppSpacing.xs),
        for (final question in _suggestions)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: _SuggestionChip(
              label: question,
              onTap: () => _send(question),
            ),
          ),
      ],
    );
  }

  // ── Một tin nhắn ─────────────────────────────────────────
  Widget _buildMessage(BuildContext context, ChatMessage message) {
    // `ListView` chỉ dựng element cho phần tử ở gần vùng nhìn. Tin nhắn bị
    // cuộn ra ngoài sẽ mất element và khi cuộn lên phải dựng lại từ đầu —
    // hiệu ứng gõ chữ chạy lại từ 0% và ảnh gợi ý món bị tải lại. Giữ element
    // sống để tin nhắn cũ hiện lại đúng như lúc vừa nhận.
    return _KeepAliveMessage(
      key: ObjectKey(message),
      child: message.isUser
          ? _buildUserMessage(context, message)
          : _buildAssistantMessage(context, message),
    );
  }

  Widget _buildUserMessage(BuildContext context, ChatMessage message) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          Flexible(
            child: Container(
              constraints: const BoxConstraints(maxWidth: 300),
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.md,
                vertical: AppSpacing.sm + 2,
              ),
              decoration: BoxDecoration(
                color: AppColors.spicyRed,
                borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(AppRadius.lg),
                  topRight: Radius.circular(AppRadius.lg),
                  bottomLeft: Radius.circular(AppRadius.lg),
                  bottomRight: Radius.circular(AppRadius.xs),
                ),
              ),
              child: Text(
                message.content,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: Colors.white,
                      height: 1.45,
                    ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildAssistantMessage(BuildContext context, ChatMessage message) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            constraints: const BoxConstraints(maxWidth: 320),
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: const BorderRadius.only(
                topLeft: Radius.circular(AppRadius.lg),
                topRight: Radius.circular(AppRadius.lg),
                bottomLeft: Radius.circular(AppRadius.xs),
                bottomRight: Radius.circular(AppRadius.lg),
              ),
              border: Border.all(color: AppColors.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _ReplyText(
                  key: ValueKey(message.id ?? message.content.hashCode),
                  text: message.content,
                  animate: message.animate,
                  onProgress: _followTyping,
                  onComplete: () {
                    _replyScrollTimer?.cancel();
                    if (message.hasSuggestions) _scrollToBottom();
                  },
                ),
              ],
            ),
          ),
          if (message.hasSuggestions) ...[
            const SizedBox(height: AppSpacing.xs),
            for (final suggestion in message.menuSuggestions)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: _MenuSuggestionTile(suggestion: suggestion),
              ),
          ],
          if (message.quickAction?.isReservation ?? false)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.xs),
              child: FilledButton.icon(
                onPressed: () => context.push('/reservation'),
                icon: const Icon(Icons.event_seat_rounded, size: 18),
                label: Text(message.quickAction!.label.isEmpty
                    ? 'Đặt bàn'
                    : message.quickAction!.label),
                style: FilledButton.styleFrom(
                  minimumSize: const Size(0, 44),
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                ),
              ),
            ),
        ],
      ),
    );
  }

  // ── Thẻ xác nhận thao tác ────────────────────────────────
  Widget _buildActionCard(BuildContext context, PendingAction action) {
    final summary = action.summary;
    final price = summary.priceBreakdown;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(color: AppColors.goldenYellow, width: 1.5),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(summary.icon, style: const TextStyle(fontSize: 20)),
                const SizedBox(width: AppSpacing.xs),
                Expanded(
                  child: Text(
                    summary.title,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          color: AppColors.textPrimary,
                        ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            const Divider(height: 1),
            const SizedBox(height: AppSpacing.sm),
            for (final item in summary.items)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.label,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Text(
                        item.value,
                        textAlign: TextAlign.right,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: AppColors.textPrimary,
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    ),
                  ],
                ),
              ),
            if (summary.toppings.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.xxs),
              Text(
                'Topping: ${summary.toppings.join(', ')}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
            if (price != null) ...[
              const SizedBox(height: AppSpacing.sm),
              const Divider(height: 1),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Text(
                    'Tổng cộng',
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          color: AppColors.textPrimary,
                        ),
                  ),
                  const Spacer(),
                  Text(
                    _formatVnd(price.subtotal),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          color: AppColors.spicyRed,
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ],
              ),
            ],
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => ref
                        .read(chatControllerProvider.notifier)
                        .resolveAction(action.id, confirm: false),
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size(0, 44),
                      foregroundColor: AppColors.textSecondary,
                      side: const BorderSide(color: AppColors.border, width: 1.5),
                    ),
                    child: const Text('Huỷ'),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: FilledButton(
                    onPressed: () => ref
                        .read(chatControllerProvider.notifier)
                        .resolveAction(action.id, confirm: true),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size(0, 44),
                      backgroundColor: AppColors.spicyRed,
                    ),
                    child: const Text('Xác nhận'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  // ── Đang chờ trợ lý trả lời ──────────────────────────────
  Widget _buildTypingIndicator(BuildContext context, ChatState state) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.md,
              vertical: AppSpacing.sm + 2,
            ),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: AppColors.border),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const _TypingDots(),
                const SizedBox(width: AppSpacing.sm),
                Text(
                  state.waitSeconds >= 3
                      ? 'Đang suy nghĩ… ${state.waitSeconds}s'
                      : 'Đang trả lời…',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
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

  // ── Lỗi ──────────────────────────────────────────────────
  Widget _buildInlineError(
    BuildContext context,
    ChatState state,
    ChatController controller,
  ) {
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.spicyRedSoft,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.spicyRed.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline_rounded, color: AppColors.danger, size: 20),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  state.error!,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.spicyRedDark,
                      ),
                ),
                const SizedBox(height: AppSpacing.xxs),
                GestureDetector(
                  onTap: controller.clearError,
                  child: Text(
                    'Đóng',
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          color: AppColors.spicyRedDark,
                          fontSize: 13,
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

  Widget _buildConnectionError(
    BuildContext context,
    ChatState state,
    ChatController controller,
  ) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('🍜', style: TextStyle(fontSize: 48)),
            const SizedBox(height: AppSpacing.md),
            Text(
              'Chưa kết nối được trợ lý',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              state.error ?? 'Vui lòng kiểm tra kết nối rồi thử lại.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: AppSpacing.lg),
            ElevatedButton.icon(
              onPressed: controller.retry,
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('Thử kết nối lại'),
              style: ElevatedButton.styleFrom(
                minimumSize: const Size(0, 48),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Ô nhập ────────────────────────────────────────────────
  Widget _buildComposer(
    BuildContext context,
    ChatState state,
    ChatController controller,
  ) {
    final enabled = state.canSend;

    return Container(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.sm,
        AppSpacing.md,
        AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: AppColors.divider)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 120),
              child: TextField(
                controller: _input,
                focusNode: _inputFocus,
                enabled: enabled,
                minLines: 1,
                maxLines: 4,
                maxLength: 2000,
                textInputAction: TextInputAction.newline,
                keyboardType: TextInputType.multiline,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: AppColors.textPrimary,
                    ),
                decoration: InputDecoration(
                  hintText: 'Nhập câu hỏi của bạn…',
                  counterText: '',
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.md,
                    vertical: AppSpacing.sm + 2,
                  ),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                    borderSide: const BorderSide(color: AppColors.border),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                    borderSide: const BorderSide(color: AppColors.border),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                    borderSide: const BorderSide(color: AppColors.spicyRed, width: 1.5),
                  ),
                  disabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                    borderSide: const BorderSide(color: AppColors.divider),
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
          _SendButton(
            enabled: enabled,
            onPressed: _send,
          ),
        ],
      ),
    );
  }

  static String _formatVnd(double amount) {
    final rounded = amount.round();
    final digits = rounded.toString();
    final buffer = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buffer.write('.');
      buffer.write(digits[i]);
    }
    return '$buffer đ';
  }
}

// ═══════════════════════════════════════════════════════════
// ── Thành phần nhỏ ─────────────────────────────────────────
// ═══════════════════════════════════════════════════════════

/// Giữ một tin nhắn sống khi bị cuộn ra khỏi vùng nhìn.
///
/// `ListView` chỉ dựng element cho phần tử ở gần vùng nhìn; tin nhắn bị
/// cuộn ra ngoài sẽ mất element và phải dựng lại từ đầu khi cuộn lên —
/// hiệu ứng gõ chữ chạy lại từ 0% và ảnh gợi ý món bị tải lại. Bọc mỗi tin
/// nhắn trong widget này để nó được chuyển sang keep-alive bucket thay vì bị
/// huỷ, nên hiện lại đúng như lúc vừa nhận.
class _KeepAliveMessage extends StatefulWidget {
  const _KeepAliveMessage({super.key, required this.child});

  final Widget child;

  @override
  State<_KeepAliveMessage> createState() => _KeepAliveMessageState();
}

class _KeepAliveMessageState extends State<_KeepAliveMessage>
    with AutomaticKeepAliveClientMixin<_KeepAliveMessage> {
  @override
  bool get wantKeepAlive => true;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return widget.child;
  }
}

/// Nút gửi — hình tròn, nổi bật, đúng vùng chạm ngón tay.
class _SendButton extends StatelessWidget {
  const _SendButton({required this.enabled, required this.onPressed});

  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      enabled: enabled,
      label: 'Gửi tin nhắn',
      child: Material(
        color: enabled ? AppColors.spicyRed : AppColors.creamSoft,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: enabled ? onPressed : null,
          child: SizedBox(
            width: 48,
            height: 48,
            child: Icon(
              Icons.send_rounded,
              size: 20,
              color: enabled ? Colors.white : AppColors.textMuted,
            ),
          ),
        ),
      ),
    );
  }
}

/// Gợi ý câu hỏi — dạng pill, bấm là gửi ngay.
class _SuggestionChip extends StatelessWidget {
  const _SuggestionChip({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(AppRadius.pill),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.pill),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md,
            vertical: AppSpacing.sm,
          ),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.pill),
            border: Border.all(color: AppColors.border),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.arrow_outward_rounded,
                size: 15,
                color: AppColors.spicyRed,
              ),
              const SizedBox(width: AppSpacing.xs),
              Flexible(
                child: Text(
                  label,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AppColors.textPrimary,
                      ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Món ăn trợ lý gợi ý — bấm để mở món trong thực đơn.
class _MenuSuggestionTile extends StatelessWidget {
  const _MenuSuggestionTile({required this.suggestion});

  final MenuSuggestion suggestion;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.creamSoft,
      borderRadius: BorderRadius.circular(AppRadius.md),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.md),
        onTap: () => context.push(
          '/menu/${Uri.encodeComponent(suggestion.slug)}',
        ),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.xs),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(AppRadius.sm),
                child: SizedBox(
                  width: 48,
                  height: 48,
                  child: suggestion.hasImage
                      ? BackendImage(
                          imageUrl: suggestion.imageUrl!,
                          fit: BoxFit.cover,
                        )
                      : Container(
                          color: AppColors.creamDark,
                          child: const Icon(
                            Icons.restaurant_menu_rounded,
                            color: AppColors.textSecondary,
                          ),
                        ),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      suggestion.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            color: AppColors.textPrimary,
                          ),
                    ),
                    if (suggestion.description != null &&
                        suggestion.description!.isNotEmpty)
                      Text(
                        suggestion.description!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                  ],
                ),
              ),
              const Icon(
                Icons.chevron_right_rounded,
                color: AppColors.textMuted,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Ba chấm nhấp nháy khi trợ lý đang trả lời.
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
    if (MediaQuery.disableAnimationsOf(context)) {
      return const SizedBox(
        width: 24,
        height: 6,
        child: Center(
          child: Text('···', style: TextStyle(color: AppColors.spicyRed)),
        ),
      );
    }
    return SizedBox(
      // 3 chấm × (5px + 1.5px margin hai bên) = 24px. Cấp thiếu chiều rộng ở
      // đây sẽ khiến Row tràn ra ngoài và báo "RIGHT OVERFLOWED BY 6.0 PIXELS".
      width: 24,
      height: 8,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) => Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (i) {
            final t = (_controller.value * 3 - i).clamp(0.0, 1.0);
            final opacity = 0.3 + 0.7 * (t < 0.5 ? t * 2 : (1 - t) * 2);
            return Container(
              margin: const EdgeInsets.symmetric(horizontal: 1.5),
              width: 5,
              height: 5,
              decoration: BoxDecoration(
                color: AppColors.spicyRed.withValues(alpha: opacity),
                shape: BoxShape.circle,
              ),
            );
          }),
        ),
      ),
    );
  }
}

/// Văn bản trả lời, có hiệu ứng gõ chữ từ từ.
///
/// Hiệu ứng bị tắt khi hệ điều hành bật "giảm chuyển động".
class _ReplyText extends StatefulWidget {
  const _ReplyText({
    super.key,
    required this.text,
    required this.animate,
    required this.onProgress,
    required this.onComplete,
  });

  final String text;
  final bool animate;
  final VoidCallback onProgress;
  final VoidCallback onComplete;

  @override
  State<_ReplyText> createState() => _ReplyTextState();
}

class _ReplyTextState extends State<_ReplyText> {
  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.bodyMedium?.copyWith(
          color: AppColors.textPrimary,
          height: 1.5,
        );

    final reduceMotion = MediaQuery.disableAnimationsOf(context);
    if (!widget.animate || reduceMotion) {
      return Text(widget.text, style: style);
    }

    final runes = widget.text.runes.toList();
    // Tốc độ gõ ~ 60 ký tự/giây, tối đa 4 giây để câu dài không bị chậm.
    final duration = Duration(
      milliseconds: (runes.length * 16).clamp(200, 4000),
    );

    return Semantics(
      label: widget.text,
      child: ExcludeSemantics(
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: duration,
          onEnd: widget.onComplete,
          builder: (context, progress, _) {
            final shown = (progress * runes.length).ceil();
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) widget.onProgress();
            });
            return Text(
              String.fromCharCodes(runes.take(shown)),
              style: style,
            );
          },
        ),
      ),
    );
  }
}
