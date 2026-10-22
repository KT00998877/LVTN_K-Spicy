import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import * as pinia from 'pinia'

const source = readFileSync(new URL('../src/stores/staffNotifications.ts', import.meta.url), 'utf8')
    .replaceAll('import.meta.env', '({ VITE_REVERB_APP_KEY: "test-key", VITE_REVERB_PORT: "8080", VITE_REVERB_SCHEME: "http" })')
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); await vue.nextTick() }
const notification = (id, time = new Date(Date.now() + 1000).toISOString()) => ({
    id, data: { event: 'SHIPPER_ASSIGNED', order_id: 1, order_code: 'ORD-TEST', message: 'New order', url: '/staff/shipper?order_id=1' },
    read_at: null, created_at: time,
})
const snapshot = (items, count = items.length) => ({ data: { data: { data: items, current_page: 1, last_page: 1 }, unread_count: count } })

function harness() {
    const requests = [], channels = new Map(), clients = []
    class FakePusher {
        constructor(key, options) { this.options = options; this.connection = { bind() {} }; clients.push(this) }
    }
    class FakeEcho {
        private(name) {
            const handlers = {}, channel = { handlers, subscribed(fn) { handlers.subscribed = fn; return channel },
                listen(name, fn) { handlers[name] = fn; return channel } }
            channels.set(name, channel)
            return channel
        }
        disconnect() {}
    }
    const api = {
        get() { return new Promise(resolve => requests.push(resolve)) },
        async patch() {},
        async post(url, payload, config) { api.authorization = { url, payload, config }; return { data: { auth: 'signed' } } },
    }
    const window = new EventTarget()
    window.location = { hostname: 'localhost', protocol: 'http:' }
    const storage = { getItem(key) { return key === 'staff_token' ? 'staff-test-token' : null }, setItem() {} }
    const modules = { vue, pinia, 'laravel-echo': { __esModule: true, default: FakeEcho }, 'pusher-js': { __esModule: true, default: FakePusher }, '@/api/client': { __esModule: true, default: api } }
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const module = { exports: {} }
    new Function('require', 'module', 'exports', 'window', 'localStorage', 'setInterval', 'clearInterval', code)(
        name => { assert.ok(name in modules, `Unexpected import ${name}`); return modules[name] }, module, module.exports,
        window, storage, () => 1, () => {},
    )
    pinia.setActivePinia(pinia.createPinia())
    const store = module.exports.useStaffNotificationsStore()
    store.start({ user_id: 7, roles: ['SHIPPER'], role: 'SHIPPER' })
    return { store, requests, api, client: clients[0], handlers: channels.get('staff.7').handlers }
}

test('push deduplication and an older HTTP response cannot remove a newer notification', async () => {
    const { store, requests, handlers } = harness()
    const item = notification('new')
    handlers['.staff.notification.created'](item)
    handlers['.staff.notification.created'](item)
    assert.equal(store.items.length, 1)
    assert.equal(store.unreadCount, 1)
    requests[0](snapshot([]))
    await flush()
    assert.equal(store.items[0].id, 'new')
    assert.equal(requests.length, 2)
    requests[1](snapshot([item]))
    await flush()
    assert.equal(store.unreadCount, 1)
    store.stop()
})

test('logout rejects outstanding requests and late events from the previous account', async () => {
    const { store, requests, handlers } = harness()
    store.stop()
    requests[0](snapshot([notification('old-account')]))
    handlers['.staff.notification.created'](notification('late'))
    handlers.subscribed()
    await flush()
    assert.equal(store.items.length, 0)
    assert.equal(store.unreadCount, 0)
    assert.equal(store.connected, false)
})

test('replayed historical notifications do not create a toast; read events are idempotent', async () => {
    const { store, requests, handlers } = harness()
    requests[0](snapshot([]))
    await flush()
    handlers['.staff.notification.created'](notification('historical', '2020-01-01T00:00:00Z'))
    assert.equal(store.latest, null)
    handlers['.staff.notification.read']({ id: 'historical' })
    assert.equal(store.unreadCount, 0)
    handlers['.staff.notification.read']({ id: 'historical' })
    assert.equal(store.unreadCount, 0)
    store.stop()
})

