import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import * as pinia from 'pinia'
import { compileScript, parse } from '@vue/compiler-sfc'
import { renderToString } from '@vue/server-renderer'

function evaluate(source, modules) {
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const module = { exports: {} }
    new Function('require', 'module', 'exports', code)(name => {
        assert.ok(name in modules, `Unexpected import ${name}`)
        return modules[name]
    }, module, module.exports)
    return module.exports
}

const realtime = evaluate(readFileSync(new URL('../src/utils/realtime.ts', import.meta.url), 'utf8'), {})
function harness() {
    const requests = []
    const source = readFileSync(new URL('../src/stores/menu.ts', import.meta.url), 'utf8')
    const { useMenuStore } = evaluate(source, {
        pinia, '@/utils/realtime': realtime,
        '@/api/home': { fetchHomeCategories: async () => [] },
        '@/api/menu': { fetchMenuItems: (filters, page, perPage) => new Promise((resolve, reject) => {
            requests.push({ filters, page, perPage, resolve, reject })
        }) },
    })
    pinia.setActivePinia(pinia.createPinia())
    return { store: useMenuStore(), requests }
}

function page(number, total = 25, category = 'all') {
    return {
        data: Array.from({ length: Math.max(0, Math.min(12, total - (number - 1) * 12)) }, (_, i) => ({
            id: (number - 1) * 12 + i + 1, category_slug: category,
        })),
        meta: { current_page: number, per_page: 12, count: 12, total, last_page: Math.ceil(total / 12) },
    }
}

async function firstPage(store, requests) {
    const loading = store.loadItems()
    requests.at(-1).resolve(page(1))
    await loading
}

test('menu loads 12 at a time, prevents duplicate clicks and stops after final page', async () => {
    const { store, requests } = harness()
    await firstPage(store, requests)
    assert.equal(requests[0].perPage, 12)
    assert.equal(store.items.length, 12)
    assert.equal(store.totalCount, 25)
    assert.equal(store.hasMore, true)
    const more = store.loadMore()
    await store.loadMore()
    assert.equal(requests.length, 2)
    assert.equal(store.items.length, 12)
    requests[1].resolve(page(2))
    await more
    assert.equal(store.items.length, 24)
    const last = store.loadMore()
    requests[2].resolve(page(3))
    await last
    assert.equal(store.items.length, 25)
    assert.equal(store.hasMore, false)
    await store.loadMore()
    assert.equal(requests.length, 3)
})

test('failed load more preserves cards and retries the same page', async () => {
    const { store, requests } = harness()
    await firstPage(store, requests)
    const more = store.loadMore()
    requests[1].reject(new Error('timeout'))
    await more
    assert.equal(store.items.length, 12)
    assert.equal(store.currentPage, 1)
    assert.equal(store.loadingMore, false)
    assert.match(store.loadMoreError, /thử lại/)
    const retry = store.loadMore()
    assert.equal(requests[2].page, 2)
    requests[2].resolve(page(2))
    await retry
    assert.equal(store.loadMoreError, null)
    assert.equal(store.items.length, 24)
})

test('changing filters discards an older load-more response and starts from page one', async () => {
    const { store, requests } = harness()
    await firstPage(store, requests)
    const more = store.loadMore()
    const filtered = store.setFilter('category', 'drinks')
    assert.equal(requests[2].page, 1)
    requests[1].resolve(page(2))
    await more
    assert.equal(store.items.length, 0)
    requests[2].resolve(page(1, 5, 'drinks'))
    await filtered
    assert.equal(store.items.length, 5)
    assert.ok(store.items.every(item => item.category_slug === 'drinks'))
    assert.equal(store.loadingMore, false)
    assert.equal(store.hasMore, false)
})

test('background refresh preserves all opened pages and skips refresh during load more', async () => {
    const { store, requests } = harness()
    await firstPage(store, requests)
    const more = store.loadMore()
    await store.loadItems(true)
    assert.equal(requests.length, 2)
    requests[1].resolve(page(2))
    await more
    const refresh = store.loadItems(true)
    requests[2].resolve(page(1))
    for (let i = 0; i < 8; i++) await Promise.resolve()
    assert.equal(requests[3].page, 2)
    assert.equal(store.items.length, 24)
    requests[3].resolve(page(2))
    await refresh
    assert.equal(store.items.length, 24)
    assert.equal(store.currentPage, 2)
})

test('menu API sends pagination together with search and category filters', async () => {
    let params
    const api = evaluate(readFileSync(new URL('../src/api/menu.ts', import.meta.url), 'utf8'), {
        './client': { __esModule: true, default: { get: async (_, config) => {
            params = config.params
            return { data: page(2) }
        } } },
    })
    await api.fetchMenuItems({ category: 'drinks', search: ' tea ', sort: 'price_asc', size: null,
        min_price: null, max_price: null }, 2, 12)
    assert.deepEqual(params, { category: 'drinks', search: 'tea', sort: 'price_asc', page: 2, per_page: 12 })
})

test('rating badge uses ratings from menu data, including zero, without another HTTP request', async () => {
    let requests = 0
    const source = readFileSync(new URL('../src/components/review/RatingBadge.vue', import.meta.url), 'utf8')
    const component = evaluate(compileScript(parse(source).descriptor, { id: 'test-rating', inlineTemplate: true }).content, {
        vue, '@/api/review': { fetchReviewSummary: async () => { requests++; return { count: 1, average: 1 } } },
    }).default
    const rated = await renderToString(vue.createSSRApp({ render: () => vue.h(component, {
        menuItemId: 35, ratingSummary: { count: 2, average: 4 },
    }) }))
    assert.match(rated, /4\.0/)
    assert.match(rated, /\(2\)/)
    const unrated = await renderToString(vue.createSSRApp({ render: () => vue.h(component, {
        menuItemId: 36, ratingSummary: { count: 0, average: 0 },
    }) }))
    assert.doesNotMatch(unrated, /rating-badge|rating-text/)
    assert.equal(requests, 0)
})
