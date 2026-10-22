@extends('reports.layout')

@section('title', 'Báo cáo món bán chạy')

@section('content')
    <div class="report-summary">
        <table>
            <tr>
                <td class="summary-card">
                    <div class="label">Số món đã bán</div>
                    <div class="value">{{ count($payload['rows']) }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Tổng SL bán</div>
                    <div class="value">{{ collect($payload['rows'])->sum('sold_qty') }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Tổng doanh thu</div>
                    <div class="value">{{ number_format(collect($payload['rows'])->sum('revenue'), 0, ',', '.') }} đ</div>
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section-title">Top {{ count($payload['rows']) }} món bán chạy nhất</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th>Tên món</th>
                <th>Danh mục</th>
                <th class="text-center">SL bán</th>
                <th class="text-center">Số đơn</th>
                <th class="text-right">Doanh thu</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['rows'] as $i => $r)
                <tr>
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td><strong>{{ $r['item_name'] }}</strong></td>
                    <td>{{ $r['category_name'] ?? '—' }}</td>
                    <td class="text-center"><strong>{{ $r['sold_qty'] }}</strong></td>
                    <td class="text-center">{{ $r['order_count'] }}</td>
                    <td class="text-right">{{ number_format($r['revenue'], 0, ',', '.') }} đ</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection