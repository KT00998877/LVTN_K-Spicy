import { defineStore } from 'pinia'
import { stockImportsApi, stocktakesApi } from '@/api/stockFlow'
import type {
  StockImport,
  StockImportPayload,
  Stocktake,
  StocktakePayload,
} from '@/api/stockFlow'

interface State {
  imports: StockImport[]
  importsTotal: number
  importsLoading: boolean
  importsError: string
  currentImport: StockImport | null

  stocktakes: Stocktake[]
  stocktakesTotal: number
  stocktakesLoading: boolean
  stocktakesError: string
  currentStocktake: Stocktake | null
}

export const useStockFlowStore = defineStore('manager-stock-flow', {
  state: (): State => ({
    imports: [],
    importsTotal: 0,
    importsLoading: false,
    importsError: '',
    currentImport: null,
    stocktakes: [],
    stocktakesTotal: 0,
    stocktakesLoading: false,
    stocktakesError: '',
    currentStocktake: null,
  }),

  getters: {
    draftImports: (s) => s.imports.filter((i) => i.status === 'DRAFT'),
    confirmedImports: (s) => s.imports.filter((i) => i.status === 'CONFIRMED'),
    openStocktakes: (s) => s.stocktakes.filter((s2) => s2.status === 'OPEN'),
    completedStocktakes: (s) => s.stocktakes.filter((s2) => s2.status === 'COMPLETED'),
  },

  actions: {
    // ---- Stock Imports ----
    async fetchImports(params: Record<string, any> = {}) {
      this.importsLoading = true
      this.importsError = ''
      try {
        const r = await stockImportsApi.list(params)
        this.imports = r.data.data
        this.importsTotal = r.data.meta.total
      } catch (e: any) {
        this.importsError = this.msg(e)
        throw e
      } finally {
        this.importsLoading = false
      }
    },

    async fetchImport(id: number) {
      const r = await stockImportsApi.get(id)
      this.currentImport = r.data.data
      return r.data.data
    },

    async createImport(payload: StockImportPayload) {
      const r = await stockImportsApi.create(payload)
      this.currentImport = r.data.data
      this.imports.unshift(r.data.data)
      this.importsTotal += 1
      return r.data.data
    },

    async updateImport(id: number, payload: StockImportPayload) {
      const r = await stockImportsApi.update(id, payload)
      this.currentImport = r.data.data
      const idx = this.imports.findIndex((i) => i.id === id)
      if (idx >= 0) this.imports[idx] = r.data.data
      return r.data.data
    },

    async confirmImport(id: number, receivedAt: string) {
      const r = await stockImportsApi.confirm(id, receivedAt)
      this.currentImport = r.data.data
      const idx = this.imports.findIndex((i) => i.id === id)
      if (idx >= 0) this.imports[idx] = r.data.data
      return r.data.data
    },

    async cancelImport(id: number) {
      const r = await stockImportsApi.cancel(id)
      const idx = this.imports.findIndex((i) => i.id === id)
      if (idx >= 0) this.imports[idx] = r.data.data
      return r.data.data
    },

    // ---- Stocktakes ----
    async fetchStocktakes(params: Record<string, any> = {}) {
      this.stocktakesLoading = true
      this.stocktakesError = ''
      try {
        const r = await stocktakesApi.list(params)
        this.stocktakes = r.data.data
        this.stocktakesTotal = r.data.meta.total
      } catch (e: any) {
        this.stocktakesError = this.msg(e)
        throw e
      } finally {
        this.stocktakesLoading = false
      }
    },

    async fetchStocktake(id: number) {
      const r = await stocktakesApi.get(id)
      this.currentStocktake = r.data.data
      return r.data.data
    },

    async createStocktake(payload: StocktakePayload) {
      const r = await stocktakesApi.create(payload)
      this.currentStocktake = r.data.data
      this.stocktakes.unshift(r.data.data)
      this.stocktakesTotal += 1
      return r.data.data
    },

    async completeStocktake(id: number) {
      const r = await stocktakesApi.complete(id)
      this.currentStocktake = r.data.data
      const idx = this.stocktakes.findIndex((s) => s.id === id)
      if (idx >= 0) this.stocktakes[idx] = r.data.data
      return r.data.data
    },

    async cancelStocktake(id: number) {
      const r = await stocktakesApi.cancel(id)
      const idx = this.stocktakes.findIndex((s) => s.id === id)
      if (idx >= 0) this.stocktakes[idx] = r.data.data
      return r.data.data
    },

    msg(e: any) {
      return (
        Object.values(e?.response?.data?.errors || {})
          .flat()
          .join(' ') ||
        e?.response?.data?.message ||
        'Không xử lý được. Vui lòng thử lại.'
      )
    },
  },
})
