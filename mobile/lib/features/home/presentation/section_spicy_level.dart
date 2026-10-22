import '../../../shared/widgets/backend_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme.dart';
import 'home_controller.dart';
import 'section_frame.dart';

/// ============================================================
/// 🌶 SPICY LEVEL SECTION — CREAM band (home-ui §5)
/// ============================================================
/// Tương tác signature: chọn cấp độ 0..6, có visual feedback
/// + CTA "Thử món cấp N" → chuyển sang Menu với filter.
/// ============================================================

class SpicyLevelSection extends ConsumerStatefulWidget {
  const SpicyLevelSection({super.key, required this.onOpenMenu});

  final void Function(int spicyLevel) onOpenMenu;

  @override
  ConsumerState<SpicyLevelSection> createState() =>
      _SpicyLevelSectionState();
}

class _SpicyLevelSectionState extends ConsumerState<SpicyLevelSection> {
  int _level = 3;

  static const _groups = <_SpicyGroup>[
    _SpicyGroup('Nhẹ nhàng', Color(0xFFFFB000)),
    _SpicyGroup('Vừa vị', Color(0xFFFF7A00)),
    _SpicyGroup('Cay nồng', Color(0xFFE83A14)),
    _SpicyGroup('Cực hạn', Color(0xFFA80F17)),
  ];

  int get _activeGroup {
    if (_level <= 1) return 0;
    if (_level <= 3) return 1;
    if (_level <= 5) return 2;
    return 3;
  }