test('private channel authorization uses staff bearer token and Laravel field names', async () => {
    const { store, requests, client, api } = harness()
    requests[0](snapshot([]))
    await flush()
    let response
    client.options.channelAuthorization.customHandler({ socketId: '1.2', channelName: 'private-staff.7' }, (error, data) => { assert.equal(error, null); response = data })
    await flush()
    assert.deepEqual(api.authorization.payload, { socket_id: '1.2', channel_name: 'private-staff.7' })
    assert.equal(api.authorization.config.headers.Authorization, 'Bearer staff-test-token')
    assert.equal(response.auth, 'signed')
    store.stop()
})

test('live refresh coalesces push events, waits for an action, and retries failed reads', async () => {
    function evaluate(source, modules, window, document) {
        const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
        const module = { exports: {} }
        new Function('require', 'module', 'exports', 'window', 'document', 'navigator', code)(
            name => { assert.ok(name in modules); return modules[name] }, module, module.exports,
            window, document, { onLine: true },
        )
        return module.exports
    }
    const window = new EventTarget(), document = new EventTarget()
    document.visibilityState = 'visible'
    const utils = evaluate(readFileSync(new URL('../src/utils/realtime.ts', import.meta.url), 'utf8'), {}, window, document)
    let timer, dispose, busy = false, reads = 0, fail = false
    const live = { connected: true }
    const modules = {
        vue: { onMounted: fn => fn(), onScopeDispose: fn => { dispose = fn } },
        '@/stores/staffNotifications': { useStaffNotificationsStore: () => live, STAFF_LIVE_EVENT: 'staff:live-update' },
        '@/utils/realtime': { ...utils, createRefreshLoop: (fn, options) => utils.createRefreshLoop(fn, {
            ...options, schedule: (fn, delay) => { timer = { fn, delay }; return timer },
            cancel: previous => { if (timer === previous) timer = null },
        }) },
    }
    const { useStaffLiveRefresh } = evaluate(readFileSync(new URL('../src/composables/useStaffLiveRefresh.ts', import.meta.url), 'utf8'), modules, window, document)
    useStaffLiveRefresh(async () => { reads++; if (fail) throw new Error('Network failure') }, () => !busy)
    const tick = async () => { const next = timer; timer = null; next.fn(); await flush() }
    await tick()
    assert.equal(reads, 0, 'Connected idle screens must not poll')
    busy = true
    window.dispatchEvent(new Event('staff:live-update'))
    window.dispatchEvent(new Event('staff:live-update'))
    await tick()
    assert.equal(reads, 0, 'Push must not overwrite a pending action')
    busy = false
    await tick()
    assert.equal(reads, 1)
    fail = true
    window.dispatchEvent(new Event('staff:live-update'))
    await tick()
    assert.equal(reads, 2)
    fail = false
    await tick()
    assert.equal(reads, 3, 'Failed reads must retry automatically')
    dispose()
    assert.equal(timer, null)
})

test('API client treats broadcast auth as staff for both request and stale-session checks', () => {
    let request, response
    const client = { interceptors: {
        request: { use(fn) { request = fn } },
        response: { use(fn) { response = fn } },
    } }
    const apiSource = readFileSync(new URL('../src/api/client.ts', import.meta.url), 'utf8')
        .replaceAll('import.meta.env', '({ VITE_API_BASE_URL: "/api" })')
    const code = ts.transpileModule(apiSource, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const modules = {
        axios: { __esModule: true, default: { create: () => client }, CanceledError: class extends Error {} },
        '@/utils/realtime': { announceDataChange() {}, markDataWrite() {} },
    }
    const tokens = { staff_token: 'staff-token', auth_token: 'customer-token' }
    const module = { exports: {} }
    new Function('require', 'module', 'exports', 'localStorage', code)(name => modules[name], module, module.exports, { getItem: key => tokens[key] })
    const config = request({ url: '/broadcasting/auth', method: 'post', headers: {} })
    assert.equal(config.headers.Authorization, 'Bearer staff-token')
    assert.doesNotThrow(() => response({ config }))
    tokens.staff_token = 'another-staff-token'
    assert.throws(() => response({ config }), /Session changed/)
    const customerConfig = request({ url: '/orders/me/1', method: 'get', headers: {} })
    assert.equal(customerConfig.headers.Authorization, 'Bearer customer-token')
    assert.doesNotThrow(() => response({ config: customerConfig }))
})
