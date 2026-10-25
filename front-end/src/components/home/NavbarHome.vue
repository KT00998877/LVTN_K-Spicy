<script setup lang="ts">
/**
 * NavbarHome — Phase 2 redesign.
 *
 * Customer Navigation & Global Shell.
 * KEEP all business logic from Phase 0 audit:
 *  - Cart count (useCartStore)
 *  - Auth state (useAuthStore)
 *  - Account dropdown
 *  - Tier color accent
 *  - Logout logic
 *  - useRealtimeRefresh hooks
 *
 * Visual state (via useNavbarState composable):
 *  - transparent (homepage hero, no scroll)
 *  - scrolled/inner-pages: chili red + white + subtle inverse border
 *
 * Mobile: fullscreen cream overlay with editorial typography.
 *
 * No new libraries. No GSAP. Only CSS transitions + rAF-throttled
 * scroll listener.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useHomeStore } from '@/stores/home'
import { useCartStore } from '@/stores/cart'
import { useCustomerNotificationsStore } from '@/stores/customerNotifications'
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { useNavbarState } from '@/composables/useNavbarState'
import { useAppDialog } from '@/composables/useAppDialog'
import CustomerPromoBell from '@/components/customer/CustomerPromoBell.vue'

// --- Stores (logic preserved) ---
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const cartStore = useCartStore()
const homeStore = useHomeStore()
const promoStore = useCustomerNotificationsStore()
const appDialog = useAppDialog()
const { isAuthenticated, displayName } = storeToRefs(authStore)
const { totalQuantity } = storeToRefs(cartStore)

// --- Navbar state machine (Phase 2) ---
const {
    isHome,
    isScrolled,
    isTransparent,
    isSolid,
    isMobileMenuOpen,
    isAccountMenuOpen,
    toggleMobileMenu,
    closeMobileMenu,
    toggleAccountMenu,
    closeAccountMenu,
} = useNavbarState()

// --- Account dropdown outside-click handler ---
const accountWrap = ref<HTMLElement | null>(null)

function onDocClick(e: MouseEvent) {
    if (!accountWrap.value) return
    if (accountWrap.value.contains(e.target as Node)) return
    closeAccountMenu()
}

watch(isAccountMenuOpen, (open) => {
    if (typeof document === 'undefined') return
    if (open) {
        document.addEventListener('click', onDocClick)
    } else {
        document.removeEventListener('click', onDocClick)
    }
})

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') {
        document.removeEventListener('click', onDocClick)
        // Phase 9 Correction §1: always strip body lock on unmount.
        document.body.classList.remove('is-mobile-menu-open')
    }
})

// --- Route change → reset menu/dropdown ---
watch(() => route.fullPath, () => {
    closeMobileMenu()
    closeAccountMenu()
})

// --- Body scroll lock while mobile menu is open ---
// Phase 9 Correction §1: use body class lock, no `touch-action: none`.
// The mobile menu itself manages its own vertical scroll via
// `overflow-y: auto` + `overscroll-behavior: contain`.
watch(isMobileMenuOpen, (open) => {
    if (typeof document === 'undefined') return
    if (open) {
        document.body.classList.add('is-mobile-menu-open')
    } else {
        document.body.classList.remove('is-mobile-menu-open')
    }
})

// --- Primary destinations (Phase 2 §7-§9) ---
// Order: commerce-first. Home is not a nav item — the wordmark logo IS Home.
// Khuyến mãi currently resolves to the news section (project has no
// dedicated /khuyen-mai route). Phase 3 may revisit if backend adds one.
const primaryDestinations = [
    { name: 'menu',             label: 'Thực đơn',     path: '/thuc-don' },
    { name: 'best-sellers',     label: 'Món bán chạy', path: '/ban-chay' },
    { name: 'featured-menu',    label: 'Món đặc sắc',  path: '/mon-dac-sac' },
    { name: 'promotion',        label: 'Khuyến mãi',   path: '/tin-vui/tin-khuyen-mai' },
    { name: 'about',            label: 'Về chúng tôi', path: '/about' },
    { name: 'reservation',      label: 'Đặt bàn',      path: '/dat-ban' },
]

const isPrimaryActive = (name: string) => route.name === name

// --- Commerce navigation ---
function goToOrder() {
    closeMobileMenu()
    if (!isAuthenticated.value) {
        router.push({ name: 'login', query: { redirect: '/thanh-toan' } })
        return
    }
    router.push('/thanh-toan')
}

function goToMenu() {
    closeMobileMenu()
    router.push('/thuc-don')
}

function goToFavorites() {
    closeMobileMenu()
    if (!isAuthenticated.value) {
        router.push({ name: 'login', query: { redirect: '/mon-yeu-thich' } })
        return
    }
    router.push('/mon-yeu-thich')
}

async function handleLogout() {
    if (!await appDialog.confirm({ title: 'Đăng xuất?', message: 'Bạn sẽ cần đăng nhập lại để tiếp tục mua hàng.', confirmLabel: 'Đăng xuất', tone: 'warning' })) return
    await authStore.logout()
    router.replace('/')
    closeMobileMenu()
}

// --- Sync state on mount ---
watch(isHome, () => {
    // When route changes between home and inner pages, sync class if any.
    if (typeof window !== 'undefined') {
        window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior })
    }
})

// --- Lifecycle / realtime (preserved) ---
if (isAuthenticated.value) {
    cartStore.fetchCart().catch(() => undefined)
}

// --- Chuông ưu đãi (Phase 1) ---
// Chỉ mở khi khách đã đăng nhập; đóng lại khi đăng xuất.
watch(
    () => ({ authenticated: isAuthenticated.value, userId: authStore.user?.id }),
    ({ authenticated, userId }) => {
        if (authenticated && userId) promoStore.start(userId)
        else promoStore.stop()
    },
    { immediate: true },
)

useRealtimeRefresh(
    () => cartStore.fetchCart(true, true),
    () => isAuthenticated.value && !cartStore.isLoading && !cartStore.pendingCheckout,
)
useRealtimeRefresh(
    () => authStore.refreshLiveProfile(),
    () => isAuthenticated.value && !authStore.loading,
    10000,
)
useRealtimeRefresh(
    () => homeStore.load(true, true),
    () => !homeStore.loading,
)

const transparent = computed(() => isTransparent.value)
const solid = computed(() => isSolid.value)
const showMobileCTA = computed(() => !['menu', 'checkout', 'order-detail'].includes(String(route.name)))
</script>

<template>
    <header
        class="navbar"
        :class="{
            'navbar--transparent': transparent,
            'navbar--solid': solid,
            'navbar--scrolled': isScrolled,
            'navbar--menu-open': isMobileMenuOpen,
        }"
        role="banner"
    >
        <div class="navbar-inner">
            <!-- LEFT: brand wordmark -->
            <router-link to="/" class="brand" @click="closeMobileMenu" aria-label="Trang chủ K-SPICY">
                <img src="/logo/logo.png" class="brand-logo-image" width="56" height="56" alt="" />
                <span class="brand-name">K-SPICY</span>
                <span class="brand-tag" aria-hidden="true">Korean Spice House</span>
            </router-link>

            <!-- CENTER: primary destinations (desktop) -->
            <nav class="nav-links" aria-label="Điều hướng chính">
                <router-link
                    v-for="item in primaryDestinations"
                    :key="item.name"
                    :to="item.path"
                    class="nav-link"
                    :class="{ 'is-active': isPrimaryActive(item.name) }"
                >
                    {{ item.label }}
                </router-link>
            </nav>

            <!-- RIGHT: actions -->
            <div class="navbar-actions">

                <!-- Chuông ưu đãi (Phase 1) — chỉ hiện khi khách đã đăng nhập -->
                <CustomerPromoBell v-if="isAuthenticated" class="promo-bell-slot" />

                <!-- Món yêu thích -->
                <button
                    class="icon-btn favorite-btn"
                    :class="{ 'is-active': route.name === 'favorites' }"
                    type="button"
                    aria-label="Món yêu thích"
                    :aria-current="route.name === 'favorites' ? 'page' : undefined"
                    @click="goToFavorites"
                >
                    <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        :fill="route.name === 'favorites' ? 'currentColor' : 'none'"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z" />
                    </svg>
                </button>

                <!-- Account dropdown -->
                <div ref="accountWrap" class="account-wrap">
                    <button
                        class="icon-btn account-btn"
                        :aria-expanded="isAccountMenuOpen"
                        aria-controls="account-dropdown"
                        :aria-label="isAuthenticated ? `Tài khoản ${displayName || ''}` : 'Đăng nhập'"
                        @click="toggleAccountMenu"
                    >
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </button>
                    <div
                        v-if="isAccountMenuOpen"
                        id="account-dropdown"
                        class="account-dropdown"
                    >
                        <template v-if="isAuthenticated">
                            <div class="account-greeting">
                                <span class="account-greeting-name">{{ displayName }}</span>
                                <span class="account-greeting-hint">Đã đăng nhập</span>
                            </div>
                            <router-link to="/tai-khoan" class="account-item" @click="closeAccountMenu">
                                Tài khoản của tôi
                            </router-link>
                            <router-link to="/tai-khoan?tab=orders" class="account-item" @click="closeAccountMenu">
                                Đơn hàng
                            </router-link>
                            <router-link to="/mon-yeu-thich" class="account-item" @click="closeAccountMenu">
                                Món yêu thích
                            </router-link>
                            <router-link to="/tai-khoan?tab=tier" class="account-item" @click="closeAccountMenu">
                                Hạng thành viên
                            </router-link>
                            <button class="account-item account-item--danger" @click="handleLogout">
                                Đăng xuất
                            </button>
                        </template>
                        <template v-else>
                            <router-link to="/dang-nhap" class="account-item" @click="closeAccountMenu">
                                Đăng nhập
                            </router-link>
                            <router-link to="/dang-ky" class="account-item" @click="closeAccountMenu">
                                Đăng ký tài khoản
                            </router-link>
                        </template>
                    </div>
                </div>

                <!-- Cart -->
                <button class="icon-btn cart-btn" :aria-label="`Giỏ hàng${totalQuantity > 0 ? `, ${totalQuantity} món` : ''}`" @click="goToOrder">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="9" cy="21" r="1"/>
                        <circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <span v-if="totalQuantity > 0" class="cart-badge" aria-live="polite">{{ totalQuantity }}</span>
                </button>

                <!-- Primary CTA: desktop -->
                <button
                    v-if="showMobileCTA"
                    class="nav-cta nav-cta--desktop"
                    type="button"
                    @click="goToMenu"
                >
                    Đặt món
                    <svg class="nav-cta-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="3" y1="6"  x2="21" y2="6"/>
                        <line x1="3" y1="12" x2="21" y2="12"/>
                        <line x1="3" y1="18" x2="13" y2="18"/>
                    </svg>
                </button>

                <!-- Mobile menu trigger -->
                <button
                    class="menu-toggle"
                    :class="{ active: isMobileMenuOpen }"
                    :aria-expanded="isMobileMenuOpen"
                    aria-controls="mobile-menu"
                    :aria-label="isMobileMenuOpen ? 'Đóng menu' : 'Mở menu'"
                    @click="toggleMobileMenu"
                >
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>

        <!-- =============================================================
             MOBILE FULLSCREEN OVERLAY
             Cream surface, large editorial typography, access links.
             ============================================================= -->
        <div
            v-if="isMobileMenuOpen"
            id="mobile-menu"
            class="mobile-menu"
            role="dialog"
            aria-modal="true"
            aria-label="Menu điều hướng"
            tabindex="-1"
        >
            <div class="mobile-menu-head">
                <span class="mobile-menu-brand">K-SPICY</span>
                <button class="mobile-menu-close" type="button" aria-label="Đóng" @click="closeMobileMenu">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <nav class="mobile-menu-list" aria-label="Điều hướng chính (mobile)">
                <router-link
                    v-for="(item, idx) in primaryDestinations"
                    :key="item.name"
                    :to="item.path"
                    class="mobile-menu-item"
                    :style="{ transitionDelay: `${40 + idx * 30}ms` }"
                    @click="closeMobileMenu"
                >
                    {{ item.label }}
                </router-link>
            </nav>

            <div class="mobile-menu-divider" />

            <div class="mobile-menu-account">
                <span class="mobile-menu-account-title">Tài khoản</span>
                <template v-if="isAuthenticated">
                    <router-link to="/tai-khoan" class="mobile-menu-account-link" @click="closeMobileMenu">
                        {{ displayName }}
                    </router-link>
                    <router-link to="/mon-yeu-thich" class="mobile-menu-account-link" @click="closeMobileMenu">
                        Món yêu thích
                    </router-link>
                    <button class="mobile-menu-account-link mobile-menu-account-link--danger" type="button" @click="handleLogout">
                        Đăng xuất
                    </button>
                </template>
                <template v-else>
                    <router-link to="/dang-nhap" class="mobile-menu-account-link" @click="closeMobileMenu">
                        Đăng nhập
                    </router-link>
                    <router-link to="/dang-ky" class="mobile-menu-account-link" @click="closeMobileMenu">
                        Đăng ký
                    </router-link>
                </template>
            </div>

            <button class="mobile-menu-cta" type="button" @click="goToMenu">
                Đặt món ngay
            </button>
        </div>
    </header>
</template>

<style scoped>
/* =====================================================
   Navbar — Spicy Editorial System. Phase 2.
   Tokens only. No hard-coded colors.
   ===================================================== */

.navbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: var(--z-navbar);
    height: var(--navbar-height);
    transition:
        background-color var(--dur-base) var(--ease-out),
        border-color var(--dur-base) var(--ease-out),
        color var(--dur-base) var(--ease-out);
    /* Border instead of shadow — barely there when scrolled. */
    border-bottom: 1px solid transparent;
}

/* =========== Transparent / Hero state =========== */
.navbar--transparent {
    background: transparent;
    color: var(--color-text-inverse); /* white text on Hero */
    border-bottom-color: transparent;
}

.navbar--transparent .brand-name,
.navbar--transparent .nav-link,
.navbar--transparent .icon-btn,
.navbar--transparent .nav-cta {
    color: var(--color-text-inverse);
}

/* Subtle text-shadow so the wordmark is still readable if the
   underlying Hero renders light (e.g. cream). This is a safety
   net — Phase 3 may tighten contrast on a redesigned Hero. */
.navbar--transparent .brand-name,
.navbar--transparent .nav-link {
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
}

/* =========== Solid / Scrolled state =========== */
.navbar--solid {
    background: var(--color-brand-primary);
    color: var(--color-text-inverse);
    border-bottom-color: var(--color-brand-border-inverse);
}
.navbar--solid .brand-name,
.navbar--solid .nav-link,
.navbar--solid .icon-btn {
    color: var(--color-text-inverse);
}

