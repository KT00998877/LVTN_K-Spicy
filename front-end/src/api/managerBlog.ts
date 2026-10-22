/**
 * Manager Blog API client (Phase 6.2).
 *
 * CRUD bài viết "Tin vui" (Tin mới / Tin khai trương / Tin khuyến mãi) cho nhà hàng.
 *
 *  - GET    /api/staff/manager/blogs                  — list (filter/search/pagination)
 *  - POST   /api/staff/manager/blogs                  — tạo
 *  - GET    /api/staff/manager/blogs/{id}             — chi tiết
 *  - PUT    /api/staff/manager/blogs/{id}             — sửa
 *  - DELETE /api/staff/manager/blogs/{id}             — xoá
 *  - POST   /api/staff/manager/blogs/{id}/publish     — chuyển sang PUBLISHED (set published_at = now)
 *  - POST   /api/staff/manager/blogs/{id}/archive     — chuyển sang ARCHIVED
 */
import apiClient from './client'
import type { Paginated } from '@/types/voucher'

export type BlogCategory = 'NEWS' | 'PROMOTION' | 'NEW_DISH' | 'EVENT' | 'CULINARY'
export type BlogSection = 'NEWS' | 'OPENING' | 'PROMOTION'
export type BlogStatus = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED'

export interface ManagerBlog {
    id: number
    title: string
    slug: string
    excerpt: string | null
    cover_image: string | null
    gallery: string[] | null
    content?: string
    category: BlogCategory
    news_section: BlogSection
    status: BlogStatus
    published_at: string | null
    reading_time: number
    created_at: string | null
    updated_at: string | null
    author: { id: number; name: string } | null
}

export interface BlogPayload {
    title: string
    slug?: string | null
    excerpt?: string | null
    content: string
    cover_image?: string | null
    category?: BlogCategory
    news_section?: BlogSection
    status?: BlogStatus
    published_at?: string | null
}

export interface ListBlogsParams {
    status?: BlogStatus
    category?: BlogCategory
    news_section?: BlogSection
    search?: string
    page?: number
    per_page?: number
}

const BASE = '/staff/manager/blogs'

export function listBlogs(params: ListBlogsParams = {}): Promise<Paginated<ManagerBlog>> {
    return apiClient
        .get<{ success: boolean; data: ManagerBlog[]; meta: Paginated<ManagerBlog>['meta'] }>(BASE, { params })
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

export function getBlog(id: number): Promise<ManagerBlog> {
    return apiClient
        .get<{ success: boolean; data: ManagerBlog }>(`${BASE}/${id}`)
        .then((r) => r.data.data)
}

export function createBlog(payload: BlogPayload): Promise<ManagerBlog> {
    return apiClient
        .post<{ success: boolean; data: ManagerBlog }>(BASE, payload)
        .then((r) => r.data.data)
}

export function updateBlog(id: number, payload: BlogPayload): Promise<ManagerBlog> {
    return apiClient
        .put<{ success: boolean; data: ManagerBlog }>(`${BASE}/${id}`, payload)
        .then((r) => r.data.data)
}

export function deleteBlog(id: number): Promise<{ message: string }> {
    return apiClient
        .delete<{ success: boolean; message: string }>(`${BASE}/${id}`)
        .then((r) => r.data)
}

export function publishBlog(id: number): Promise<ManagerBlog> {
    return apiClient
        .post<{ success: boolean; data: ManagerBlog }>(`${BASE}/${id}/publish`)
        .then((r) => r.data.data)
}

export function archiveBlog(id: number): Promise<ManagerBlog> {
    return apiClient
        .post<{ success: boolean; data: ManagerBlog }>(`${BASE}/${id}/archive`)
        .then((r) => r.data.data)
}

export interface BlogImageUploadResult {
    url: string
    path: string
}

/** Upload ảnh lên server (cover + inline). Tối đa 2MB. */
export function uploadBlogImage(file: File): Promise<BlogImageUploadResult> {
    const fd = new FormData()
    fd.append('file', file)
    return apiClient
        .post<{ success: boolean; data: BlogImageUploadResult }>(`${BASE}/upload-image`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        .then((r) => r.data.data)
}