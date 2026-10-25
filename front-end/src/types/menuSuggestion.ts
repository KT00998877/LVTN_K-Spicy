/**
 * Types cho gợi ý combo nước uống (Phase 3).
 * Đồng bộ với MenuItemSuggestion.php + MenuSuggestionService.php (back-end).
 */

/** Món được gợi ý đi kèm (đồ uống / món phụ). */
export interface SuggestionItem {
    id: number
    name: string
    price: number
    price_formatted: string
    image_url: string | null
    is_available: boolean
}

/** 1 gợi ý combo hiển thị cho khách. */
export interface MenuSuggestion {
    id: number
    title: string
    message: string | null
    /** Giá combo (món chính + món gợi ý). null = không có ưu đãi. */
    combo_price: number | null
    combo_price_formatted: string | null
    /** Tổng giá nếu mua riêng 2 món. */
    original_price: number
    original_price_formatted: string
    /** Số tiền khách tiết kiệm. */
    saving: number
    saving_formatted: string | null
    suggested_item: SuggestionItem
}

/** Dòng tiết kiệm combo trong giỏ hàng / quote. */
export interface ComboSavingLine {
    suggestion_id: number
    title: string
    menu_item_id: number
    suggested_item_id: number
    quantity: number
    combo_price: number
    saving: number
}
