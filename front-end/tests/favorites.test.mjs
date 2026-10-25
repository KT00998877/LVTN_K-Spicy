import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as pinia from 'pinia'

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

function harness(initial = []) {
    const calls = []
    const { useFavoritesStore } = evaluate(
        readFileSync(new URL('../src/stores/favorites.ts', import.meta.url), 'utf8'),
        {
            pinia,
            '@/api/favorites': {
                fetchFavorites: async () => initial,
                addFavorite: async id => calls.push(['add', id]),
                removeFavorite: async id => calls.push(['remove', id]),
            },
        },
    )
    pinia.setActivePinia(pinia.createPinia())
    return { store: useFavoritesStore(), calls }
}

test('favorites load once and expose favorite ids', async () => {
    const item = { id: 7, name: 'Mì cay' }
    const { store } = harness([item])

    await store.load()
    await store.load()

    assert.deepEqual(store.items, [item])
    assert.deepEqual(store.favoriteIds, [7])
    assert.equal(store.loaded, true)
})

test('favorite toggle adds and removes the same menu item', async () => {
    const item = { id: 9, name: 'Tokbokki' }
    const { store, calls } = harness()

    assert.equal(await store.toggle(item), true)
    assert.deepEqual(store.favoriteIds, [9])
    assert.equal(await store.toggle(item), false)
    assert.deepEqual(store.favoriteIds, [])
    assert.deepEqual(calls, [['add', 9], ['remove', 9]])
})
