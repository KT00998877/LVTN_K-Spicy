import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../app/theme.dart';
import '../../auth/presentation/auth_controller.dart';
import 'account_controller.dart';
import 'widgets/account_widgets.dart';

/// ============================================================
/// 📋 TAB: THÔNG TIN CÁ NHÂN + ẢNH ĐẠI DIỆN
/// ============================================================
///
/// Trạng thái: xem → bấm "Chỉnh sửa" → nhập → lưu.
///
/// Avatar nằm NGOÀI chế độ edit (giống web): bấm ở đâu cũng đổi được,
/// vì đó là hành động độc lập, không cần validate form.
///
class ProfileTab extends ConsumerStatefulWidget {
  const ProfileTab({super.key});

  @override
  ConsumerState<ProfileTab> createState() => _ProfileTabState();
}

class _ProfileTabState extends ConsumerState<ProfileTab> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameCtrl;
  late final TextEditingController _fullNameCtrl;
  late final TextEditingController _phoneCtrl;

  bool _editing = false;

  @override
  void initState() {
    super.initState();
    _nameCtrl = TextEditingController();
    _fullNameCtrl = TextEditingController();
    _phoneCtrl = TextEditingController();
    WidgetsBinding.instance.addPostFrameCallback((_) => _syncFromUser());
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _fullNameCtrl.dispose();
    _phoneCtrl.dispose();
    super.dispose();
  }

  void _syncFromUser() {
    final user = ref.read(authControllerProvider).user;
    if (user == null) return;
    _nameCtrl.text = user.name;
    _fullNameCtrl.text = user.fullName ?? '';
    _phoneCtrl.text = user.phone ?? '';
  }

  /// Không ghi đè form khi khách đang gõ.
  void _startEdit() {
    _syncFromUser();
    setState(() => _editing = true);
    ref.read(profileControllerProvider.notifier).clearMessage();
  }

  void _cancelEdit() {
    _syncFromUser();
    setState(() => _editing = false);
    ref.read(profileControllerProvider.notifier).clearMessage();
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    FocusScope.of(context).unfocus();

    final ok = await ref.read(profileControllerProvider.notifier).saveProfile(
          name: _nameCtrl.text,
          fullName: _fullNameCtrl.text,
          phone: _phoneCtrl.text,
        );
    if (!mounted) return;
    if (ok) {
      setState(() => _editing = false);
      _syncFromUser();
    }
  }

  Future<void> _pickAvatar(ImageSource source) async {
    final picker = ImagePicker();
    final file = await picker.pickImage(
      source: source,
      // Server giới hạn 4MB — resize nhẹ phía client để tránh 422.
      maxWidth: 1024,
      maxHeight: 1024,
      imageQuality: 85,
    );
    if (file == null || !mounted) return;

    await ref
        .read(profileControllerProvider.notifier)
        .uploadAvatar(file.path);
  }

  Future<void> _confirmDeleteAvatar() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
        title: const Text('Xoá ảnh đại diện?'),
        content: const Text(
          'Ảnh của bạn sẽ bị xoá khỏi hệ thống và trở về chữ cái đầu.',
          style: TextStyle(height: 1.5),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Giữ lại'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Xoá'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    await ref.read(profileControllerProvider.notifier).deleteAvatar();
  }

  Future<void> _showAvatarSheet() async {
    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: AppColors.cream,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.xl)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: AppSpacing.sm),
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: AppColors.creamDark,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            ListTile(
              leading: const Icon(Icons.photo_camera_rounded,
                  color: AppColors.spicyRed),
              title: const Text('Chụp ảnh mới'),
              onTap: () {
                Navigator.pop(ctx);
                _pickAvatar(ImageSource.camera);
              },
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_rounded,
                  color: AppColors.spicyRed),
              title: const Text('Chọn từ thư viện'),
              onTap: () {
                Navigator.pop(ctx);
                _pickAvatar(ImageSource.gallery);
              },
            ),
            ListTile(
              leading: const Icon(Icons.delete_outline_rounded,
                  color: AppColors.danger),
              title: const Text(
                'Xoá ảnh đại diện',
                style: TextStyle(color: AppColors.danger),
              ),
              onTap: () {
                Navigator.pop(ctx);
                _confirmDeleteAvatar();
              },
            ),
            const SizedBox(height: AppSpacing.sm),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final profile = ref.watch(profileControllerProvider);
    final user = auth.user;
    if (user == null) return const SizedBox.shrink();

    final displayName = user.fullName?.isNotEmpty == true
        ? user.fullName!
        : user.name;

    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        // ── Avatar ────────────────────────────────────────
        Center(
          child: Column(
            children: [
              Stack(
                children: [
                  UserAvatar(
                    name: displayName,
                    avatarUrl: user.avatarUrl,
                    size: 104,
                    showCameraBadge: true,
                    onTap: profile.isBusy ? null : _showAvatarSheet,
                  ),
                  if (profile.isUploadingAvatar)
                    const Positioned.fill(
                      child: SizedBox(
                        width: 104,
                        height: 104,
                        child: CircularProgressIndicator(
                          strokeWidth: 3,
                          color: AppColors.spicyRed,
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                displayName,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 2),
              Text(
                user.email,
                style: const TextStyle(
                  fontSize: 13,
                  color: AppColors.textSecondary,
                ),
              ),
              if (user.tier != null) ...[
                const SizedBox(height: AppSpacing.sm),
                TierBadge(
                  name: user.tier!.name,
                  colorHex: user.tier!.color,
                  icon: user.tier!.icon,
                ),
              ],
            ],
          ),
        ),

        const SizedBox(height: AppSpacing.lg),

        _FeedbackBanner(
          message: profile.message,
          error: profile.error,
          onDismiss: () =>
              ref.read(profileControllerProvider.notifier).clearMessage(),
        ),

        // ── Form ──────────────────────────────────────────
        Form(
          key: _formKey,
          child: _AccountCard(
            title: _editing ? 'Chỉnh sửa thông tin' : 'Thông tin tài khoản',
            trailing: _editing
                ? null
                : TextButton.icon(
                    onPressed: _startEdit,
                    icon: const Icon(Icons.edit_rounded, size: 16),
                    label: const Text('Sửa'),
                    style: TextButton.styleFrom(
                      foregroundColor: AppColors.spicyRed,
                    ),
                  ),
            child: Column(
              children: [
                _Field(
                  controller: _nameCtrl,
                  label: 'Họ tên',
                  hint: 'Nguyễn Văn A',
                  enabled: _editing,
                  errorText: profile.fieldError('name'),
                  textCapitalization: TextCapitalization.words,
                  validator: (v) =>
                      (v == null || v.trim().length < 2)
                          ? 'Họ tên tối thiểu 2 ký tự.'
                          : null,
                ),
                const SizedBox(height: AppSpacing.md),
                _Field(
                  controller: _fullNameCtrl,
                  label: 'Tên đầy đủ',
                  hint: 'Nguyễn Văn An',
                  enabled: _editing,
                  errorText: profile.fieldError('full_name'),
                  helper: 'Dùng cho hoá đơn và giao hàng.',
                  textCapitalization: TextCapitalization.words,
                ),
                const SizedBox(height: AppSpacing.md),
                _Field(
                  controller: _phoneCtrl,
                  label: 'Số điện thoại',
                  hint: '0901234567',
                  enabled: _editing,
                  errorText: profile.fieldError('phone'),
                  helper: 'Định dạng: 0xxxxxxxxx hoặc +84xxxxxxxxx',
                  keyboardType: TextInputType.phone,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
                const SizedBox(height: AppSpacing.md),
                _Field(
                  controller: TextEditingController(text: user.email),
                  label: 'Email',
                  enabled: false,
                  helper: 'Email không thể thay đổi.',
                  readOnly: true,
                ),
                if (_editing) ...[
                  const SizedBox(height: AppSpacing.lg),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: profile.isSaving ? null : _cancelEdit,
                          style: OutlinedButton.styleFrom(
                            foregroundColor: AppColors.textPrimary,
                            side: const BorderSide(color: AppColors.border),
                            padding: const EdgeInsets.symmetric(
                              vertical: AppSpacing.md,
                            ),
                            shape: RoundedRectangleBorder(
                              borderRadius:
                                  BorderRadius.circular(AppRadius.pill),
                            ),
                          ),
                          child: const Text('Huỷ'),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        flex: 2,
                        child: FilledButton(
                          onPressed: profile.isSaving ? null : _save,
                          style: FilledButton.styleFrom(
                            backgroundColor: AppColors.spicyRed,
                            padding: const EdgeInsets.symmetric(
                              vertical: AppSpacing.md,
                            ),
                            shape: RoundedRectangleBorder(
                              borderRadius:
                                  BorderRadius.circular(AppRadius.pill),
                            ),
                          ),
                          child: profile.isSaving
                              ? const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: Colors.white,
                                  ),
                                )
                              : const Text('Lưu thay đổi'),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),

        // ── Thông tin phụ ─────────────────────────────────
        const SizedBox(height: AppSpacing.md),
        _AccountCard(
          title: 'Tài khoản',
          child: Column(
            children: [
              _InfoRow(
                label: 'Trạng thái',
                value: user.isActive ? 'Hoạt động' : 'Đã khoá',
                valueColor:
                    user.isActive ? AppColors.success : AppColors.danger,
              ),
              const Divider(height: AppSpacing.lg),
              _InfoRow(
                label: 'Ngày tham gia',
                value: user.createdAt == null
                    ? '—'
                    : '${user.createdAt!.day.toString().padLeft(2, '0')}/'
                        '${user.createdAt!.month.toString().padLeft(2, '0')}/'
                        '${user.createdAt!.year}',
              ),
              const Divider(height: AppSpacing.lg),
              _InfoRow(
                label: 'Điểm thưởng',
                value: '${user.loyaltyPoints} điểm',
                valueColor: AppColors.spicyRed,
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.xl),
      ],
    );
  }
}

// ────────────────── Sub-widgets ─────────────────────────────

/// Card trắng bo tròn theo chuẩn design system.
class _AccountCard extends StatelessWidget {
  const _AccountCard({
    required this.title,
    required this.child,
    this.trailing,
  });

  final String title;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(title, style: Theme.of(context).textTheme.titleMedium),
              ),
              if (trailing != null) trailing!,
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          child,
        ],
      ),
    );
  }
}

/// TextField wrapper chuẩn: label trên, helper/lỗi dưới.
class _Field extends StatelessWidget {
  const _Field({
    required this.controller,
    required this.label,
    this.hint,
    this.helper,
    this.errorText,
    this.enabled = true,
    this.readOnly = false,
    this.keyboardType,
    this.formatters,
    this.textCapitalization = TextCapitalization.none,
    this.validator,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final String? helper;
  final String? errorText;
  final bool enabled;
  final bool readOnly;
  final TextInputType? keyboardType;
  final List<TextInputFormatter>? formatters;
  final TextCapitalization textCapitalization;
  final String? Function(String?)? validator;

  @override
  Widget build(BuildContext context) {
    final hasError = errorText != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        const SizedBox(height: AppSpacing.xs),
        TextFormField(
          controller: controller,
          enabled: enabled,
          readOnly: readOnly,
          keyboardType: keyboardType,
          inputFormatters: formatters,
          textCapitalization: textCapitalization,
          validator: validator,
          style: const TextStyle(fontSize: 15, color: AppColors.textPrimary),
          decoration: InputDecoration(
            hintText: hint,
            isDense: true,
            contentPadding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.sm,
              vertical: AppSpacing.sm,
            ),
            filled: true,
            fillColor: enabled ? Colors.white : AppColors.cream,
            hintStyle: const TextStyle(color: AppColors.textMuted),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(AppRadius.md),
              borderSide: BorderSide(
                color: hasError ? AppColors.danger : AppColors.border,
              ),
            ),
            disabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(AppRadius.md),
              borderSide: const BorderSide(color: AppColors.divider),
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
        if (hasError) ...[
          const SizedBox(height: 5),
          Text(
            errorText!,
            style: const TextStyle(fontSize: 12, color: AppColors.danger),
          ),
        ] else if (helper != null) ...[
          const SizedBox(height: 5),
          Text(
            helper!,
            style: const TextStyle(fontSize: 12, color: AppColors.textMuted),
          ),
        ],
      ],
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({
    required this.label,
    required this.value,
    this.valueColor,
  });

  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: const TextStyle(
              fontSize: 13,
              color: AppColors.textSecondary,
            ),
          ),
        ),
        Text(
          value,
          style: TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w700,
            color: valueColor ?? AppColors.textPrimary,
          ),
        ),
      ],
    );
  }
}

