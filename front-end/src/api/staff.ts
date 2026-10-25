/**
 * staff.ts — API client cho khu vực nhân viên (Staff).
 *
 * Thay thế staffMock.ts (dữ liệu giả localStorage) bằng các endpoint Laravel thật.
 *
 * Auth flow:
 *  - staffAuth.login() trả về {user, token, roles, primary_role}
 *  - token được lưu vào localStorage('staff_token')
 *  - apiClient (./client) sẽ tự động đính kèm Bearer token cho mọi request
 *
 * Lưu ý: KHÔNG lưu token staff vào `auth_token` (key của customer) để tránh xung đột.
 */

import apiClient from './client'

const STAFF_TOKEN_KEY = 'staff_token'

/* ============================================================
 *  Helper: lưu / xoá token riêng cho staff
 * ========================================================== */

function setStaffToken(token: string | null): void {
    if (token) {
        localStorage.setItem(STAFF_TOKEN_KEY, token)
    } else {
        localStorage.removeItem(STAFF_TOKEN_KEY)
    }
}

export function getStaffToken(): string | null {
    return localStorage.getItem(STAFF_TOKEN_KEY)
}

export function hasStaffToken(): boolean {
    return !!getStaffToken()
}

/* ============================================================
 *  Auth
 * ========================================================== */

export interface StaffLoginPayload {
    email: string
    password: string
}

export interface StaffUser {
    id: number
    name: string
    full_name: string | null
    email: string
    phone: string | null
    is_active: boolean
    can_close_daily_stock: boolean
    created_at: string | null
}

export interface StaffLoginResponse {
    user: StaffUser
    token: string
    roles: string[]
    primary_role: string
}

export const staffAuth = {
    /**
     * POST /api/staff/auth/login
     * Trả về Sanctum token + roles của nhân viên.
     * Tự động lưu token vào localStorage.
     */
    async login(payload: StaffLoginPayload): Promise<StaffLoginResponse> {
        const res = await apiClient.post<{ success: boolean; data: StaffLoginResponse }>(
            '/staff/auth/login',
            payload
        )
        const data = res.data.data
        setStaffToken(data.token)
        return data
    },

    /**
     * POST /api/staff/auth/logout
     * Xóa token hiện tại + xoá localStorage.
     */
    async logout(): Promise<void> {
        try {
            await apiClient.post('/staff/auth/logout')
        } catch {
            // Bỏ qua lỗi — vẫn xóa token local
        } finally {
            setStaffToken(null)
        }
    },

    /**
     * GET /api/staff/auth/me
     * Trả về user + roles hiện tại (dùng để validate token khi reload page).
     */
    async me(): Promise<{ user: StaffUser; roles: string[]; primary_role: string }> {
        const res = await apiClient.get<{
            success: boolean
            data: { user: StaffUser; roles: string[]; primary_role: string }
        }>('/staff/auth/me')
        return res.data.data
    },
}

export interface TwoFactorStatus {
    enabled: boolean
    setup_pending: boolean
}

export interface TwoFactorSetup extends TwoFactorStatus {
    secret: string
    provisioning_uri: string
}

export const managerSecurity = {
    async status(): Promise<TwoFactorStatus> {
        const { data } = await apiClient.get<{ success: boolean; data: TwoFactorStatus }>('/staff/auth/two-factor')
        return data.data
    },
    async setup(): Promise<TwoFactorSetup> {
        const { data } = await apiClient.post<{ success: boolean; data: TwoFactorSetup }>('/staff/auth/two-factor/setup')
        return { ...data.data, enabled: false, setup_pending: true }
    },
    async confirm(code: string): Promise<void> {
        await apiClient.post('/staff/auth/two-factor/confirm', { code })
    },
    async disable(password: string, code: string): Promise<void> {
        await apiClient.delete('/staff/auth/two-factor', { data: { password, code } })
    },
}

/* ============================================================
 *  Tables + Sessions
 * ========================================================== */

