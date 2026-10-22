<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrderExport extends BaseSpreadsheetExport
{
    protected function sheetName(): string { return 'Đơn hàng'; }
    protected function reportTitle(): string { return 'Đơn hàng'; }

    protected function rows(): array
    {
        $rows = [];
        $rows[] = ['[TỔNG QUAN]', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Tổng đơn', $this->payload['count'], 'đơn', '', '', '', '', '', '', ''];
        $rows[] = ['Tổng tiền', $this->payload['total_amount'], 'VND', '', '', '', '', '', '', ''];
        $rows[] = [];
        foreach ($this->payload['rows'] as $r) {
            $rows[] = [
                $r['code'], $r['status'], $r['order_type'],
                $r['customer_name'] ?? '', $r['waiter_name'] ?? '', $r['table_code'] ?? '',
                $r['placed_at'], $r['completed_at'] ?? '',
                $r['subtotal'], $r['total_amount'],
            ];
        }
        return $rows;
    }

    protected function columnHeadings(): array
    {
        return ['Mã đơn', 'Trạng thái', 'Loại đơn', 'Khách hàng', 'Phục vụ', 'Bàn',
            'Đặt lúc', 'Hoàn thành', 'Tạm tính', 'Tổng tiền'];
    }

    protected function mapRow(array $row): array { return $row; }

    protected function formatColumns(): array
    {
        return [
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }
}
