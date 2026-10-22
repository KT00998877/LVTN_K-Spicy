import 'package:flutter/material.dart';

import '../../../app/theme.dart';

/// ============================================================
/// 🧱 SECTION FRAME — khung section có thể chỉnh màu nền
/// ============================================================
/// Theo home-ui.mdc:
///   CREAM hero → WHITE strip → CHARCOAL → RED → CREAM → WHITE → ...
/// Mỗi section dùng [SectionFrame] để chuẩn hoá padding + màu nền.
/// ============================================================

enum SectionTone { cream, white, charcoal, red }

class SectionFrame extends StatelessWidget {
  const SectionFrame({
    super.key,
    required this.tone,
    required this.child,
    this.padding =
        const EdgeInsets.symmetric(horizontal: 16, vertical: 28),
  });

  final SectionTone tone;
  final Widget child;
  final EdgeInsetsGeometry padding;

  Color get _bg {
    switch (tone) {
      case SectionTone.cream:
        return AppColors.cream;
      case SectionTone.white:
        return Colors.white;
      case SectionTone.charcoal:
        return AppColors.charcoal;
      case SectionTone.red:
        return AppColors.spicyRed;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: _bg,
      padding: padding,
      child: child,
    );
  }
}

/// ============================================================
/// 🏷 SECTION HEAD — eyebrow + tiêu đề + mô tả ngắn
/// ============================================================
class SectionHead extends StatelessWidget {
  const SectionHead({
    super.key,
    required this.eyebrow,
    required this.title,
    this.subtitle,
    this.align = TextAlign.left,
    this.onDark = false,
    this.trailing,
  });

  final String eyebrow;
  final String title;
  final String? subtitle;
  final TextAlign align;
  final bool onDark;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final eyebrowColor = onDark ? AppColors.goldenYellow : AppColors.spicyRed;
    final titleColor = onDark ? AppColors.textOnDark : AppColors.textPrimary;
    final subColor = onDark
        ? AppColors.textOnDark.withValues(alpha: 0.7)
        : AppColors.textSecondary;

    final children = <Widget>[
      Text(
        eyebrow.toUpperCase(),
        textAlign: align,
        style: TextStyle(
          color: eyebrowColor,
          fontSize: 11,
          fontWeight: FontWeight.w800,
          letterSpacing: 1.4,
        ),
      ),
      const SizedBox(height: 8),
      Text(
        title,
        textAlign: align,
        style: Theme.of(context)
            .textTheme
            .headlineMedium
            ?.copyWith(
              color: titleColor,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.6,
              height: 1.15,
            ),
      ),
      if (subtitle != null) ...[
        const SizedBox(height: 8),
        Text(
          subtitle!,
          textAlign: align,
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: subColor,
                height: 1.5,
              ),
        ),
      ],
    ];

    final inner = align == TextAlign.center
        ? Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: children,
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: children,
          );

    if (trailing == null) return inner;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: inner),
        if (trailing != null) ...[
          const SizedBox(width: 16),
          trailing!,
        ],
      ],
    );
  }
}

/// ============================================================
/// 🥫 SKELETON — placeholder cho ảnh / card khi loading
/// ============================================================
class SkeletonBox extends StatefulWidget {
  const SkeletonBox({
    super.key,
    required this.height,
    this.width,
    this.radius = 12,
  });

  final double height;
  final double? width;
  final double radius;

  @override
  State<SkeletonBox> createState() => _SkeletonBoxState();
}

class _SkeletonBoxState extends State<SkeletonBox>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat();
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (_, __) {
        final t = _ctrl.value;
        return Container(
          height: widget.height,
          width: widget.width ?? double.infinity,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(widget.radius),
            gradient: LinearGradient(
              begin: const Alignment(-1, 0),
              end: const Alignment(1, 0),
              colors: const [
                Color(0xFFEEE6D5),
                Color(0xFFF3ECDD),
                Color(0xFFEEE6D5),
              ],
              stops: [
                (t - 0.3).clamp(0.0, 1.0),
                t.clamp(0.0, 1.0),
                (t + 0.3).clamp(0.0, 1.0),
              ],
            ),
          ),
        );
      },
    );
  }
}
