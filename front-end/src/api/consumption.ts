import apiClient from './client'

/**
 * Manager Consumption API — Phase F.
 * Endpoint prefix: /api/staff/manager
 *
 * Báo cáo tiêu hao nguyên liệu: mỗi món đã bán trừ bao nhiêu, tồn còn lại,
 * nguyên liệu nào đang thiếu. Kèm danh sách yêu cầu nhập kho do bếp gửi.
 */

/** Một dòng nguyên liệu trong báo cáo tiêu hao theo món. */
export interface ConsumptionIngredientLine {
    ingredient_id: number
    ingredient_name: string
    unit: string
    /** Lượng theo công thức (lý thuyết). */
    quantity_required: number
    /** Lượng thực sự đã trừ kho. */
    quantity_deducted: number
    /** Phần thiếu = yêu cầu − đã trừ. */
    shortage_qty: number
}

/** Một món đã bán trong kỳ. */
export interface ConsumptionMenuItem {
    menu_item_id: number
    menu_item_name: string
    order_count: number
    total_quantity: number
    ingredients: ConsumptionIngredientLine[]
}

/** Một nguyên liệu trong báo cáo tiêu hao theo nguyên liệu. */
export interface ConsumptionIngredient {
    ingredient_id: number
    code: string
    name: string
    unit: string
    current_stock: number
    min_stock_level: number
    is_low: boolean
    consumed_qty: number
    required_qty: number
    shortage_qty: number
    estimated_days_left: number | null
}

export interface ConsumptionResponse {
    from: string
    to: string
    view: 'menu' | 'ingredient'
    menu_items: ConsumptionMenuItem[]
    ingredients: ConsumptionIngredient[]
}

/** Yêu cầu nhập kho do bếp gửi khi phát hiện thiếu nguyên liệu. */
export interface StockRequest {
    id: number
    code: string
    menu_item_id: number | null
    menu_item_name: string | null
    status: 'OPEN' | 'RESOLVED' | 'DISMISSED'
    requested_by: number | null
    requested_by_name: string | null
    resolved_by_name: string | null
    resolved_at: string | null
    resolution_note: string | null
    note: string | null
    created_at: string | null
    items: {
        ingredient_id: number
        ingredient_name: string | null
        unit: string
        required_qty: number
        available_qty: number
        shortage_qty: number
    }[]
}

export const consumptionApi = {
    /** GET /api/staff/manager/consumption */
    async report(params: {
        from?: string
        to?: string
        view?: 'menu' | 'ingredient'
    }): Promise<ConsumptionResponse> {
        const res = await apiClient.get<{ success: boolean; data: ConsumptionResponse }>(
            '/staff/manager/consumption',
            { params }
        )
        return res.data.data
    },

    /** GET /api/staff/manager/stock-requests */
    async stockRequests(status?: 'OPEN' | 'RESOLVED' | 'DISMISSED'): Promise<StockRequest[]> {
        const res = await apiClient.get<{ success: boolean; data: StockRequest[] }>(
            '/staff/manager/stock-requests',
            { params: status ? { status } : {} }
        )
        return res.data.data
    },

    /** PATCH /api/staff/manager/stock-requests/{id} */
    async resolveStockRequest(
        id: number,
        status: 'RESOLVED' | 'DISMISSED',
        note?: string
    ): Promise<void> {
        await apiClient.patch(`/staff/manager/stock-requests/${id}`, { status, note })
    },
}
