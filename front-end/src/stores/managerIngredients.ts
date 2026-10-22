import { defineStore } from 'pinia'
import {
    ingredientsApi,
    recipesApi,
    type Ingredient,
    type IngredientListResponse,
    type IngredientPayload,
    type RecipePayload,
    type RecipeResponse,
} from '@/api/ingredients'
import { beginRead, markDataWrite } from '@/utils/realtime'

interface State {
    list: Ingredient[]
    meta: IngredientListResponse['meta'] | null
    loading: boolean
    saving: boolean
    error: string | null

    // Detail map for show() per id — populated lazily
    detail: Record<number, Ingredient>

    // Recipe cache per menuItemId
    recipes: Record<number, RecipeResponse>
}

export const useIngredientsStore = defineStore('manager-ingredients', {
    state: (): State => ({
        list: [],
        meta: null,
        loading: false,
        saving: false,
        error: null,
        detail: {},
        recipes: {},
    }),

    getters: {
        byId: (state) => (id: number): Ingredient | undefined => state.detail[id],
        recipeOf: (state) => (menuItemId: number): RecipeResponse | undefined => state.recipes[menuItemId],
    },

    actions: {
        reset() {
            this.list = []
            this.meta = null
            this.error = null
            this.detail = {}
        },

        async fetchList(params: Parameters<typeof ingredientsApi.list>[0] = {}, quiet = false): Promise<void> {
            if (!quiet) this.loading = true
            if (!quiet) this.error = null
            const fresh = beginRead(this, 'list', quiet)
            try {
                const res = await ingredientsApi.list(params)
                if (!fresh()) return
                this.list = res.data
                this.meta = res.meta
            } catch (err) {
                if (quiet) throw err
                this.error = err instanceof Error ? err.message : 'Không tải được danh sách nguyên liệu.'
            } finally {
                if (!quiet && fresh()) this.loading = false
            }
        },

        async fetchDetail(id: number): Promise<Ingredient> {
            const ing = await ingredientsApi.get(id)
            this.detail[id] = ing
            return ing
        },

        async create(payload: IngredientPayload): Promise<Ingredient> {
            this.saving = true
            this.error = null
            markDataWrite()
            try {
                const ing = await ingredientsApi.create(payload)
                this.list.unshift(ing)
                this.detail[ing.id] = ing
                return ing
            } catch (err: unknown) {
                this.error = (err as Error).message
                throw err
            } finally {
                this.saving = false
            }
        },

        async update(id: number, payload: IngredientPayload): Promise<Ingredient> {
            this.saving = true
            this.error = null
            markDataWrite()
            try {
                const ing = await ingredientsApi.update(id, payload)
                const idx = this.list.findIndex((x) => x.id === id)
                if (idx >= 0) this.list[idx] = ing
                this.detail[id] = ing
                return ing
            } catch (err: unknown) {
                this.error = (err as Error).message
                throw err
            } finally {
                this.saving = false
            }
        },

        async fetchRecipe(menuItemId: number, quiet = false): Promise<RecipeResponse> {
            const fresh = beginRead(this, `recipe:${menuItemId}`, quiet)
            try {
                const data = await recipesApi.get(menuItemId)
                if (!fresh()) return this.recipes[menuItemId] || data
                this.recipes[menuItemId] = data
                return data
            } catch (err) {
                if (quiet) throw err
                throw err
            }
        },

        async replaceRecipe(menuItemId: number, payload: RecipePayload): Promise<RecipeResponse> {
            this.saving = true
            markDataWrite()
            try {
                const data = await recipesApi.replace(menuItemId, payload)
                this.recipes[menuItemId] = data
                return data
            } finally {
                this.saving = false
            }
        },

        async blockingReferences(id: number) {
            return ingredientsApi.blockingReferences(id)
        },
    },
})
