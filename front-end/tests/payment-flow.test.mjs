import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import { parse, compileScript } from '@vue/compiler-sfc'
import * as vue from 'vue'
import * as pinia from 'pinia'

function evaluate(source, modules = {}) {
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const module = { exports: {} }
    new Function('require', 'module', 'exports', code)(name => {
        if (!(name in modules)) throw new Error(`Unmocked import: ${name}`)
        return modules[name]
    }, module, module.exports)
    return module.exports
}
const { createPaymentPoller } = evaluate(readFileSync(new URL('../src/utils/paymentPolling.ts', import.meta.url), 'utf8'))
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); await vue.nextTick() }

test('network loss, expiry, then backend success; refresh never creates a payment', async () => {
    const results = [], errors = [], scheduled = []
    const responses = [new Error('offline'), { status: 'expired' }, { status: 'paid' }]
    let reads = 0
    const poller = createPaymentPoller({
        read: async () => { reads++; const next = responses.shift(); if (next instanceof Error) throw next; return next },
        currentSession: () => () => true, result: r => results.push(r.status), error: e => errors.push(e), busy: () => {},
        schedule: fn => { scheduled.push(fn); return scheduled.length }, cancel: () => {},
    })
    poller.start('VNP1'); await flush()
    assert.ok(errors.some(Boolean)); assert.deepEqual(results, [])
    scheduled.shift()(); await flush()
    assert.deepEqual(results, ['expired'])
    scheduled.shift()(); await flush()
    assert.deepEqual(results, ['expired', 'paid']); assert.equal(reads, 3); assert.equal(scheduled.length, 0)
    poller.stop()
})

test('back/forward ignores late result from previous payment and blocks overlapping reads', async () => {
    const resolvers = {}, results = []
    let reads = 0
    const poller = createPaymentPoller({
        read: code => { reads++; return new Promise(resolve => { resolvers[code] = resolve }) },
        currentSession: () => () => true, result: r => results.push(r.status), error: () => {}, busy: () => {},
    })
    poller.start('VNPA'); void poller.refresh(); assert.equal(reads, 1)
    poller.start('VNPB'); resolvers.VNPB({ status: 'cancelled' }); await flush()
    resolvers.VNPA({ status: 'paid' }); await flush()
    assert.deepEqual(results, ['cancelled']); poller.stop()
})

test('logout/unmount cannot apply an old success response', async () => {
    let resolve, session = true
    const results = []
    const poller = createPaymentPoller({ read: () => new Promise(r => { resolve = r }),
        currentSession: () => () => session, result: r => results.push(r), error: () => {}, busy: () => {} })
    poller.start('VNP1'); session = false; resolve({ status: 'paid' }); await flush()
    assert.deepEqual(results, []); poller.stop()
})

function node(type, text = '') { return { type, text, props: {}, children: [], parent: null } }
const renderer = vue.createRenderer({
    createElement: type => node(type), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text }, setElementText: (n, text) => { n.text = text; n.children = [] },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] ?? null,
    patchProp: (n, key, _, value) => { n.props[key] = value },
    insert(n, parent, anchor) { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = parent; const index = parent.children.indexOf(anchor); parent.children.splice(index < 0 ? parent.children.length : index, 0, n) },
    remove(n) { n.parent?.children.splice(n.parent.children.indexOf(n), 1) },
})
const content = n => (n.type === 'comment' ? '' : n.text) + n.children.map(content).join(' ')
const descendants = n => [n, ...n.children.flatMap(descendants)]
const descriptor = parse(readFileSync(new URL('../src/views/VNPayResultView.vue', import.meta.url), 'utf8')).descriptor
const compiled = compileScript(descriptor, { id: 'payment-test', inlineTemplate: true }).content

function mountResult(status, query = { payment_code: 'VNPTEST', vnp_ResponseCode: '00' }) {
    const route = vue.reactive({ query })
    let reads = 0, creates = 0
    const listeners = new Map(), redirects = []
    const previousWindow = globalThis.window
    globalThis.window = { addEventListener: (key, fn) => listeners.set(key, fn), removeEventListener: key => listeners.delete(key), location: { assign: url => redirects.push(url) } }
    const auth = vue.reactive({ sessionRevision: 1, token: 'test', isCurrentSession: (revision, token) => revision === auth.sessionRevision && token === auth.token })
    const component = evaluate(compiled, {
        vue, 'vue-router': { useRoute: () => route }, '@/stores/auth': { useAuthStore: () => auth },
        '@/api/payments': {
            paymentStatus: async () => { reads++; return { status, order_id: 1, order_code: 'ORD-TEST', order_status: 'CONFIRMED', amount: 20000, paid_at: null, transaction_id: null, can_retry: ['failed', 'cancelled'].includes(status) } },
            createVNPay: async () => { creates++; return { payment_url: 'https://sandbox.vnpayment.vn/test' } },
        },
        '@/utils/paymentPolling': { createPaymentPoller },
        '@/components/home/NavbarHome.vue': { __esModule: true, default: { render: () => vue.h('nav') } },
        '@/components/ui/UiButton.vue': { __esModule: true, default: { props: ['loading', 'disabled'], setup: (props, { attrs, slots }) => () => vue.h('button', { ...attrs, disabled: props.loading || props.disabled }, slots.default?.()) } },
    }).default
    const app = renderer.createApp(component)
    app.component('RouterLink', { setup: (_, { slots }) => () => vue.h('a', {}, slots.default?.()) })
    const root = node('root'); app.mount(root)
    return { root, route, auth, redirects, listeners, reads: () => reads, creates: () => creates,
        stop: () => { app.unmount(); globalThis.window = previousWindow } }
}

