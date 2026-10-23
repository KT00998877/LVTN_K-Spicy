import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme.dart';
import '../../auth/presentation/auth_controller.dart';
import 'account_controller.dart';
import 'profile_tab.dart' show AccountFeedbackBanner;

/// ============================================================
/// 🔐 TAB: BẢO MẬT — đổi mật khẩu + đăng xuất
/// ============================================================
///
/// Luật mật khẩu (khớp backend `Password::min(8)->letters()->mixedCase()
///
/// ->numbers()->symbols()`):
///   • ≥ 8 ký tự
///   • Có chữ hoa và chữ thường
///   • Có số
///   • Có ký hiệu đặc biệt
///
/// Độ mạnh chỉ là gợi ý phía client — server vẫn là nguồn chân lý, nên mọi
/// lỗi 422 từ server vẫn hiển thị nguyên vẹn.
///
class SecurityTab extends ConsumerStatefulWidget {
  const SecurityTab({super.key});

  @override
  ConsumerState<SecurityTab> createState() => _SecurityTabState();
}

class _SecurityTabState extends ConsumerState<SecurityTab> {
  final _formKey = GlobalKey<FormState>();
  final _currentCtrl = TextEditingController();
  final _newCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();

  bool _showCurrent = false;
  bool _showNew = false;
  bool _showConfirm = false;

  @override
  void initState() {
    super.initState();
    _newCtrl.addListener(() => setState(() {}));
    _confirmCtrl.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _currentCtrl.dispose();
    _newCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    FocusScope.of(context).unfocus();

    final ok = await ref.read(passwordControllerProvider.notifier).submit(
          currentPassword: _currentCtrl.text,
          newPassword: _newCtrl.text,
          confirmation: _confirmCtrl.text,
        );

    if (!mounted || !ok) return;

    // Backend thu hồi token cũ sau khi đổi mật khẩu → session đã bị expire,
    // router tự chuyển về /login. Chỉ cần báo lại cho khách biết vì sao.
    _currentCtrl.clear();
    _newCtrl.clear();
    _confirmCtrl.clear();

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: AppColors.charcoal,
        content: const Text(
          'Đổi mật khẩu thành công. Vui lòng đăng nhập lại.',
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
    );
  }

  Future<void> _confirmLogout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
        title: const Text('Đăng xuất?'),
        content: const Text(
          'Phiên làm việc hiện tại trên thiết bị này sẽ kết thúc.',
          style: TextStyle(height: 1.5),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Huỷ'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Đăng xuất'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    await ref.read(authControllerProvider.notifier).logout();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(passwordControllerProvider);

    // Đổi mật khẩu xong → session hết hạn, chuyển về màn đăng nhập.
    ref.listen(passwordControllerProvider, (prev, next) {
      if (prev?.done != true && next.done) {
        context.go('/login');
      }
    });

    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        AccountFeedbackBanner(
          message: state.done ? 'Đổi mật khẩu thành công.' : null,
          error: state.error,
        ),

        Container(
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: AppColors.border),
          ),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Đổi mật khẩu',
                  style: TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                const Text(
                  'Sau khi đổi, bạn sẽ cần đăng nhập lại với mật khẩu mới.',
                  style: TextStyle(
                    fontSize: 13,
                    height: 1.5,
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),

                _PwField(
                  controller: _currentCtrl,
                  label: 'Mật khẩu hiện tại',
                  obscure: !_showCurrent,
                  errorText: state.fieldError('current_password'),
                  onToggle: () => setState(() => _showCurrent = !_showCurrent),
                ),
                const SizedBox(height: AppSpacing.md),

                _PwField(
                  controller: _newCtrl,
                  label: 'Mật khẩu mới',
                  obscure: !_showNew,
                  errorText: state.fieldError('password'),
                  onToggle: () => setState(() => _showNew = !_showNew),
                  validator: (v) => validateNewPassword(v),
                  textInputAction: TextInputAction.next,
                ),

                // Thanh độ mạnh + checklist luật — chỉ hiện khi đã nhập.
                if (_newCtrl.text.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.sm),
                  _PasswordStrengthMeter(password: _newCtrl.text),
                  const SizedBox(height: AppSpacing.sm),
                  _PasswordRules(password: _newCtrl.text),
                ],