/* =========== Layout =========== */
.navbar-inner {
    height: 100%;
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: var(--space-5);
    padding-inline: var(--space-6);
    max-width: var(--container-functional);
    margin-inline: auto;
}

/* Solid brand navigation remains readable over page imagery. */
.navbar--scrolled {
    backdrop-filter: saturate(160%) blur(8px);
    -webkit-backdrop-filter: saturate(160%) blur(8px);
    background-color: var(--color-brand-primary);
    border-bottom-color: var(--color-brand-border-inverse);
}

@media (min-width: 1280px) {
    .navbar-inner {
        max-width: var(--container-editorial);
        padding-inline: var(--space-7);
    }
}

/* =========== Brand =========== */
.brand {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    text-decoration: none;
    line-height: 1;
}
.brand-logo-image {
    width: 56px;
    height: 56px;
    object-fit: contain;
    border-radius: var(--radius-sm);
    align-self: center;
    flex-shrink: 0;
}
.brand-name {
    font-family: var(--font-display);
    font-weight: var(--weight-extrabold);
    font-size: 26px;
    letter-spacing: -0.02em;
    line-height: 1.2;
    white-space: nowrap;
    color: inherit;
}
.brand-tag {
    display: none;
}

/* =========== Nav links (desktop) =========== */
.nav-links {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-7);
}
.nav-link {
    position: relative;
    font-family: var(--font-ui);
    font-size: 14px;
    font-weight: var(--weight-medium);
    color: inherit;
    padding: 6px 0;
    transition:
        color var(--dur-fast) var(--ease-out),
        opacity var(--dur-fast) var(--ease-out);
}
.nav-link::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 2px;
    background: var(--color-brand-action);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform var(--dur-base) var(--ease-out);
}
.nav-link:hover {
    color: var(--color-brand-action);
    opacity: 1;
}
.navbar--solid .nav-link:hover {
    color: var(--color-white);
}
.navbar--solid .nav-link.is-active { color: var(--color-white); }
.navbar--solid .nav-link::after { background: var(--color-white); }
.navbar--transparent .nav-link:hover,
.navbar--transparent .nav-link.is-active { color: var(--color-brand-muted-inverse); }
.navbar--transparent .nav-link::after { background: var(--color-brand-muted-inverse); }
.nav-link.is-active {
    color: var(--color-brand-action);
    font-weight: var(--weight-semibold);
}
.nav-link.is-active::after {
    transform: scaleX(1);
}

