<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class RevenueExport extends BaseSpreadsheetExport
{
    protected function sheetName(): string { return 'Doanh thu'; }
    protected function reportTitle(): string { return 'Doanh thu'; }

    protected function rows(): array
    {
        $rows = [];
        $rows[] = ['[TỔNG QUAN]', '', '', ''];
        $rows[] = ['Tổng doanh thu', $this->payload['total_revenue'], 'VND', ''];
        $rows[] = ['Số hóa đơn', $this->payload['invoice_count'], 'HĐ', ''];
        $rows[] = ['Số giao dịch', $this->payload['payment_count'], 'GD', ''];
        $rows[] = [];
        $rows[] = ['[DOANH THU THEO NGÀY]', '', '', ''];
        foreach ($this->payload['daily'] as $d) {
            $rows[] = [$d['date'], $d['revenue'], $d['count']];
        }
        $rows[] = [];
        $rows[] = ['[THEO PHƯƠNG THỨC THANH TOÁN]', '', '', ''];
        foreach ($this->payload['by_method'] as $m) {
            $rows[] = [$m['method'], $m['amount'], $m['count']];
        }
        $rows[] = [];
        $rows[] = ['[THEO LOẠI ĐƠN]', '', '', ''];
        foreach ($this->payload['by_order_type'] as $t) {
            $rows[] = [$t['order_type'], $t['amount'], $t['count']];
        }
        return $rows;
    }

    protected function columnHeadings(): array
    {
        return ['Chỉ tiêu', 'Giá trị', 'Số lượng', 'Đơn vị'];
    }

    protected function mapRow(array $row): array { return $row; }

    protected function formatColumns(): array
    {
        return ['B' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }
}
