<script setup lang="ts">
/**
 * FooterHome — redesigned information layer (Phase 7)
 *
 * Design goals (Phase 7 §34-§50):
 *  - INFORMATION LAYER — not another conversion section.
 *  - Warm cream surface (settles after spicy-red Final CTA).
 *  - Actual data only: restaurant slogan, address, open_hours, phone, email, social URLs.
 *  - Semantic HTML: <footer>, <nav aria-label>, <address>.
 *  - Clean multi-column editorial layout.
 *  - No invented links, no fake social icons.
 *
 * Data sources:
 *  - homeStore.restaurant: address, open_hours, phone, email, slogan, social.
 *  - No new fetches — all already loaded by HomeView.
 *
 * Reuses: RouterLink only. No custom icons, no new components.
 */
import { computed } from 'vue'
import { useHomeStore } from '@/stores/home'

const store = useHomeStore()
const year = new Date().getFullYear()

// Real data — omit if unavailable
const slogan = computed(() => store.restaurant?.slogan ?? '')
const address = computed(() => store.restaurant?.address ?? '')
const openHours = computed(() => store.restaurant?.open_hours ?? '')
const phone = computed(() => store.restaurant?.phone ?? '')
const email = computed(() => store.restaurant?.email ?? '')
const social = computed(() => store.restaurant?.social ?? { facebook: '', instagram: '', tiktok: '' })

const hasSocial = computed(() =>
    Boolean(social.value.facebook || social.value.instagram || social.value.tiktok),
)
const hasPhone = computed(() => Boolean(phone.value))
const hasEmail = computed(() => Boolean(email.value))
</script>

<template>
    <footer class="footer" aria-label="Footer K-SPICY">
        <div class="footer-inner">

            <!-- ===== BRAND COLUMN ===== -->
            <div class="footer-brand">
                <h2 class="footer-wordmark" aria-label="K-SPICY">
                    <img src="/logo/logo.png" width="128" height="128" alt="" loading="lazy" class="footer-logo" />
                </h2>
                <p v-if="slogan" class="footer-slogan">{{ slogan }}</p>
                <p v-else class="footer-slogan">
                    Đánh thức vị giác với từng cấp độ cay.
                </p>

                <!-- Social links — only show if real URLs exist -->
                <nav
                    v-if="hasSocial"
                    class="footer-social"
                    aria-label="Mạng xã hội K-SPICY"
                >
                    <a
                        v-if="social.facebook"
                        :href="social.facebook"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="social-link"
                        aria-label="Facebook K-SPICY"
                    >
                        <!-- Facebook icon -->
                        <svg width="18" height="18" viewBox="0 0 24 24"
                            fill="currentColor" aria-hidden="true">
                            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                        </svg>
                        Facebook
                    </a>
                    <a
                        v-if="social.instagram"
                        :href="social.instagram"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="social-link"
                        aria-label="Instagram K-SPICY"
                    >
                        <!-- Instagram icon -->
                        <svg width="18" height="18" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                            <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/>
                            <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>
                        </svg>
                        Instagram
                    </a>
                    <a
                        v-if="social.tiktok"
                        :href="social.tiktok"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="social-link"
                        aria-label="TikTok K-SPICY"
                    >
                        <!-- TikTok icon -->
                        <svg width="18" height="18" viewBox="0 0 24 24"
                            fill="currentColor" aria-hidden="true">
                            <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.27 6.27 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V9.01a8.16 8.16 0 0 0 4.77 1.52V7.09a4.85 4.85 0 0 1-1-.4z"/>
                        </svg>
                        TikTok
                    </a>
                </nav>
            </div>

            <!-- ===== NAVIGATION COLUMNS ===== -->
            <nav class="footer-nav" aria-label="Thực đơn">
                <h3 class="nav-heading">Thực đơn</h3>
                <ul class="nav-list">
                    <li><router-link to="/thuc-don">Tất cả món</router-link></li>
                    <li><router-link to="/thuc-don">Mì cay</router-link></li>
                    <li><router-link to="/thuc-don">Tokbokki</router-link></li>
                    <li><router-link to="/thuc-don">Gà Hàn Quốc</router-link></li>
                    <li><router-link to="/ban-chay">Bán chạy</router-link></li>
                </ul>
            </nav>

            <nav class="footer-nav" aria-label="Nhà hàng">
                <h3 class="nav-heading">Nhà hàng</h3>
                <ul class="nav-list">
                    <li><router-link to="/khong-gian">Không gian</router-link></li>
                    <li><router-link to="/dat-ban">Đặt bàn</router-link></li>
                    <li><router-link to="/about">Về chúng tôi</router-link></li>
                    <li><router-link to="/lien-he">Liên hệ</router-link></li>
                </ul>
            </nav>

            <!-- ===== CONTACT / ADDRESS COLUMN ===== -->
            <address class="footer-contact" aria-label="Thông tin liên hệ">
                <h3 class="nav-heading">Liên hệ</h3>

                <div v-if="address" class="contact-row">
                    <svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true" class="contact-icon">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <span>{{ address }}</span>
                </div>

                <div v-if="openHours" class="contact-row">
                    <svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true" class="contact-icon">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span>{{ openHours }}</span>
                </div>

                <div v-if="hasPhone" class="contact-row">
                    <svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true" class="contact-icon">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.06 6.06l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                    <a :href="`tel:${phone}`" class="contact-link">{{ phone }}</a>
                </div>

                <div v-if="hasEmail" class="contact-row">
                    <svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true" class="contact-icon">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <a :href="`mailto:${email}`" class="contact-link">{{ email }}</a>
                </div>
            </address>
        </div>

        <!-- ===== COPYRIGHT BAR ===== -->
        <div class="footer-bottom">
            <span>© {{ year }} K-SPICY. Mì cay Hàn Quốc chính hiệu.</span>
        </div>
    </footer>
