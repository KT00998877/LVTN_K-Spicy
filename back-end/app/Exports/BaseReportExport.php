<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Base class cho các Export - cung cấp styles mặc định (header bold, freeze pane, auto-size).
 * Mỗi report type sẽ extend class này và override collection/headings/map.
 */
abstract class BaseReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, WithEvents, WithColumnFormatting
{
    public function __construct(
        protected array $payload,
        protected string $fromDate,
        protected string $toDate,
        protected ?string $generatedBy = null,
    ) {}

    abstract protected function rows(): array;

    /** Heading cho bảng. */
    abstract protected function columnHeadings(): array;

    /** Map 1 dòng → cells. */
    abstract protected function mapRow(array $row): array;

    /** Format cột cho Excel (vd: cột D là số tiền). */
    protected function formatColumns(): array
    {
        return [];
    }

    /** Tên sheet. */
    protected function sheetName(): string
    {
        return 'BaoCao';
    }

    /** Tiêu đề lớn cho báo cáo. */
    abstract protected function reportTitle(): string;

    public function collection()
    {
        return collect($this->rows());
    }

    public function title(): string
    {
        return $this->sheetName();
    }

    /** Header rows (4 dòng đầu: tiêu đề + meta + trống), sau đó là column headings. */
    public function headings(): array
    {
        return [
            ['BÁO CÁO ' . strtoupper($this->reportTitle())],
            ['Từ ngày: ' . $this->fromDate . '  -  Đến ngày: ' . $this->toDate],
            ['Xuất bởi: ' . ($this->generatedBy ?? 'Hệ thống') . '  -  Xuất lúc: ' . now()->format('d/m/Y H:i')],
            [],
            $this->columnHeadings(),
        ];
    }

    public function map($row): array
    {
        return $this->mapRow($row);
    }

    public function columnFormats(): array
    {
        return $this->formatColumns();
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'color' => ['rgb' => 'D71920']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center']],
            2 => ['font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '555555']]],
            3 => ['font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '555555']]],
            5 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => 'solid', 'color' => ['rgb' => '1F2937']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $headings = $this->columnHeadings();
                $lastCol = $this->columnLetter(count($headings));
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->mergeCells("A3:{$lastCol}3");
                $sheet->getRowDimension(1)->setRowHeight(28);
                $sheet->getRowDimension(5)->setRowHeight(30);

                foreach (range('A', $lastCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
                $sheet->freezePane('A6');

                $rowCount = count($this->rows()) + 5;
                $range = "A5:{$lastCol}{$rowCount}";
                $sheet->getStyle($range)->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'D1D5DB']],
                    ],
                ]);
            },
        ];
    }

    protected function columnLetter(int $n): string
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