/// Banner thông báo thành công / lỗi, có nút đóng.
class _FeedbackBanner extends StatelessWidget {
  const _FeedbackBanner({
    this.message,
    this.error,
    this.onDismiss,
  });

  final String? message;
  final String? error;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final isError = error != null;
    final text = isError ? error : message;
    if (text == null) return const SizedBox.shrink();

    final fg = isError ? AppColors.danger : AppColors.success;
    final bg = isError
        ? AppColors.spicyRedSoft
        : const Color(0xFFE6F7EE);

    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: fg.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          Icon(
            isError
                ? Icons.error_outline_rounded
                : Icons.check_circle_outline_rounded,
            size: 18,
            color: fg,
          ),
          const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w600,
                color: fg,
              ),
            ),
          ),
          if (onDismiss != null)
            GestureDetector(
              onTap: onDismiss,
              child: Icon(Icons.close_rounded, size: 16, color: fg),
            ),
        ],
      ),
    );
  }
}

/// Export để các tab khác dùng lại (báo lỗi + banner).
class AccountFeedbackBanner extends StatelessWidget {
  const AccountFeedbackBanner({super.key, this.message, this.error, this.onDismiss});

  final String? message;
  final String? error;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) => _FeedbackBanner(
        message: message,
        error: error,
        onDismiss: onDismiss,
      );
}