</template>

<style scoped>
/* =========================================================
   FOOTER — INFORMATION LAYER (Phase 7)
   Warm cream surface, editorial columns, semantic HTML.
   ========================================================= */

.footer {
    background-color: var(--color-brand-dark);
    color: var(--color-text-inverse);
    border-top: 1px solid var(--color-brand-border-inverse);
}

.footer-inner {
    max-width: var(--container-wide, 1280px);
    margin: 0 auto;
    padding: var(--space-12) var(--space-6) var(--space-9);
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.5fr;
    gap: var(--space-9);
}

/* ===== Brand column ===== */
.footer-brand {
    max-width: 320px;
}

.footer-wordmark {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-bottom: var(--space-4);
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: var(--weight-black);
    letter-spacing: -0.03em;
    color: var(--color-text-inverse);
}

.footer-logo { width: 128px; height: 128px; object-fit: contain; border-radius: var(--radius-lg); }

.footer-slogan {
    font-size: 14px;
    line-height: 1.65;
    color: var(--color-brand-muted-inverse);
    margin-bottom: var(--space-5);
}

/* Social links — subtle pill style */
.footer-social {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
}

.social-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: transparent;
    color: var(--color-brand-muted-inverse);
    border: 1px solid var(--color-brand-border-inverse);
    border-radius: 6px;
    font-size: 12px;
    font-weight: var(--weight-semibold);
    text-decoration: none;
    transition:
        color 200ms var(--ease-out),
        border-color 200ms var(--ease-out),
        background-color 200ms var(--ease-out);
}

.social-link:hover {
    color: var(--color-text-inverse);
    border-color: var(--color-brand-muted-inverse);
    background: var(--color-brand-border-inverse);
}

.social-link:focus-visible {
    outline: 2px solid var(--color-brand-highlight);
    outline-offset: 2px;
}

/* ===== Navigation columns ===== */
.footer-nav,
.footer-contact {
    min-width: 0;
}

.nav-heading {
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: var(--weight-extrabold);
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: var(--color-brand-muted-inverse);
    margin-bottom: var(--space-4);
}

.nav-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
}

.nav-list a {
    display: inline-block;
    font-size: 14px;
    color: var(--color-brand-muted-inverse);
    text-decoration: none;
    line-height: 1.4;
    transition: color 180ms var(--ease-out);
}

.nav-list a:hover {
    color: var(--color-text-inverse);
}

.nav-list a:focus-visible {
    outline: 2px solid var(--color-brand-highlight);
    outline-offset: 2px;
    border-radius: 2px;
}

/* ===== Contact / address column ===== */
.footer-contact {
    font-style: normal;
}

.contact-row {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
    font-size: 14px;
    line-height: 1.5;
    color: var(--color-brand-muted-inverse);
}

.contact-icon {
    flex-shrink: 0;
    margin-top: 2px;
    color: var(--color-brand-muted-inverse);
}

.contact-link {
    color: var(--color-brand-muted-inverse);
    text-decoration: none;
    transition: color 180ms var(--ease-out);
}

.contact-link:hover {
    color: var(--color-white);
}

.contact-link:focus-visible {
    outline: 2px solid var(--color-brand-highlight);
    outline-offset: 2px;
    border-radius: 2px;
}

/* ===== Copyright bar ===== */
.footer-bottom {
    max-width: var(--container-wide, 1280px);
    margin: 0 auto;
    padding: var(--space-4) var(--space-6) var(--space-6);
    border-top: 1px solid var(--color-brand-border-inverse);
    font-size: 12px;
    color: var(--color-brand-muted-inverse);
}

/* =========================================================
   Tablet (1024)
   ========================================================= */
@media (max-width: 1023px) {
    .footer-inner {
        grid-template-columns: 1fr 1fr;
        gap: var(--space-8);
    }
    .footer-brand {
        grid-column: 1 / -1;
        max-width: 100%;
    }
}

/* =========================================================
   Mobile (640) — Phase 9: tighter single column + tap targets
   ========================================================= */
@media (max-width: 640px) {
    .footer-inner {
        grid-template-columns: 1fr;
        gap: var(--space-8);
        padding: var(--space-9) var(--space-5) var(--space-7);
    }
    .footer-brand {
        grid-column: auto;
    }
    .footer-bottom {
        padding: var(--space-4) var(--space-5) var(--space-5);
    }
    /* Phase 9 §72: ≥44px tap target on nav + contact links. */
    .nav-list a,
    .contact-link {
        display: inline-block;
        min-height: 44px;
        line-height: 44px;
    }
    .nav-heading {
        margin-bottom: var(--space-2);
    }
    .contact-row {
        min-height: 44px;
    }
}
</style>
