<?php

return [
    'restaurant_address' => 'Đại học Cần Thơ, Khu II, đường 3/2, phường Ninh Kiều, TP. Cần Thơ',
    // Điểm xuất phát trong khuôn viên Khu II; có thể điều chỉnh theo vị trí quán.
    'latitude' => (float) env('RESTAURANT_LATITUDE', 10.0308),
    'longitude' => (float) env('RESTAURANT_LONGITUDE', 105.7689),
    'base_fee' => 15000,
    'base_km' => 2,
    'per_km' => 5000,
    'max_km' => 15,
    'point_value' => 1000,
    'max_discount_percent' => 30,
];
