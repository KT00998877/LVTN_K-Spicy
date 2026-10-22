<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class TopMenuExport extends BaseSpreadsheetExport
{
    protected function sheetName(): string { return 'Món bán chạy'; }
    protected function reportTitle(): string { return 'Món bán chạy'; }

    protected function rows(): array
    {
        $rows = [];
        foreach ($this->payload['rows'] as $r) {
            $rows[] = [
                $r['menu_item_id'], $r['item_name'],
                $r['category_name'] ?? '', $r['sold_qty'],
                $r['order_count'], $r['revenue'],
            ];
        }
        return $rows;
    }

    protected function columnHeadings(): array
    {
        return ['ID', 'Tên món', 'Danh mục', 'SL bán', 'Số đơn', 'Doanh thu'];
    }

    protected function mapRow(array $row): array { return $row; }

    protected function formatColumns(): array
    {
        return ['F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }
}