export interface RestaurantTable {
    id: number
    code: string
    name: string
    capacity: number
    location: string | null
    status: 'AVAILABLE' | 'OCCUPIED' | 'RESERVED' | 'WAITING_PAYMENT' | 'CLEANING'
    is_active: boolean
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
    opened_at: string
    closed_at: string | null
    status: 'OPEN' | 'CANCELLED' | 'CLOSED'
    items_count: number
    subtotal: number
    order_id: number | null
}

export interface TablesResponse {
    tables: RestaurantTable[]
    sessions: TableSession[]
    stats: {
        total: number
        available: number
        occupied: number
        reserved: number
        waiting_payment: number
        cleaning: number
    }
}

export const staffTables = {
    /** GET /api/staff/tables */
    async list(): Promise<TablesResponse> {
        const res = await apiClient.get<{ success: boolean; data: TablesResponse }>(
            '/staff/tables'
        )
        return res.data.data
    },

    /** PATCH /api/staff/tables/{id}/status */
    async updateStatus(id: number, status: RestaurantTable['status']): Promise<RestaurantTable> {
        const res = await apiClient.patch<{ success: boolean; data: RestaurantTable }>(
            `/staff/tables/${id}/status`,
            { status }
        )
        return res.data.data
    },

    /** PATCH /api/staff/tables/{id}/request-payment */
    async requestPayment(id: number): Promise<RestaurantTable> {
        const res = await apiClient.patch<{ success: boolean; data: RestaurantTable }>(
            `/staff/tables/${id}/request-payment`
        )
        return res.data.data
    },

    /** POST /api/staff/table-sessions */
    async openSession(payload: {
        table_id: number
        guest_count: number
        customer_name?: string | null
    }): Promise<TableSession> {
        const res = await apiClient.post<{ success: boolean; data: TableSession }>(
            '/staff/table-sessions',
            payload
        )
        return res.data.data
    },

    /** PATCH /api/staff/table-sessions/{id}/move */
    async moveSession(sessionId: number, newTableId: number): Promise<TableSession> {
        const res = await apiClient.patch<{ success: boolean; data: TableSession }>(
            `/staff/table-sessions/${sessionId}/move`,
            { new_table_id: newTableId }
        )
        return res.data.data
    },

    /** PATCH /api/staff/table-sessions/{id}/close */
    async closeSession(sessionId: number): Promise<TableSession> {
        const res = await apiClient.patch<{ success: boolean; data: TableSession }>(
            `/staff/table-sessions/${sessionId}/close`
        )
        return res.data.data
    },

    /** PATCH /api/staff/table-sessions/{id}/cancel */
    async cancelSession(sessionId: number): Promise<TableSession> {
        const res = await apiClient.patch<{ success: boolean; data: TableSession }>(
            `/staff/table-sessions/${sessionId}/cancel`
        )
        return res.data.data
    },
}

/* ============================================================
 *  Orders + Items
 * ========================================================== */

export interface OrderItemTopping {
    id: number
    name: string | null
    unit_price: number
    quantity: number
    subtotal: number
}

export interface OrderItem {
    id: number
    menu_item_id: number
    menu_item_name: string
    menu_item_image: string | null
    unit_price: number
    quantity: number
    subtotal: number
    note: string | null
    spicy_level: number | null
    status: 'PENDING' | 'PREPARING' | 'READY' | 'SERVED' | 'CANCELLED'
    started_at: string | null
    ready_at: string | null
    served_at: string | null
    cancellation_reason: string | null
    toppings: OrderItemTopping[]
}

export interface Order {
    id: number
    code: string
    order_type: 'DINE_IN' | 'TAKEAWAY' | 'ONLINE'
    status:
        | 'PENDING'
        | 'CONFIRMED'
        | 'PREPARING'
        | 'READY'
        | 'SERVED'
        | 'DELIVERING'
        | 'DELIVERED'
        | 'COMPLETED'
        | 'CANCELLED'
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
    invoice_id: number | null
    invoice_status: string | null
    item_count?: number
    total_quantity?: number
    items: OrderItem[]
}

