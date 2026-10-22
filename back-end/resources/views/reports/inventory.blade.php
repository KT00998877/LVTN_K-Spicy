@extends('reports.layout')

@section('title', 'Báo cáo tồn ghi nhận')

@section('content')
    <div class="report-summary">
        <table>
            <tr>
                <td class="summary-card">
                    <div class="label">Tổng nguyên liệu</div>
                    <div class="value">{{ $payload['total_items'] }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Sắp hết</div>
                    <div class="value">{{ $payload['low_stock_count'] }}</div>
                </td>
                <td class="summary-card">
                    <div class="label">Tổng giá trị</div>
                    <div class="value">{{ number_format($payload['total_value'], 0, ',', '.') }} đ</div>
                </td>
            </tr>
        </table>
    </div>

    <p>Số tồn lấy từ lần bếp chốt gần nhất cộng các chứng từ kho đã ghi, chưa trừ nguyên liệu nấu sau lần chốt. Đối chiếu tồn thực tế trong sổ kho ngày.</p>
    <h2 class="section-title">Chi tiết tồn ghi nhận</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Mã</th>
                <th>Tên nguyên liệu</th>
                <th>Danh mục</th>
                <th class="text-right">Tồn ghi nhận</th>
                <th>Đơn vị</th>
                <th class="text-right">Đơn giá</th>
                <th class="text-right">Giá trị</th>
                <th class="text-center">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['rows'] as $r)
                <tr>
                    <td>{{ $r['code'] }}</td>
                    <td><strong>{{ $r['name'] }}</strong></td>
                    <td>{{ $r['category_name'] ?? '—' }}</td>
                    <td class="text-right">{{ number_format($r['stock'], 2, ',', '.') }}</td>
                    <td>{{ $r['unit'] }}</td>
                    <td class="text-right">{{ number_format($r['cost_per_unit'], 0, ',', '.') }} đ</td>
                    <td class="text-right">{{ number_format($r['value'], 0, ',', '.') }} đ</td>
                    <td class="text-center">
                        @if ($r['is_low_stock'])
                            <span class="pill" style="background:#fee2e2;color:#991b1b;">Sắp hết</span>
                        @else
                            <span class="pill" style="background:#d1fae5;color:#065f46;">Ổn</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="text-right">Giá trị tồn ghi nhận (ước tính)</td>
                <td class="text-right">{{ number_format($payload['total_value'], 0, ',', '.') }} đ</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
@endsection
