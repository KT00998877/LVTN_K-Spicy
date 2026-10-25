import apiClient from './client'
import type { ReservationForm, ReservationOptions } from '@/utils/reservation'

export interface ReservationReceipt {
    id: number
    customer_name: string
    guest_count: number
    reserved_date: string
    reserved_time: string
    status: string
}

export type ReservationStatus = 'PENDING' | 'CONFIRMED' | 'ARRIVED' | 'CANCELLED' | 'COMPLETED'

export interface CustomerReservation extends ReservationReceipt {
    customer_phone: string
    note: string | null
    status: ReservationStatus
    table: {
        id: number
        code: string
        name: string | null
        capacity: number
        location: string | null
    } | null
    can_edit: boolean
    can_cancel: boolean
}

export interface ReservationListResponse {
    success: boolean
    data: CustomerReservation[]
    meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export type UpdateReservationPayload = Pick<
    ReservationForm,
    'guest_count' | 'reserved_date' | 'reserved_time' | 'location' | 'note' | 'table_id'
>

export async function getReservationOptions(): Promise<ReservationOptions> {
    return (await apiClient.get<{ data: ReservationOptions }>('/reservations/options')).data.data
}

export async function createReservation(form: ReservationForm, requestKey: string): Promise<ReservationReceipt> {
    const response = await apiClient.post<{ data: ReservationReceipt }>('/reservations', { ...form, request_key: requestKey })
    if (!response.data.data?.id) throw new Error('Thiếu thông tin xác nhận từ nhà hàng.')
    return response.data.data
}

export async function fetchMyReservations(): Promise<ReservationListResponse> {
    return (await apiClient.get<ReservationListResponse>('/reservations/me')).data
}

export async function updateMyReservation(id: number, payload: UpdateReservationPayload): Promise<CustomerReservation> {
    return (await apiClient.patch<{ success: boolean; data: CustomerReservation }>(`/reservations/me/${id}`, payload)).data.data
}

export async function cancelMyReservation(id: number): Promise<CustomerReservation> {
    return (await apiClient.post<{ success: boolean; data: CustomerReservation }>(`/reservations/me/${id}/cancel`)).data.data
}
