<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlogFormRequest;
use App\Models\{AuditLog, Blog};
use App\Services\{AuditLogService, BlogService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ManagerBlogController — CRUD blog cho MANAGER (Phase 6.2).
 *
 * Endpoint (đã mount ở /api/staff/manager/blogs):
 *  - GET    /api/staff/manager/blogs            — list (filter/search/pagination)
 *  - POST   /api/staff/manager/blogs            — tạo
 *  - GET    /api/staff/manager/blogs/{id}       — chi tiết
 *  - PUT    /api/staff/manager/blogs/{id}       — sửa
 *  - DELETE /api/staff/manager/blogs/{id}       — xoá
 *  - POST   /api/staff/manager/blogs/{id}/publish — chuyển sang PUBLISHED (set published_at = now)
 *  - POST   /api/staff/manager/blogs/{id}/archive — chuyển sang ARCHIVED
 *
 * Quyền: chỉ MANAGER (route đã gắn middleware staff:MANAGER).
 *
 * Khác với CustomerPagesController (chỉ read public), controller này cho manager CRUD đầy đủ.
 */
class ManagerBlogController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly BlogService $blogs,
    ) {}

    /* ============================================================ */
    /* LIST                                                         */
    /* ============================================================ */

    public function index(Request $request): JsonResponse
    {
        $query = Blog::query()
            ->with('author:id,name')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper((string) $status));
        }
        if ($category = $request->query('category')) {
            $query->where('category', strtoupper((string) $category));
        }
        if ($section = $request->query('news_section')) {
            $query->where('news_section', strtoupper((string) $section));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (Blog $b) => $this->format($b))->values(),
            'meta'    => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /* ============================================================ */
    /* SHOW                                                         */
    /* ============================================================ */

    public function show(int $id): JsonResponse
    {
        $blog = Blog::with('author:id,name')->findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $this->format($blog, includeContent: true),
        ]);
    }

    /* ============================================================ */
    /* STORE                                                        */
    /* ============================================================ */

    public function store(BlogFormRequest $request): JsonResponse
    {
        $payload = $this->payloadFromRequest($request);
        $payload['slug'] = $payload['slug'] ?? $this->uniqueSlug($payload['title']);
        $payload['author_id'] = $request->user()->id;

        // Sanitize HTML content + tính reading_time.
        $payload['content'] = $this->blogs->sanitizeHtml((string) $payload['content']);
        $payload['reading_time'] = $this->blogs->estimateReadingMinutes($payload['content']);

        $blog = Blog::create($payload);

        $this->audit->record(
            AuditLog::ENTITY_BLOG,
            (int) $blog->id,
            AuditLog::ACTION_CREATE,
            (int) $request->user()->id,
            [['field' => 'all', 'old' => null, 'new' => $blog->title]],
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã tạo bài viết.',
            'data'    => $this->format($blog->fresh(['author']), includeContent: true),
        ], 201);
    }

    /* ============================================================ */
    /* UPDATE                                                       */
    /* ============================================================ */

    public function update(BlogFormRequest $request, int $id): JsonResponse
    {
        $blog = Blog::findOrFail($id);
        $before = [
            'title'        => $blog->title,
            'slug'         => $blog->slug,
            'status'       => $blog->status,
            'category'     => $blog->category,
            'news_section' => $blog->news_section,
            'published_at' => $blog->published_at?->toIso8601String(),
        ];

        $payload = $this->payloadFromRequest($request);
        // Giữ nguyên slug cũ nếu request không truyền.
        if (empty($payload['slug'])) {
            $payload['slug'] = $blog->slug;
        }

        // Sanitize HTML content + tính reading_time lại nếu content đổi.
        $payload['content'] = $this->blogs->sanitizeHtml((string) $payload['content']);
        $payload['reading_time'] = $this->blogs->estimateReadingMinutes($payload['content']);

        // Nếu chuyển sang PUBLISHED mà chưa có published_at → tự set now.
        if (($payload['status'] ?? null) === 'PUBLISHED' && empty($payload['published_at']) && $blog->status !== 'PUBLISHED') {
            $payload['published_at'] = now();
        }

        $blog->fill($payload)->save();
        $after = [
            'title'        => $blog->title,
            'slug'         => $blog->slug,
            'status'       => $blog->status,
            'category'     => $blog->category,
            'news_section' => $blog->news_section,
            'published_at' => $blog->published_at?->toIso8601String(),
        ];

        $this->audit->record(
            AuditLog::ENTITY_BLOG,
            (int) $blog->id,
            AuditLog::ACTION_UPDATE,
            (int) $request->user()->id,
            $this->audit->diff($before, $after),
        );

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật bài viết.',
            'data'    => $this->format($blog->fresh(['author']), includeContent: true),
        ]);
    }

    /* ============================================================ */
    /* DESTROY                                                      */
    /* ============================================================ */

    public function destroy(Request $request, int $id): JsonResponse
    {
        $blog = Blog::findOrFail($id);
        $this->audit->record(
            AuditLog::ENTITY_BLOG,
            (int) $blog->id,
            AuditLog::ACTION_DELETE,
            (int) $request->user()->id,
            [['field' => 'title', 'old' => $blog->title, 'new' => null]],
        );
        $blog->delete();
        return response()->json([
            'success' => true,
            'message' => 'Đã xoá bài viết.',
        ]);
    }

    /* ============================================================ */
    /* PUBLISH / ARCHIVE (quick action)                              */
    /* ============================================================ */

    public function publish(Request $request, int $id): JsonResponse
    {
        $blog = Blog::findOrFail($id);
        $wasPublished = $blog->status === 'PUBLISHED';
        $blog->update([
            'status'       => 'PUBLISHED',
            'published_at' => $wasPublished ? $blog->published_at : now(),
        ]);
        $this->audit->record(
            AuditLog::ENTITY_BLOG,
            (int) $blog->id,
            AuditLog::ACTION_TOGGLE,
            (int) $request->user()->id,
            [['field' => 'status', 'old' => $wasPublished ? 'PUBLISHED' : 'OTHER', 'new' => 'PUBLISHED']],
        );
        return response()->json([
            'success' => true,
            'message' => 'Đã xuất bản bài viết.',
            'data'    => $this->format($blog->fresh(['author']), includeContent: true),
        ]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $blog = Blog::findOrFail($id);
        $oldStatus = $blog->status;
        $blog->update(['status' => 'ARCHIVED']);
        $this->audit->record(
            AuditLog::ENTITY_BLOG,
            (int) $blog->id,
            AuditLog::ACTION_TOGGLE,
            (int) $request->user()->id,
            [['field' => 'status', 'old' => $oldStatus, 'new' => 'ARCHIVED']],
        );
        return response()->json([
            'success' => true,
            'message' => 'Đã lưu trữ bài viết.',
            'data'    => $this->format($blog->fresh(['author']), includeContent: true),
        ]);
    }

    /* ============================================================ */
    /* PRIVATE                                                       */
    /* ============================================================ */

    /**
     * @return array<string, mixed>
     */
    private function payloadFromRequest(BlogFormRequest $request): array
    {
        $data = $request->validated();
        return [
            'title'        => $data['title'],
            'slug'         => $data['slug'] ?? null,
            'excerpt'      => $data['excerpt'] ?? null,
            'content'      => $data['content'],
            'cover_image'  => $data['cover_image'] ?? null,
            'category'     => strtoupper((string) ($data['category'] ?? 'NEWS')),
            'news_section' => strtoupper((string) ($data['news_section'] ?? 'NEWS')),
            'status'       => strtoupper((string) ($data['status'] ?? 'DRAFT')),
            'published_at' => $data['published_at'] ?? null,
        ];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'bai-viet';
        $slug = $base;
        $i = 1;
        while (Blog::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }
        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Blog $b, bool $includeContent = false): array
    {
        $out = [
            'id'           => (int) $b->id,
            'title'        => $b->title,
            'slug'         => $b->slug,
            'excerpt'      => $b->excerpt,
            'cover_image'  => $b->cover_image,
            'gallery'      => $b->gallery,
            'category'     => $b->category,
            'news_section' => $b->news_section,
            'status'       => $b->status,
            'reading_time' => (int) ($b->reading_time ?? 0),
            'published_at' => $b->published_at?->toIso8601String(),
            'created_at'   => $b->created_at?->toIso8601String(),
            'updated_at'   => $b->updated_at?->toIso8601String(),
            'author'       => $b->author ? [
                'id'   => (int) $b->author->id,
                'name' => $b->author->name,
            ] : null,
        ];
        if ($includeContent) {
            $out['content'] = $b->content;
        }
        return $out;
    }
}