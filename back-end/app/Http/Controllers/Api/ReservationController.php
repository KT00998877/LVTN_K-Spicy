<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Models\Reservation;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $data = $request->validated();
        $data['customer_id'] = $request->user('sanctum')?->id;
        $reservation = $service->create($data);

        return response()->json([
            'success' => true,
            'data' => $this->format($reservation),
        ], $reservation->wasRecentlyCreated ? 201 : 200);
    }

    public function mine(Request $request): JsonResponse
    {
        $page = Reservation::query()
            ->where('customer_id', $request->user()->id)
            ->with('table:id,code,name,capacity,location')
            ->orderByDesc('reserved_date')
            ->orderByDesc('reserved_time')
            ->paginate(min(50, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (Reservation $reservation) => $this->format($reservation))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function update(UpdateReservationRequest $request, int $id, ReservationService $service): JsonResponse
    {
        $reservation = $service->updateForCustomer($request->user(), $id, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->format($reservation),
        ]);
    }

    public function cancel(Request $request, int $id, ReservationService $service): JsonResponse
    {
        $reservation = $service->cancelForCustomer($request->user(), $id);

        return response()->json([
            'success' => true,
            'data' => $this->format($reservation),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(Reservation $reservation): array
    {
        $reservation->loadMissing('table:id,code,name,capacity,location');

        return [
            'id' => (int) $reservation->id,
            'customer_name' => $reservation->customer_name,
            'customer_phone' => $reservation->customer_phone,
            'guest_count' => (int) $reservation->guest_count,
            'reserved_date' => $reservation->reserved_date->format('Y-m-d'),
            'reserved_time' => substr($reservation->reserved_time, 0, 5),
            'note' => $reservation->note,
            'status' => $reservation->status,
            'table' => $reservation->table ? [
                'id' => (int) $reservation->table->id,
                'code' => $reservation->table->code,
                'name' => $reservation->table->name,
                'capacity' => (int) $reservation->table->capacity,
                'location' => $reservation->table->location,
            ] : null,
            'can_edit' => $reservation->status === 'PENDING',
            'can_cancel' => in_array($reservation->status, ['PENDING', 'CONFIRMED'], true),
        ];
    }
}