export const staffOrders = {
    async requestInvoice(orderId: number): Promise<Order> {
        const res = await apiClient.post<{ data: Order }>(`/staff/orders/${orderId}/request-invoice`)
        return res.data.data
    },
    async submitItems(orderId: number, items: { menu_item_id: number; quantity: number; spicy_level?: number; note?: string }[]): Promise<Order> {
        const res = await apiClient.post<{ data: Order }>(`/staff/orders/${orderId}/submit-items`, { items })
        return res.data.data
    },
    async demoPay(orderId: number): Promise<Order> {
        const res = await apiClient.post<{ data: Order }>(`/staff/orders/${orderId}/demo-pay`)
        return res.data.data
    },
    /** GET /api/staff/orders?status= */
    async list(status?: string): Promise<Order[]> {
        const res = await apiClient.get<{ success: boolean; data: Order[] }>('/staff/orders', {
            params: status ? { status } : {},
        })
        return res.data.data
    },

    /** GET /api/staff/orders/{id} */
    async get(orderId: number): Promise<Order> {
        const res = await apiClient.get<{ success: boolean; data: Order }>(
            `/staff/orders/${orderId}`
        )
        return res.data.data
    },

    /** POST /api/staff/orders */
    async create(tableSessionId: number): Promise<Order> {
        const res = await apiClient.post<{ success: boolean; data: Order }>('/staff/orders', {
            table_session_id: tableSessionId,
        })
        return res.data.data
    },

    /** POST /api/staff/orders/{id}/items */
    async addItem(
        orderId: number,
        payload: {
            menu_item_id: number
            quantity?: number
            spicy_level?: number
            note?: string | null
            topping_ids?: number[]
        }
    ): Promise<Order> {
        const res = await apiClient.post<{ success: boolean; data: Order }>(
            `/staff/orders/${orderId}/items`,
            payload
        )
        return res.data.data
    },

    /** PATCH /api/staff/orders/{oid}/items/{iid} */
    async updateItem(
        orderId: number,
        itemId: number,
        payload: {
            quantity?: number
            spicy_level?: number
            note?: string | null
            topping_ids?: number[]
        }
    ): Promise<Order> {
        const res = await apiClient.patch<{ success: boolean; data: Order }>(
            `/staff/orders/${orderId}/items/${itemId}`,
            payload
        )
        return res.data.data
    },

    /** PATCH /api/staff/orders/{oid}/items/{iid}/status */
    async updateItemStatus(
        orderId: number,
        itemId: number,
        status: OrderItem['status'],
        reason?: string
    ): Promise<Order> {
        const res = await apiClient.patch<{ success: boolean; data: Order }>(
            `/staff/orders/${orderId}/items/${itemId}/status`,
            { status, reason }
        )
        return res.data.data
    },
}

/* ============================================================
 *  Reservations
 * ========================================================== */

export interface Reservation {
    id: number
    customer_name: string
    customer_phone: string
    customer_id: number | null
    table_id: number | null
    table_code: string | null
    guest_count: number
    reserved_date: string
    reserved_time: string
    note: string | null
    status: 'PENDING' | 'CONFIRMED' | 'ARRIVED' | 'CANCELLED' | 'COMPLETED'
    handled_by: number | null
    handled_by_name: string | null
    created_at: string | null
}

export const staffReservations = {
    /** GET /api/staff/reservations?date=YYYY-MM-DD */
    async list(date?: string): Promise<Reservation[]> {
        const res = await apiClient.get<{ success: boolean; data: Reservation[] }>(
            '/staff/reservations',
            { params: date ? { date } : {} }
        )
        return res.data.data
    },

    /** PATCH /api/staff/reservations/{id}/status */
    async updateStatus(id: number, status: Reservation['status']): Promise<Reservation> {
        const res = await apiClient.patch<{ success: boolean; data: Reservation }>(
            `/staff/reservations/${id}/status`,
            { status }
        )
        return res.data.data
    },
}

/* ============================================================
 *  Kitchen (bếp)
 * ========================================================== */

/** Nguyên liệu thiếu khi bếp bắt đầu nấu — bếp tự quyết định nấu tiếp hay hủy món. */
export interface KitchenShortage {
    ingredient_id: number
    ingredient: string
    unit: string
    required: number
    available: number
    shortage: number
}

