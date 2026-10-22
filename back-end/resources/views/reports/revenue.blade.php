@extends('reports.layout')

@section('title', 'Báo cáo doanh thu')

@section('content')
    <div class="report-summary">
        <table>
            <tr>
                <td class="summary-card">
                    <div class="label">Tổng doanh thu</div>
                    <div class="value">{{ number_format($payload['total_revenue'], 0, ',', '.') }} đ</div>
                </td>
                <td class="summary-card">
                    <div class="label">Số hóa đơn</div>
                    <div class="value">{{ $payload['invoice_count'] }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Số giao dịch</div>
                    <div class="value">{{ $payload['payment_count'] }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Trung bình / HĐ</div>
                    <div class="value">
                        {{ $payload['invoice_count'] > 0 ? number_format($payload['total_revenue'] / $payload['invoice_count'], 0, ',', '.') : 0 }} đ
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section-title">Theo ngày</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Ngày</th>
                <th class="text-right">Doanh thu</th>
                <th class="text-center">Số HĐ</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['daily'] as $d)
                <tr>
                    <td>{{ $d['date'] }}</td>
                    <td class="text-right">{{ number_format($d['revenue'], 0, ',', '.') }} đ</td>
                    <td class="text-center">{{ $d['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Tổng</td>
                <td class="text-right">{{ number_format($payload['total_revenue'], 0, ',', '.') }} đ</td>
                <td class="text-center">{{ $payload['invoice_count'] }}</td>
            </tr>
        </tfoot>
    </table>

    <h2 class="section-title">Theo phương thức thanh toán</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Phương thức</th>
                <th class="text-right">Doanh thu</th>
                <th class="text-center">Số GD</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['by_method'] as $m)
                <tr>
                    <td>{{ $m['method'] }}</td>
                    <td class="text-right">{{ number_format($m['amount'], 0, ',', '.') }} đ</td>
                    <td class="text-center">{{ $m['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section-title">Theo loại đơn</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Loại đơn</th>
                <th class="text-right">Doanh thu</th>
                <th class="text-center">Số đơn</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['by_order_type'] as $t)
                <tr>
                    <td>{{ $t['order_type'] }}</td>
                    <td class="text-right">{{ number_format($t['amount'], 0, ',', '.') }} đ</td>
                    <td class="text-center">{{ $t['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection