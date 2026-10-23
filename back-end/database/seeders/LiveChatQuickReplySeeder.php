<?php

namespace Database\Seeders;

use App\Models\LiveChatQuickReply;
use Illuminate\Database\Seeder;

/**
 * Mẫu câu trả lời nhanh cho nhân viên tư vấn trực tiếp.
 *
 * Đây là FALLBACK khi AI không gợi ý được, và cũng là câu trả lời mặc định
 * khi khách chưa nhắn gì. Nhân viên luôn có sẵn để bấm.
 */
class LiveChatQuickReplySeeder extends Seeder
{
    public function run(): void
    {
        $replies = [
            [
                'content' => 'Chào bạn! Mình là nhân viên tư vấn của Mì Cay Hàn Quốc K-Spicy. Mình có thể giúp gì cho bạn hôm nay?',
                'category' => 'chung',
                'sort_order' => 1,
            ],
            [
                'content' => 'Dạ mình kiểm tra giá món đó cho bạn nhé, bạn chờ một chút.',
                'category' => 'mon_an',
                'sort_order' => 2,
            ],
            [
                'content' => 'Quán có mì cay bò, mì cay tôm và tokbokki phô mai đều được khách gọi nhiều. Bạn thích vị cay hay vị ngọt?',
                'category' => 'mon_an',
                'sort_order' => 3,
            ],
            [
                'content' => 'Dạ mình kiểm tra trạng thái đơn hàng của bạn ngay nhé.',
                'category' => 'don_hang',
                'sort_order' => 4,
            ],
            [
                'content' => 'Bạn muốn đặt bàn cho mấy người và vào khoảng thời gian nào? Mình sẽ kiểm tra bàn trống cho bạn.',
                'category' => 'dat_ban',
                'sort_order' => 5,
            ],
            [
                'content' => 'Quán mở cửa từ 10:00 đến 22:00 hằng ngày. Bạn đến vào buổi nào để mình sắp xếp bàn cho phù hợp?',
                'category' => 'chung',
                'sort_order' => 6,
            ],
        ];

        foreach ($replies as $reply) {
            LiveChatQuickReply::updateOrCreate(
                ['content' => $reply['content']],
                $reply,
            );
        }
    }
}