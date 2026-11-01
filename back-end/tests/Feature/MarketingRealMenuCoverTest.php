<?php

namespace Tests\Feature;

use App\Models\MarketingAsset;
use App\Models\MarketingAssetVersion;
use App\Services\Marketing\MarketingAssetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketingRealMenuCoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('marketing_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('subject');
            $table->json('context_payload')->nullable();
            $table->timestamps();
        });
        Schema::create('marketing_asset_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('headline')->nullable();
            $table->text('caption')->nullable();
            $table->longText('body')->nullable();
            $table->unsignedTinyInteger('version_number');
            $table->timestamps();
        });
        Schema::create('blogs', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->unsignedSmallInteger('reading_time')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('category');
            $table->string('news_section');
            $table->string('status');
            $table->unsignedBigInteger('author_id');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('marketing_publishes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('version_id');
            $table->string('channel');
            $table->string('status');
            $table->json('response_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by');
            $table->timestamps();
        });
    }

    public function test_blog_draft_uses_the_selected_real_menu_image(): void
    {
        $menuId = DB::table('menu_items')->insertGetId([
            'image_url' => '/storage/menu/ga-ran-han-quoc.png',
            'is_active' => true,
        ]);
        $asset = MarketingAsset::create(['subject' => 'Gà rán Hàn Quốc']);
        $version = MarketingAssetVersion::create([
            'asset_id' => $asset->id,
            'version_number' => 1,
            'headline' => 'Khám phá gà rán Hàn Quốc',
            'caption' => 'Một món ngon để thử.',
            'body' => "## Hương vị\n\nGà rán giòn thơm.",
        ]);

        $blog = app(MarketingAssetService::class)->pushToBlog($asset, $version, ['menu_item_id' => $menuId], 1);

        $this->assertSame('/storage/menu/ga-ran-han-quoc.png', $blog->cover_image);
        $this->assertSame('Khám phá gà rán Hàn Quốc', $blog->title);
        $this->assertStringContainsString('<h2>Hương vị</h2>', $blog->content);
    }
}
