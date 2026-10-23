import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as vue from 'vue'
import * as pinia from 'pinia'

const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); await vue.nextTick() }

/**
 * Khung test cho store: nạp file .ts bằng TypeScript transpile rồi chạy trong
 * sandbox với các module giả (Echo, Pusher, API).
 */
function loadStore(file, modules, extraGlobals = {}) {
    const source = readFileSync(new URL(file, import.meta.url), 'utf8')
        .replaceAll('import.meta.env', '({ VITE_REVERB_APP_KEY: "test-key", VITE_REVERB_PORT: "8080", VITE_REVERB_SCHEME: "http" })')
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText
    const module = { exports: {} }
    const globals = {
        require: name => {
            assert.ok(name in modules, `Unexpected import ${name}`)
            return modules[name]
        },
        module,
        exports: module.exports,
        window: { location: { hostname: 'localhost', protocol: 'http:' } },
        localStorage: { getItem: () => null, setItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        console: { error() {}, warn() {} },
        ...extraGlobals,
    }
    new Function(...Object.keys(globals), code)(...Object.values(globals))
    return module.exports
}

/** Echo giả: giữ lại handler của từng kênh để test gọi trực tiếp. */
function createEchoFactory(channels) {
    class FakePusher {
        constructor(key, options) {
            this.options = options
            this.connection = { bind() {} }
            this._handlers = {}
            FakePusher.instances.push(this)
        }
    }
    FakePusher.instances = []

    class FakeEcho {
        private(name) {
            const handlers = {}
            const channel = {
                handlers,
                subscribed(fn) { handlers.subscribed = fn; return channel },
                listen(event, fn) { handlers[event] = fn; return channel },
                unsubscribe() { channels.delete(name) },
            }
            channels.set(name, channel)
            return channel
        }
        disconnect() {}
    }

    return { FakeEcho, FakePusher }
}

const customerApi = (overrides = {}) => ({
    startSession: async () => ({ id: 12, status: 'waiting', customer_name: 'Chị Lan', assigned_staff_name: null, messages: [] }),
    showSession: async () => ({ id: 12, status: 'waiting', customer_name: 'Chị Lan', assigned_staff_name: null, messages: [] }),
    sendMessage: async () => ({ message: { id: 1, session_id: 12, sender_type: 'customer', content: 'Xin chào' }, session: { id: 12, status: 'waiting' } }),
    closeSession: async () => ({ id: 12, status: 'closed' }),
    ...overrides,
})

function customerHarness({ api = customerApi(), storage = new Map() } = {}) {
    const channels = new Map()
    const { FakeEcho, FakePusher } = createEchoFactory(channels)
    const modules = {
        vue, pinia,
        'laravel-echo': { __esModule: true, default: FakeEcho },
        'pusher-js': { __esModule: true, default: FakePusher },
        '@/api/liveChat': api,
    }
    const exports = loadStore('../src/stores/liveChat.ts', modules, {
        sessionStorage: {
            getItem: key => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, String(value)),
            removeItem: key => storage.delete(key),
        },
    })
    pinia.setActivePinia(pinia.createPinia())
    const store = exports.useLiveChatStore()
    return { store, channels, storage }
}

const managerApi = (overrides = {}) => ({
    managerQueue: async () => ({ waiting: [], mine: [] }),
    managerShowSession: async () => ({ id: 12, status: 'accepted', customer_name: 'Chị Lan', assigned_staff_name: 'Bạn An', messages: [] }),
    managerSuggestions: async () => ({ suggestions: ['Câu 1'], source: 'template' }),
    acceptSession: async () => ({ id: 12, status: 'accepted' }),
    managerSendMessage: async () => ({ message: { id: 1, session_id: 12, sender_type: 'staff', content: 'Chào bạn' }, session: { id: 12, status: 'accepted' } }),
    managerCloseSession: async () => ({ id: 12, status: 'closed' }),
    ...overrides,
})

function managerHarness({ api = managerApi() } = {}) {
    const channels = new Map()
    const { FakeEcho, FakePusher } = createEchoFactory(channels)
    const modules = {
        vue, pinia,
        'laravel-echo': { __esModule: true, default: FakeEcho },
        'pusher-js': { __esModule: true, default: FakePusher },
        '@/api/liveChat': api,
    }
    const exports = loadStore('../src/stores/liveChatManager.ts', modules)
    pinia.setActivePinia(pinia.createPinia())
    const store = exports.useLiveChatManagerStore()
    return { store, channels }
}

// ===== Phía khách =====

test('khách chưa đăng nhập thấy lời mời đăng nhập thay vì lỗi', async () => {
    const { store } = customerHarness({
        api: customerApi({
            startSession: async () => { throw { response: { status: 401, data: { message: 'Vui lòng đăng nhập' } } } },
        }),
    })
    await store.ensureSession()
    assert.equal(store.requiresLogin, true)
    assert.equal(store.error, null)
    assert.equal(store.sessionId, null)
})

test('mở phiên thành công thì nhớ id trong sessionStorage', async () => {
    const { store, storage } = customerHarness()
    await store.ensureSession()
    assert.equal(store.sessionId, 12)
    assert.equal(store.status, 'waiting')
    assert.equal(storage.get('live_chat_session_id'), '12')
})

test('phiên đã lưu được khôi phục thay vì tạo phiên mới', async () => {
    const storage = new Map([['live_chat_session_id', '12']])
    let started = 0
    const { store } = customerHarness({
        storage,
        api: customerApi({
            startSession: async () => { started++; return { id: 99, status: 'waiting' } },
        }),
    })
    await store.ensureSession()
    assert.equal(started, 0)
    assert.equal(store.sessionId, 12)
})

