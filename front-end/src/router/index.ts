import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { useStaffStore } from '@/stores/staff'
import type { StaffRole } from '@/types/staff'

const HomeView = () => import('../views/HomeView.vue')
const AboutView = () => import('../views/AboutView.vue')
const ReservationView = () => import('../views/ReservationView.vue')
const MenuView = () => import('../views/MenuView.vue')
const FeaturedMenuView = () => import('../views/FeaturedMenuView.vue')
const FavoritesView = () => import('../views/FavoritesView.vue')
const CheckoutView = () => import('../views/CheckoutView.vue')
const LoginView = () => import('../views/auth/LoginView.vue')
const RegisterView = () => import('../views/auth/RegisterView.vue')
const ForgotPasswordView = () => import('../views/auth/ForgotPasswordView.vue')
const ResetPasswordView = () => import('../views/auth/ResetPasswordView.vue')
const AccountView = () => import('../views/auth/AccountView.vue')
const OrderDetailView = () => import('../views/OrderDetailView.vue')

// ===== Staff (lazy) =====
const StaffLayout          = () => import('../components/staff/StaffLayout.vue')
// Waiter
const WaiterTablesView     = () => import('../views/staff/waiter/WaiterTablesView.vue')
const WaiterOrdersView     = () => import('../views/staff/waiter/WaiterOrdersView.vue')
const WaiterOrderDetailView= () => import('../views/staff/waiter/WaiterOrderDetailView.vue')
const WaiterReservationsView = () => import('../views/staff/waiter/WaiterReservationsView.vue')
// Kitchen
const KitchenQueueView     = () => import('../views/staff/kitchen/KitchenQueueView.vue')
const KitchenPreparingView = () => import('../views/staff/kitchen/KitchenPreparingView.vue')
const KitchenReadyView     = () => import('../views/staff/kitchen/KitchenReadyView.vue')
const KitchenAvailabilityView = () => import('../views/staff/kitchen/KitchenAvailabilityView.vue')
const DailyStockSheetView  = () => import('../views/staff/DailyStockSheetView.vue')
// Cashier
const CashierInvoicesView  = () => import('../views/staff/cashier/CashierInvoicesView.vue')
const CashierReservationsView = () => import('../views/staff/cashier/CashierReservationsView.vue')
const CashierRevenueView   = () => import('../views/staff/cashier/CashierRevenueView.vue')
// Manager
const ManagerDashboardView = () => import('../views/staff/manager/ManagerDashboardView.vue')
const ManagerMenuView      = () => import('../views/staff/manager/ManagerCatalogView.vue')
const ManagerInventoryView = () => import('../views/staff/manager/ManagerStockView.vue')
const ManagerStaffView     = () => import('../views/staff/manager/ManagerStaffView.vue')
const ManagerReportView    = () => import('../views/staff/manager/ManagerReportView.vue')
// Phase 7 — Statistics dashboard (end-to-end với Phase 6 API).
const ManagerStatisticsView = () => import('../views/staff/manager/ManagerStatisticsView.vue')
// Phase 3 — Promotions & Vouchers management
const ManagerPromotionsView = () => import('../views/staff/manager/ManagerPromotionsView.vue')
const ManagerMarketingCampaignsView = () => import('../views/staff/manager/ManagerMarketingCampaignsView.vue')
// Phase 6.1 — AI Marketing Studio
const ManagerMarketingView = () => import('../views/staff/manager/ManagerMarketingView.vue')
// Phase 2 — Inventory & Recipe
const ManagerIngredientsView = () => import('../views/staff/manager/ManagerIngredientsView.vue')
const ManagerRecipeView      = () => import('../views/staff/manager/ManagerRecipeView.vue')
// Phase 4 — Stock flow
const ManagerStockImportsView       = () => import('../views/staff/manager/ManagerStockImportsView.vue')
const ManagerStockTransactionsView  = () => import('../views/staff/manager/ManagerStockTransactionsView.vue')
const ManagerStocktakeView          = () => import('../views/staff/manager/ManagerStocktakeView.vue')
// Phase F — Tiêu hao món bán + yêu cầu nhập kho từ bếp
const ManagerConsumptionView        = () => import('../views/staff/manager/ManagerConsumptionView.vue')
// Phase 3 — Combo nước uống (gợi ý món do quản lý cấu hình)
const ManagerSuggestionsView        = () => import('../views/staff/manager/ManagerSuggestionsView.vue')
const ManagerDeliveryRatingView    = () => import('../views/staff/manager/ManagerDeliveryRatingView.vue')
const ManagerSecurityView          = () => import('../views/staff/manager/ManagerSecurityView.vue')

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        { path: '/payment/vnpay/result', name: 'vnpay-result', component: () => import('../views/VNPayResultView.vue'), meta: { requiresAuth: true, title: 'Kết quả thanh toán VNPay' } },
        { path: '/lien-he', name: 'contact', component: () => import('../views/CustomerContentView.vue'), meta: { title: 'Liên hệ' } },
        { path: '/tin-vui', redirect: '/tin-vui/tin-moi' },
        { path: '/tin-vui/:section(tin-moi|tin-khai-truong|tin-khuyen-mai)', name: 'customer-news', component: () => import('../views/CustomerContentView.vue'), meta: { title: 'Tin vui' } },
        { path: '/bai-viet/:slug', alias: '/blog/:slug', name: 'customer-article', component: () => import('../views/CustomerContentView.vue'), meta: { title: 'Bài viết' } },
        // Phase 5 — Campaigns public (gắn nhiều voucher + promotion vào 1 chiến dịch).
        { path: '/khuyen-mai', name: 'campaign-list', component: () => import('../views/CampaignListView.vue'), meta: { title: 'Khuyến mãi đang chạy' } },
        { path: '/khuyen-mai/:slug', name: 'campaign-detail', component: () => import('../views/CampaignDetailView.vue'), meta: { title: 'Chi tiết chiến dịch' } },
        { path: '/ban-chay', name: 'best-sellers', component: () => import('../views/CustomerContentView.vue'), meta: { title: 'Món bán chạy' } },
        { path: '/khong-gian', name: 'customer-tables', component: () => import('../views/CustomerContentView.vue'), meta: { title: 'Không gian nhà hàng' } },
        { path: '/dat-ban', name: 'reservation', component: ReservationView, meta: { title: 'Đặt bàn - Mì Cay Hàn Quốc' } },
        {
            path: '/',
            name: 'home',
            component: HomeView,
            meta: { title: 'Mì Cay Hàn Quốc - Korean Spice House' },
        },
        {
            path: '/thuc-don',
            name: 'menu',
            component: MenuView,
            meta: { title: 'Thực đơn - Mì Cay Hàn Quốc' },
        },
        {
            path: '/mon-dac-sac',
            name: 'featured-menu',
            component: FeaturedMenuView,
            meta: { title: 'Món đặc sắc - Mì Cay Hàn Quốc' },
        },
        {
            path: '/mon-yeu-thich',
            name: 'favorites',
            component: FavoritesView,
            meta: { title: 'Món yêu thích - Mì Cay Hàn Quốc', requiresAuth: true },
        },
        {
            path: '/thanh-toan',
            name: 'checkout',
            component: CheckoutView,
            meta: { title: 'Thanh toán - Mì Cay Hàn Quốc', requiresAuth: true },
        },
        {
            path: '/about',
            name: 'about',
            component: AboutView,
            meta: { title: 'Giới thiệu - Mì Cay Hàn Quốc' },
        },
        // ===== Auth =====
        {
            path: '/dang-nhap',
            name: 'login',
            component: LoginView,
            meta: { title: 'Đăng nhập - Mì Cay Hàn Quốc', guestOnly: true },
        },
        {
            path: '/dang-ky',
            name: 'register',
            component: RegisterView,
            meta: { title: 'Đăng ký tài khoản - Mì Cay Hàn Quốc', guestOnly: true },
        },
        {
            path: '/quen-mat-khau',
            name: 'forgot-password',
            component: ForgotPasswordView,
            meta: { title: 'Quên mật khẩu - Mì Cay Hàn Quốc', guestOnly: true },
        },
        {
            path: '/dat-lai-mat-khau',
            name: 'reset-password',
            component: ResetPasswordView,
            meta: { title: 'Đặt lại mật khẩu - Mì Cay Hàn Quốc', guestOnly: true },
        },
        {
            path: '/tai-khoan',
            name: 'account',
            component: AccountView,
            meta: { title: 'Tài khoản của tôi', requiresAuth: true },
        },
        {
            path: '/tai-khoan/don-hang/:id',
            name: 'order-detail',
            component: OrderDetailView,
            meta: { title: 'Chi tiết đơn hàng', requiresAuth: true },
        },

        // ===== Staff =====
        {
            path: '/staff',
            redirect: '/staff/login',
        },
        {
            path: '/staff/login',
            name: 'staff-login',
            redirect: (to) => ({ name: 'login', query: to.query }),
            meta: { title: 'Đăng nhập nhân viên - Mì Cay Hàn Quốc', guestOnlyStaff: true },
        },
        {
            path: '/staff/waiter',
            component: StaffLayout,
            meta: { requiresStaffRole: ['WAITER'] as StaffRole[] },
            children: [
                { path: '', redirect: '/staff/waiter/tables' },
                { path: 'tables',        name: 'waiter-tables',        component: WaiterTablesView,     meta: { title: 'Sơ đồ bàn',         subtitle: 'Quản lý bàn và phiên phục vụ' } },
                { path: 'orders',        name: 'waiter-orders',        component: WaiterOrdersView,     meta: { title: 'Đơn đang phục vụ', subtitle: 'Theo dõi các đơn đang mở' } },
                { path: 'orders/:id',    name: 'waiter-order-detail',  component: WaiterOrderDetailView, meta: { title: 'Chi tiết đơn',     subtitle: 'Thêm món cho khách' } },
                { path: 'reservations',  name: 'waiter-reservations',  component: WaiterReservationsView, meta: { title: 'Đặt bàn trước',  subtitle: 'Quản lý lịch đặt bàn' } },
            ],
        },
        {
            path: '/staff/kitchen',
            component: StaffLayout,
            meta: { requiresStaffRole: ['KITCHEN'] as StaffRole[] },
            children: [
                { path: '', redirect: '/staff/kitchen/queue' },
                { path: 'queue',     name: 'kitchen-queue',     component: KitchenQueueView,     meta: { title: 'Món chờ chế biến', subtitle: 'Danh sách món cần làm' } },
                { path: 'preparing', name: 'kitchen-preparing', component: KitchenPreparingView, meta: { title: 'Đang chế biến',    subtitle: 'Món đang nấu' } },
                { path: 'ready',     name: 'kitchen-ready',     component: KitchenReadyView,     meta: { title: 'Món đã sẵn sàng',  subtitle: 'Chờ phục vụ mang ra' } },
                { path: 'availability', name: 'kitchen-availability', component: KitchenAvailabilityView, meta: { title: 'Báo hết món', subtitle: 'Cập nhật món và topping còn bán' } },
                { path: 'daily-stock', name: 'kitchen-daily-stock', component: DailyStockSheetView, meta: { title: 'Sổ kho hằng ngày', subtitle: 'Đếm và chốt tồn cuối ngày' } },
            ],
        },
        {
            path: '/staff/cashier',
            component: StaffLayout,
            meta: { requiresStaffRole: ['CASHIER'] as StaffRole[] },
            children: [
                { path: '', redirect: '/staff/cashier/invoices' },
                { path: 'invoices',     name: 'cashier-invoices',     component: CashierInvoicesView,     meta: { title: 'Quản lý hóa đơn',  subtitle: 'Hóa đơn cần thanh toán và đã thanh toán' } },
                { path: 'online-orders',name: 'cashier-online-orders',component: () => import('../views/staff/manager/ManagerOnlineOrdersView.vue'), meta: { title: 'Đơn giao hàng', subtitle: 'Xác nhận, từ chối, bàn giao shipper' } },
                { path: 'reservations', name: 'cashier-reservations', component: CashierReservationsView, meta: { title: 'Đặt bàn trước',    subtitle: 'Xác nhận đặt bàn' } },
                { path: 'revenue',      name: 'cashier-revenue',      component: CashierRevenueView,      meta: { title: 'Báo cáo doanh thu', subtitle: 'Theo ngày / tháng' } },
            ],
        },
        {
            path: '/staff/shipper',
            component: StaffLayout,
            meta: { requiresStaffRole: ['SHIPPER'] as StaffRole[] },
            children: [
                { path: '', name: 'shipper-home', component: () => import('../views/staff/shipper/ShipperHomeView.vue'), meta: { title: 'Giao hàng', subtitle: 'Đơn đang giao và lịch sử' } },
            ],
        },
        {
            path: '/staff/manager',
            component: StaffLayout,
            meta: { requiresStaffRole: ['MANAGER'] as StaffRole[] },
            children: [
                { path: '', redirect: '/staff/manager/dashboard' },
                { path: 'accounts', name: 'manager-accounts', component: () => import('../views/staff/manager/ManagerAccountsView.vue'), meta: { title: 'Quản lý tài khoản' } },
                { path: 'permissions', name: 'manager-permissions', component: () => import('../views/staff/manager/ManagerAccountsView.vue'), meta: { title: 'Phân quyền' } },
                { path: 'security', name: 'manager-security', component: ManagerSecurityView, meta: { title: 'Bảo mật đăng nhập', subtitle: 'Xác thực hai lớp cho tài khoản quản lý' } },
                { path: 'orders', name: 'manager-orders', component: () => import('../views/staff/manager/ManagerSalesView.vue'), meta: { title: 'Quản lý đơn hàng' } },
                { path: 'invoices', name: 'manager-invoices', component: () => import('../views/staff/manager/ManagerSalesView.vue'), meta: { title: 'Quản lý hóa đơn' } },
                { path: 'online-orders', name: 'manager-online-orders', component: () => import('../views/staff/manager/ManagerOnlineOrdersView.vue'), meta: { title: 'Đơn giao hàng', subtitle: 'Xác nhận, từ chối, bàn giao shipper' } },
                { path: 'live-chat', name: 'manager-live-chat', component: () => import('../views/staff/manager/ManagerLiveChatView.vue'), meta: { title: 'Tư vấn trực tiếp', subtitle: 'Trả lời khách đang chờ' } },
                { path: 'ingredients', name: 'manager-ingredients', component: ManagerInventoryView, meta: { title: 'Nguyên liệu', subtitle: 'Danh sách nguyên liệu và đơn vị tính' } },
                { path: 'ingredients/manager', name: 'manager-ingredients-crud', component: ManagerIngredientsView, meta: { title: 'Quản lý nguyên liệu', subtitle: 'CRUD nguyên liệu — Phase 2' } },
                { path: 'recipes', name: 'manager-recipes', component: ManagerRecipeView, meta: { title: 'Công thức món ăn', subtitle: 'Định lượng nguyên liệu theo món' } },
                { path: 'stock-imports', name: 'manager-stock-imports', component: ManagerStockImportsView, meta: { title: 'Phiếu nhập kho', subtitle: 'DRAFT → CONFIRMED, có audit history' } },
                { path: 'stock-transactions', name: 'manager-stock-transactions', component: ManagerStockTransactionsView, meta: { title: 'Lịch sử giao dịch kho', subtitle: 'IMPORT · EXPORT · WASTE · ADJUSTMENT' } },
                { path: 'stocktake', name: 'manager-stocktake', component: ManagerStocktakeView, meta: { title: 'Kiểm kê kho', subtitle: 'OPEN → COMPLETED, tạo ADJUSTMENT khi hoàn tất' } },
                { path: 'consumption', name: 'manager-consumption', component: ManagerConsumptionView, meta: { title: 'Tiêu hao món bán', subtitle: 'Đã bán món nào trừ bao nhiêu nguyên liệu' } },
                { path: 'daily-stock', name: 'manager-daily-stock', component: DailyStockSheetView, meta: { title: 'Sổ kho hằng ngày', subtitle: 'Xem số bếp đã chốt' } },
                { path: 'categories', name: 'manager-categories', component: ManagerMenuView, meta: { title: 'Danh mục món ăn', subtitle: 'Phân nhóm và sắp xếp thực đơn' } },
                { path: 'dashboard', name: 'manager-dashboard', component: ManagerDashboardView, meta: { title: 'Dashboard',           subtitle: 'Tổng quan hoạt động nhà hàng' } },
                { path: 'menu',      name: 'manager-menu',      component: ManagerMenuView,      meta: { title: 'Quản lý thực đơn',   subtitle: 'Món ăn, danh mục, topping' } },
                { path: 'combos',    name: 'manager-combos',    component: ManagerSuggestionsView, meta: { title: 'Combo nước uống', subtitle: 'Gợi ý món đi kèm và giá combo' } },
                { path: 'delivery-ratings', name: 'manager-delivery-ratings', component: ManagerDeliveryRatingView, meta: { title: 'Đánh giá giao hàng', subtitle: 'Chấm điểm dịch vụ giao và phân tích theo shipper' } },
                { path: 'inventory', name: 'manager-inventory', component: ManagerInventoryView, meta: { title: 'Kho & Nguyên liệu',  subtitle: 'Nhập / xuất / điều chỉnh tồn kho' } },
                { path: 'staff',     name: 'manager-staff',     component: ManagerStaffView,     meta: { title: 'Quản lý nhân viên',  subtitle: 'Tài khoản, phân quyền' } },
                { path: 'statistics', name: 'manager-statistics', component: ManagerStatisticsView, meta: { title: 'Thống kê tổng quan', subtitle: 'Dashboard end-to-end: KPI · Charts · Kho' } },
                { path: 'promotions', name: 'manager-promotions', component: ManagerPromotionsView, meta: { title: 'Khuyến mãi & Voucher', subtitle: 'Quản lý mã giảm giá và chương trình khuyến mãi' } },
                { path: 'marketing-campaigns', name: 'manager-marketing-campaigns', component: ManagerMarketingCampaignsView, meta: { title: 'Ưu đãi khung giờ vàng', subtitle: 'Gửi ưu đãi kèm mã voucher tới khách thân thiết' } },
                { path: 'marketing', name: 'manager-marketing', component: ManagerMarketingView, meta: { title: 'AI Marketing Studio', subtitle: 'Sinh & duyệt content tự động' } },
                { path: 'report',    name: 'manager-report',    component: ManagerReportView,    meta: { title: 'Báo cáo doanh thu', subtitle: 'Báo cáo gọn nhẹ (Phase 2)' } },
            ],
        },
    ],
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) return savedPosition
        if (to.hash) return { el: to.hash, behavior: 'smooth' }
        return { top: 0 }
    },
})