export interface KitchenItem {
    order: {
        order_type: 'DINE_IN' | 'TAKEAWAY' | 'ONLINE'
        invoice_code: string | null
        id: number
        code: string
        table_code: string | null
        table_session_id: number | null
        status: string
        placed_at: string | null
    }
    item: {
        service_reminded_at?: string | null
        id: number
        menu_item_id: number
        menu_item_name: string
        menu_item_image: string | null
        unit_price: number
        quantity: number
        subtotal: number
        note: string | null
        spicy_level: number | null
        status: 'PENDING' | 'PREPARING' | 'READY' | 'SERVED' | 'CANCELLED'
        started_at: string | null
        ready_at: string | null
        /** Nguyên liệu đang thiếu (nếu có) — bếp thấy ngay trên danh sách. */
        shortages: KitchenShortage[]
        toppings: Array<{
            id: number
            name: string | null
            unit_price: number
            quantity: number
            subtotal: number
        }>
    }
}

export const staffKitchen = {
    /** GET /api/staff/kitchen/items?status=PENDING,PREPARING,READY */
    async listItems(status?: string): Promise<KitchenItem[]> {
        const res = await apiClient.get<{ success: boolean; data: KitchenItem[] }>(
            '/staff/kitchen/items',
            { params: status ? { status } : {} }
        )
        return res.data.data
    },

    /**
     * PATCH /api/staff/kitchen/items/{oid}/{iid}/status
     *
     * Khi chuyển sang PREPARING mà thiếu nguyên liệu, backend trả 409 kèm
     * `shortages` — bếp phải chọn "Tiếp tục nấu" (allowShortage=true) hoặc "Hủy món".
     */
    async updateItemStatus(
        orderId: number,
        itemId: number,
        status: 'PENDING' | 'PREPARING' | 'READY' | 'SERVED' | 'CANCELLED',
        reason?: string,
        allowShortage = false
    ): Promise<{ order: Order; shortages: KitchenShortage[]; requestCode: string | null }> {
        const res = await apiClient.patch<{
            success: boolean
            data: Order
            shortages: KitchenShortage[]
            request_code: string | null
        }>(
            `/staff/kitchen/items/${orderId}/${itemId}/status`,
            { status, reason, allow_shortage: allowShortage }
        )
        return {
            order: res.data.data,
            shortages: res.data.shortages ?? [],
            requestCode: res.data.request_code ?? null,
        }
    },

    /** POST /api/staff/kitchen/orders/{orderId}/notify-shipper
     *  Bếp bấm "Thông báo shipper" khi tất cả món đã READY.
     */
    async notifyShipper(orderId: number): Promise<void> {
        await apiClient.post(`/staff/kitchen/orders/${orderId}/notify-shipper`)
    },
}

/* ============================================================
 *  Invoices (thu ngân)
 * ========================================================== */

export interface InvoicePayment {
    id: number
    method: 'CASH' | 'TRANSFER' | 'QR'
    amount: number
    transaction_ref: string | null
    note: string | null
    status: string
    paid_at: string | null
}

export interface Invoice {
    id: number
    code: string
    order_id: number | null
    order_code: string | null
    table_code: string | null
    customer_name: string | null
    subtotal: number
    discount: number
    total: number
    tax: number
    paid: number
    due: number
    status: 'UNPAID' | 'PARTIAL' | 'PAID'
    issued_at: string | null
    paid_at: string | null
    payments: InvoicePayment[]
}

export const staffInvoices = {
    /** GET /api/staff/invoices?status=&from=&to= */
    async list(params?: {
        status?: string
        from?: string
        to?: string
    }): Promise<Invoice[]> {
        const res = await apiClient.get<{ success: boolean; data: Invoice[] }>(
            '/staff/invoices',
            { params }
        )
        return res.data.data
    },

    /** GET /api/staff/invoices/{id} */
    async get(id: number): Promise<Invoice> {
        const res = await apiClient.get<{ success: boolean; data: Invoice }>(
            `/staff/invoices/${id}`
        )
        return res.data.data
    },

    /** POST /api/staff/invoices/from-order/{orderId} */
    async createFromOrder(orderId: number): Promise<Invoice> {
        const res = await apiClient.post<{ success: boolean; data: Invoice }>(
            `/staff/invoices/from-order/${orderId}`
        )
        return res.data.data
    },

    /** PATCH /api/staff/invoices/{id}/pay */
    async pay(
        id: number,
        payload: {
            method: 'CASH' | 'TRANSFER' | 'QR'
            amount: number
            transaction_ref?: string
            note?: string
        }
    ): Promise<Invoice> {
        const res = await apiClient.patch<{ success: boolean; data: Invoice }>(
            `/staff/invoices/${id}/pay`,
            payload
        )
        return res.data.data
    },
}

