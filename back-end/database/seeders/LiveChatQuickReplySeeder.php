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
                'title' => 'Chào khách',
                'content' => 'Chào bạn, mình là nhân viên tư vấn của Mì Cay Hàn Quốc K-Spicy. Mình có thể giúp gì cho bạn hôm nay?',
                'category' => 'general',
                'sort_order' => 1,
            ],
            [
                'title' => 'Tra cứu giá',
                'content' => 'Dạ mình kiểm tra giá món đó cho bạn nhé, bạn chờ một chút.',
                'category' => 'menu',
                'sort_order' => 2,
            ],
            [
                'title' => 'Gợi ý món',
                'content' => 'Quán có mì cay bò, mì cay tôm và tokbokki phô mai đều được khách gọi nhiều. Bạn thích vị cay hay vị ngọt?',
                'category' => 'menu',
                'sort_order' => 3,
            ],
            [
                'title' => 'Tra cứu đơn hàng',
                'content' => 'Dạ mình kiểm tra trạng thái đơn hàng của bạn ngay nhé.',
                'category' => 'order',
                'sort_order' => 4,
            ],
            [
                'title' => 'Đặt bàn',
                'content' => 'Bạn muốn đặt bàn cho mấy người và vào khoảng thời gian nào? Mình sẽ kiểm tra bàn trống cho bạn.',
                'category' => 'reservation',
                'sort_order' => 5,
            ],
            [
                'title' => 'Giờ mở cửa',
                'content' => 'Quán mở cửa từ 10:00 đến 22:00 hằng ngày. Bạn đến vào buổi nào để mình sắp xếp bàn cho phù hợp?',
                'category' => 'general',
                'sort_order' => 6,
            ],
        ];

        foreach ($replies as $reply) {
            LiveChatQuickReply::updateOrCreate(
                ['title' => $reply['title']],
                $reply,
            );
        }
    }
}
