<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\{MarketingAsset, MarketingAssetVersion};
use App\Services\Marketing\MarketingAssetService;
use Illuminate\Http\{JsonResponse, Request};

/**
 * PATCH /api/manager/marketing/assets/{asset}/versions/{version}/select
 * Manager tick chọn version nào để duyệt → asset chuyển APPROVED.
 *
 * PATCH /api/manager/marketing/assets/{asset}/versions/{version}
 * Manager sửa trực tiếp nội dung của 1 version (caption/body/headline/hashtags).
 * Nếu asset đang APPROVED, sửa version sẽ đẩy asset về IN_REVIEW.
 */
class MarketingVersionController extends Controller
{
    public function __construct(private readonly MarketingAssetService $assets) {}

    public function select(Request $request, MarketingAsset $asset, MarketingAssetVersion $version): JsonResponse
    {
        // Chặn version không thuộc asset
        abort_unless((int) $version->asset_id === (int) $asset->id, 404, 'Version không thuộc asset.');

        $updated = $this->assets->selectVersion($asset, $version->id, $request->user()->id);

        return response()->json([
            'success' => true,
            'data'    => $updated->load('versions'),
        ]);
    }

    /**
     * PATCH /api/manager/marketing/assets/{asset}/versions/{version}
     *
     * Manager tự sửa caption/body/headline/hashtags trên version.
     * Provider đánh dấu là HUMAN để phân biệt với version do AI sinh.
     */
    public function update(
        Request $request,
        MarketingAsset $asset,
        MarketingAssetVersion $version,
    ): JsonResponse {
        abort_unless((int) $version->asset_id === (int) $asset->id, 404, 'Version không thuộc asset.');

        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'caption'  => ['nullable', 'string', 'max:5000'],
            'body'     => ['nullable', 'string', 'max:50000'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:50'],
        ]);

        $updated = $this->assets->updateVersion($asset, $version, $data, $request->user()->id);

        return response()->json([
            'success' => true,
            'data'    => $updated->load('versions'),
        ]);
    }
}