/**
 * Types cho Staff UI (phục vụ / bếp / thu ngân / quản lý).
 * Dùng chung cho 4 vai trò nhân viên nhà hàng.
 */

// ===== Roles =====

export type StaffRole = 'WAITER' | 'KITCHEN' | 'CASHIER' | 'MANAGER' | 'SHIPPER'

export const STAFF_ROLES: StaffRole[] = ['WAITER', 'KITCHEN', 'CASHIER', 'MANAGER', 'SHIPPER']

export const ROLE_LABELS: Record<StaffRole, { label: string; icon: string; color: string }> = {
    WAITER:  { label: 'Phục vụ',       icon: '🍽️', color: '#2563eb' },
    KITCHEN: { label: 'Bếp',           icon: '👨‍🍳', color: '#ea580c' },
    CASHIER: { label: 'Thu ngân',      icon: '💰', color: '#16a34a' },
    SHIPPER: { label: 'Giao hàng', icon: 'GH', color: '#0369a1' },
    MANAGER: { label: 'Quản lý',       icon: '👔', color: '#7c3aed' },
}

// ===== Tables =====

export type TableStatus = 'AVAILABLE' | 'OCCUPIED' | 'RESERVED' | 'WAITING_PAYMENT' | 'CLEANING'

export const TABLE_STATUS_LABELS: Record<TableStatus, { label: string; icon: string; cls: string }> = {
    AVAILABLE:        { label: 'Trống',            icon: '🟢', cls: 'status-available' },
    OCCUPIED:         { label: 'Đang phục vụ',     icon: '🔵', cls: 'status-occupied' },
    RESERVED:         { label: 'Đã đặt trước',    icon: '🟡', cls: 'status-reserved' },
    WAITING_PAYMENT:  { label: 'Chờ thanh toán',  icon: '🟠', cls: 'status-waiting' },
    CLEANING:         { label: 'Đang dọn',         icon: '⚪', cls: 'status-cleaning' },
}

export interface RestaurantTable {
    id: number
    code: string             // B01, B02, V01...
    name: string             // "Bàn 1", "Phòng VIP 1"
    capacity: number
    location: string | null  // "Tầng 1", "Tầng 2" - có thể null nếu chưa set
    status: TableStatus
    is_active: boolean
    reserved_time?: string | null
    reservation_name?: string | null
}

export interface TableSession {
    id: number
    table_id: number
    table_code: string | null
    table_name: string | null
    waiter_id: number | null
    waiter_name: string | null
    customer_id: number | null
    customer_name: string | null
    guest_count: number
    opened_at: string | null
    closed_at: string | null
    status: 'OPEN' | 'CANCELLED' | 'CLOSED'
    items_count: number
    subtotal: number
    order_id: number | null
}

// ===== Reservations =====

export type ReservationStatus = 'PENDING' | 'CONFIRMED' | 'ARRIVED' | 'CANCELLED' | 'COMPLETED'

export interface Reservation {
    id: number
    customer_name: string
    customer_phone: string
    customer_id: number | null
    table_id: number | null
    table_code: string | null
    guest_count: number
    reserved_date: string        // YYYY-MM-DD
    reserved_time: string        // HH:mm
    note: string | null
    status: ReservationStatus
    handled_by: number | null
    handled_by_name: string | null
    created_at: string | null
}

// ===== Orders / OrderItems =====

export type OrderStatus = 'PENDING' | 'CONFIRMED' | 'PREPARING' | 'READY' | 'SERVED' | 'DELIVERING' | 'DELIVERED' | 'COMPLETED' | 'CANCELLED'

export type OrderType = 'DINE_IN' | 'TAKEAWAY' | 'ONLINE'

export type OrderItemStatus = 'PENDING' | 'PREPARING' | 'READY' | 'SERVED' | 'CANCELLED'

export interface OrderItem {
    id: number
    menu_item_id: number
    menu_item_name: string
    menu_item_image: string | null
    unit_price: number
    quantity: number
    subtotal: number
    note: string | null
    spicy_level: number | null      // 0-7, null = default
    status: OrderItemStatus
    started_at: string | null
    ready_at: string | null
    served_at: string | null
    cancellation_reason: string | null
    toppings: Array<{
        id: number
        name: string | null
        unit_price: number
        quantity: number
        subtotal: number
    }>
}

