import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as pinia from 'pinia'
import * as vue from 'vue'
import { parse } from '@vue/compiler-sfc'

function evaluate(source, modules) {
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const module = { exports: {} }
    new Function('require', 'module', 'exports', code)(name => {
        if (!(name in modules)) throw new Error(`Unmocked import ${name}`)
        return modules[name]
    }, module, module.exports)
    return module.exports
}

function session(fetchMe) {
    const storage = new Map()
    globalThis.localStorage = {
        getItem: key => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
        removeItem: key => storage.delete(key),
    }
    globalThis.sessionStorage = { removeItem() {} }
    pinia.setActivePinia(pinia.createPinia())
    const realtime = evaluate(readFileSync(new URL('../src/utils/realtime.ts', import.meta.url), 'utf8'), {})
    const { useAuthStore } = evaluate(readFileSync(new URL('../src/stores/auth.ts', import.meta.url), 'utf8'), {
        pinia, '@/utils/realtime': realtime,
        './staff': {}, './cart': { useCartStore: () => ({ $reset() {} }) },
        './orders': { useOrderStore: () => ({ $reset() {} }) }, '@/api/auth': { fetchMe },
    })
    const store = useAuthStore()
    store.token = 'test-token'
    store.user = { id: 1, total_spent: 0, tier: { id: 1, code: 'BRONZE' } }
    store.persist()
    return { store, storage }
}

test('live tier persists across route hydration and page reload', async () => {
    const vip = { id: 1, total_spent: 15000000, tier: { id: 4, code: 'VIP' } }
    const { store, storage } = session(async () => vip)
    await store.refreshLiveProfile()
    assert.equal(JSON.parse(storage.get('auth_user')).tier.code, 'VIP')
    store.init()
    assert.equal(store.user.tier.code, 'VIP')
    store.$reset()
    store.init()
    assert.equal(store.user.tier.code, 'VIP')
})

test('route hydration cannot replace a newer in-memory tier with an older cached rank', () => {
    const { store } = session(async () => null)
    store.user = { ...store.user, tier: { id: 4, code: 'VIP' } }
    store.init()
    assert.equal(store.user.tier.code, 'VIP')
})

test('late profile response cannot repaint the background after logout', async () => {
    let resolve
    const { store, storage } = session(() => new Promise(r => { resolve = r }))
    const pending = store.refreshLiveProfile()
    store.clearSession()
    resolve({ id: 1, tier: { id: 4, code: 'VIP' } })
    await pending
    assert.equal(store.user, null)
    assert.equal(storage.has('auth_user'), false)
})

test('tier refresh retains cards and ignores an older response', async () => {
    const { store } = session(async () => null)
    const requests = []
    const { descriptor } = parse(readFileSync(new URL('../src/components/loyalty/LoyaltyTierPanel.vue', import.meta.url), 'utf8'))
    const panel = evaluate(descriptor.scriptSetup.content + '\nexport { load, tierLoading, tierReady, tierCurrent, tierTiers, tierError };', {
        vue: { ...vue, onMounted() {}, watch() {} }, pinia,
        '@/stores/auth': { useAuthStore: () => store }, './TierBadge.vue': {},
        '@/api/loyalty': {
            fetchLoyaltyTiers: async () => [{ id: 1 }, { id: 4 }],
            fetchLoyaltyMe: () => new Promise(resolve => requests.push(resolve)),
        },
    })
    const first = panel.load()
    assert.equal(panel.tierLoading.value, true)
    requests.shift()({ current: { id: 1, code: 'BRONZE' }, next: { id: 4 }, progress: null })
    await first
    assert.equal(panel.tierReady.value, true)
    const older = panel.load()
    assert.equal(panel.tierLoading.value, false)
    assert.equal(panel.tierCurrent.value.code, 'BRONZE')
    const newer = panel.load()
    requests[1]({ current: { id: 4, code: 'VIP' }, next: null, progress: null })
    await newer
    requests[0]({ current: { id: 1, code: 'BRONZE' }, next: { id: 4 }, progress: null })
    await older
    assert.equal(panel.tierCurrent.value.code, 'VIP')
    assert.equal(panel.tierLoading.value, false)
})