                const SizedBox(height: AppSpacing.md),

                _PwField(
                  controller: _confirmCtrl,
                  label: 'Xác nhận mật khẩu mới',
                  obscure: !_showConfirm,
                  onToggle: () =>
                      setState(() => _showConfirm = !_showConfirm),
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _submit(),
                  validator: (v) {
                    if (v == null || v.isEmpty) return 'Vui lòng xác nhận mật khẩu.';
                    if (v != _newCtrl.text) {
                      return 'Mật khẩu xác nhận không khớp.';
                    }
                    return null;
                  },
                ),

                const SizedBox(height: AppSpacing.lg),
                FilledButton(
                  onPressed: state.isSubmitting ? null : _submit,
                  style: FilledButton.styleFrom(
                    backgroundColor: AppColors.spicyRed,
                    disabledBackgroundColor: AppColors.spicyRedSoft,
                    disabledForegroundColor: AppColors.textMuted,
                    minimumSize: const Size.fromHeight(50),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                    ),
                  ),
                  child: state.isSubmitting
                      ? const SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.4,
                            color: Colors.white,
                          ),
                        )
                      : const Text(
                          'Lưu mật khẩu mới',
                          style: TextStyle(fontWeight: FontWeight.w700),
                        ),
                ),
              ],
            ),
          ),
        ),

        const SizedBox(height: AppSpacing.md),

        // ── Đăng xuất ────────────────────────────────────
        Container(
          padding: const EdgeInsets.all(AppSpacing.md),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Phiên đăng nhập',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: AppSpacing.xs),
              const Text(
                'Đăng xuất sẽ thu hồi phiên làm việc hiện tại trên thiết bị này.',
                style: TextStyle(
                  fontSize: 13,
                  height: 1.5,
                  color: AppColors.textSecondary,
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              OutlinedButton.icon(
                onPressed: _confirmLogout,
                icon: const Icon(Icons.logout_rounded, size: 18),
                label: const Text('Đăng xuất khỏi tài khoản'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.danger,
                  side: const BorderSide(color: AppColors.danger),
                  minimumSize: const Size.fromHeight(48),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(AppRadius.pill),
                  ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.xl),
      ],
    );
  }
}

/// Validate luật mật khẩu phía client — trùng với backend để báo lỗi
/// sớm, nhưng server vẫn là nguồn chân lý.
String? validateNewPassword(String? value) {
  final v = value ?? '';
  if (v.isEmpty) return 'Vui lòng nhập mật khẩu mới.';
  if (v.length < 8) return 'Mật khẩu phải có ít nhất 8 ký tự.';
  if (!RegExp(r'[a-z]').hasMatch(v)) return 'Cần ít nhất 1 chữ thường.';
  if (!RegExp(r'[A-Z]').hasMatch(v)) return 'Cần ít nhất 1 chữ hoa.';
  if (!RegExp(r'[0-9]').hasMatch(v)) return 'Cần ít nhất 1 chữ số.';
  if (!RegExp(r'[^A-Za-z0-9]').hasMatch(v)) {
    return 'Cần ít nhất 1 ký hiệu đặc biệt.';
  }
  return null;
}

// ────────────────── Sub-widgets ─────────────────────────────

