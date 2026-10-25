import apiClient from './client'
import type { MenuItemCard } from '@/types/home'

export async function fetchFavorites(): Promise<MenuItemCard[]> {
    const { data } = await apiClient.get<{ success: boolean; data: MenuItemCard[] }>('/favorites')
    return data.data
}

export async function addFavorite(menuItemId: number): Promise<void> {
    await apiClient.put(`/favorites/${menuItemId}`)
}

export async function removeFavorite(menuItemId: number): Promise<void> {
    await apiClient.delete(`/favorites/${menuItemId}`)
}