for (const status of ['paid', 'pending', 'failed', 'cancelled', 'expired', 'refunded']) {
    test(`Vue renders backend ${status}, never URL success; repeat mount does not create order/payment`, async () => {
        const page = mountResult(status)
        try {
            await flush()
            const text = content(page.root)
            if (status === 'paid') {
                assert.match(text, /đã được thanh toán thành công/)
                assert.match(text, /ORD-TEST/); assert.match(text, /20\.000 VNĐ/)
                assert.match(text, /Xem đơn hàng/); assert.match(text, /Về trang chủ/)
            } else assert.doesNotMatch(text, /đã được thanh toán thành công/)
            if (status === 'cancelled') assert.match(text, /Bạn đã hủy thanh toán/)
            if (status === 'failed') assert.match(text, /Vui lòng thử lại/)
            assert.equal(page.creates(), 0)
        } finally { page.stop() }
    })
}

test('Vue rejects invalid return and missing reference without reading a payment', async () => {
    for (const query of [{ error: 'invalid_signature' }, { vnp_ResponseCode: '00' }]) {
        const page = mountResult('paid', query)
        try { await flush(); assert.equal(page.reads(), 0); assert.doesNotMatch(content(page.root), /đã được thanh toán thành công/) }
        finally { page.stop() }
    }
})

test('Vue retry double-click creates one payment and redirects once', async () => {
    const page = mountResult('cancelled')
    try {
        await flush()
        const button = descendants(page.root).find(n => n.type === 'button' && content(n).includes('Thanh toán lại'))
        assert.ok(button)
        button.props.onClick(); button.props.onClick(); await flush()
        assert.equal(page.creates(), 1); assert.equal(page.redirects.length, 1)
    } finally { page.stop() }
})

test('checkout retry after reload preserves the original idempotency key and chosen method', async () => {
    const memory = new Map(), requests = []
    const priorStorage = globalThis.sessionStorage
    globalThis.sessionStorage = { getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, value), removeItem: key => memory.delete(key) }
    const auth = { user: { id: 1 }, isAuthenticated: true, sessionRevision: 1, token: 'test', isCurrentSession: () => true }
    let fail = true
    const { useCartStore } = evaluate(readFileSync(new URL('../src/stores/cart.ts', import.meta.url), 'utf8'), {
        pinia, '@/utils/realtime': { beginRead: () => () => true }, './auth': { useAuthStore: () => auth },
        './orders': { useOrderStore: () => ({ resetList() {} }) },
        '@/api/cart': { checkout: async data => { requests.push(data); if (fail) throw new Error('lost response'); return { order_id: 1, order_code: 'ORD-TEST' } } },
    })
    try {
        pinia.setActivePinia(pinia.createPinia())
        let cart = useCartStore()
        cart.cart = { items: [{ id: 1 }], version: 3 }
        await cart.checkout({ order_type: 'TAKEAWAY', payment_method: 'PREPAID', recipient_name: 'Test', recipient_phone: '0912345678' })
        assert.ok(cart.pendingCheckout)
        pinia.setActivePinia(pinia.createPinia()); cart = useCartStore(); cart.restoreCheckout()
        fail = false
        const result = await cart.checkout({ order_type: 'ONLINE', payment_method: 'COD' })
        assert.equal(result.order_id, 1)
        assert.deepEqual(requests[0], requests[1])
        assert.equal(requests[1].payment_method, 'PREPAID')
        assert.equal(cart.pendingCheckout, null)
    } finally { globalThis.sessionStorage = priorStorage; pinia.setActivePinia(undefined) }
})

test('checkout GPS initialization cannot discard an uncertain order after refresh', () => {
    const checkout = parse(readFileSync(new URL('../src/views/CheckoutView.vue', import.meta.url), 'utf8')).descriptor.scriptSetup.content
    const ast = ts.createSourceFile('checkout.ts', checkout, ts.ScriptTarget.Latest, true)
    const fn = ast.statements.find(n => ts.isFunctionDeclaration(n) && n.name?.text === 'autoLocateOnOnline')
    assert.ok(fn)
    const code = ts.transpileModule(fn.getText(ast), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
    const pending = { idempotency_key: 'original-key' }
    const cartStore = { pendingCheckout: pending, clearPendingCheckout: () => { throw new Error('must preserve checkout') } }
    new Function('orderType', 'cartStore', 'location', 'navigator', code + ';autoLocateOnOnline();')(
        { value: 'ONLINE' }, cartStore, { value: null }, { geolocation: { getCurrentPosition: () => { throw new Error('must resolve pending checkout first') } } },
    )
    assert.equal(cartStore.pendingCheckout, pending)
})
