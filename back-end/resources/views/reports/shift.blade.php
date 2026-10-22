@extends('reports.layout')

@section('title', 'Báo cáo ca làm việc (Cashier)')

@section('content')
    <div class="report-summary">
        <table>
            <tr>
                <td class="summary-card">
                    <div class="label">Tổng ca đã chốt</div>
                    <div class="value">{{ $payload['count'] }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Tổng thu</div>
                    <div class="value">{{ number_format(collect($payload['rows'])->sum('total_collected'), 0, ',', '.') }} đ</div>
                </td>
                <td class="summary-card">
                    <div class="label">Chênh lệch</div>
                    <div class="value">{{ number_format(collect($payload['rows'])->sum('difference'), 0, ',', '.') }} đ</div>
                </td>
            </tr>
        </table>
    </div>

    <h2 class="section-title">Chi tiết ca làm việc</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Mã BC</th>
                <th>Thu ngân</th>
                <th>Bắt đầu</th>
                <th>Kết thúc</th>
                <th class="text-center">HĐ / GD</th>
                <th class="text-right">Tổng thu</th>
                <th class="text-right">Tiền đầu ca</th>
                <th class="text-right">Kỳ vọng</th>
                <th class="text-right">Thực tế</th>
                <th class="text-right">Chênh lệch</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['rows'] as $r)
                <tr>
                    <td><strong>{{ $r['code'] }}</strong></td>
                    <td>{{ $r['cashier_name'] ?? '—' }}</td>
                    <td><small>{{ $r['from_at'] }}</small></td>
                    <td><small>{{ $r['to_at'] }}</small></td>
                    <td class="text-center">{{ $r['invoice_count'] }} / {{ $r['payment_count'] }}</td>
                    <td class="text-right">{{ number_format($r['total_collected'], 0, ',', '.') }} đ</td>
                    <td class="text-right">{{ number_format($r['opening_cash'], 0, ',', '.') }} đ</td>
                    <td class="text-right">{{ number_format($r['expected_cash'], 0, ',', '.') }} đ</td>
                    <td class="text-right">{{ number_format($r['actual_cash'], 0, ',', '.') }} đ</td>
                    <td class="text-right" style="color: {{ $r['difference'] == 0 ? '#15803d' : '#b91c1c' }};">
                        {{ number_format($r['difference'], 0, ',', '.') }} đ
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection