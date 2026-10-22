<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BlogService;
use Illuminate\Http\{JsonResponse, Request};

/**
 * ManagerBlogUploadController — Upload ảnh cho blog (cover + inline).
 *
 * Endpoint:
 *  - POST /api/staff/manager/blogs/upload-image  (multipart, field 'file')
 *
 * Trả về: { url: 'http://localhost:8000/storage/blogs/2026/10/uuid.jpg', path: '...' }
 *
 * Dùng cho:
 *  - BlogFormModal: chọn cover image
 *  - TinyMCE: chèn ảnh inline vào content
 *
 * Quyền: chỉ MANAGER (route đã gắn middleware staff:MANAGER).
 */
class ManagerBlogUploadController extends Controller
{
    public function __construct(private readonly BlogService $blogs) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:2048'], // 2MB
        ]);

        try {
            $result = $this->blogs->storeImage($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $result,
        ], 201);
    }
}