/* ============================================================
 *  Dashboard (quản lý)
 * ========================================================== */

export interface DashboardStats {
    today_revenue: number
    today_orders: number
    today_invoices: number
    active_tables: number
    available_tables: number
    preparing_items: number
    pending_items?: number
    ready_items: number
    total_staff: number
    revenue_growth_pct: number | null
    orders_growth_pct: number | null
}

export interface RevenueReport {
    generated_at?: string
    payment_count?: number
    previous_revenue?: number
    previous_from?: string
    previous_to?: string
    growth_pct?: number | null
    by_method?: { code: string; amount: number; count: number }[]
    by_order_type?: { code: string; amount: number; count: number }[]
    order_statuses?: { status: string; count: number }[]
    avg_receipt_value?: number
    from_date: string
    to_date: string
    total_revenue: number
    total_orders: number
    total_invoices: number
    avg_order_value: number
    cash_revenue: number
    transfer_revenue: number
    daily: Array<{
        date: string
        revenue: number
        count: number
    }>
    chart_data?: Array<{
        label: string
        value: number
        count: number
    }>
    top_dishes?: Array<{
        id: number
        name: string
        sold_count: number
        revenue: number
    }>
}

export interface MenuItemAdmin {
    id: number
    name: string
    price: number
    category: string | null
    is_available: boolean
    image_url: string | null
    sold_out_reason?: string | null
    sold_out_at?: string | null
}

export interface StaffUserAdmin {
    id: number
    name: string
    email: string
    phone: string | null
    is_active: boolean
    roles: string[]
}

export const staffDashboard = {
    /** GET /api/staff/dashboard/stats */
    async stats(): Promise<DashboardStats> {
        const res = await apiClient.get<{ success: boolean; data: DashboardStats }>(
            '/staff/dashboard/stats'
        )
        return res.data.data
    },

    /** GET /api/staff/dashboard/revenue?from=&to= */
    async revenue(params?: { from?: string; to?: string }): Promise<RevenueReport> {
        const res = await apiClient.get<{ success: boolean; data: RevenueReport }>(
            '/staff/dashboard/revenue',
            { params }
        )
        return res.data.data
    },

    /** GET /api/staff/dashboard/menu-items */
    async menuItems(): Promise<MenuItemAdmin[]> {
        const res = await apiClient.get<{ success: boolean; data: MenuItemAdmin[] }>(
            '/staff/dashboard/menu-items'
        )
        return res.data.data
    },

    /** PATCH /api/staff/dashboard/menu-items/{id} */
    async updateMenuItem(
        id: number,
        payload: { is_available?: boolean; price?: number }
    ): Promise<MenuItemAdmin> {
        const res = await apiClient.patch<{ success: boolean; data: MenuItemAdmin }>(
            payload.is_available === undefined ? `/staff/dashboard/menu-items/${id}` : `/staff/menu-items/${id}/availability`,
            payload
        )
        return res.data.data
    },

    /** GET /api/staff/dashboard/staff-users */
    async staffUsers(): Promise<StaffUserAdmin[]> {
        const res = await apiClient.get<{ success: boolean; data: StaffUserAdmin[] }>(
            '/staff/dashboard/staff-users'
        )
        return res.data.data
    },

    /** PATCH /api/staff/dashboard/staff-users/{id} */
    async updateStaffUser(
        id: number,
        payload: { is_active?: boolean; name?: string }
    ): Promise<StaffUserAdmin> {
        const res = await apiClient.patch<{ success: boolean; data: StaffUserAdmin }>(
            `/staff/dashboard/staff-users/${id}`,
            payload
        )
        return res.data.data
    },
}

