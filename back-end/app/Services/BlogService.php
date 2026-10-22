<?php

namespace App\Services;

use App\Models\Blog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * BlogService — xử lý upload ảnh, sanitize HTML, tính reading_time, related posts.
 *
 * Phase 6.2 cải tiến Blog: ảnh inline + rich text + trang detail sinh động.
 */
class BlogService
{
    /** Tốc độ đọc trung bình (từ/phút) cho tiếng Việt. */
    private const WORDS_PER_MINUTE = 220;

    /**
     * Lưu ảnh upload lên disk 'public' và trả về URL truy cập.
     * Trả về ['url' => 'http...', 'path' => 'blogs/2026/10/uuid.jpg'].
     *
     * @return array{url: string, path: string}
     */
    public function storeImage(UploadedFile $file): array
    {
        // 2MB hard limit (user đã chọn option 2MB).
        $maxKb = 2 * 1024;
        if ($file->getSize() > $maxKb * 1024) {
            throw new \InvalidArgumentException("Ảnh vượt quá dung lượng cho phép ({$maxKb}KB).");
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        // Chỉ chấp nhận ảnh.
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
        if (!in_array($ext, $allowed, true)) {
            throw new \InvalidArgumentException('Chỉ hỗ trợ ảnh: jpg, png, webp, gif, avif.');
        }

        $filename = Str::uuid()->toString().'.'.$ext;
        $relativeDir = 'blogs/'.date('Y/m');
        $relativePath = $relativeDir.'/'.$filename;

        Storage::disk('public')->put($relativePath, file_get_contents($file->getRealPath()));

        return [
            'url'  => Storage::disk('public')->url($relativePath),
            'path' => $relativePath,
        ];
    }

    /**
     * Sanitize HTML content (loại bỏ script/iframe nguy hiểm, giữ formatting an toàn).
     *
     * Dùng strip_tags với whitelist để tránh phụ thuộc thư viện ngoài.
     * Nếu sau này cần whitelist phức tạp hơn (figure, picture, ...) → cài mews/purifier.
     */
    public function sanitizeHtml(string $html): string
    {
        // Whitelist tags: format content + ảnh + link.
        $allowed = '<p><br><strong><b><em><i><u><h2><h3><h4><h5><h6><ul><ol><li><a><img><figure><figcaption><blockquote><pre><code><hr><span><div>';
        $cleaned = strip_tags($html, $allowed);

        // Loại bỏ inline event handlers (onclick, onerror, ...) phòng XSS.
        $cleaned = preg_replace('#\s*on[a-z]+\s*=\s*("([^"]*)"|\'([^\']*)\')#i', '', $cleaned) ?? $cleaned;
        // Loại bỏ javascript: scheme trong href/src.
        $cleaned = preg_replace('#(href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')#i', '$1="#"', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * Ước lượng thời gian đọc từ HTML content.
     * Đếm từ trong text thuần (sau khi strip tags) → chia WORDS_PER_MINUTE.
     */
    public function estimateReadingMinutes(string $htmlContent): int
    {
        $plain = trim(strip_tags($htmlContent));
        if ($plain === '') {
            return 1;
        }
        // Tiếng Việt: đếm theo khoảng trắng + dấu câu (mỗi cụm là 1 "từ").
        $tokens = preg_split('/[\s\p{Z}\.,;:!\?\-—()\[\]"\']+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($tokens) ? count($tokens) : 0;
        $minutes = (int) ceil($wordCount / self::WORDS_PER_MINUTE);
        return max(1, $minutes);
    }

    /**
     * Tìm các bài viết liên quan (cùng news_section, loại trừ bài hiện tại).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Blog>
     */
    public function relatedPosts(Blog $current, int $limit = 3)
    {
        return Blog::query()
            ->where('id', '!=', $current->id)
            ->where('news_section', $current->news_section)
            ->where('status', 'PUBLISHED')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }
}