/* =========== Actions =========== */
.navbar-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

.icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: transparent;
    border: none;
    border-radius: var(--radius-pill);
    color: inherit;
    cursor: pointer;
    /* Phase 9 Correction §2: ensure ≥44×44 mobile touch target. */
    min-width: 44px;
    min-height: 44px;
    transition:
        background-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out);
}
.icon-btn:hover {
    background: rgba(0, 0, 0, 0.06);
}
.navbar--solid .icon-btn:hover {
    background: var(--color-brand-overlay);
    color: var(--color-white);
}
.navbar--transparent .icon-btn:hover {
    background: rgba(255, 255, 255, 0.18);
    color: var(--color-text-inverse);
}
.icon-btn[aria-expanded='true'] {
    background: rgba(0, 0, 0, 0.06);
}
.navbar--solid .icon-btn[aria-expanded='true'] {
    background: var(--color-brand-overlay);
}
.navbar--solid .cart-badge {
    background: var(--color-white);
    color: var(--color-brand-primary);
}
.navbar--solid :is(.brand, .nav-link, .icon-btn, .nav-cta):focus-visible {
    outline-color: var(--color-white);
}

.cart-btn {
    position: relative;
}
.cart-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    background: var(--color-brand-action);
    color: var(--color-text-inverse);
    font-family: var(--font-ui);
    font-size: 10px;
    font-weight: var(--weight-bold);
    border-radius: var(--radius-pill);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}


