<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ShiftExport extends BaseSpreadsheetExport
{
    protected function sheetName(): string { return 'Ca làm việc'; }
    protected function reportTitle(): string { return 'Ca làm việc (Cashier)'; }

    protected function rows(): array
    {
        $rows = [];
        $rows[] = ['[TỔNG QUAN]', '', '', '', '', '', '', '', ''];
        $rows[] = ['Tổng ca đã chốt', $this->payload['count'], 'ca', '', '', '', '', '', ''];
        $rows[] = [];
        foreach ($this->payload['rows'] as $r) {
            $rows[] = [
                $r['code'], $r['cashier_name'] ?? '',
                $r['from_at'], $r['to_at'],
                $r['invoice_count'], $r['payment_count'],
                $r['total_collected'], $r['difference'],
                $r['opening_cash'], $r['expected_cash'], $r['actual_cash'],
            ];
        }
        return $rows;
    }

    protected function columnHeadings(): array
    {
        return ['Mã BC', 'Thu ngân', 'Bắt đầu', 'Kết thúc',
            'HĐ', 'GD', 'Tổng thu', 'Chênh lệch',
            'Tiền đầu ca', 'Kỳ vọng', 'Thực tế'];
    }

    protected function mapRow(array $row): array { return $row; }

    protected function formatColumns(): array
    {
        return [
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'K' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }
}
