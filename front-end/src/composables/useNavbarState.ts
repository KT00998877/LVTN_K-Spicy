/**
 * useNavbarState — derive Navbar state from route + scroll position.
 *
 * Used by NavbarHome and any future global customer shell.
 * Phase 2 — replaces scattered booleans (`scrolled`, `open`) in the
 * template with a single reactive state object.
 *
 * State:
 *  - isHome               — current route is `/` (homepage)
 *  - isScrolled           — user has scrolled past the threshold
 *  - isTransparent        — hero state: transparent + white text + no shadow
 *  - isSolid              — solid cream + charcoal text + 1px border
 *  - isMobileMenuOpen
 *  - isAccountMenuOpen
 *
 * On the homepage, `isTransparent` is true only when NOT scrolled.
 * On inner pages, `isTransparent` is always false → solid.
 *
 * Scroll detection: passive `scroll` listener throttled with
 * `requestAnimationFrame`. Cleanup happens on `onBeforeUnmount`.
 *
 * Keyboard: Escape closes open dropdown / mobile menu.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

const SCROLL_THRESHOLD = 32

export function useNavbarState() {
    const route = useRoute()
    const isScrolled = ref(false)
    const isMobileMenuOpen = ref(false)
    const isAccountMenuOpen = ref(false)

    const isHome = computed(() => route.name === 'home')
    // Transparent only on home + not scrolled + not currently in mobile menu.
    const isTransparent = computed(() => isHome.value && !isScrolled.value && !isMobileMenuOpen.value)
    const isSolid = computed(() => !isTransparent.value)

    let ticking = false
    function updateScroll() {
        isScrolled.value = window.scrollY > SCROLL_THRESHOLD
        ticking = false
    }

    function handleScroll() {
        if (ticking) return
        ticking = true
        window.requestAnimationFrame(updateScroll)
    }

    function onKey(e: KeyboardEvent) {
        if (e.key === 'Escape') {
            if (isMobileMenuOpen.value) isMobileMenuOpen.value = false
            else if (isAccountMenuOpen.value) isAccountMenuOpen.value = false
        }
    }

    onMounted(() => {
        if (typeof window === 'undefined') return
        // Sync state with current scroll position (page may reload mid-scroll).
        updateScroll()
        window.addEventListener('scroll', handleScroll, { passive: true })
        window.addEventListener('keydown', onKey)
    })

    onBeforeUnmount(() => {
        if (typeof window === 'undefined') return
        window.removeEventListener('scroll', handleScroll)
        window.removeEventListener('keydown', onKey)
        window.removeEventListener('keydown', onTrapKey)
        // Restore scroll lock if the user navigates while the mobile menu is open.
        if (typeof document !== 'undefined') {
            document.body.classList.remove('is-mobile-menu-open')
        }
    })

    // =====================================================
    // Phase 10 §56 / Correction §1:
    // Mobile menu focus management + Tab cycle trap.
    // Lightweight local containment — no library.
    // =====================================================
    const FOCUSABLE_SELECTOR = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',')

    let lastFocusedTrigger: HTMLElement | null = null

    function getFocusable(): HTMLElement[] {
        if (typeof document === 'undefined') return []
        const menu = document.getElementById('mobile-menu')
        if (!menu) return []
        return Array.from(menu.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
            .filter((el) => el.offsetParent !== null || el === document.activeElement)
    }

    function onTrapKey(e: KeyboardEvent) {
        if (!isMobileMenuOpen.value) return
        if (e.key === 'Tab' || e.key === 'Escape') {
            // Escape is handled in onKey() to also close other dropdowns.
            if (e.key === 'Tab') {
                const items = getFocusable()
                if (items.length === 0) {
                    // Fall back to the dialog root if no focusable child exists.
                    const menu = document.getElementById('mobile-menu')
                    if (menu) {
                        e.preventDefault()
                        menu.focus({ preventScroll: true })
                    }
                    return
                }
                const first = items[0]!
                const last = items[items.length - 1]!
                const active = document.activeElement as HTMLElement | null
                if (e.shiftKey) {
                    if (active === first || !active || active === document.body) {
                        e.preventDefault()
                        last.focus({ preventScroll: true })
                    }
                } else {
                    if (active === last || !active || active === document.body) {
                        e.preventDefault()
                        first.focus({ preventScroll: true })
                    }
                }
            }
        }
    }

    function openMobileMenu() {
        // Capture the trigger that opened the menu (for restoration).
        if (typeof document !== 'undefined') {
            const active = document.activeElement
            lastFocusedTrigger = active instanceof HTMLElement ? active : null
        }
        isMobileMenuOpen.value = true
        isAccountMenuOpen.value = false
        document.body.classList.add('is-mobile-menu-open')
        // Move focus into the menu after the DOM paints.
        if (typeof window !== 'undefined') {
            window.requestAnimationFrame(() => {
                if (typeof document === 'undefined') return
                const items = getFocusable()
                const menu = document.getElementById('mobile-menu')
                if (items.length > 0) {
                    items[0]!.focus({ preventScroll: true })
                } else if (menu) {
                    menu.focus({ preventScroll: true })
                }
            })
            // Trap Tab cycling while the menu is open.
            window.addEventListener('keydown', onTrapKey)
        }
    }

    function closeMobileMenu() {
        isMobileMenuOpen.value = false
        document.body.classList.remove('is-mobile-menu-open')
        if (typeof window !== 'undefined') {
            window.removeEventListener('keydown', onTrapKey)
        }
        // Restore focus to the trigger that opened the menu.
        if (typeof document !== 'undefined' && lastFocusedTrigger && document.contains(lastFocusedTrigger)) {
            lastFocusedTrigger.focus({ preventScroll: true })
        }
        lastFocusedTrigger = null
    }

    function toggleMobileMenu() {
        if (isMobileMenuOpen.value) closeMobileMenu()
        else openMobileMenu()
    }

    function openAccountMenu() { isAccountMenuOpen.value = true }
    function closeAccountMenu() {
        isAccountMenuOpen.value = false
    }
    function toggleAccountMenu() {
        if (isAccountMenuOpen.value) closeAccountMenu()
        else openAccountMenu()
    }

    return {
        isHome,
        isScrolled,
        isTransparent,
        isSolid,
        isMobileMenuOpen,
        isAccountMenuOpen,
        openMobileMenu,
        closeMobileMenu,
        toggleMobileMenu,
        openAccountMenu,
        closeAccountMenu,
        toggleAccountMenu,
    }
}
