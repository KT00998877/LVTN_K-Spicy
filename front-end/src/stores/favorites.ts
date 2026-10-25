import { defineStore } from 'pinia'
import { addFavorite, fetchFavorites, removeFavorite } from '@/api/favorites'
import type { MenuItemCard } from '@/types/home'

export const useFavoritesStore = defineStore('favorites', {
    state: () => ({
        items: [] as MenuItemCard[],
        loading: false,
        loaded: false,
        error: null as string | null,
        pendingIds: [] as number[],
    }),

    getters: {
        favoriteIds: (state): number[] => state.items.map(item => item.id),
    },

    actions: {
        async load(force = false): Promise<void> {
            if (this.loading || (this.loaded && !force)) return
            this.loading = true
            this.error = null
            try {
                this.items = await fetchFavorites()
                this.loaded = true
            } catch (error) {
                this.error = error instanceof Error ? error.message : 'Không thể tải món yêu thích.'
            } finally {
                this.loading = false
            }
        },

        async toggle(item: MenuItemCard): Promise<boolean> {
            if (this.pendingIds.includes(item.id)) return this.favoriteIds.includes(item.id)
            this.pendingIds.push(item.id)
            this.error = null
            const wasFavorite = this.favoriteIds.includes(item.id)

            try {
                if (wasFavorite) {
                    await removeFavorite(item.id)
                    this.items = this.items.filter(favorite => favorite.id !== item.id)
                } else {
                    await addFavorite(item.id)
                    this.items = [item, ...this.items]
                }
                this.loaded = true
                return !wasFavorite
            } catch (error) {
                this.error = error instanceof Error ? error.message : 'Không thể cập nhật món yêu thích.'
                throw error
            } finally {
                this.pendingIds = this.pendingIds.filter(id => id !== item.id)
            }
        },
    },
})