test('phiên đã đóng trên server thì mở phiên mới', async () => {
    const storage = new Map([['live_chat_session_id', '12']])
    const { store } = customerHarness({
        storage,
        api: customerApi({
            showSession: async () => ({ id: 12, status: 'closed', messages: [] }),
            startSession: async () => ({ id: 99, status: 'waiting', messages: [] }),
        }),
    })
    await store.ensureSession()
    assert.equal(store.sessionId, 99)
})

test('tin nhắn của chính khách không hiện hai lần', async () => {
    const { store } = customerHarness()
    await store.ensureSession()
    assert.equal(await store.sendMessage('Xin chào'), true)
    assert.equal(store.messages.length, 1)
    assert.equal(store.messages[0].content, 'Xin chào')
})

test('chỉ hiện dòng "đang chờ nhân viên" khi khách đã nói', async () => {
    const { store } = customerHarness()
    await store.ensureSession()
    assert.equal(store.hasCustomerMessage, false)
    await store.sendMessage('Xin chào')
    assert.equal(store.hasCustomerMessage, true)
})

// ===== Phía nhân viên =====

test('nhân viên nghe kênh hàng chờ và kênh từng phiên của mình', async () => {
    const { store, channels } = managerHarness({
        api: managerApi({
            managerQueue: async () => ({
                waiting: [],
                mine: [{ id: 12, status: 'accepted', customer_name: 'Chị Lan' }],
            }),
        }),
    })
    store.start()
    await flush()
    assert.ok(channels.has('live-chat-queue'), 'phải nghe kênh hàng chờ')
    assert.ok(channels.has('live-chat-session.12'), 'phải nghe kênh phiên của mình')
    store.stop()
})

test('tin nhắn của phiên đang mở hiện thẳng trong khung chat', async () => {
    const { store, channels } = managerHarness({
        api: managerApi({
            managerQueue: async () => ({
                waiting: [],
                mine: [{ id: 12, status: 'accepted', customer_name: 'Chị Lan' }],
            }),
        }),
    })
    store.start()
    await flush()
    await store.openSession(12)
    await flush()

    const handler = channels.get('live-chat-session.12').handlers['.live-chat.message.sent']
    handler({
        session_id: 12,
        message: { id: 5, session_id: 12, sender_type: 'customer', content: 'Mì cay giá bao nhiêu?' },
        session: { id: 12, status: 'accepted' },
    })
    await flush()

    assert.equal(store.messages.length, 1)
    assert.equal(store.messages[0].content, 'Mì cay giá bao nhiêu?')
    assert.equal(store.unreadSessionIds.includes(12), false)
    store.stop()
})

test('tin nhắn của phiên khác đánh dấu chưa đọc thay vì nhảy vào khung chat', async () => {
    const { store, channels } = managerHarness({
        api: managerApi({
            managerQueue: async () => ({
                waiting: [],
                mine: [
                    { id: 12, status: 'accepted', customer_name: 'Chị Lan' },
                    { id: 13, status: 'accepted', customer_name: 'Anh Tuấn' },
                ],
            }),
        }),
    })
    store.start()
    await flush()
    await store.openSession(12)
    await flush()

    const handler = channels.get('live-chat-session.13').handlers['.live-chat.message.sent']
    handler({
        session_id: 13,
        message: { id: 6, session_id: 13, sender_type: 'customer', content: 'Cho mình hỏi tokbokki' },
        session: { id: 13, status: 'accepted' },
    })
    await flush()

    assert.equal(store.messages.length, 0, 'không được nhảy tin vào khung chat đang mở')
    assert.equal(store.unreadSessionIds.includes(13), true)
    store.stop()
})

test('mở phiên khác thì phiên cũ hết chấm đỏ', async () => {
    const { store, channels } = managerHarness({
        api: managerApi({
            managerQueue: async () => ({
                waiting: [],
                mine: [
                    { id: 12, status: 'accepted', customer_name: 'Chị Lan' },
                    { id: 13, status: 'accepted', customer_name: 'Anh Tuấn' },
                ],
            }),
        }),
    })
    store.start()
    await flush()
    await store.openSession(12)
    await flush()

    const handler = channels.get('live-chat-session.13').handlers['.live-chat.message.sent']
    handler({
        session_id: 13,
        message: { id: 7, session_id: 13, sender_type: 'customer', content: 'Tokbokki giá bao nhiêu?' },
        session: { id: 13, status: 'accepted' },
    })
    await flush()
    assert.equal(store.unreadSessionIds.includes(13), true)

    await store.openSession(13)
    await flush()
    assert.equal(store.unreadSessionIds.includes(13), false)
    store.stop()
})

test('phiên đã bị nhân viên khác nhận thì đồng bộ lại hàng chờ', async () => {
    let queueCalls = 0
    const { store } = managerHarness({
        api: managerApi({
            managerQueue: async () => {
                queueCalls++
                return { waiting: [], mine: [] }
            },
            acceptSession: async () => { throw { response: { status: 409, data: { message: 'Phiên đã được nhận' } } } },
        }),
    })
    const accepted = await store.acceptSession(12)
    assert.equal(accepted, false)
    assert.equal(store.error, 'Phiên đã được nhận')
    assert.equal(queueCalls, 1, 'phải nạp lại hàng chờ khi có xung đột')
})

test('nhân viên chỉ được trả lời khi phiên đã được nhận', async () => {
    const { store } = managerHarness({
        api: managerApi({
            managerShowSession: async () => ({ id: 12, status: 'waiting', customer_name: 'Chị Lan', assigned_staff_name: null, messages: [] }),
        }),
    })
    await store.openSession(12)
    assert.equal(store.canReply, false)
    assert.equal(await store.sendMessage('Chào bạn'), false)
})
