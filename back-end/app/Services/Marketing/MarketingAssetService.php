<?php

namespace App\Services\Marketing;

use App\Models\{Blog, MarketingAsset, MarketingAssetVersion};
use App\Services\AI\AiManager;
use App\Services\AI\Contracts\CopyGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MarketingAssetService — Điều phối vòng đời MarketingAsset.
 *
 * Trách nhiệm:
 *  - Tạo asset + tự sinh version đầu tiên.
 *  - Sinh thêm version mới (giữ lịch sử, không ghi đè).
 *  - Duyệt version (tick is_selected) → chuyển asset sang APPROVED.
 *  - Từ chối / archive asset.
 *
 * Phase 6.2+ sẽ thêm publish-to-blog ở đây.
 */
class MarketingAssetService
{
    public function __construct(
        private readonly AiManager $ai,
    ) {}

    /**
     * Tạo asset mới + sinh version 1 ngay lập tức.
     *
     * @param  array<string,mixed> $input  Có: created_by, channel, content_type, subject, tone,
     *                                     optional: campaign_id, menu_item_id, promotion_id
     * @return MarketingAsset
     */
    public function createWithFirstVersion(array $input): MarketingAsset
    {
        $this->validateInput($input, requireSubject: true);

        return DB::transaction(function () use ($input) {
            $asset = MarketingAsset::create([
                'created_by'      => $input['created_by'],
                'campaign_id'     => $input['campaign_id'] ?? null,
                'channel'         => $input['channel'],
                'content_type'    => $input['content_type'],
                'subject'         => $input['subject'],
                'tone'            => strtoupper((string) ($input['tone'] ?? 'FUN')),
                'context_payload' => $input['context_payload'] ?? null,
                'status'          => 'DRAFT',
            ]);

            $this->appendVersion($asset, $input['context'] ?? []);

            return $asset->fresh(['versions']);
        });
    }

    /**
     * Sinh thêm 1 version mới (version_number tự tăng).
     *
     * @param  array<string,mixed> $context Context đầy đủ cho AI.
     * @return MarketingAssetVersion
     */
    public function appendVersion(MarketingAsset $asset, array $context): MarketingAssetVersion
    {
        $next = ((int) $asset->versions()->max('version_number')) + 1;

        $copy = $this->ai->copy()->generate($context);

        return MarketingAssetVersion::create([
            'asset_id'       => $asset->id,
            'version_number' => $next,
            'provider'       => strtoupper($this->ai->providerName()),
            'provider_model' => $copy['meta']['provider_raw']['model']
                                ?? config("ai.providers.{$this->ai->providerName()}.model"),
            'headline'       => $copy['headline'],
            'caption'        => $copy['caption'],
            'body'           => $copy['body'],
            'hashtags'       => $copy['hashtags'],
            'meta'           => $copy['meta'],
            'prompt_used'    => $copy['prompt_used'],
            'is_selected'    => false,
        ]);
    }

    /**
     * Tick một version là selected → set các version khác cùng asset về false → đánh dấu asset APPROVED.
     */
    public function selectVersion(MarketingAsset $asset, int $versionId, int $approvedBy): MarketingAsset
    {
        if ($asset->status === 'ARCHIVED') {
            throw ValidationException::withMessages([
                'asset' => 'Asset đã archive, không thể duyệt.',
            ]);
        }

        DB::transaction(function () use ($asset, $versionId, $approvedBy) {
            $version = $asset->versions()->where('id', $versionId)->first();
            if (!$version) {
                throw ValidationException::withMessages([
                    'version' => 'Version không thuộc asset này.',
                ]);
            }

            // Bỏ chọn version cũ
            $asset->versions()->where('id', '!=', $versionId)->update(['is_selected' => false]);
            $version->update(['is_selected' => true]);

            $asset->update([
                'status'      => 'APPROVED',
                'approved_at' => now(),
                'approved_by' => $approvedBy,
            ]);
        });

        return $asset->fresh(['versions']);
    }

    public function archive(MarketingAsset $asset): MarketingAsset
    {
        $asset->update(['status' => 'ARCHIVED']);
        return $asset;
    }

    /**
     * Manager sửa trực tiếp nội dung 1 version. Đánh dấu provider=HUMAN để audit.
     * Nếu asset đang APPROVED → đẩy về IN_REVIEW (sửa sau lưng = cần duyệt lại).
     */
    public function updateVersion(
        MarketingAsset $asset,
        MarketingAssetVersion $version,
        array $data,
        int $editorId,
    ): MarketingAsset {
        if ($asset->status === 'ARCHIVED') {
            throw ValidationException::withMessages([
                'asset' => 'Asset đã archive, không thể sửa.',
            ]);
        }

        DB::transaction(function () use ($asset, $version, $data) {
            $patch = array_filter($data, fn ($v) => $v !== null);
            // Đánh dấu version này là do người sửa (audit trail).
            if (array_key_exists('headline', $patch) || array_key_exists('caption', $patch)
                || array_key_exists('body', $patch) || array_key_exists('hashtags', $patch)) {
                $patch['provider'] = 'HUMAN';
            }
            $version->fill($patch)->save();

            // Nếu asset đã được duyệt trước đó → sửa version = cần duyệt lại.
            if ($asset->status === 'APPROVED' || $asset->status === 'SCHEDULED' || $asset->status === 'PUBLISHED') {
                $asset->update([
                    'status'      => 'IN_REVIEW',
                    'approved_at' => null,
                    'approved_by' => null,
                ]);
            }
        });

        return $asset->fresh(['versions']);
    }