class _PwField extends StatelessWidget {
  const _PwField({
    required this.controller,
    required this.label,
    required this.obscure,
    required this.onToggle,
    this.validator,
    this.errorText,
    this.textInputAction,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final String label;
  final bool obscure;
  final VoidCallback onToggle;
  final String? Function(String?)? validator;
  final String? errorText;
  final TextInputAction? textInputAction;
  final ValueChanged<String>? onSubmitted;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: AppSpacing.xs),
        TextFormField(
          controller: controller,
          obscureText: obscure,
          validator: validator,
          textInputAction: textInputAction,
          onFieldSubmitted: onSubmitted,
          autocorrect: false,
          enableSuggestions: false,
          style: const TextStyle(fontSize: 15),
          decoration: InputDecoration(
            isDense: true,
            contentPadding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.sm,
            ),
            filled: true,
            fillColor: Colors.white,
            suffixIcon: IconButton(
              icon: Icon(
                obscure
                    ? Icons.visibility_outlined
                    : Icons.visibility_off_outlined,
                size: 20,
                color: AppColors.textSecondary,
              ),
              tooltip: obscure ? 'Hiện mật khẩu' : 'Ẩn mật khẩu',
              onPressed: onToggle,
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(AppRadius.md),
              borderSide: BorderSide(
                color: errorText != null ? AppColors.danger : AppColors.border,
              ),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(AppRadius.md),
              borderSide: const BorderSide(color: AppColors.spicyRed, width: 1.6),
            ),
            errorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(AppRadius.md),
              borderSide: const BorderSide(color: AppColors.danger),
            ),
            focusedErrorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(AppRadius.md),
              borderSide: const BorderSide(color: AppColors.danger, width: 1.6),
            ),
          ),
        ),
        if (errorText != null)
          Padding(
            padding: const EdgeInsets.only(top: 5),
            child: Text(
              errorText!,
              style: const TextStyle(fontSize: 12, color: AppColors.danger),
            ),
          ),
      ],
    );
  }
}

/// Thanh 4 mức: Yếu / Trung bình / Khá / Mạnh.
class _PasswordStrengthMeter extends StatelessWidget {
  const _PasswordStrengthMeter({required this.password});

  final String password;

  static int _score(String p) {
    if (p.isEmpty) return 0;
    var score = 0;
    if (p.length >= 8) score++;
    if (p.length >= 12) score++;
    if (RegExp(r'[A-Z]').hasMatch(p) && RegExp(r'[a-z]').hasMatch(p)) score++;
    if (RegExp(r'[0-9]').hasMatch(p)) score++;
    if (RegExp(r'[^A-Za-z0-9]').hasMatch(p)) score++;
    return score.clamp(0, 4);
  }

  static const _labels = ['Rất yếu', 'Yếu', 'Trung bình', 'Khá', 'Mạnh'];
  static const _colors = [
    AppColors.danger,
    Color(0xFFEA580C),
    AppColors.warning,
    AppColors.goldenYellow,
    AppColors.success,
  ];

  @override
  Widget build(BuildContext context) {
    final score = _score(password);
    final color = _colors[score];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: Row(
                  children: List.generate(4, (i) {
                    return Expanded(
                      child: Container(
                        height: 5,
                        margin: EdgeInsets.only(right: i == 3 ? 0 : 3),
                        decoration: BoxDecoration(
                          color: i < score ? color : AppColors.creamSoft,
                          borderRadius: BorderRadius.circular(999),
                        ),
                      ),
                    );
                  }),
                ),
              ),
            ),
            const SizedBox(width: AppSpacing.xs),
            Text(
              _labels[score],
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                color: color,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// Checklist luật mật khẩu — tick dần khi người dùng gõ.
class _PasswordRules extends StatelessWidget {
  const _PasswordRules({required this.password});

  final String password;

  @override
  Widget build(BuildContext context) {
    final rules = <(String, bool)>[
      ('Ít nhất 8 ký tự', password.length >= 8),
      ('Có chữ hoa và chữ thường',
          RegExp(r'[A-Z]').hasMatch(password) &&
              RegExp(r'[a-z]').hasMatch(password)),
      ('Có chữ số', RegExp(r'[0-9]').hasMatch(password)),
      ('Có ký hiệu đặc biệt',
          RegExp(r'[^A-Za-z0-9]').hasMatch(password)),
    ];

    return Column(
      children: rules
          .map((rule) => Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    Icon(
                      rule.$2
                          ? Icons.check_circle_rounded
                          : Icons.circle_outlined,
                      size: 15,
                      color: rule.$2 ? AppColors.success : AppColors.textMuted,
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    // Expanded để nhãn dài xuống dòng thay vì tràn ngang
                    // trên màn hình hẹp (rule §22 mobile-first).
                    Expanded(
                      child: Text(
                        rule.$1,
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: rule.$2
                              ? AppColors.success
                              : AppColors.textMuted,
                        ),
                      ),
                    ),
                  ],
                ),
              ))
          .toList(),
    );
  }
}