/**
 * Auth guards
 *  - requiresAuth:        bắt buộc đăng nhập customer
 *  - guestOnly:           chỉ cho khách (đã login → redirect về trang chủ)
 *  - requiresStaffRole:   chỉ cho staff có role phù hợp
 *  - guestOnlyStaff:      chỉ cho staff chưa đăng nhập
 *
 * Quy tắc tách biệt 2 hệ thống đăng nhập:
 *  - Customer auth  → dùng /dang-nhap, /tai-khoan
 *  - Staff auth     → dùng /staff/login, /staff/{role}/*
 *  - Customer đã đăng nhập KHÔNG được vào /staff/login (và ngược lại).
 *
 * Lưu ý: dùng callback form `next` cho tương thích, nhưng đã await init()
 * để state được hydrate xong trước khi check guard (tránh race condition).
 */
const staffRoots: Record<StaffRole, string> = { WAITER: '/staff/waiter/tables', KITCHEN: '/staff/kitchen/queue', CASHIER: '/staff/cashier/invoices', SHIPPER: '/staff/shipper', MANAGER: '/staff/manager/dashboard' }

router.beforeEach(async (to, _from) => {
    const authStore = useAuthStore()
    const staffStore = useStaffStore()

    // Đợi hydrate xong từ localStorage (nếu chưa hydrate) trước khi check guard.
    await authStore.init()
    await staffStore.init()

    // Nhân viên chỉ sử dụng giao diện của vai trò được API xác nhận.
    if (staffStore.isLoggedIn && staffStore.currentRole && !to.meta.requiresStaffRole) {
        return staffRoots[staffStore.currentRole]
    }

    // ============== Customer auth ==============
    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } }
    }

    if (to.meta.guestOnly && authStore.isAuthenticated) {
        return { name: 'home' }
    }

    // ============== Tách biệt 2 hệ thống đăng nhập ==============
    // Customer đã đăng nhập → chặn không cho vào trang staff (kể cả /staff/login)
    if (to.path.startsWith('/staff') && authStore.isAuthenticated) {
        return { name: 'home' }
    }

    // Nhân viên đã đăng nhập → chặn không cho vào trang auth customer
    if (
        staffStore.isLoggedIn
        && (to.name === 'login' || to.name === 'register')
        && !to.path.startsWith('/staff')
    ) {
        return staffStore.currentRole ? staffRoots[staffStore.currentRole] : '/'
    }

    // ============== Staff auth + role guard ==============
    if (to.meta.requiresStaffRole) {
        const roles = to.meta.requiresStaffRole as StaffRole[]
        if (!staffStore.isLoggedIn || !staffStore.currentRole) {
            return { name: 'login', query: { redirect: to.fullPath } }
        }
        if (!roles.includes(staffStore.currentRole)) {
            // Không đúng role → về trang mặc định của role hiện tại
            const root: Record<StaffRole, string> = {
                WAITER:  '/staff/waiter/tables',
                KITCHEN: '/staff/kitchen/queue',
                CASHIER: '/staff/cashier/invoices',
                SHIPPER: '/staff/shipper', MANAGER: '/staff/manager/dashboard',
            }
            return root[staffStore.currentRole] ?? '/staff/login'
        }
    }

    if (to.meta.guestOnlyStaff && staffStore.isLoggedIn && staffStore.currentRole) {
        const root: Record<StaffRole, string> = {
            WAITER:  '/staff/waiter/tables',
            KITCHEN: '/staff/kitchen/queue',
            CASHIER: '/staff/cashier/invoices',
            SHIPPER: '/staff/shipper', MANAGER: '/staff/manager/dashboard',
        }
        return root[staffStore.currentRole]
    }
})

router.afterEach((to) => {
    const title = (to.meta?.title as string) || 'Mì Cay Hàn Quốc'
    if (typeof document !== 'undefined') {
        document.title = title
    }
})

export default router

/**
 * Scope <body> cho foundation form CSS.
 *
 * Khi route là staff → xoá class 'customer-scope' trên <body>, nghĩa là các
 * selector trong `assets/foundation.css` (form-control, form-label, ...) sẽ
 * KHÔNG áp dụng cho các raw <input>/<select>/<textarea> ở staff views.
 *
 * Ngược lại: route customer (homepage, menu, checkout, reservation, login,
 * register, account, ...) → gắn class 'customer-scope' để form primitives
 * hoạt động.
 *
 * Việc này đảm bảo Phase 1 redesign form không vô tình đổi radius/padding/
 * focus glow của Staff/Admin UI.
 */
const STAFF_PREFIX = '/staff'

router.afterEach((to) => {
    if (typeof document === 'undefined') return
    const isStaffRoute = to.path.startsWith(STAFF_PREFIX)
    document.body.classList.toggle('customer-scope', !isStaffRoute)
})
