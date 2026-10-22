<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Base class cho Excel export dùng PhpSpreadsheet trực tiếp.
 * Tương thích Laravel 13 (không cần maatwebsite/excel).
 */
abstract class BaseSpreadsheetExport
{
    public function __construct(
        protected array $payload,
        protected string $fromDate,
        protected string $toDate,
        protected ?string $generatedBy = null,
    ) {}

    abstract protected function sheetName(): string;
    abstract protected function reportTitle(): string;
    abstract protected function rows(): array;
    abstract protected function columnHeadings(): array;
    abstract protected function mapRow(array $row): array;
    protected function formatColumns(): array { return []; }

    /** Build Spreadsheet and return as binary string. */
    public function download(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetName());

        $cols = $this->columnHeadings();
        $lastCol = $this->colLetter(count($cols));
        $rowNum = 1;

        // ── Row 1: Big title ──
        $sheet->setCellValue('A1', 'BÁO CÁO ' . mb_strtoupper($this->reportTitle()));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'color' => ['rgb' => 'D71920']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        // ── Row 2: Date range ──
        $sheet->setCellValue('A2', "Từ ngày: {$this->fromDate}  -  Đến ngày: {$this->toDate}");
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        // ── Row 3: Generator + timestamp ──
        $sheet->setCellValue('A3', 'Xuất bởi: ' . ($this->generatedBy ?? 'Hệ thống') . '  -  Xuất lúc: ' . now()->format('d/m/Y H:i'));
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->getRowDimension(3)->setRowHeight(16);
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        $rowNum = 4; // blank row

        // ── Row 5: Column headings ──
        $rowNum++;
        foreach ($cols as $i => $label) {
            $col = $this->colLetter($i + 1);
            $sheet->setCellValue("{$col}{$rowNum}", $label);
            $sheet->getRowDimension($rowNum)->setRowHeight(22);
            $sheet->getStyle("{$col}{$rowNum}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'color' => ['rgb' => '1F2937']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            ]);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ── Data rows ──
        $rowNum++;
        foreach ($this->rows() as $row) {
            $mapped = $this->mapRow($row);
            // Section marker rows (begin with '[') — skip auto-format
            foreach ($mapped as $i => $cell) {
                $col = $this->colLetter($i + 1);
                $sheet->setCellValue("{$col}{$rowNum}", $cell);
                $sheet->getStyle("{$col}{$rowNum}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                    'alignment' => ['vertical' => 'center'],
                ]);
                if (is_string($cell) && str_starts_with($cell, '[')) {
                    // Section row: bold, bg
                    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '1F2937']],
                        'fill' => ['fillType' => 'solid', 'color' => ['rgb' => 'F3F4F6']],
                    ]);
                }
            }
            $rowNum++;
        }

        // ── Apply column number formats ──
        foreach ($this->formatColumns() as $col => $format) {
            $sheet->getStyle("{$col}5:{$col}" . ($rowNum - 1))->getNumberFormat()->setFormatCode($format);
        }

        // Freeze pane at A6
        $sheet->freezePane('A6');

        // Output
        ob_start();
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        return ob_get_clean();
    }

    protected function colLetter(int $n): string
    {
        $letters = '';
        while ($n > 0) {
            $n--;
            $letters = chr(65 + ($n % 26)) . $letters;
            $n = intdiv($n, 26);
        }
        return $letters;
    }
}
