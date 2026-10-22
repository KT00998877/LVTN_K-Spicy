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

export async function getReservationOptions(): Promise<ReservationOptions> {
    return (await apiClient.get<{ data: ReservationOptions }>('/reservations/options')).data.data
}

export async function createReservation(form: ReservationForm, requestKey: string): Promise<ReservationReceipt> {
    const response = await apiClient.post<{ data: ReservationReceipt }>('/reservations', { ...form, request_key: requestKey })
    if (!response.data.data?.id) throw new Error('Thiếu thông tin xác nhận từ nhà hàng.')
    return response.data.data
}
