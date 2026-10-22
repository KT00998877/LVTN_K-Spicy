<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Báo cáo')</title>
    <style>
        @page { margin: 18mm 12mm; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111;
            font-size: 11px;
            line-height: 1.4;
        }
        .report-header {
            background: #D71920;
            color: white;
            padding: 12px 16px;
            margin-bottom: 8px;
            border-radius: 4px;
        }
        .report-title { font-size: 18px; font-weight: 800; margin: 0; }
        .report-meta { font-size: 10px; color: rgba(255,255,255,0.85); margin-top: 4px; }
        .report-summary {
            background: #FDF8F0;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 12px 0;
            display: table;
            width: 100%;
            box-sizing: border-box;
        }
        .report-summary table { width: 100%; }
        .summary-card {
            display: inline-block;
            text-align: center;
            padding: 6px 12px;
            min-width: 120px;
        }
        .summary-card .label { font-size: 10px; color: #555; text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-card .value { font-size: 16px; font-weight: 800; color: #D71920; }
        h2.section-title {
            font-size: 13px;
            color: #1F2937;
            font-weight: 700;
            margin: 14px 0 6px;
            padding-bottom: 4px;
            border-bottom: 2px solid #D71920;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.data-table th {
            background: #1F2937;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
        }
        table.data-table td {
            padding: 6px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }
        table.data-table tr:nth-child(even) { background: #f9fafb; }
        table.data-table tfoot td {
            font-weight: 800;
            background: #fef3c7;
            border-top: 2px solid #D71920;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 9px;
            color: #888;
            text-align: center;
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            background: #f3f4f6;
            color: #1F2937;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <h1 class="report-title">@yield('title')</h1>
        <div class="report-meta">
            Từ ngày <strong>{{ $fromDate }}</strong> đến ngày <strong>{{ $toDate }}</strong>
            · Xuất bởi <strong>{{ $generatedBy ?? 'Hệ thống' }}</strong>
            · Xuất lúc {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    @yield('content')

    <div class="footer">
        Mì Cay Hàn Quốc - Korean Spice House · Báo cáo được tạo tự động bởi hệ thống.
    </div>
</body>
</html>