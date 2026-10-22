<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\{MarketingAsset, MarketingAssetVersion};
use App\Services\Marketing\{MarketingAssetService, MarketingService};
use Illuminate\Http\{JsonResponse, Request};

class MarketingAssetController extends Controller
{
    public function __construct(
        private readonly MarketingAssetService $assets,
        private readonly MarketingService $marketing,
    ) {}

    /**
     * GET /api/manager/marketing/assets
     * Query: ?status=&channel=&q=&page=&include_archived=1
     *
     * include_archived=1: KHÔNG lọc status, bao gồm cả asset ARCHIVED (cho tab "Thùng rác").
     * Mặc định: ẩn ARCHIVED (chỉ list asset đang trong pipeline).
     */
    public function index(Request $request): JsonResponse
    {
        $query = MarketingAsset::query()
            ->with(['versions:id,asset_id,version_number,provider,headline,is_selected', 'campaign:id,name,slug'])
            ->withCount('versions')
            ->latest();

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper((string) $status));
        } elseif (!$request->boolean('include_archived')) {
            // Mặc định ẩn ARCHIVED — nếu caller muốn thấy archive (thùng rác), truyền include_archived=1.
            $query->where('status', '!=', 'ARCHIVED');
        }
        if ($channel = $request->query('channel')) {
            $query->where('channel', strtoupper((string) $channel));
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', "%{$q}%")
                  ->orWhere('tone', 'like', "%{$q}%");
            });
        }

        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $page    = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $page->items(),
            'meta'    => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /**
     * POST /api/manager/marketing/assets
     * Body: { channel, content_type, subject, tone, campaign_id?, menu_item_id?, promotion_id? }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel'       => 'required|string|in:FACEBOOK,INSTAGRAM,TIKTOK,ZALO,BLOG,EMAIL',
            'content_type'  => 'required|string|in:POST,STORY,REEL_SCRIPT,BLOG_ARTICLE,EMAIL_BLAST',
            'subject'       => 'nullable|string|max:200',
            'tone'          => 'nullable|string|in:FUN,WARM,PREMIUM,CASUAL,PUNCHY',
            'campaign_id'   => 'nullable|integer|exists:campaigns,id',
            'menu_item_id'  => 'nullable|integer|exists:menu_items,id',
            'promotion_id'  => 'nullable|integer|exists:promotions,id',
        ]);

        $context = $this->marketing->buildContextFromInput($data);
        // Đảm bảo có subject
        $data['subject'] = $context['subject'];
        $data['context_payload'] = $context;
        $data['created_by']      = $request->user()->id;
        $data['context']         = $context;

        $asset = $this->assets->createWithFirstVersion($data);

        return response()->json([
            'success' => true,
            'data'    => $asset->load('versions'),
        ], 201);
    }

    /**
     * GET /api/manager/marketing/assets/{id}
     */
    public function show(MarketingAsset $asset): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $asset->load(['versions', 'uploads', 'campaign']),
        ]);
    }

    /**
     * POST /api/manager/marketing/assets/{id}/generate
     * Sinh thêm 1 version mới (giữ lịch sử).
     */
    public function generate(MarketingAsset $asset): JsonResponse
    {
        // Tái dựng context từ payload đã lưu — đảm bảo version mới dùng cùng brand info.
        $context = is_array($asset->context_payload)
            ? $asset->context_payload
            : [];

        $version = $this->assets->appendVersion($asset, $context);

        return response()->json([
            'success' => true,
            'data'    => $version,
        ], 201);
    }

    /**
     * DELETE /api/manager/marketing/assets/{id}
     * Soft delete: archive (status=ARCHIVED). Dùng để bỏ khỏi pipeline.
     */
    public function destroy(MarketingAsset $asset): JsonResponse
    {
        $this->assets->archive($asset);
        return response()->json([
            'success' => true,
            'message' => 'Asset đã được archive.',
        ]);
    }

    /**
     * PATCH /api/manager/marketing/assets/{id}/restore
     * Khôi phục asset từ ARCHIVED → DRAFT.
     */
    public function restore(MarketingAsset $asset): JsonResponse
    {
        $restored = $this->assets->restore($asset);
        return response()->json([
            'success' => true,
            'data'    => $restored->load('versions'),
            'message' => 'Đã khôi phục asset về trạng thái DRAFT.',
        ]);
    }

    /**
     * DELETE /api/manager/marketing/assets/{id}/force
     * Hard delete: xoá cứng khỏi DB (cascade versions, uploads, publishes).
     */
    public function forceDestroy(MarketingAsset $asset): JsonResponse
    {
        $this->assets->forceDelete($asset);
        return response()->json([
            'success' => true,
            'message' => 'Đã xoá vĩnh viễn asset.',
        ]);
    }

    /**
     * POST /api/manager/marketing/assets/{id}/publish-to-blog
     * Đẩy 1 version của asset lên bảng blogs (DRAFT) — manager có thể chỉnh sửa + publish.
     *
     * Body: { version_id: number, category?: string, news_section?: string }
     */
    public function publishToBlog(Request $request, MarketingAsset $asset): JsonResponse
    {
        $data = $request->validate([
            'version_id'    => 'required|integer|exists:marketing_asset_versions,id',
            'category'      => 'nullable|string|in:NEWS,PROMOTION,NEW_DISH,EVENT,CULINARY',
            'news_section'  => 'nullable|string|in:NEWS,OPENING,PROMOTION',
            'title'         => 'nullable|string|max:250',
        ]);

        $version = $asset->versions()->where('id', $data['version_id'])->first();
        if (!$version) {
            return response()->json([
                'success' => false,
                'message' => 'Version không thuộc asset này.',
            ], 404);
        }

        $blog = $this->assets->pushToBlog(
            $asset,
            $version,
            [
                'category'     => $data['category'] ?? null,
                'news_section' => $data['news_section'] ?? null,
                'title'        => $data['title'] ?? null,
            ],
            (int) $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã tạo bản nháp bài viết trên Blog.',
            'data'    => $blog,
        ], 201);
    }
}