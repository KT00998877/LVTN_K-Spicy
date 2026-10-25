import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const styles = readFileSync(new URL('../src/assets/main.css', import.meta.url), 'utf8')
const navbar = readFileSync(new URL('../src/components/home/NavbarHome.vue', import.meta.url), 'utf8')
const filters = readFileSync(new URL('../src/components/menu/MenuFilters.vue', import.meta.url), 'utf8')
const promoBell = readFileSync(new URL('../src/components/customer/CustomerPromoBell.vue', import.meta.url), 'utf8')

function zIndexToken(name) {
    const match = styles.match(new RegExp(`--z-${name}:\\s*(\\d+)`))
    assert.ok(match, `Missing --z-${name} token`)
    return Number(match[1])
}

test('customer navbar stays above page-level sticky controls', () => {
    assert.ok(zIndexToken('navbar') > zIndexToken('sticky'))
    assert.ok(zIndexToken('navbar') < zIndexToken('overlay'))
    assert.match(navbar, /\.navbar\s*\{[\s\S]*?z-index:\s*var\(--z-navbar\)/)
    assert.match(filters, /\.mobile-sticky-header\s*\{[\s\S]*?z-index:\s*var\(--z-sticky\)/)
})

test('mobile sticky content follows the shared navbar height', () => {
    assert.match(filters, /\.mobile-sticky-header\s*\{[\s\S]*?top:\s*var\(--navbar-height-mobile\)/)
})

test('nested navbar actions inherit its accessible foreground color', () => {
    assert.match(promoBell, /\.bell-button\s*\{[\s\S]*?color:\s*inherit/)
})

test('customer navbar exposes curated menu and favorites destinations', () => {
    assert.match(navbar, /label:\s*'Món bán chạy',\s*path:\s*'\/ban-chay'/)
    assert.match(navbar, /label:\s*'Món đặc sắc',\s*path:\s*'\/mon-dac-sac'/)
    assert.match(navbar, /aria-label="Món yêu thích"/)
    assert.match(navbar, /query:\s*\{\s*redirect:\s*'\/mon-yeu-thich'\s*\}/)
})
