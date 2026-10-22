<?php

namespace App\Http\Controllers\Api;

use App\Exports\{BaseSpreadsheetExport, InventoryExport, OrderExport, RevenueExport, ShiftExport, TopMenuExport};
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportController — export Excel / PDF cho các báo cáo nhà hàng.
 *
 * Endpoint duy nhất:
 *   GET /api/staff/reports/export?type={type}&format={xlsx|pdf}&from=YYYY-MM-DD&to=YYYY-MM-DD[&status=...&order_type=...]
 *
 * Phân quyền:
 *   - MANAGER: tất cả 5 loại
 *   - CASHIER: revenue, orders, shift
 *
 * Trả về:
 *   - format=xlsx → binary download .xlsx
 *   - format=pdf  → binary download .pdf
 *
 * 5 loại report:
 *   - revenue:    doanh thu (theo ngày + phương thức + loại đơn)
 *   - orders:     danh sách đơn hàng
 *   - top_menu:   top món bán chạy
 *   - inventory:  tồn kho nguyên liệu
 *   - shift:      ca làm việc của cashier
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $service) {}

    public function export(Request $request)
    {
        $params = $request->validate([
            'type'       => ['required', 'string', 'in:revenue,orders,top_menu,inventory,shift'],
            'format'     => ['required', 'string', 'in:xlsx,pdf'],
            'from'       => ['nullable', 'date_format:Y-m-d'],
            'to'         => ['nullable', 'date_format:Y-m-d'],
            'status'     => ['nullable', 'string', 'max:30'],
            'order_type' => ['nullable', 'in:DINE_IN,TAKEAWAY,ONLINE'],
        ]);

        $user = $request->user();
        $roles = $user->roles()->pluck('code')->map(fn ($r) => strtoupper($r))->all();
        $this->service->authorizeType($params['type'], $roles);

        $range = $this->service->resolveRange($params['from'] ?? null, $params['to'] ?? null);
        $payload = $this->buildPayload($params['type'], $range, $params);
        $fileBase = $this->makeFilename($params['type'], $range['from_date'], $range['to_date']);

        if ($params['format'] === 'xlsx') {
            return $this->exportExcel($params['type'], $payload, $range, $user->name ?? null, $fileBase);
        }
        return $this->exportPdf($params['type'], $payload, $range, $user->name ?? null, $fileBase);
    }

    /**
     * Build payload cho loại report.
     */
    private function buildPayload(string $type, array $range, array $params): array
    {
        return match ($type) {
            'revenue' => $this->service->buildRevenue($range['from'], $range['to']),
            'orders' => $this->service->buildOrders($range['from'], $range['to'], $params['status'] ?? null, $params['order_type'] ?? null),
            'top_menu' => $this->service->buildTopMenu($range['from'], $range['to']),
            'inventory' => $this->service->buildInventory(),
            'shift' => $this->service->buildShift($range['from'], $range['to']),
        };
    }

    /** Map type → Export class. */
    private function exportClass(string $type): string
    {
        return match ($type) {
            'revenue' => RevenueExport::class,
            'orders' => OrderExport::class,
            'top_menu' => TopMenuExport::class,
            'inventory' => InventoryExport::class,
            'shift' => ShiftExport::class,
        };
    }

    /** Map type → Blade view cho PDF. */
    private function pdfView(string $type): string
    {
        return "reports.$type";
    }

    private function makeFilename(string $type, string $from, string $to): string
    {
        return sprintf('%s_%s_%s', $type, $from, $to);
    }

    private function exportExcel(string $type, array $payload, array $range, ?string $user, string $filenameBase): StreamedResponse
    {
        $class = $this->exportClass($type);
        /** @var BaseSpreadsheetExport $export */
        $export = new $class($payload, $range['from_date'], $range['to_date'], $user);
        $content = $export->download();
        $response = new StreamedResponse(function () use ($content) {
            echo $content;
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filenameBase . '.xlsx"');
        $response->headers->set('Content-Length', (string) strlen($content));
        $response->headers->set('Cache-Control', 'no-cache, private');
        return $response;
    }

    private function exportPdf(string $type, array $payload, array $range, ?string $user, string $filenameBase)
    {
        $pdf = Pdf::loadView($this->pdfView($type), [
            'payload' => $payload,
            'fromDate' => $range['from_date'],
            'toDate' => $range['to_date'],
            'generatedBy' => $user,
        ])->setPaper('a4', 'portrait');
        return $pdf->download($filenameBase . '.pdf');
    }
}