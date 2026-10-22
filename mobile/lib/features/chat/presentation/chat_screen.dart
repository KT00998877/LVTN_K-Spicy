import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/chat_repository.dart';
import '../../../shared/widgets/backend_image.dart';

class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({super.key});

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  static const _storage = FlutterSecureStorage();
  final _input = TextEditingController();
  final _scroll = ScrollController();
  Timer? _replyScrollTimer;
  int? _sessionId;
  List<Map<String, dynamic>> _messages = [];
  List<Map<String, dynamic>> _actions = [];
  bool _busy = true;
  String? _error;

  String get _sessionKey =>
      'customer_chat_session_${ref.read(authControllerProvider).user?.id ?? 0}';

  @override
  void initState() {
    super.initState();
    Future.microtask(_load);
  }

  Future<void> _load() async {
    setState(() { _busy = true; _error = null; });
    try {
      final repo = ref.read(chatRepositoryProvider);
      final saved = int.tryParse(await _storage.read(key: _sessionKey) ?? '');
      if (saved != null) {
        try {
          final session = await repo.loadSession(saved);
          if (session['status'] == 'active') {
            _sessionId = saved;
            _messages = (session['messages'] as List)
                .map((m) => Map<String, dynamic>.from(m as Map))
                .where((m) => m['role'] == 'user' || m['role'] == 'assistant')
                .toList();
            _actions = await repo.pendingActions(saved);
          }
        } catch (_) {
          await _storage.delete(key: _sessionKey);
        }
      }
      _sessionId ??= await repo.startSession();
      await _storage.write(key: _sessionKey, value: '$_sessionId');
    } catch (e) {
      _error = 'Không thể kết nối trợ lý. Vui lòng thử lại.';
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send([String? suggestion]) async {
    final message = (suggestion ?? _input.text).trim();
    if (message.isEmpty || _busy || _sessionId == null) return;
    _input.clear();
    setState(() { _busy = true; _error = null; });
    try {
      final reply = await ref.read(chatRepositoryProvider).sendMessage(_sessionId!, message);
      if (!mounted) return;
      setState(() {
        _messages.add({'role': 'user', 'content': message});
        _messages.add({
          'id': reply['message_id'],
          'role': 'assistant',
          'content': reply['content'],
          'animate': true,
          'metadata': {
            'menu_suggestions': reply['menu_suggestions'],
            'quick_action': reply['quick_action'],
          },
        });
        _actions.addAll((reply['pending_actions'] as List? ?? [])
            .map((a) => Map<String, dynamic>.from(a as Map)));
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) {
          _scroll.jumpTo(_scroll.position.maxScrollExtent);
        }
      });
      _replyScrollTimer?.cancel();
      if (!MediaQuery.disableAnimationsOf(context)) {
        _replyScrollTimer = Timer.periodic(const Duration(milliseconds: 80), (_) {
          if (_scroll.hasClients && _scroll.position.extentAfter < 120) {
            _scroll.jumpTo(_scroll.position.maxScrollExtent);
          }
        });
      }
    } catch (_) {
      if (mounted) {
        _input.text = message;
        setState(() => _error = 'Chưa gửi được tin nhắn. Vui lòng gửi lại.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resolve(int id, bool confirm) async {
    if (_busy) return;
    setState(() { _busy = true; _error = null; });
    try {
      await ref.read(chatRepositoryProvider).resolveAction(id, confirm: confirm);
      if (mounted) {
        setState(() => _actions.removeWhere((a) => a['id'] == id));
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(confirm ? 'Đã xác nhận yêu cầu.' : 'Đã hủy yêu cầu.'),
        ));
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'Không thể xử lý yêu cầu. Vui lòng thử lại.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    _replyScrollTimer?.cancel();
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.cream,
      appBar: AppBar(title: const Text('Trợ lý khách hàng')),
      body: SafeArea(
        child: Column(children: [
          Expanded(child: ListView(
            controller: _scroll,
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              const Text('Xin chào! Mình có thể giúp bạn xem thực đơn, đơn hàng hoặc đặt bàn.'),
              if (_messages.isEmpty && !_busy) ...[
                const SizedBox(height: 16),
                for (final question in ['Menu có món gì ngon?', 'Đặt bàn như thế nào?', 'Có khuyến mãi gì không?'])
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: OutlinedButton(
                      onPressed: () => _send(question),
                      child: Text(question),
                    ),
                  ),
              ],
              for (final message in _messages)
                Align(
                  alignment: message['role'] == 'user' ? Alignment.centerRight : Alignment.centerLeft,
                  child: Card(
                    color: message['role'] == 'user' ? AppColors.spicyRedSoft : Colors.white,
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (message['role'] == 'assistant')
                            _AnimatedReply(
                              key: ValueKey(message['id']),
                              text: '${message['content']}',
                              animate: message['animate'] == true,
                              onComplete: () {
                                _replyScrollTimer?.cancel();
                                if (!mounted) return;
                                final showFirstCard = _scroll.hasClients &&
                                    _scroll.position.extentAfter < 120 &&
                                    (message['metadata'] as Map?)?['menu_suggestions'] is List &&
                                    ((message['metadata'] as Map)['menu_suggestions'] as List).isNotEmpty;
                                setState(() => message['animate'] = false);
                                if (showFirstCard) {
                                  WidgetsBinding.instance.addPostFrameCallback((_) {
                                    if (_scroll.hasClients) {
                                      _scroll.jumpTo((_scroll.offset + 80)
                                          .clamp(0.0, _scroll.position.maxScrollExtent));
                                    }
                                  });
                                }
                              },
                            )
                          else
                            Text('${message['content']}'),
                          if (message['role'] == 'assistant' &&
                              (message['animate'] != true || MediaQuery.disableAnimationsOf(context)))
                            ..._messageLinks(message),
                        ],
                      ),
                    ),
                  ),
                ),
              for (final action in _actions) _actionCard(action),
              if (_busy) const Center(child: Padding(
                padding: EdgeInsets.all(12), child: CircularProgressIndicator(),
              )),
              if (_error != null) ...[
                Text(_error!),
                if (_sessionId == null)
                  TextButton(onPressed: _load, child: const Text('Thử kết nối lại')),
              ],
            ],
          )),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Row(children: [
              Expanded(child: TextField(
                controller: _input,
                maxLines: 3,
                minLines: 1,
                maxLength: 2000,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => _send(),
                decoration: const InputDecoration(labelText: 'Nhập câu hỏi', border: OutlineInputBorder()),
              )),
              const SizedBox(width: 8),
              IconButton.filled(
                tooltip: 'Gửi tin nhắn',
                onPressed: _busy ? null : _send,
                icon: const Icon(Icons.send_rounded),
              ),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _actionCard(Map<String, dynamic> action) {
    final summary = Map<String, dynamic>.from(action['summary'] as Map? ?? {});
    final id = action['id'] as int;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${summary['title'] ?? 'Xác nhận thao tác'}', style: Theme.of(context).textTheme.titleMedium),
          for (final item in (summary['items'] as List? ?? []))
            Text('${item['label']}: ${item['value']}'),
          const SizedBox(height: 8),
          Row(children: [
            TextButton(onPressed: _busy ? null : () => _resolve(id, false), child: const Text('Hủy')),
            const SizedBox(width: 8),
            FilledButton(onPressed: _busy ? null : () => _resolve(id, true), child: const Text('Xác nhận')),
          ]),
        ]),
      ),
    );
  }

  List<Widget> _messageLinks(Map<String, dynamic> message) {
    final metadata = message['metadata'] as Map? ?? {};
    final suggestions = metadata['menu_suggestions'] as List? ?? [];
    final quickAction = metadata['quick_action'] as Map? ?? {};
    return [
      for (final item in suggestions)
        if (item is Map && item['slug'] is String && item['name'] is String)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: OutlinedButton(
              onPressed: () => context.push('/menu/${Uri.encodeComponent(item['slug'] as String)}'),
              style: OutlinedButton.styleFrom(padding: const EdgeInsets.all(6)),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: SizedBox(
                    width: 56, height: 56,
                    child: item['image_url'] is String && (item['image_url'] as String).isNotEmpty
                        ? BackendImage(imageUrl: item['image_url'] as String, fit: BoxFit.cover)
                        : const Icon(Icons.restaurant_menu_rounded),
                  ),
                ),
                const SizedBox(width: 8),
                Flexible(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(item['name'] as String, style: const TextStyle(fontWeight: FontWeight.w600)),
                  if (item['description'] is String && (item['description'] as String).isNotEmpty)
                    Text(item['description'] as String, maxLines: 2, overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall),
                ])),
                const Icon(Icons.chevron_right_rounded),
              ]),
            ),
          ),
      if (quickAction['type'] == 'reservation_form')
        FilledButton(
          onPressed: () => context.push('/reservation'),
          child: const Text('Đặt bàn'),
        ),
    ];
  }
}

class _AnimatedReply extends StatelessWidget {
  const _AnimatedReply({
    super.key,
    required this.text,
    required this.animate,
    required this.onComplete,
  });

  final String text;
  final bool animate;
  final VoidCallback onComplete;

  @override
  Widget build(BuildContext context) {
    if (!animate || MediaQuery.disableAnimationsOf(context)) return Text(text);

    final runes = text.runes.toList();
    final duration = Duration(milliseconds: (runes.length * 25).clamp(0, 6000));
    return Semantics(
      label: text,
      child: ExcludeSemantics(
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: duration,
          onEnd: onComplete,
          builder: (context, progress, _) => Text(
            String.fromCharCodes(runes.take((progress * runes.length).ceil())),
          ),
        ),
      ),
    );
  }
}
