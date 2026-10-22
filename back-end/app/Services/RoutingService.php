<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RoutingService — Tra khoảng cách và hướng dẫn đường đi bằng OSRM (OpenStreetMap).
 *
 * - OSRM public demo endpoint (router.project-osrm.org) — không cần API key.
 * - Đường đi cho ô tô (car profile) → phù hợp shipper giao hàng.
 * - Có cache 24h để giảm tải cho OSRM.
 * - LUÔN fallback Haversine nếu OSRM lỗi / chậm (>5s) — đảm bảo UX không bao giờ bị treo.
 * - Dùng `withoutVerifying()` để tránh lỗi SSL cert ở một số máy local.
 *
 * Trả về:
 *   - distance_km:   khoảng cách đường bộ (km, làm tròn 3 chữ số)
 *   - duration_min:  thời gian ước tính (phút, làm tròn lên)
 *   - geometry:      polyline mã hoá (Google encoded polyline) — dùng để vẽ lên bản đồ
 *   - steps:         danh sách chỉ dẫn tiếng Việt cho shipper
 */
class RoutingService
{
    /** OSRM demo public server (giới hạn rate ~1req/s, không dùng cho production lớn). */
    private const OSRM_URL = 'https://router.project-osrm.org';

    /** Cache TTL 7 ngày — OSRM data ổn định, không cần refresh thường xuyên. */
    private const CACHE_TTL = 604800;

