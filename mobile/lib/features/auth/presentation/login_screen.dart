import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import '../../../core/api/api_exception.dart';
import '../domain/app_user.dart';
import 'auth_controller.dart';

/// ============================================================
/// 🔐 LOGIN SCREEN
/// ============================================================
/// - Có 2 chế độ: Khách hàng / Shipper
/// - Mặc định: Customer. Bấm "Đăng nhập Shipper" để chuyển.
/// - Validate cơ bản; lỗi server hiển thị qua [ApiException].
/// ============================================================

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key, this.register = false});
  final bool register;

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _confirmationCtrl = TextEditingController();
  Map<String, List<String>> _fieldErrors = {};
  final _emailCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _obscureCtrl = ValueNotifier<bool>(true);

  /// Loại tài khoản đang đăng nhập
  UserRole _role = UserRole.customer;
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _confirmationCtrl.dispose();
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    _obscureCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_isLoading) return;
    setState(() => _fieldErrors = {});
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final controller = ref.read(authControllerProvider.notifier);
      if (widget.register) {
        await controller.registerCustomer(
          name: _nameCtrl.text.trim(),
          email: _emailCtrl.text.trim(),
          password: _passwordCtrl.text,
          passwordConfirmation: _confirmationCtrl.text,
          phone: _phoneCtrl.text.trim(),
        );
      } else if (_role == UserRole.customer) {
        await controller.loginCustomer(
          email: _emailCtrl.text.trim(),
          password: _passwordCtrl.text,
        );
      } else {
        await controller.loginStaff(
          email: _emailCtrl.text.trim(),
          password: _passwordCtrl.text,
        );
      }
      // Router sẽ tự điều hướng theo role (qua redirect).
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _errorMessage = e.message;
          _fieldErrors = e.errors ?? {};
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _errorMessage = 'Đã có lỗi xảy ra. Vui lòng thử lại.');
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const _BrandHeader(),
                    const SizedBox(height: AppSpacing.lg),
                    if (!widget.register)
                      _RoleSwitcher(
                        current: _role,
                        onChanged: (r) {
                          if (_isLoading) return;
                          setState(() {
                            _role = r;
                            _errorMessage = null;
                            _fieldErrors = {};
                          });
                        },
                      ),
                    const SizedBox(height: AppSpacing.lg),
                    if (widget.register) ...[
                      TextFormField(
                        controller: _nameCtrl,
                        enabled: !_isLoading,
                        autofillHints: const [AutofillHints.name],
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: 'Họ tên',
                          errorText: _fieldErrors['name']?.join('\n'),
                        ),
                        validator: (v) =>
                            (v?.trim().length ?? 0) < 2 ||
                                v!.trim().length > 100
                            ? 'Họ tên cần từ 2 đến 100 ký tự'
                            : null,
                      ),
                      const SizedBox(height: AppSpacing.md),
                      TextFormField(
                        controller: _phoneCtrl,
                        enabled: !_isLoading,
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.next,
                        autofillHints: const [AutofillHints.telephoneNumber],
                        decoration: InputDecoration(
                          labelText: 'Số điện thoại (không bắt buộc)',
                          errorText: _fieldErrors['phone']?.join('\n'),
                        ),
                        validator: (v) =>
                            v == null ||
                                v.trim().isEmpty ||
                                RegExp(
                                  r'^(0|\+84)[0-9]{9,10}$',
                                ).hasMatch(v.trim())
                            ? null
                            : 'Số điện thoại không hợp lệ',
                      ),
                      const SizedBox(height: AppSpacing.md),
                    ],
                    TextFormField(
                      enabled: !_isLoading,
                      autofillHints: [
                        widget.register
                            ? AutofillHints.email
                            : AutofillHints.username,
                      ],
                      textInputAction: TextInputAction.next,
                      controller: _emailCtrl,
                      keyboardType: TextInputType.emailAddress,
                      autocorrect: false,
                      decoration: InputDecoration(
                        labelText: widget.register
                            ? 'Email'
                            : 'Email hoặc số điện thoại',
                        errorText:
                            (_fieldErrors[widget.register
                                    ? 'email'
                                    : 'identifier'])
                                ?.join('\n'),
                        hintText: 'you@example.com',
                        prefixIcon: const Icon(Icons.alternate_email_rounded),
                      ),
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) {
                          return 'Vui lòng nhập email';
                        }
                        if (widget.register &&
                            (!RegExp(
                                  r'^[^\s@]+@[^\s@]+\.[^\s@]+$',
                                ).hasMatch(v.trim()) ||
                                v.trim().length > 191)) {
                          return 'Email không hợp lệ';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: AppSpacing.md),
                    ValueListenableBuilder<bool>(
                      valueListenable: _obscureCtrl,
                      builder: (context, obscure, _) => TextFormField(
                        enabled: !_isLoading,
                        autofillHints: [
                          widget.register
                              ? AutofillHints.newPassword
                              : AutofillHints.password,
                        ],
                        controller: _passwordCtrl,
                        obscureText: obscure,
                        decoration: InputDecoration(
                          labelText: 'Mật khẩu',
                          errorText: _fieldErrors['password']?.join('\n'),
                          helperText: widget.register
                              ? 'Ít nhất 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt'
                              : null,
                          helperMaxLines: 3,
                          hintText: '••••••••',
                          prefixIcon: const Icon(Icons.lock_outline_rounded),
                          suffixIcon: IconButton(
                            tooltip: obscure ? 'Hiện mật khẩu' : 'Ẩn mật khẩu',
                            icon: Icon(
                              obscure
                                  ? Icons.visibility_outlined
                                  : Icons.visibility_off_outlined,
                            ),
                            onPressed: () => _obscureCtrl.value = !obscure,
                          ),
                        ),
                        validator: (v) {
                          if (v == null || v.isEmpty) {
                            return 'Vui lòng nhập mật khẩu';
                          }
                          if (widget.register &&
                              (v.length < 8 ||
                                  !RegExp(r'[A-Z]').hasMatch(v) ||
                                  !RegExp(r'[a-z]').hasMatch(v) ||
                                  !RegExp(r'[0-9]').hasMatch(v) ||
                                  !RegExp(r'[^a-zA-Z0-9\s]').hasMatch(v))) {
                            return 'Mật khẩu chưa đáp ứng yêu cầu';
                          }
                          return null;
                        },
                      ),
                    ),
                    if (widget.register) ...[
                      const SizedBox(height: AppSpacing.md),
                      TextFormField(
                        controller: _confirmationCtrl,
                        enabled: !_isLoading,
                        obscureText: true,
                        autofillHints: const [AutofillHints.newPassword],
                        textInputAction: TextInputAction.done,
                        onFieldSubmitted: (_) => _submit(),
                        decoration: InputDecoration(
                          labelText: 'Xác nhận mật khẩu',
                          errorText: _fieldErrors['password_confirmation']
                              ?.join('\n'),
                        ),
                        validator: (v) =>
                            v == null || v.isEmpty || v != _passwordCtrl.text
                            ? 'Xác nhận mật khẩu không khớp'
                            : null,
                      ),
                    ],
                    if (_errorMessage != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      _ErrorBanner(message: _errorMessage!),
                    ],
                    const SizedBox(height: AppSpacing.lg),
                    ElevatedButton(
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: Colors.white,
                              ),
                            )
                          : Text(
                              widget.register
                                  ? 'Đăng ký'
                                  : _role == UserRole.customer
                                  ? 'Đăng nhập'
                                  : 'Đăng nhập Shipper',
                            ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    if (_role == UserRole.customer) ...[
                      const SizedBox(height: AppSpacing.xs),
                      Wrap(
                        alignment: WrapAlignment.center,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text(
                            widget.register
                                ? 'Đã có tài khoản? '
                                : 'Chưa có tài khoản? ',
                          ),
                          TextButton(
                            onPressed: _isLoading
                                ? null
                                : () => context.go(
                                    widget.register ? '/login' : '/register',
                                  ),
                            child: Text(
                              widget.register ? 'Đăng nhập' : 'Đăng ký',
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BrandHeader extends StatelessWidget {
  const _BrandHeader();

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: 84,
          height: 84,
          decoration: BoxDecoration(
            color: AppColors.spicyRed,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            boxShadow: AppShadow.floating,
          ),
          alignment: Alignment.center,
          child: const Text('🍜', style: TextStyle(fontSize: 44)),
        ),
        const SizedBox(height: AppSpacing.sm),
        Text('Mì Cay', style: Theme.of(context).textTheme.displaySmall),
        Text(
          'Hệ thống nhà hàng Hàn Quốc',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ],
    );
  }
}

class _RoleSwitcher extends StatelessWidget {
  const _RoleSwitcher({required this.current, required this.onChanged});
  final UserRole current;
  final ValueChanged<UserRole> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.xxs),
      decoration: BoxDecoration(
        color: AppColors.creamSoft,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        children: [
          _tab(context, UserRole.customer, 'Khách hàng', Icons.person_outline),
          _tab(
            context,
            UserRole.shipper,
            'Shipper',
            Icons.delivery_dining_outlined,
          ),
        ],
      ),
    );
  }

  Widget _tab(
    BuildContext context,
    UserRole role,
    String label,
    IconData icon,
  ) {
    final selected = current == role;
    return Expanded(
      child: GestureDetector(
        onTap: () => onChanged(role),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
          decoration: BoxDecoration(
            color: selected ? AppColors.spicyRed : Colors.transparent,
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                icon,
                size: 18,
                color: selected
                    ? AppColors.textOnDark
                    : AppColors.textSecondary,
              ),
              const SizedBox(width: 6),
              Text(
                label,
                style: TextStyle(
                  fontWeight: FontWeight.w600,
                  color: selected
                      ? AppColors.textOnDark
                      : AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.spicyRedSoft,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.spicyRed.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline, color: AppColors.spicyRed, size: 20),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(color: AppColors.spicyRedDark),
            ),
          ),
        ],
      ),
    );
  }
}
