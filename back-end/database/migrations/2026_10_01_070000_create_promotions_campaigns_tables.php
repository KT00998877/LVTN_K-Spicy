<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Hệ thống Khuyến mãi & Voucher (Phase 1 — Foundation).
 *
 * Bảng mới:
 *  - campaigns        : nhóm quảng bá marketing (gồm nhiều voucher + promotion con).
 *  - promotions       : khuyến mãi tự động (PERCENT_OFF / FIXED_OFF / BXGY / COMBO).
 *                       Hệ thống tự áp khi giỏ đủ điều kiện — không cần khách nhập mã.
 *  - promotion_items  : quan hệ N-N giữa promotion với category hoặc menu_item (phạm vi áp dụng).
 *  - promotion_usages : log mỗi lần promotion được dùng cho 1 đơn.
 *  - audit_logs       : ghi mọi thao tác CRUD của Manager (ai, khi nào, đổi gì).
 *
 * Quy ước BR-PROMO-* (chi tiết trong PromotionService / Rule Engine ở Phase 2):
 *  - BR-PROMO-01: 1 đơn có thể áp NHIỀU promotion (stacking), nhưng tổng giảm phải theo
 *                 max_total_discount_pct (chốt ở Phase 2). Voucher thì 1 đơn chỉ 1.
 *  - BR-PROMO-02: priority cao áp trước; cùng priority thì promotion giảm nhiều hơn thắng.
 *  - BR-PROMO-03: promotion sau khi đã có usage thì KHÔNG xoá cứng — chỉ is_active=false
 *                 (Phase 4 controller sẽ enforce).
 *  - BR-PROMO-04: cấu hình theo `kind` lưu trong cột `config` (json), điều kiện theo
 *                 `conditions` (json) — JSON để dễ mở rộng mà không ALTER nhiều.
 *  - BR-PROMO-05: marketing_meta (json nullable) — dữ liệu cho AI Marketing Studio phase sau.
 *  - BR-PROMO-06: orders.discount_amount vẫn là tổng (sum voucher + promotions) — giữ schema cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* -------------------------------------------------------- */
        /* 1. campaigns — chiến dịch marketing                     */
        /* -------------------------------------------------------- */
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 120)->unique()->comment('URL thân thiện, vd: khai-truong-cn2');
            $table->string('name', 150)->comment('Tên chiến dịch (hiển thị)');
            $table->string('cover_image_url', 500)->nullable();
            $table->text('description')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true);

            // Dữ liệu cho AI Marketing Studio phase sau.
            // Schema gợi ý: { audience_tags: [], tone: "fun|warm|premium", ai_seed: "...", key_visual: "..." }
            $table->json('marketing_meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        /* -------------------------------------------------------- */
        /* 2. promotions — khuyến mãi tự động                      */
        /* -------------------------------------------------------- */
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();

            $table->string('slug', 120)->unique()->comment('Mã nội bộ, vd: mi-cay-giam-15-hom-nay');
            $table->string('name', 150)->comment('Tên hiển thị');
            $table->text('description')->nullable();

            // PERCENT_OFF | FIXED_OFF | BXGY | COMBO (xem App\Enums\PromotionKind).
            $table->string('kind', 20);

            // Cấu hình theo kind (json). Ví dụ:
            //   PERCENT_OFF: { value: 15, max_discount: 30000, applies_to: "ALL|CATEGORY|MENU_ITEM" }
            //   FIXED_OFF:   { value: 20000, applies_to: "..." }
            //   BXGY:        { buy_qty: 2, get_qty: 1, get_item_ids: [], discount_percent: 100 }
            //   COMBO:       { bundle_items: [{item_id, qty}], bundle_price: 99000 }
            $table->json('config');

            // Điều kiện kích hoạt (json). Ví dụ:
            //   { min_subtotal: 100000, days_of_week: [5,6,0], time_from: "18:00",
            //     time_to: "22:00", channels: ["ONLINE","DINE_IN"],
            //     customer_tags: [], first_order_only: false, loyalty_tier_ids: [] }
            $table->json('conditions')->nullable();

            // STACK | EXCLUSIVE | BEST_ONLY (xem App\Enums\StackingMode).
            $table->string('stacking_mode', 20)->default('STACK');
            $table->unsignedSmallInteger('priority')->default(0)->comment('Cao áp trước');

            $table->unsignedInteger('usage_limit')->nullable()->comment('Tổng lượt tối đa (NULL = không giới hạn)');
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('usage_limit_per_user')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->json('marketing_meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Filter chính: các promotion ĐANG chạy + sort theo priority khi áp.
            $table->index(['is_active', 'starts_at', 'ends_at', 'priority']);
            $table->index('campaign_id');
        });

        /* -------------------------------------------------------- */
        /* 3. promotion_items — phạm vi áp dụng (CATEGORY/MENU_ITEM) */
        /* -------------------------------------------------------- */
        Schema::create('promotion_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();

            // CATEGORY | MENU_ITEM (xem App\Enums\PromotionItemType).
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');

            // Cho phép override giá trị riêng theo item (vd: BXGY tặng đúng 1 món cụ thể).
            $table->json('override')->nullable();

            $table->timestamps();

            $table->index(['promotion_id', 'item_type', 'item_id']);
        });

        /* -------------------------------------------------------- */
        /* 4. promotion_usages — log mỗi lần áp dụng               */
        /* -------------------------------------------------------- */
        Schema::create('promotion_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Số tiền giảm thực tế (VND, major units — đồng bộ orders.discount_amount).
            $table->decimal('discount_amount', 14, 2);

            $table->timestamp('created_at')->useCurrent();

            // 1 promotion 1 dòng / đơn (cho phép nhiều promotion khác nhau trên cùng đơn).
            $table->unique(['order_id', 'promotion_id']);
            $table->index(['promotion_id', 'user_id']);
        });

        /* -------------------------------------------------------- */
        /* 5. audit_logs — lịch sử thao tác CRUD                   */
        /* -------------------------------------------------------- */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: cho phép system ghi log khi chưa có user (vd refund auto khi CANCELLED).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // VOUCHER | PROMOTION | CAMPAIGN — giữ string để dễ mở rộng (AuditEntity enum sau).
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');

            // CREATE | UPDATE | TOGGLE | DELETE — (AuditAction enum).
            $table->string('action', 20);

            // Snapshot diff (json) — Phase 3 controller sẽ build bằng `array_diff`.
            $table->json('diff')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('promotion_usages');
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('campaigns');
    }
};