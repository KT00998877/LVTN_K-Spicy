<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class InventoryExport extends BaseSpreadsheetExport
{
    protected function sheetName(): string { return 'Tồn kho'; }
    protected function reportTitle(): string { return 'Tồn kho nguyên liệu'; }

    protected function rows(): array
    {
        $rows = [];
        $rows[] = ['[TỔNG QUAN]', '', '', '', '', '', ''];
        $rows[] = ['Tổng nguyên liệu', $this->payload['total_items'], '', '', '', '', ''];
        $rows[] = ['Nguyên liệu sắp hết', $this->payload['low_stock_count'], '', '', '', '', ''];
        $rows[] = ['Tổng giá trị tồn', $this->payload['total_value'], 'VND', '', '', '', ''];
        $rows[] = [];
        foreach ($this->payload['rows'] as $r) {
            $rows[] = [
                $r['id'], $r['code'], $r['name'],
                $r['category_name'] ?? '', $r['stock'],
                $r['unit'], $r['value'],
            ];
        }
        return $rows;
    }

    protected function columnHeadings(): array
    {
        return ['ID', 'Mã', 'Tên nguyên liệu', 'Danh mục', 'Tồn kho', 'Đơn vị', 'Giá trị (VND)'];
    }

    protected function mapRow(array $row): array { return $row; }

    protected function formatColumns(): array
    {
        return ['G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }
}
