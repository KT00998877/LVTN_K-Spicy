import apiClient from './client'

/**
 * Manager Ingredients API — Phase 2 CRUD module.
 * Endpoint prefix: /api/staff/manager/ingredients
 */

export interface Ingredient {
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
    supplier_name: string | null
    ingredient_category_id: number | null
    category_name: string | null
    packagings: IngredientPackaging[]
    is_active: boolean
    is_low_stock: boolean
    estimated_value: number
    created_at: string | null
    updated_at: string | null
}
export interface IngredientPackaging { id: number; supplier_id: number | null; supplier_name: string | null; purchase_unit: string; conversion_quantity: number; is_default: boolean; is_active: boolean }
export interface IngredientCategory { id: number; name: string; sort_order: number; is_active: boolean; ingredients_count: number }

export interface IngredientListResponse {
    data: Ingredient[]
    meta: {
        current_page: number
        per_page: number
        total: number
        last_page: number
    }
}

export interface IngredientPayload {
    code: string
    name: string
    unit: string
    min_stock_level: number
    supplier_id?: number | null
    ingredient_category_id?: number | null
    is_active: boolean
}

export interface BlockingReferences {
    can_deactivate: boolean
    data: { id: number; name: string; code: string | null }[]
}

export interface RecipeLine {
    id?: number
    ingredient_id: number
    ingredient_code?: string
    ingredient_name?: string
    ingredient_unit?: string
    ingredient_active?: boolean
    ingredient_in_stock?: number
    quantity_required: number
    waste_factor: number
    unit_override: string | null
    availability_required: boolean
    effective_quantity?: number
    display_unit?: string
    line_cost?: number
}

export interface RecipeSummary {
    ingredient_cost: number
    selling_price: number
    gross_profit: number
    food_cost_pct: number
    waste_lines_count: number
    lines_count: number
}

export interface RecipePayload {
    lines: {
        ingredient_id: number
        quantity_required: number
        waste_factor: number
        unit_override?: string | null
        availability_required?: boolean
    }[]
}

export interface RecipeResponse {
    menu_item: {
        id: number
        name: string
        price: number
        slug: string
        size: 'SMALL' | 'LARGE' | null
        is_active: boolean
        is_available: boolean
        auto_availability_enabled: boolean
        sold_out_reason?: string | null
        sold_out_at?: string | null
    }
    lines: RecipeLine[]
    summary: RecipeSummary
}

/** Unit options enforced by backend. Keep in sync with UpsertIngredientRequest. */
export const ALLOWED_UNITS = [
    'kg', 'g', 'mg', 'l', 'ml', 'chai', 'lon', 'gói', 'hộp', 'quả', 'cái', 'cục', 'cây', 'phần',
] as const
export type IngredientUnit = typeof ALLOWED_UNITS[number]

export const ingredientsApi = {
    async list(params: {
        q?: string
        is_active?: boolean
        low_stock?: boolean
        supplier_id?: number
        ingredient_category_id?: number
        per_page?: number
        page?: number
    } = {}): Promise<IngredientListResponse> {
        const res = await apiClient.get<{ success: boolean; data: Ingredient[]; meta: IngredientListResponse['meta'] }>(
            '/staff/manager/ingredients',
            { params },
        )
        return { data: res.data.data, meta: res.data.meta }
    },

    async categories(): Promise<IngredientCategory[]> {
        return (await apiClient.get<{ data: IngredientCategory[] }>('/staff/manager/ingredients/categories')).data.data
    },
    async createCategory(name: string): Promise<IngredientCategory> {
        return (await apiClient.post<{ data: IngredientCategory }>('/staff/manager/ingredients/categories', { name })).data.data
    },
    async savePackaging(ingredientId: number, payload: Omit<IngredientPackaging, 'id' | 'supplier_name'>): Promise<Ingredient> {
        return (await apiClient.post<{ data: Ingredient }>(`/staff/manager/ingredients/${ingredientId}/packagings`, payload)).data.data
    },

    async get(id: number): Promise<Ingredient> {
        const res = await apiClient.get<{ success: boolean; data: Ingredient }>(`/staff/manager/ingredients/${id}`)
        return res.data.data
    },

    async create(payload: IngredientPayload): Promise<Ingredient> {
        const res = await apiClient.post<{ success: boolean; data: Ingredient }>('/staff/manager/ingredients', payload)
        return res.data.data
    },

    async update(id: number, payload: IngredientPayload): Promise<Ingredient> {
        const res = await apiClient.patch<{ success: boolean; data: Ingredient }>(`/staff/manager/ingredients/${id}`, payload)
        return res.data.data
    },

    async blockingReferences(id: number): Promise<BlockingReferences> {
        const res = await apiClient.get<{ success: boolean; data: BlockingReferences['data']; can_deactivate: boolean }>(
            `/staff/manager/ingredients/${id}/blocking-references`,
        )
        return { data: res.data.data, can_deactivate: res.data.can_deactivate }
    },
}

export const recipesApi = {
    async get(menuItemId: number): Promise<RecipeResponse> {
        const res = await apiClient.get<{ success: boolean; data: RecipeResponse }>(
            `/staff/manager/menu-items/${menuItemId}/recipe`,
        )
        return res.data.data
    },

    async replace(menuItemId: number, payload: RecipePayload): Promise<RecipeResponse> {
        const res = await apiClient.put<{ success: boolean; data: RecipeResponse }>(
            `/staff/manager/menu-items/${menuItemId}/recipe`,
            payload,
        )
        return res.data.data
    },
}

/** Phase E — Tồn dự kiến hiển thị cho manager (3 cột tách bạch). */
export type InventoryDataStatus = 'ready' | 'missing_snapshot' | 'uncomparable' | 'insufficient_data'

export interface IngredientInventoryBalance {
    ingredient_id: number
    code: string
    name: string
    unit: string
    is_active: boolean
    actual_stock: number
    expected_usage_qty: number
    reserved_qty: number
    estimated_available: number | null
    data_status: InventoryDataStatus
    since_closed_at: string | null
}

export const inventoryBalanceApi = {
    /** Lấy balance cho 1 ingredient. */
    async forIngredient(ingredientId: number): Promise<IngredientInventoryBalance> {
        const res = await apiClient.get<{ success: boolean; data: IngredientInventoryBalance }>(
            '/staff/manager/inventory-balance',
            { params: { ingredient_id: ingredientId } },
        )
        return res.data.data
    },
}