export interface Order {
    id: number
    code: string                       // ORD-20260909-0001
    order_type: OrderType
    status: OrderStatus
    subtotal: number
    discount: number
    total_amount: number
    note: string | null
    placed_at: string | null
    completed_at: string | null
    table_session_id: number | null
    table_code: string | null
    waiter_id: number | null
    waiter_name: string | null
    customer_id: number | null
    customer_name: string | null
    items: OrderItem[]
    item_count: number
    total_quantity: number
    invoice_id: number | null
    invoice_status: InvoiceStatus | null
}

// ===== Invoice / Payments =====

export type InvoiceStatus = 'UNPAID' | 'PENDING' | 'PAID' | 'FAILED' | 'REFUNDED'

export type PaymentMethod = 'CASH' | 'BANK_TRANSFER' | 'QR' | 'ONLINE_GATEWAY' | 'OTHER'

export interface Payment {
    id: number
    invoice_id: number
    method: PaymentMethod
    amount: number
    transaction_ref: string | null
    status: 'PENDING' | 'SUCCESS' | 'FAILED' | 'REFUNDED'
    processed_by: number | null
    processed_by_name: string | null
    processed_at: string | null
    note: string | null
}

export interface Invoice {
    id: number
    code: string
    order_id: number
    order_code: string
    customer_id: number | null
    customer_name: string | null
    cashier_id: number | null
    cashier_name: string | null
    waiter_id: number | null
    waiter_name: string | null
    table_code: string | null
    subtotal: number
    discount: number
    tax: number
    total: number
    paid: number
    status: InvoiceStatus
    issued_at: string | null
    paid_at: string | null
    items: OrderItem[]
    payments: Payment[]
}

// ===== Dashboard / Reports =====

export interface DashboardStats {
    today_revenue: number
    today_orders: number
    today_invoices: number
    active_tables: number
    available_tables: number
    preparing_items: number
    ready_items: number
    total_customers_today: number
    revenue_growth_pct: number          // so với hôm qua
    orders_growth_pct: number
}

export interface ChartDataPoint {
    label: string
    value: number
}

export interface TopDish {
    id: number
    name: string
    image_url: string | null
    sold_count: number
    revenue: number
}

export interface RevenueReport {
    period: 'day' | 'month'
    from_date: string
    to_date: string
    total_revenue: number
    total_orders: number
    total_invoices: number
    cash_revenue: number
    transfer_revenue: number
    chart_data: ChartDataPoint[]
    top_dishes: TopDish[]
}

// ===== Ingredient / Inventory =====

export interface Ingredient {
    id: number
    code: string
    name: string
    unit: string                          // kg, l, cái...
    quantity_in_stock: number
    min_stock_level: number
    import_price: number
    import_date: string | null
    expiry_date: string | null
    supplier_name: string | null
    is_active: boolean
    is_low_stock: boolean
}

export type InventoryTxType = 'IMPORT' | 'EXPORT' | 'ADJUSTMENT' | 'WASTE'

export interface InventoryTransaction {
    id: number
    ingredient_id: number
    ingredient_name: string
    type: InventoryTxType
    quantity: number
    unit_price: number
    note: string | null
    performed_by: number | null
    performed_by_name: string | null
    transacted_at: string | null
}

// ===== Menu management =====

export interface Category {
    id: number
    name: string
    slug: string
    description: string | null
    sort_order: number
    is_active: boolean
    items_count: number
}

export interface MenuItem {
    id: number
    category_id: number
    category_name: string
    name: string
    slug: string
    description: string | null
    image_url: string | null
    price: number
    cost_price: number
    is_available: boolean
    is_active: boolean
    preparation_minutes: number
    spicy_level: number
    size: 'SMALL' | 'LARGE' | null
}

// ===== User management =====

export interface StaffUserType {
    id: number
    name: string
    full_name: string | null
    email: string
    phone: string | null
    is_active: boolean
    roles: StaffRole[]
    created_at: string | null
}

// ===== Work shifts =====

export interface WorkShift {
    id: number
    user_id: number
    user_name: string
    work_date: string           // YYYY-MM-DD
    check_in: string | null
    check_out: string | null
    total_hours: number
    shift_name: string | null
    note: string | null
}

// ===== Common =====

export interface ApiResponse<T> {
    success: boolean
    message?: string
    data: T
}

export interface Paginated<T> {
    items: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
}