/* ============================================================
 *  Online orders (giao hàng)
 *  - Manager/Cashier: xác nhận / từ chối đơn, chọn shipper bàn giao
 *  - Shipper: nhận danh sách đơn đang giao, xác nhận giao thành công / báo lỗi
 * ========================================================== */

export interface DeliveryShipper {
    id: number
    name: string
}

export interface DeliveryEvent {
    event: string
    status: string
    note: string | null
    created_at: string
}

export type PaymentMethod = 'COD' | 'PREPAID'

export interface DeliveryOrder {
    id: number
    code: string
    order_type: 'ONLINE' | 'TAKEAWAY'
    status:
        | 'PENDING'
        | 'CONFIRMED'
        | 'PREPARING'
        | 'READY'
        | 'READY_NOTIFY_SHIPPER'
        | 'DELIVERING'
        | 'DELIVERED'
        | 'COMPLETED'
        | 'CANCELLED'
    payment_method: PaymentMethod
    subtotal: number
    discount: number
    total_amount: number
    note: string | null
    placed_at: string | null
    completed_at: string | null
    customer_id: number | null
    customer_name: string | null
    items: OrderItem[]
    recipient_name: string | null
    recipient_phone: string | null
    delivery_address: string | null
    delivery_latitude?: number | string | null
    delivery_longitude?: number | string | null
    shipping_fee: number
    confirmed_at: string | null
    handed_over_at: string | null
    picked_up_at: string | null    // Bước 1: shipper đã lấy món
    arrived_at: string | null      // Bước 2: shipper đã tới điểm giao
    delivered_at: string | null
    customer_received_at: string | null  // Khách xác nhận đã nhận
    points_awarded: number           // Điểm thưởng đã cộng cho đơn này
    shipper_id: number | null
    shipper_name: string | null
    delivery_issue: string | null
    payment: {
        invoice_id: number
        invoice_code: string
        status: 'UNPAID' | 'PAID' | 'FAILED' | 'REFUNDED'
        total: number
        paid: number
        is_simulated: boolean
    } | null
    events: DeliveryEvent[]
}

/**
 * Đơn ONLINE đang chờ shipper nhận (Phase 2).
 * `distance_km` = khoảng cách từ vị trí hiện tại của shipper tới điểm giao.
 */
export interface NearbyJob {
    id: number
    code: string
    recipient_name: string | null
    delivery_address: string | null
    delivery_latitude: number
    delivery_longitude: number
    total_amount: number
    payment_method: PaymentMethod
    distance_km: number | null
    is_mine: boolean
}

/**
 * Shipper đang rảnh, kèm khoảng cách từ nhà hàng (Phase 2).
 */
export interface NearestShipper {
    id: number
    name: string
    distance_km: number
    latitude: number
    longitude: number
    last_seen_at: string | null
}

