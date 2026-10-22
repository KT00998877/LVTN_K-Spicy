@extends('reports.layout')

@section('title', 'Báo cáo đơn hàng')

@section('content')
    <div class="report-summary">
        <table>
            <tr>
                <td class="summary-card">
                    <div class="label">Tổng đơn</div>
                    <div class="value">{{ $payload['count'] }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Tổng tiền</div>
                    <div class="value">{{ number_format($payload['total_amount'], 0, ',', '.') }} đ</div>
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section-title">Chi tiết đơn hàng ({{ $payload['count'] }})</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Mã đơn</th>
                <th>Trạng thái</th>
                <th>Loại</th>
                <th>Khách / Bàn</th>
                <th>Đặt lúc</th>
                <th>Hoàn thành</th>
                <th class="text-right">Tổng</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['rows'] as $r)
                <tr>
                    <td><strong>{{ $r['code'] }}</strong></td>
                    <td><span class="pill">{{ $r['status'] }}</span></td>
                    <td>{{ $r['order_type'] }}</td>
                    <td>
                        @if($r['customer_name']) {{ $r['customer_name'] }} @endif
                        @if($r['table_code']) <br><small>Bàn {{ $r['table_code'] }}</small> @endif
                    </td>
                    <td><small>{{ $r['placed_at'] }}</small></td>
                    <td><small>{{ $r['completed_at'] ?? '—' }}</small></td>
                    <td class="text-right">{{ number_format($r['total_amount'], 0, ',', '.') }} đ</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="text-right">Tổng</td>
                <td class="text-right">{{ number_format($payload['total_amount'], 0, ',', '.') }} đ</td>
            </tr>
        </tfoot>
    </table>
@endsection