  @override
  Widget build(BuildContext context) {
    final featured = ref.watch(homeControllerProvider).featured;
    final bowlImage = featured.isNotEmpty ? featured.first.imageUrl : null;

    return SectionFrame(
      tone: SectionTone.cream,
      padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 16),
      child: Column(
        children: [
          const SectionHead(
            eyebrow: 'Câu chuyện cay',
            title: 'Bạn chịu cay đến đâu?',
            subtitle:
                'Mỗi cấp độ là một trải nghiệm khác biệt. Chọn mức phù hợp để '
                'thưởng thức trọn vẹn nước dùng chuẩn Hàn.',
            align: TextAlign.center,
          ),
          const SizedBox(height: 24),

          // ── Bowl visual ────────────────────────────────────
          Stack(
            alignment: Alignment.topLeft,
            children: [
              ClipOval(
                child: Container(
                  width: 200,
                  height: 200,
                  decoration: BoxDecoration(
                    color: AppColors.creamSoft,
                    boxShadow: [
                      BoxShadow(
                        color: _groups[_activeGroup].color
                            .withValues(alpha: 0.35),
                        blurRadius: 36,
                        spreadRadius: 4,
                      ),
                    ],
                  ),
                  child: bowlImage != null && bowlImage.isNotEmpty
                      ? BackendImage(
                          imageUrl: bowlImage,
                          fit: BoxFit.cover,
                          placeholder: (_, __) =>
                              Container(color: AppColors.creamSoft),
                          errorWidget: (_, __, ___) =>
                              const _BowlFallback(),
                        )
                      : const _BowlFallback(),
                ),
              ),
              Container(
                margin: const EdgeInsets.all(8),
                padding: const EdgeInsets.symmetric(
                    horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: _groups[_activeGroup].color,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 22,
                      height: 22,
                      decoration: const BoxDecoration(
                        color: Color(0x33FFFFFF),
                        shape: BoxShape.circle,
                      ),
                      alignment: Alignment.center,
                      child: Text(
                        '$_level',
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w800,
                          fontSize: 12,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                    Text(
                      _level == 0 ? 'Không cay' : 'Cấp $_level',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 28),

          // ── Level selector (0..6) ──────────────────────────
          _LevelDial(
            level: _level,
            onChanged: (v) => setState(() => _level = v),
          ),
          const SizedBox(height: 20),

          // ── Group meta ─────────────────────────────────────
          Text(
            _level == 0 ? 'Không cay' : 'Cấp $_level · ${_groups[_activeGroup].name}',
            style: TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.w800,
              color: _groups[_activeGroup].color,
            ),
          ),
          const SizedBox(height: 16),

          // ── Legend ─────────────────────────────────────────
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.center,
            children: [
              for (int i = 0; i < _groups.length; i++)
                _LegendChip(
                  group: _groups[i],
                  range: 'Cấp ${i * 2}–${i * 2 + 1}',
                  active: _activeGroup == i,
                ),
            ],
          ),
          const SizedBox(height: 24),

          // ── CTA ────────────────────────────────────────────
          ElevatedButton(
            onPressed: () => widget.onOpenMenu(_level),
            style: ElevatedButton.styleFrom(
              backgroundColor: _groups[_activeGroup].color,
              foregroundColor: Colors.white,
              minimumSize: const Size.fromHeight(52),
            ),
            child: Text(
              _level == 0
                  ? 'Thử món không cay'
                  : 'Thử món cấp $_level',
            ),
          ),
        ],
      ),
    );
  }
}

class _BowlFallback extends StatelessWidget {
  const _BowlFallback();
  @override
  Widget build(BuildContext context) =>
      const Center(child: Text('🍜', style: TextStyle(fontSize: 96)));
}

class _SpicyGroup {
  const _SpicyGroup(this.name, this.color);
  final String name;
  final Color color;
}

class _LevelDial extends StatelessWidget {
  const _LevelDial({required this.level, required this.onChanged});
  final int level;
  final ValueChanged<int> onChanged;

  static const _redGradient = [
    Color(0xFFFFB000),
    Color(0xFFFF7A00),
    Color(0xFFE83A14),
    Color(0xFFA80F17),
  ];

  @override
  Widget build(BuildContext context) {
    const maxLevel = 6;
    return LayoutBuilder(
      builder: (context, constraints) {
        final w = constraints.maxWidth;
        return SizedBox(
          height: 60,
          child: Stack(
            children: [
              // Track background
              Positioned.fill(
                top: 26,
                child: Container(
                  height: 6,
                  decoration: BoxDecoration(
                    color: AppColors.border,
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
              ),
              // Track progress (gradient theo level)
              Positioned(
                left: 0,
                right: w - w * (level / maxLevel),
                top: 26,
                child: Container(
                  height: 6,
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(colors: _redGradient),
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
              ),
              // Dots
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: List.generate(maxLevel + 1, (i) {
                  final isActive = i <= level;
                  final isCurrent = i == level;
                  return GestureDetector(
                    onTap: () => onChanged(i),
                    child: Container(
                      width: isCurrent ? 56 : 44,
                      height: isCurrent ? 56 : 44,
                      decoration: BoxDecoration(
                        color: isActive
                            ? _colorFor(i)
                            : Colors.white,
                        shape: BoxShape.circle,
                        border: Border.all(
                          color: isActive
                              ? _colorFor(i)
                              : AppColors.border,
                          width: 2.5,
                        ),
                        boxShadow: isCurrent
                            ? [
                                BoxShadow(
                                  color: Colors.black.withValues(alpha: 0.18),
                                  blurRadius: 12,
                                  offset: const Offset(0, 4),
                                )
                              ]
                            : null,
                      ),
                      alignment: Alignment.center,
                      child: i == 0
                          ? Text(
                              '0',
                              style: TextStyle(
                                fontWeight: FontWeight.w800,
                                color: isActive
                                    ? Colors.white
                                    : AppColors.textMuted,
                              ),
                            )
                          : Text(
                              '$i',
                              style: TextStyle(
                                fontSize: 16,
                                color: isActive
                                    ? Colors.white
                                    : AppColors.textMuted,
                              ),
                            ),
                    ),
                  );
                }),
              ),
            ],
          ),
        );
      },
    );
  }

  Color _colorFor(int i) {
    if (i == 0) return AppColors.textMuted;
    if (i <= 1) return _redGradient[0];
    if (i <= 3) return _redGradient[1];
    if (i <= 5) return _redGradient[2];
    return _redGradient[3];
  }
}

class _LegendChip extends StatelessWidget {
  const _LegendChip({
    required this.group,
    required this.range,
    required this.active,
  });
  final _SpicyGroup group;
  final String range;
  final bool active;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(
          color: active ? group.color : AppColors.border,
          width: active ? 1.8 : 1.2,
        ),
        boxShadow: active
            ? [
                BoxShadow(
                  color: group.color.withValues(alpha: 0.12),
                  blurRadius: 12,
                  offset: const Offset(0, 4),
                ),
              ]
            : null,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 12,
            height: 12,
            decoration: BoxDecoration(
              color: group.color,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                group.name,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                ),
              ),
              Text(
                range,
                style: const TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textMuted,
                  letterSpacing: 0.6,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
