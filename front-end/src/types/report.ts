/**
 * Types cho chức năng export báo cáo.
 */

/** Loại báo cáo. */
export type ReportType =
    | 'revenue'     // Doanh thu (MANAGER + CASHIER)
    | 'orders'      // Đơn hàng (MANAGER + CASHIER)
    | 'top_menu'    // Món bán chạy (MANAGER)
    | 'inventory'   // Tồn kho nguyên liệu (MANAGER)
    | 'shift'       // Ca làm việc (MANAGER + CASHIER — chỉ cashier của mình)

/** Format file xuất ra. */
export type ReportFormat = 'xlsx' | 'pdf'

export interface ReportExportParams {
    type: ReportType
    format: ReportFormat
    from?: string        // YYYY-MM-DD
    to?: string          // YYYY-MM-DD
    status?: string      // Filter cho orders
    order_type?: 'DINE_IN' | 'TAKEAWAY' | 'ONLINE'  // Filter cho orders
}

/** Cấu hình UI cho từng loại report. */
export interface ReportTypeOption {
    code: ReportType
    label: string
    description: string
    allowedRoles: Array<'MANAGER' | 'CASHIER'>
    needsDateRange: boolean
}