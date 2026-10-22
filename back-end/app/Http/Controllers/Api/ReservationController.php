<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => array_merge(config('reservations'), [
            'server_now' => CarbonImmutable::now(config('reservations.timezone'))->toIso8601String(),
        ])]);
    }

    public function store(StoreReservationRequest $request, ReservationService $service): JsonResponse
    {
        $reservation = $service->create($request->validated());
        return response()->json(['success' => true, 'data' => [
            'id' => $reservation->id,
            'customer_name' => $reservation->customer_name,
            'guest_count' => $reservation->guest_count,
            'reserved_date' => $reservation->reserved_date->format('Y-m-d'),
            'reserved_time' => substr($reservation->reserved_time, 0, 5),
            'status' => $reservation->status,
        ]], $reservation->wasRecentlyCreated ? 201 : 200);
    }
}