    /**
     * Khôi phục asset đã archive về DRAFT (đưa vào pipeline lại).
     */
    public function restore(MarketingAsset $asset): MarketingAsset
    {
        if ($asset->status !== 'ARCHIVED') {
            throw ValidationException::withMessages([
                'asset' => 'Chỉ asset đang ở trạng thái lưu trữ mới khôi phục được.',
            ]);
        }

        $asset->update([
            'status'      => 'DRAFT',
            'approved_at' => null,
            'approved_by' => null,
        ]);

        // Bỏ chọn tất cả version cũ (nếu còn vương tích selected) để manager duyệt lại.
        $asset->versions()->update(['is_selected' => false]);

        return $asset;
    }

    /**
     * Xoá vĩnh viễn asset + versions (cascade). Chỉ cho phép khi đã ARCHIVED.
     */
    public function forceDelete(MarketingAsset $asset): void
    {
        if ($asset->status !== 'ARCHIVED') {
            throw ValidationException::withMessages([
                'asset' => 'Cần lưu trữ asset trước khi xoá vĩnh viễn.',
            ]);
        }

        $asset->delete(); // cascade xoá versions + uploads + publishes theo migration.
    }

    /**
     * Đẩy 1 version của asset lên bảng blogs (DRAFT) — manager có thể chỉnh sửa + publish sau.
     *
     * Logic:
     *  - title: lấy từ asset.subject (hoặc param override).
     *  - excerpt: lấy từ caption (cắt 240 ký tự).
     *  - content: body (nếu có), fallback caption.
     *  - category: param → context_payload.promotion_id (PROMOTION) → NEWS.
     *  - news_section: param → OPENING nếu category OPENING → PROMOTION nếu category=PROMOTION → NEWS.
     *  - status: DRAFT (manager phải publish thủ công).
     */
    public function pushToBlog(
        MarketingAsset $asset,
        MarketingAssetVersion $version,
        array $options,
        int $authorId,
    ): Blog {
        $title = (string) ($options['title'] ?? $asset->subject);
        $title = $title !== '' ? $title : 'Bài viết từ AI Marketing Studio';

        $excerpt = $version->caption ? mb_substr((string) $version->caption, 0, 240) : null;
        $content = $version->body ?: ($version->caption ?: '');

        // Map category từ context nếu không truyền override.
        $category = $options['category'] ?? null;
        if (!$category) {
            $ctx = (array) $asset->context_payload;
            $category = !empty($ctx['promotion_id']) ? 'PROMOTION' : 'NEWS';
        }
        $category = strtoupper((string) $category);

        $newsSection = $options['news_section'] ?? null;
        if (!$newsSection) {
            $newsSection = match ($category) {
                'PROMOTION' => 'PROMOTION',
                'EVENT'     => 'OPENING',
                default     => 'NEWS',
            };
        }
        $newsSection = strtoupper((string) $newsSection);

        return DB::transaction(function () use ($asset, $version, $title, $excerpt, $content, $category, $newsSection, $authorId) {
            $blog = Blog::create([
                'title'        => $title,
                'slug'         => $this->generateBlogSlug($title),
                'excerpt'      => $excerpt,
                'content'      => $content,
                'cover_image'  => null,
                'category'     => $category,
                'news_section' => $newsSection,
                'status'       => 'DRAFT',
                'author_id'    => $authorId,
                'published_at' => null,
            ]);

            // Log publish để truy vết.
            \App\Models\MarketingPublish::create([
                'asset_id'         => $asset->id,
                'version_id'       => $version->id,
                'channel'          => 'BLOG',
                'status'           => 'PENDING',
                'response_payload' => ['blog_id' => (int) $blog->id, 'mode' => 'push_to_blog_draft'],
                'published_at'     => null,
                'published_by'     => $authorId,
            ]);

            return $blog;
        });
    }

    private function generateBlogSlug(string $title): string
    {
        $base = \Illuminate\Support\Str::slug($title) ?: 'bai-viet';
        $slug = $base;
        $i = 1;
        while (Blog::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }
        return $slug;
    }

    /* ===== Validation ===== */

    private function validateInput(array $input, bool $requireSubject): void
    {
        $rules = [
            'channel'         => 'required|string|in:FACEBOOK,INSTAGRAM,TIKTOK,ZALO,BLOG,EMAIL',
            'content_type'    => 'required|string|in:POST,STORY,REEL_SCRIPT,BLOG_ARTICLE,EMAIL_BLAST',
            'tone'            => 'nullable|string|in:FUN,WARM,PREMIUM,CASUAL,PUNCHY',
            'created_by'      => 'required|integer|exists:users,id',
            'campaign_id'     => 'nullable|integer|exists:campaigns,id',
            'menu_item_id'    => 'nullable|integer|exists:menu_items,id',
            'promotion_id'    => 'nullable|integer|exists:promotions,id',
        ];
        if ($requireSubject) {
            $rules['subject'] = 'required|string|max:200';
        } else {
            $rules['subject'] = 'nullable|string|max:200';
        }

        validator($input, $rules)->validate();
    }
}