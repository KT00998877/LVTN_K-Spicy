export interface ShiftReport {
    report_date?: string | null; has_new_data?: boolean;
    id?: number; code?: string; from: string; to: string; closed_at?: string;
    cashier_id: number; cashier_name: string; total_collected: number; invoice_count: number; payment_count: number;
    by_method: Record<string, number>; cashier_cash: number; opening_cash: number; cash_in: number; cash_out: number;
    expected_cash: number; actual_cash: number; difference: number; note: string;
    payments: { id: number; invoice_code: string; method: string; amount: number; collector: string | null; processed_at: string }[];
}
