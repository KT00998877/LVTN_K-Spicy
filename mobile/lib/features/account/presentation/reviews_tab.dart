import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/theme.dart';
import '../../../core/utils/backend_image_url.dart';
import '../../../shared/widgets/common_widgets.dart';
import '../domain/review_models.dart';
import 'account_controller.dart';

/// ============================================================
/// ⭐ TAB: ĐÁNH GIÁ CỦA TÔI
/// ============================================================
///
/// Danh sách review khách đã viết, kèm món ăn liên quan.
/// Dùng `ScrollController` + `loadMore()` giống `OrdersListScreen` để
/// không phân trang bằng nút (mobile dễ vuốt hơn bấm nút).
///
class ReviewsTab extends ConsumerStatefulWidget {
  const ReviewsTab({super.key});

  @override
  ConsumerState<ReviewsTab> createState() => _ReviewsTabState();
}

class _ReviewsTabState extends ConsumerState<ReviewsTab> {
  final _scrollCtrl = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollCtrl.addListener(_onScroll);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(reviewsControllerProvider.notifier).load();
    });
  }

  @override
  void dispose() {
    _scrollCtrl.removeListener(_onScroll);
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scrollCtrl.hasClients) return;
    if (_scrollCtrl.position.pixels >= _scrollCtrl.position.maxScrollExtent - 240) {
      ref.read(reviewsControllerProvider.notifier).loadMore();
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(reviewsControllerProvider);

    if (state.isLoading && !state.hasLoadedOnce) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.items.isEmpty) {
      return RefreshIndicator(
        color: AppColors.spicyRed,
        onRefresh: () => ref.read(reviewsControllerProvider.notifier).refresh(),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            SizedBox(
              height: MediaQuery.of(context).size.height * 0.6,
              child: state.error != null
                  ? EmptyState(
                      icon: '😢',
                      title: 'Không tải được đánh giá',
                      subtitle: state.error,
                      actionLabel: 'Thử lại',
                      onAction: () =>
                          ref.read(reviewsControllerProvider.notifier).refresh(),
                    )
                  : const EmptyState(
                      icon: '⭐',
                      title: 'Chưa có đánh giá nào',
                      subtitle:
                          'Sau khi hoàn thành đơn hàng, bạn có thể đánh giá món ăn.',
                    ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      color: AppColors.spicyRed,
      onRefresh: () => ref.read(reviewsControllerProvider.notifier).refresh(),
      child: ListView.separated(
        controller: _scrollCtrl,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(AppSpacing.md),
        itemCount: state.items.length + (state.hasMore ? 1 : 0),
        separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
        itemBuilder: (context, i) {
          if (i >= state.items.length) {
            return const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Center(
                child: SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(strokeWidth: 2.5),
                ),
              ),
            );
          }
          return _ReviewCard(review: state.items[i]);
        },
      ),
    );
  }
}

class _ReviewCard extends StatelessWidget {
  const _ReviewCard({required this.review});

  final MyReview review;

  @override
  Widget build(BuildContext context) {
    final item = review.menuItem;
    final imageUrl = backendImageUrl(item?.imageUrl);

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Header: món ăn + số sao ───────────────────
          Padding(
            padding: const EdgeInsets.all(AppSpacing.sm),
            child: Row(
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  child: SizedBox(
                    width: 52,
                    height: 52,
                    child: imageUrl.isEmpty
                        ? Container(
                            color: AppColors.creamSoft,
                            alignment: Alignment.center,
                            child: const Icon(
                              Icons.ramen_dining_rounded,
                              color: AppColors.textMuted,
                            ),
                          )
                        : CachedNetworkImage(
                            imageUrl: imageUrl,
                            fit: BoxFit.cover,
                            errorWidget: (_, __, ___) => Container(
                              color: AppColors.creamSoft,
                              alignment: Alignment.center,
                              child: const Icon(
                                Icons.ramen_dining_rounded,
                                color: AppColors.textMuted,
                              ),
                            ),
                          ),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item?.name ?? 'Món ăn',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Row(
                        children: [
                          for (var i = 1; i <= 5; i++)
                            Icon(
                              i <= review.rating
                                  ? Icons.star_rounded
                                  : Icons.star_outline_rounded,
                              size: 16,
                              color: AppColors.goldenYellow,
                            ),
                          const SizedBox(width: AppSpacing.xs),
                          if (review.createdAt != null)
                            Text(
                              DateFormat('dd/MM/yyyy')
                                  .format(review.createdAt!),
                              style: const TextStyle(
                                fontSize: 11,
                                color: AppColors.textMuted,
                              ),
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
                if (item?.slug != null)
                  IconButton(
                    icon: const Icon(Icons.chevron_right_rounded),
                    color: AppColors.textMuted,
                    tooltip: 'Xem món',
                    onPressed: () => context.push('/menu/${item!.slug}'),
                  ),
              ],
            ),
          ),

          if (review.isHidden)
            Container(
              width: double.infinity,
              color: AppColors.creamSoft,
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.md,
                vertical: AppSpacing.sm,
              ),
              child: const Row(
                children: [
                  Icon(Icons.visibility_off_rounded,
                      size: 15, color: AppColors.textMuted),
                  SizedBox(width: AppSpacing.xs),
                  Expanded(
                    child: Text(
                      'Đánh giá này đang bị ẩn do vi phạm quy định.',
                      style: TextStyle(fontSize: 12, color: AppColors.textMuted),
                    ),
                  ),
                ],
              ),
            )
          else if (review.comment != null && review.comment!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.md,
                0,
                AppSpacing.md,
                AppSpacing.sm,
              ),
              child: Text(
                review.comment!,
                style: const TextStyle(
                  fontSize: 14,
                  height: 1.55,
                  color: AppColors.textSecondary,
                ),
              ),
            ),

          if (review.photos.isNotEmpty && !review.isHidden)
            SizedBox(
              height: 76,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.md,
                  0,
                  AppSpacing.md,
                  AppSpacing.md,
                ),
                itemCount: review.photos.length,
                separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.xs),
                itemBuilder: (_, i) => ClipRRect(
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                  child: CachedNetworkImage(
                    imageUrl: backendImageUrl(review.photos[i].url),
                    width: 76,
                    height: 76,
                    fit: BoxFit.cover,
                    errorWidget: (_, __, ___) => Container(
                      width: 76,
                      height: 76,
                      color: AppColors.creamSoft,
                      child: const Icon(Icons.broken_image_rounded,
                          color: AppColors.textMuted),
                    ),
                  ),
                ),
              ),
            )
          else
            const SizedBox(height: AppSpacing.xs),
        ],
      ),
    );
  }
}