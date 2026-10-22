/**
 * inventory.ts — API client cho Inventory snapshot & top consumed ingredients.
 *
 *  - inventoryAlerts: lấy low-stock / out-of-stock / near-expiry / expired
 *    cho dashboard. Tận dụng endpoint statistics/inventory (đã có ở Phase 6).
 *
 * Mỗi ingredient có thể có nhiều trạng thái cảnh báo:
 *  - LOW STOCK: quantity > 0 VÀ quantity <= min
 *  - OUT OF STOCK: quantity <= 0
 *  - NEAR EXPIRY: expiry trong khoảng [today+1, today+7]
 *  - EXPIRED: expiry < today
 *
 * Ở Phase 3 backend chưa trả về danh sách ingredient kèm current_quantity /
 * min_stock_level cho dashboard. Phase này sẽ mở rộng service trả về chi tiết.
 *
 *  Tạm thời: lấy danh sách ingredient từ ManagerIngredients (đã có ở Phase 2)
 *  rồi tính alert ở FE — đơn giản, không cần thêm endpoint mới.
 */
import apiClient from './client'

export type IngredientAlertSeverity = 'OUT' | 'LOW' | 'EXPIRED' | 'NEAR_EXPIRY' | 'OK'

export interface IngredientAlert {
    id: number
    code: string
    name: string
    unit: string
    quantity_in_stock: number
    min_stock_level: number
    import_price: number
    expiry_date: string | null
    supplier_id: number | null
    severity: IngredientAlertSeverity
    message: string
}

export interface IngredientListResponse {
    success: boolean
    data: Array<{
        id: number
        code: string
        name: string
        unit: string
        quantity_in_stock: number
        min_stock_level: number
        import_price: number
        import_date: string | null
        expiry_date: string | null
        supplier_id: number | null
        is_active: boolean
    }>
    meta?: {
        total: number
        per_page: number
        current_page: number
    }
}

/* ============================================================
 *  Helper: phân loại severity cho 1 ingredient.
 *  - Có thể chuyển sang BE nếu sau này muốn (Performance).
 * ========================================================== */
export function classifyIngredient(
    ing: {
        quantity_in_stock: number
        min_stock_level: number
        expiry_date: string | null
    },
    today = new Date(),
    nearExpiryDays = 7
): { severity: IngredientAlertSeverity; message: string } {
    const qty = Number(ing.quantity_in_stock) || 0
    const min = Number(ing.min_stock_level) || 0
    if (qty <= 0) return { severity: 'OUT', message: 'Đã hết hàng' }
    if (min > 0 && qty <= min) {
        return { severity: 'LOW', message: `Sắp hết — còn ${qty.toLocaleString('vi-VN')} / tối thiểu ${min.toLocaleString('vi-VN')}` }
    }
    if (ing.expiry_date) {
        const expiry = new Date(ing.expiry_date)
        const diffDays = Math.ceil((expiry.getTime() - today.getTime()) / (1000 * 60 * 60 * 24))
        if (diffDays < 0) return { severity: 'EXPIRED', message: `Đã hết hạn ${Math.abs(diffDays)} ngày` }
        if (diffDays <= nearExpiryDays) {
            return { severity: 'NEAR_EXPIRY', message: `Sắp hết hạn (${diffDays} ngày)` }
        }
    }
    return { severity: 'OK', message: 'Bình thường' }
}

/* ============================================================
 *  inventoryAlerts — danh sách ingredient có cảnh báo.
 *  Gọi ManagerIngredients (Phase 2) đã có sẵn.
 *  Sort: severity nguy hiểm nhất trước.
 * ========================================================== */

const severityRank: Record<IngredientAlertSeverity, number> = {
    EXPIRED: 0,
    OUT: 1,
    LOW: 2,
    NEAR_EXPIRY: 3,
    OK: 4,
}

export const inventoryAlerts = {
    /** GET /api/staff/manager/ingredients — lấy từ ManagerIngredients (Phase 2). */
    async list(): Promise<IngredientAlert[]> {
        const res = await apiClient.get<IngredientListResponse>('/staff/manager/ingredients', {
            params: { per_page: 100, is_active: true },
        })
        const list = (res.data.data || []).filter((i) => i.is_active)
        const today = new Date()
        return list
            .map<IngredientAlert>((ing) => {
                const cls = classifyIngredient(ing, today)
                return {
                    id: ing.id,
                    code: ing.code,
                    name: ing.name,
                    unit: ing.unit,
                    quantity_in_stock: ing.quantity_in_stock,
                    min_stock_level: ing.min_stock_level,
                    import_price: ing.import_price,
                    expiry_date: ing.expiry_date,
                    supplier_id: ing.supplier_id,
                    severity: cls.severity,
                    message: cls.message,
                }
            })
            .filter((a) => a.severity !== 'OK')
            .sort((a, b) => severityRank[a.severity] - severityRank[b.severity])
    },
}