/* =========== Primary CTA — desktop =========== */
.nav-cta {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 10px 18px;
    border: var(--border-width-strong) solid currentColor;
    border-radius: var(--radius-button);
    font-family: var(--font-ui);
    font-weight: var(--weight-semibold);
    font-size: 14px;
    color: inherit;
    background: transparent;
    cursor: pointer;
    transition:
        background-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out),
        border-color var(--dur-fast) var(--ease-out);
}
.nav-cta:hover {
    background: var(--color-brand-action);
    color: var(--color-text-inverse);
    border-color: var(--color-brand-action);
}
.nav-cta:hover .nav-cta-arrow {
    transform: translateX(3px);
}
.nav-cta-arrow {
    transition: transform var(--dur-fast) var(--ease-out);
}

/* =========== Account dropdown =========== */
.account-wrap {
    position: relative;
}
.account-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    min-width: 220px;
    background: var(--color-surface);
    color: var(--color-text);
    border: var(--border-width) solid var(--color-border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    padding: var(--space-2);
    z-index: calc(var(--z-sticky) + 1);
    animation: account-in 180ms var(--ease-out);
}
@keyframes account-in {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.account-greeting {
    padding: var(--space-3) var(--space-3) var(--space-2);
    border-bottom: var(--border-width) solid var(--color-border);
    margin-bottom: var(--space-2);
}
.account-greeting-name {
    display: block;
    font-weight: var(--weight-semibold);
    color: var(--color-text);
}
.account-greeting-hint {
    display: block;
    font-size: 12px;
    color: var(--color-text-muted);
    margin-top: 2px;
}
.account-item {
    display: block;
    width: 100%;
    padding: var(--space-3);
    font-family: var(--font-ui);
    font-size: 14px;
    font-weight: var(--weight-medium);
    color: var(--color-text);
    background: transparent;
    border: none;
    border-radius: var(--radius-sm);
    text-align: left;
    cursor: pointer;
    text-decoration: none;
    transition:
        background-color var(--dur-fast) var(--ease-out),
        color var(--dur-fast) var(--ease-out);
}
.account-item:hover {
    background: var(--neutral-100);
    color: var(--color-text);
}
.account-item--danger {
    color: var(--color-brand-action);
    border-top: var(--border-width) solid var(--color-border);
    margin-top: var(--space-2);
    padding-top: var(--space-3);
    border-radius: 0 0 var(--radius-sm) var(--radius-sm);
}
.account-item--danger:hover {
    background: rgba(228, 38, 61, 0.06);
    color: var(--color-brand-action-hover);
}

/* =========== Mobile menu trigger =========== */
.menu-toggle {
    display: none;
    flex-direction: column;
    justify-content: center;
    width: 44px;
    height: 44px;
    min-width: 44px;
    min-height: 44px;
    padding: 0;
    background: transparent;
    border: none;
    cursor: pointer;
    color: inherit;
}
.menu-toggle span {
    display: block;
    width: 22px;
    height: 2px;
    margin: 2px auto;
    background: currentColor;
    border-radius: 1px;
    transition:
        transform var(--dur-base) var(--ease-out),
        opacity var(--dur-fast) var(--ease-out);
}
.menu-toggle.active span:nth-child(1) {
    transform: translateY(6px) rotate(45deg);
}
.menu-toggle.active span:nth-child(2) {
    opacity: 0;
}
.menu-toggle.active span:nth-child(3) {
    transform: translateY(-6px) rotate(-45deg);
}

/* =====================================================
   MOBILE FULLSCREEN OVERLAY
   ===================================================== */

.mobile-menu {
    position: fixed;
    inset: 0;
    background: var(--color-background);
    color: var(--color-text);
    z-index: var(--z-overlay);
    display: flex;
    flex-direction: column;
    padding: var(--space-6) var(--space-5) var(--space-7);
    /* Phase 9 §10 / §13 / §14: safe-area + menu scroll containment. */
    padding-top: calc(var(--space-6) + env(safe-area-inset-top));
    padding-bottom: calc(var(--space-7) + env(safe-area-inset-bottom));
    animation: mobile-menu-in 280ms var(--ease-out);
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}
@keyframes mobile-menu-in {
    from { opacity: 0; transform: translateY(-12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.mobile-menu-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-7);
}
.mobile-menu-brand {
    font-family: var(--font-display);
    font-weight: var(--weight-extrabold);
    font-size: 22px;
    letter-spacing: -0.02em;
}
.mobile-menu-close {
    width: 44px;
    height: 44px;
    border: none;
    background: transparent;
    cursor: pointer;
    border-radius: var(--radius-pill);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: inherit;
    transition: background-color var(--dur-fast) var(--ease-out);
}
.mobile-menu-close:hover {
    background: var(--neutral-100);
}

.mobile-menu-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
}
.mobile-menu-item {
    display: block;
    padding: var(--space-3) 0;
    font-family: var(--font-display);
    font-weight: var(--weight-bold);
    font-size: clamp(28px, 7vw, 38px);
    line-height: 1.05;
    letter-spacing: -0.02em;
    color: inherit;
    text-decoration: none;
    border-bottom: var(--border-width) solid var(--color-border);
    opacity: 0;
    animation: mobile-item-in 280ms var(--ease-out) forwards;
}
@keyframes mobile-item-in {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.mobile-menu-divider {
    height: var(--border-width);
    background: var(--color-border);
    margin: var(--space-6) 0 var(--space-4);
}

.mobile-menu-account {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-bottom: var(--space-6);
}
.mobile-menu-account-title {
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: var(--weight-bold);
    text-transform: uppercase;
    letter-spacing: var(--tracking-eyebrow);
    color: var(--color-text-muted);
    margin-bottom: var(--space-2);
}
.mobile-menu-account-link {
    display: block;
    padding: var(--space-2) 0;
    font-family: var(--font-ui);
    font-size: 16px;
    font-weight: var(--weight-medium);
    color: inherit;
    background: transparent;
    border: none;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
}
.mobile-menu-account-link--danger {
    color: var(--color-brand-action);
}

.mobile-menu-cta {
    margin-top: auto;
    align-self: stretch;
    padding: 16px 24px;
    border: none;
    border-radius: var(--radius-button);
    background: var(--color-brand-action);
    color: var(--color-text-inverse);
    font-family: var(--font-ui);
    font-weight: var(--weight-bold);
    font-size: 15px;
    cursor: pointer;
    transition: background-color var(--dur-fast) var(--ease-out);
}
.mobile-menu-cta:hover {
    background: var(--color-brand-action-hover);
}

/* =====================================================
   RESPONSIVE BREAKPOINTS
   ===================================================== */

/* Large desktop: keep CTA visible until 1280, then protect nav room */
@media (max-width: 1280px) and (min-width: 1025px) {
    .nav-links {
        gap: var(--space-5);
    }
}

/* Tablet/laptop ↔ mobile switch — protect the six primary links from crowding. */
@media (max-width: 1120px) {
    .nav-links {
        display: none;
    }
    .nav-cta--desktop {
        display: none;
    }
    .menu-toggle {
        display: flex;
    }
    .brand-tag {
        display: none;
    }
}

/* Mobile */
@media (max-width: 768px) {
    .navbar {
        height: var(--navbar-height-mobile);
    }
    .navbar-inner {
        padding-inline: var(--space-5);
        gap: var(--space-3);
    }
    .brand-logo-image { width: 44px; height: 44px; }
    .brand-name {
        font-size: 22px;
    }
    .icon-btn {
        width: 36px;
        height: 36px;
    }
    .cart-badge {
        top: 2px;
        right: 2px;
    }
    /* Hide account icon on small screens — it lives inside mobile menu. */
    .account-btn {
        display: none;
    }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .account-dropdown,
    .mobile-menu,
    .mobile-menu-item {
        animation: none;
    }
    .mobile-menu-item {
        opacity: 1;
        transform: none;
    }
}
</style>