    /**
     * Lấy khoảng cách đường bộ giữa 2 toạ độ (Haversine fallback nếu OSRM lỗi).
     *
     * @return array{distance_km: float, duration_min: int, source: string}
     */
    public function distance(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $cacheKey = sprintf('route:dist:%.4f,%.4f->%.4f,%.4f', $fromLat, $fromLng, $toLat, $toLng);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($fromLat, $fromLng, $toLat, $toLng) {
            // Tính Haversine trước — dùng làm fallback nếu OSRM lỗi.
            $km = $this->haversineKm($fromLat, $fromLng, $toLat, $toLng);
            $duration = (int) ceil($km / 25 * 60);

            try {
                $url = sprintf(
                    '%s/route/v1/car/%.6f,%.6f;%.6f,%.6f?overview=false',
                    self::OSRM_URL,
                    $fromLng, $fromLat,
                    $toLng, $toLat,
                );
                // OSRM public server thường trả 1-5s; có khi timeout 30s+ khi quá tải.
                // Timeout NGẮN (5s) để UX nhanh — fallback Haversine nếu quá chậm.
                $response = Http::timeout(5)->connectTimeout(3)->retry(1, 200)->withoutVerifying()->get($url);

                if ($response->successful() && $response->json('code') === 'Ok') {
                    $route = $response->json('routes.0');
                    if ($route && isset($route['distance'], $route['duration'])) {
                        return [
                            'distance_km' => round($route['distance'] / 1000, 3),
                            'duration_min' => (int) ceil($route['duration'] / 60),
                            'source' => 'osrm',
                        ];
                    }
                }
                Log::info('OSRM distance fallback (non-OK): ' . $response->status() . ' body=' . substr((string) $response->body(), 0, 200));
            } catch (\Throwable $e) {
                Log::info('OSRM distance fallback (exception): '.$e->getMessage());
            }

            // Fallback Haversine — đảm bảo flow luôn chạy.
            return [
                'distance_km' => $km,
                'duration_min' => $duration,
                'source' => 'haversine_fallback',
            ];
        });
    }

    /**
     * Lấy full route để vẽ lên bản đồ + chỉ dẫn tiếng Việt.
     *
     * @return array{
     *     distance_km: float,
     *     duration_min: int,
     *     polyline: string|null,   // Google encoded polyline precision 5
     *     steps: array<int, array{instruction: string, distance_m: int, duration_s: int, name: string}>,
     *     source: string
     * }
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $cacheKey = sprintf('route:full:%.4f,%.4f->%.4f,%.4f', $fromLat, $fromLng, $toLat, $toLng);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($fromLat, $fromLng, $toLat, $toLng) {
            // Fallback Haversine pre-computed — trả ngay nếu OSRM chậm/lỗi.
            $km = $this->haversineKm($fromLat, $fromLng, $toLat, $toLng);
            $duration = (int) ceil($km / 25 * 60);

            try {
                $url = sprintf(
                    '%s/route/v1/car/%.6f,%.6f;%.6f,%.6f?overview=full&geometries=polyline&steps=true&annotations=false',
                    self::OSRM_URL,
                    $fromLng, $fromLat,
                    $toLng, $toLat,
                );
                // OSRM với overview=full + steps=true trả về rất nặng; timeout 8s để UX nhanh.
                $response = Http::timeout(8)->connectTimeout(3)->retry(1, 200)->withoutVerifying()->get($url);

                if ($response->successful() && $response->json('code') === 'Ok') {
                    $route = $response->json('routes.0');
                    if ($route) {
                        $steps = [];
                        foreach (($route['legs'][0]['steps'] ?? []) as $step) {
                            if (($step['maneuver']['type'] ?? '') === 'depart') continue;
                            $steps[] = [
                                'instruction' => $this->translateStep($step),
                                'distance_m' => (int) $step['distance'],
                                'duration_s' => (int) $step['duration'],
                                'name' => $step['name'] ?? '',
                            ];
                        }
                        return [
                            'distance_km' => round($route['distance'] / 1000, 3),
                            'duration_min' => (int) ceil($route['duration'] / 60),
                            'polyline' => $route['geometry'] ?? null,
                            'steps' => $steps,
                            'source' => 'osrm',
                        ];
                    }
                }
                Log::info('OSRM route fallback (non-OK): ' . $response->status() . ' body=' . substr((string) $response->body(), 0, 200));
            } catch (\Throwable $e) {
                Log::info('OSRM route fallback (exception): '.$e->getMessage());
            }

            // Fallback: trả distance/duration từ Haversine, polyline/steps rỗng
            // → frontend vẫn vẽ được đường thẳng nối 2 điểm (xem ShipperRouteMap).
            return [
                'distance_km' => $km,
                'duration_min' => $duration,
                'polyline' => null,
                'steps' => [],
                'source' => 'haversine_fallback',
            ];
        });
    }

    /**
     * Dịch 1 maneuver từ OSRM (tiếng Anh) → tiếng Việt dễ hiểu cho shipper.
     */
    private function translateStep(array $step): string
    {
        $maneuver = $step['maneuver'] ?? [];
        $type = $maneuver['type'] ?? '';
        $modifier = $maneuver['modifier'] ?? '';
        $name = $step['name'] ?? '';
        $road = $name ? " vào đường {$name}" : '';

        return match ($type) {
            'turn' => match ($modifier) {
                'left' => "Rẽ trái{$road}",
                'right' => "Rẽ phải{$road}",
                'sharp left' => "Rẽ trái gấp{$road}",
                'sharp right' => "Rẽ phải gấp{$road}",
                'slight left' => "Chếch sang trái{$road}",
                'slight right' => "Chếch sang phải{$road}",
                'straight' => "Đi thẳng{$road}",
                'uturn' => "Quay đầu xe{$road}",
                default => "Rẽ{$road}",
            },
            'new name' => "Tiếp tục đi{$road}",
            'depart' => "Khởi hành{$road}",
            'arrive' => 'Đến điểm đích (bên phải)',
            'merge' => "Nhập vào{$road}",
            'on ramp' => "Đi lên đường nhập{$road}",
            'off ramp' => "Đi ra đường rẽ{$road}",
            'fork' => match ($modifier) {
                'left' => "Rẽ chếch trái{$road}",
                'right' => "Rẽ chếch phải{$road}",
                default => "Tới ngã ba{$road}",
            },
            'end of road' => match ($modifier) {
                'left' => "Cuối đường rẽ trái{$road}",
                'right' => "Cuối đường rẽ phải{$road}",
                default => "Cuối đường{$road}",
            },
            'continue' => "Đi tiếp{$road}",
            'roundabout' => "Vào vòng xoay, đi theo lối ra{$road}",
            'rotary' => "Vào bùng binh, đi theo lối ra{$road}",
            'roundabout turn' => "Vào vòng xoay, rẽ{$road}",
            'notification' => "Lưu ý{$road}",
            default => "Đi tiếp{$road}",
        };
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return round(6371 * 2 * asin(sqrt(min(1, max(0, $a)))), 3);
    }
}
