import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import * as pinia from 'pinia'
import * as axios from 'axios'
import { compileScript, parse } from '@vue/compiler-sfc'

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

test('home timeout clears loading and a retry can recover', async () => {
    let fail = true
    const payload = { featured: [{ id: 1 }] }
    const source = readFileSync(new URL('../src/stores/home.ts', import.meta.url), 'utf8')
    const { useHomeStore } = evaluate(source, {
        pinia, axios,
        '@/utils/realtime': { beginRead: () => () => true },
        '@/api/home': { fetchHomePayload: async () => {
            if (fail) throw new axios.AxiosError('timeout of 15000ms exceeded', 'ECONNABORTED')
            return payload
        } },
    })
    pinia.setActivePinia(pinia.createPinia())
    const store = useHomeStore()
    await store.load()
    assert.equal(store.loading, false)
    assert.match(store.error, /Tải dữ liệu quá lâu/)
    assert.equal(store.isReady, false)
    fail = false
    await store.refresh()
    assert.equal(store.error, null)
    assert.equal(store.isReady, true)
    assert.deepEqual(store.featured, payload.featured)
})

const source = readFileSync(new URL('../src/components/ui/UiLazySection.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const component = evaluate(compileScript(descriptor, { id: 'test-lazy', inlineTemplate: true }).content, { vue }).default
const renderer = vue.createRenderer({
    createElement: tag => ({ tag, children: [] }),
    createText: text => ({ text }), createComment: text => ({ text }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => { node.text = text },
    patchProp() {}, parentNode: node => node.parent,
    nextSibling: node => node.parent.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert: (node, parent, anchor) => {
        node.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index < 0) parent.children.push(node)
        else parent.children.splice(index, 0, node)
    },
    remove: node => { node.parent.children = node.parent.children.filter(child => child !== node) },
})

test('lazy content loads once near viewport and disconnects its observer', async () => {
    let callback, options, observed, disconnects = 0, renders = 0
    globalThis.IntersectionObserver = class {
        constructor(fn, config) { callback = fn; options = config }
        observe(node) { observed = node }
        disconnect() { disconnects++ }
    }
    const app = renderer.createApp({ render: () => vue.h(component, null, {
        default: () => { renders++; return vue.h('section', 'Loaded') },
    }) })
    try {
        app.mount({ children: [] })
        assert.ok(observed)
        assert.equal(options.rootMargin, '300px')
        assert.equal(renders, 0)
        callback([{ isIntersecting: false }])
        await vue.nextTick()
        assert.equal(renders, 0)
        callback([{ isIntersecting: true }])
        await vue.nextTick()
        assert.equal(renders, 1)
        assert.equal(disconnects, 1)
        app.unmount()
        assert.equal(disconnects, 2)
    } finally {
        delete globalThis.IntersectionObserver
    }
})

test('lazy content remains available without IntersectionObserver', async () => {
    let renders = 0
    const app = renderer.createApp({ render: () => vue.h(component, null, {
        default: () => { renders++; return vue.h('section', 'Loaded') },
    }) })
    app.mount({ children: [] })
    await vue.nextTick()
    assert.equal(renders, 1)
    app.unmount()
})

test('home keeps its hero rendered while loading and after an API timeout', async () => {
    const store = vue.reactive({ loading: true, error: null, isReady: false, load() {}, refresh() {} })
    const stub = tag => ({ render: () => vue.h(tag) })
    const loadingSource = readFileSync(new URL('../src/components/home/HomeLoading.vue', import.meta.url), 'utf8')
    const loading = evaluate(compileScript(parse(loadingSource).descriptor, { id: 'test-loading', inlineTemplate: true }).content, { vue }).default
    const homeSource = readFileSync(new URL('../src/views/HomeView.vue', import.meta.url), 'utf8')
    const home = evaluate(compileScript(parse(homeSource).descriptor, { id: 'test-home', inlineTemplate: true }).content, {
        vue, pinia: { storeToRefs: vue.toRefs },
        '@/stores/home': { useHomeStore: () => store },
        '@/components/home/NavbarHome.vue': { __esModule: true, default: stub('nav') },
        '@/components/home/HeroSection.vue': { __esModule: true, default: stub('h1') },
        '@/components/customer/PromoBanner.vue': { __esModule: true, default: stub('aside') },
        '@/components/home/FooterHome.vue': { __esModule: true, default: stub('footer') },
        '@/components/home/HomeLoading.vue': { __esModule: true, default: loading },
        '@/components/ui/UiLazySection.vue': { __esModule: true, default: stub('div') },
        '@/components/home/home.css': {},
    }).default
    const root = { children: [] }
    const app = renderer.createApp(home)
    const hasHero = node => node.tag === 'h1' || (node.children ?? []).some(hasHero)
    app.mount(root)
    assert.ok(hasHero(root))
    store.loading = false
    store.error = 'Tải dữ liệu quá lâu. Bạn có thể thử lại.'
    await vue.nextTick()
    assert.ok(hasHero(root))
    app.unmount()
})