export const onlineOrders = {
    /** GET /api/staff/online-orders */
    async list(status?: string, orderType?: string): Promise<DeliveryOrder[]> {
        const res = await apiClient.get<{ success: boolean; data: DeliveryOrder[] }>(
            '/staff/online-orders',
            { params: { ...(status ? { status } : {}), ...(orderType ? { order_type: orderType } : {}) } },
        )
        return res.data.data
    },

    /** GET /api/staff/online-orders/shippers */
    async listShippers(): Promise<DeliveryShipper[]> {
        const res = await apiClient.get<{ success: boolean; data: DeliveryShipper[] }>(
            '/staff/online-orders/shippers',
        )
        return res.data.data
    },

    /**
     * GET /api/staff/deliveries/nearest (Phase 2) — shipper đang rảnh, xếp theo
     * khoảng cách từ nhà hàng. Manager dùng để duyệt đơn nhanh hơn.
     */
    async nearestShippers(): Promise<NearestShipper[]> {
        const res = await apiClient.get<{ success: boolean; data: NearestShipper[] }>(
            '/staff/deliveries/nearest',
        )
        return res.data.data
    },

    /** POST /api/staff/online-orders/{id}/confirm */
    async confirm(id: number, shipperId?: number): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/online-orders/${id}/confirm`,
            { shipper_id: shipperId },
        )
        return res.data.data
    },

    /** POST /api/staff/online-orders/{id}/reject (reason required) */
    async reject(id: number, reason: string): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/online-orders/${id}/reject`,
            { reason },
        )
        return res.data.data
    },

    /** POST /api/staff/online-orders/{id}/handover (shipper_id required) */
    async handover(id: number, shipperId: number): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/online-orders/${id}/handover`,
            { shipper_id: shipperId },
        )
        return res.data.data
    },
}

export const deliveries = {
    /** GET /api/staff/deliveries — đơn được giao cho shipper hiện tại (DELIVERING + COMPLETED gần đây) */
    async list(): Promise<DeliveryOrder[]> {
        const res = await apiClient.get<{ success: boolean; data: DeliveryOrder[] }>(
            '/staff/deliveries',
        )
        return res.data.data
    },

    /** POST /api/staff/deliveries/{id}/pickup — Bước 1: xác nhận đã lấy món */
    async pickup(id: number): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/deliveries/${id}/pickup`,
        )
        return res.data.data
    },

    /** POST /api/staff/deliveries/{id}/arrived — Bước 2: xác nhận đã tới điểm giao (chỉ ONLINE) */
    async arrived(id: number): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/deliveries/${id}/arrived`,
        )
        return res.data.data
    },

    /** POST /api/staff/deliveries/{id}/complete — Bước 3: giao thành công (shipper thu COD) */
    async complete(id: number): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/deliveries/${id}/complete`,
        )
        return res.data.data
    },

    /** POST /api/staff/deliveries/{id}/issue — báo cáo sự cố giao hàng */
    async reportIssue(id: number, reason: string): Promise<DeliveryOrder> {
        const res = await apiClient.post<{ success: boolean; data: DeliveryOrder }>(
            `/staff/deliveries/${id}/issue`,
            { reason },
        )
        return res.data.data
    },

    /** GET /api/staff/deliveries/{id}/route — lấy hướng dẫn đường đi bộ từ nhà hàng đến khách. */
    async route(id: number): Promise<DeliveryRoute> {
        try {
            const res = await apiClient.get<{ success: boolean; data: DeliveryRoute }>(
                `/staff/deliveries/${id}/route`,
                { timeout: 30000 },
            )
            return res.data.data
        } catch (e) {
            // Log chi tiết để debug khi modal treo.
            // eslint-disable-next-line no-console
            console.error('[route API] failed:', id, e)
            throw e
        }
    },

    /**
     * POST /api/staff/deliveries/location — gửi vị trí hiện tại (Phase 2).
     * App gọi theo chu kỳ (60s) hoặc khi shipper di chuyển > 200m.
     */
    async updateLocation(payload: {
        latitude: number
        longitude: number
        accuracy?: number | null
        is_available?: boolean
    }): Promise<void> {
        await apiClient.post('/staff/deliveries/location', payload)
    },

    /**
     * POST /api/staff/deliveries/{id}/accept — shipper xác nhận nhận đơn (Phase 2).
     * Chỉ 1 shipper nhận được; shipper khác sẽ nhận lỗi 409.
     */
    async accept(id: number): Promise<void> {
        await apiClient.post(`/staff/deliveries/${id}/accept`)
    },

    /**
     * GET /api/staff/deliveries/nearby-jobs — các đơn ONLINE đang chờ shipper nhận,
     * sắp theo khoảng cách từ vị trí hiện tại (Phase 2).
     */
    async nearbyJobs(): Promise<NearbyJob[]> {
        const res = await apiClient.get<{ success: boolean; data: NearbyJob[] }>(
            '/staff/deliveries/nearby-jobs',
        )
        return res.data.data
    },
}

/** Một bước chỉ dẫn đường. */
export interface RouteStep {
    instruction: string
    distance_m: number
    duration_s: number
    name: string
}

export interface DeliveryRoute {
    restaurant: {
        name: string
        address: string
        latitude: number
        longitude: number
    }
    customer: {
        name: string | null
        phone: string | null
        address: string | null
        latitude: number
        longitude: number
        note: string | null
    }
    route: {
        distance_km: number
        duration_min: number
        polyline: string | null
        steps: RouteStep[]
        source: 'osrm' | 'haversine_fallback'
    }
}
