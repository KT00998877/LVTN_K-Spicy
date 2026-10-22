import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
import * as pinia from 'pinia'

function harness(overrides = {}) {
    const storage = new Map()
    globalThis.sessionStorage = globalThis.localStorage = {
        getItem: key => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, String(value)),
        removeItem: key => storage.delete(key),
    }
    const requests = []
    const api = {
        startSession: async () => ({ id: 12, status: 'active', guest_token: 'guest' }),
        getSessionPendingActions: async () => [],
        sendMessage: async (...args) => { requests.push(args); return { message_id: 34, content: 'Answer', intent: 'menu' } },
        ...overrides,
    }
    const source = readFileSync(new URL('../src/stores/chatbot.ts', import.meta.url), 'utf8')
    const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText
    const module = { exports: {} }
    new Function('require', 'module', 'exports', code)(name => name === 'pinia' ? pinia : api, module, module.exports)
    pinia.setActivePinia(pinia.createPinia())
    return { store: module.exports.useChatbotStore(), requests, storage }
}

test('failed session creation never sends to a null session', async () => {
    const { store, requests } = harness({ startSession: async () => { throw new Error('offline') } })
    assert.equal(await store.sendMessage('Hello'), false)
    assert.equal(requests.length, 0)
    assert.equal(store.messages.length, 0)
    assert.equal(store.sending, false)
})

test('failed send can retry with the same UUID without duplicate local messages', async () => {
    const calls = []
    const { store } = harness({ sendMessage: async (...args) => {
        calls.push(args)
        if (calls.length === 1) throw { response: { data: { message: 'Temporary failure' } } }
        return { message_id: 34, content: 'Answer', intent: 'menu' }
    } })
    assert.equal(await store.sendMessage('Hello'), false)
    assert.equal(store.messages.length, 0)
    assert.equal(store.error, 'Temporary failure')
    assert.equal(await store.sendMessage('Hello'), true)
    assert.equal(calls[0][2], calls[1][2])
    assert.equal(store.messages.length, 2)
})

test('missing stored session starts a new session before sending', async () => {
    const { store, storage, requests } = harness({ showSession: async () => { throw { response: { status: 404 } } } })
    storage.set('chatbot_session_id', '99')
    assert.equal(await store.sendMessage('Hello'), true)
    assert.equal(requests[0][0], 12)
})

test('closed session and duplicate concurrent click cannot send', async () => {
    let complete
    const { store, requests } = harness({ sendMessage: (...args) => {
        requests.push(args)
        return new Promise(resolve => { complete = resolve })
    } })
    await store.startNewConversation()
    store.sessionStatus = 'closed'
    assert.equal(await store.sendMessage('Hello'), false)
    store.sessionStatus = 'active'
    const first = store.sendMessage('Hello')
    assert.equal(await store.sendMessage('Hello'), false)
    assert.equal(requests.length, 1)
    complete({ message_id: 34, content: 'Answer', intent: 'menu' })
    assert.equal(await first, true)
})

test('assistant reply keeps menu links and booking action for rendering', async () => {
    const { store } = harness({ sendMessage: async () => ({
        message_id: 35,
        content: 'Bạn có thể thử Kimbap truyền thống.',
        intent: 'make_reservation',
        menu_suggestions: [{ name: 'Kimbap truyền thống', slug: 'kimbap-truyen-thong' }],
        quick_action: { type: 'reservation_form', label: 'Đặt bàn' },
    }) })
    assert.equal(await store.sendMessage('Tui ăn cay hông được'), true)
    assert.equal(store.messages[1].metadata.menu_suggestions[0].slug, 'kimbap-truyen-thong')
    assert.equal(store.messages[1].metadata.quick_action.type, 'reservation_form')
})

test('failed confirmation remains pending and guest recovery does not call protected API', async () => {
    let recoveryCalls = 0
    const { store } = harness({
        confirmPendingAction: async () => { throw new Error('offline') },
        getSessionPendingActions: async () => { recoveryCalls++; return [] },
    })
    await store.startNewConversation()
    await store.loadPendingActions()
    assert.equal(recoveryCalls, 0)
    store.pendingActions.set(1, { id: 1, status: 'pending' })
    assert.equal(await store.confirmAction(1), false)
    assert.equal(store.pendingActions.get(1).status, 'pending')
})

test('expired confirmation becomes non-actionable instead of returning to pending', async () => {
    const { store } = harness({ confirmPendingAction: async () => {
        throw { response: { status: 410, data: { message: 'Expired request' } } }
    } })
    store.pendingActions.set(1, { id: 1, status: 'pending' })
    assert.equal(await store.confirmAction(1), false)
    assert.equal(store.pendingActions.get(1).status, 'expired')
    assert.equal(store.hasUnconfirmedPending, false)
})
