<?php

Route::get('/reservations/options', [\App\Http\Controllers\Api\ReservationController::class, 'options']);
Route::post('/reservations', [\App\Http\Controllers\Api\ReservationController::class, 'store'])->middleware('throttle:30,1');

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LoyaltyTierController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\MenuController;
Route::get('/news', [\App\Http\Controllers\Api\CustomerPagesController::class,'news']);
Route::get('/news/{slug}', [\App\Http\Controllers\Api\CustomerPagesController::class,'article']);
Route::post('/contact', [\App\Http\Controllers\Api\CustomerPagesController::class,'contact'])->middleware('throttle:5,1');
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffDashboardController;
use App\Http\Controllers\Api\StaffInvoiceController;
use App\Http\Controllers\Api\StaffKitchenController;
use App\Http\Controllers\Api\StaffOrderController;
use App\Http\Controllers\Api\StaffTableController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/* Loyalty tiers — public list (marketing) + customer me.
   Route đặt trước các nhóm có prefix khác để URL /api/loyalty/* không bị ghi đè. */
Route::get('/loyalty/tiers', [LoyaltyTierController::class, 'index']);
Route::middleware('auth:sanctum')->get('/loyalty/me', [LoyaltyTierController::class, 'me']);

// Phase 5 — Campaigns public (khách xem chiến dịch đang chạy).
Route::get('/campaigns', [\App\Http\Controllers\Api\PublicCampaignController::class, 'index']);
Route::get('/campaigns/{slug}', [\App\Http\Controllers\Api\PublicCampaignController::class, 'show'])
    ->where('slug', '[a-z0-9-]+');

Route::get('/checkout/config', [\App\Http\Controllers\Api\CheckoutQuoteController::class, 'config']);
Route::post('/checkout/quote', [\App\Http\Controllers\Api\CheckoutQuoteController::class, 'quote'])->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Reviews — Public xem review của món + Customer CRUD + Manager moderation
|--------------------------------------------------------------------------
*/
$reviewController = \App\Http\Controllers\Api\ReviewController::class;
// Public: lấy reviews đã approved + summary rating cho 1 món
Route::get('/menu-items/{id}/reviews',         [$reviewController, 'indexForMenuItem'])->whereNumber('id');
Route::get('/menu-items/{id}/reviews/summary', [$reviewController, 'summaryForMenuItem'])->whereNumber('id');

// Customer endpoints
Route::middleware('auth:sanctum')->group(function () use ($reviewController) {
    Route::get('/reviews/menu-item/{id}/eligibility', [$reviewController, 'eligibility'])->whereNumber('id');
    Route::get('/reviews/me',                        [$reviewController, 'myReviews']);
    Route::post('/reviews',                          [$reviewController, 'store']);
    Route::post('/reviews/{id}',                     [$reviewController, 'update'])->whereNumber('id');
    Route::delete('/reviews/{id}',                   [$reviewController, 'destroy'])->whereNumber('id');
});

// Manager endpoints
Route::middleware(['auth:sanctum', 'staff:MANAGER'])->prefix('reviews')->group(function () use ($reviewController) {
    Route::get('/',          [$reviewController, 'adminIndex']);
    Route::patch('/{id}/hide', [$reviewController, 'hide'])->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Vouchers — public list + customer preview + manager CRUD
|--------------------------------------------------------------------------
*/
Route::prefix('vouchers')->group(function () {
    // Public + customer routes
    Route::get('/', [\App\Http\Controllers\Api\VoucherController::class, 'index']);
    Route::post('/preview', [\App\Http\Controllers\Api\VoucherController::class, 'preview'])
        ->middleware('auth:sanctum');

    // Loyalty ledger cho khách
    Route::middleware('auth:sanctum')->get('/loyalty', [\App\Http\Controllers\Api\LoyaltyController::class, 'index']);

    // Manager CRUD
    Route::middleware(['auth:sanctum', 'staff:MANAGER'])->group(function () {
        $controller = \App\Http\Controllers\Api\VoucherController::class;
        Route::post('/', [$controller, 'store']);
        Route::put('/{id}', [$controller, 'update'])->whereNumber('id');
        Route::delete('/{id}', [$controller, 'destroy'])->whereNumber('id');
    });
});
Route::post('/payments/vnpay/create', [\App\Http\Controllers\Api\VNPayController::class, 'create'])->middleware(['auth:sanctum', 'throttle:20,1']);
Route::get('/payments/vnpay/return', [\App\Http\Controllers\Api\VNPayController::class, 'returnUrl']);
Route::get('/payments/vnpay/ipn', [\App\Http\Controllers\Api\VNPayController::class, 'ipn']);
Route::get('/payments/{code}/status', [\App\Http\Controllers\Api\VNPayController::class, 'status'])->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Trang chủ - Public API (không cần đăng nhập)
|--------------------------------------------------------------------------
*/
Route::prefix('home')->group(function () {
    Route::get('/',        [HomeController::class, 'index']);
    Route::get('/restaurant', [HomeController::class, 'restaurant']);
    Route::get('/stats',      [HomeController::class, 'stats']);
    Route::get('/categories', [HomeController::class, 'categories']);
    Route::get('/featured',   [HomeController::class, 'featured']);
    Route::get('/best-sellers', [HomeController::class, 'bestSellers']);
    Route::get('/tables',     [HomeController::class, 'tables']);
    Route::get('/blogs',      [HomeController::class, 'blogs']);
});

/*
|--------------------------------------------------------------------------
| Trang thực đơn - Public API
|--------------------------------------------------------------------------
*/
Route::prefix('menu')->group(function () {
    Route::get('/',         [MenuController::class, 'index']);
    Route::get('/{slug}',   [MenuController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Auth - Đăng ký / Đăng nhập / Quên mật khẩu
|--------------------------------------------------------------------------
| - register: rate limit 3/5ph/IP
| - login:    rate limit 5/1ph/IP+identifier
| - forgot:   rate limit 3/5ph/IP+email
| - reset:    không cần rate limit (đã có token expire)
*/
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth-register')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
    });
    Route::middleware('throttle:auth-login')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
    });
    Route::middleware('throttle:auth-forgot')->group(function () {
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    });
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout',          [AuthController::class, 'logout']);
        Route::post('/logout-all',      [AuthController::class, 'logoutAll']);
        Route::get('/me',               [AuthController::class, 'me']);
        Route::put('/me',               [AuthController::class, 'updateProfile']);
        Route::post('/me/avatar',       [AuthController::class, 'updateAvatar']);
        Route::delete('/me/avatar',     [AuthController::class, 'deleteAvatar']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

/*
|--------------------------------------------------------------------------
| Orders - Khách hàng xem đơn của mình
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('orders')->group(function () {
    Route::get('/me',         [OrderController::class, 'index']);
    Route::get('/me/{id}',    [OrderController::class, 'show'])->whereNumber('id');
    // Khách hàng xem hướng dẫn đường đi của shipper đến đơn mình (chỉ đơn ONLINE của chính user).
    Route::get('/me/{id}/route', [\App\Http\Controllers\Api\OnlineOrderController::class, 'customerRoute'])->whereNumber('id');
    // Khách hàng xác nhận đã nhận hàng → COMPLETED + tích điểm thưởng
    Route::post('/me/{id}/confirm-receipt', [OrderController::class, 'confirmReceipt'])->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Cart - Giỏ hàng (yêu cầu đăng nhập)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('cart')->group(function () {
    Route::get('/',             [CartController::class, 'index']);
    Route::post('/',            [CartController::class, 'store']);
    Route::delete('/',          [CartController::class, 'clear']);
    Route::get('/count',        [CartController::class, 'count']);
    Route::get('/toppings',     [CartController::class, 'toppings']);
    Route::post('/checkout',    [CartController::class, 'checkout']);

    Route::put('/items/{id}',   [CartController::class, 'update'])->whereNumber('id');
    Route::delete('/items/{id}',[CartController::class, 'destroy'])->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Staff Auth - Đăng nhập / Đăng xuất riêng cho nhân viên
|--------------------------------------------------------------------------
| Phân biệt với /api/auth/* (customer):
|  - Staff phải có role WAITER/KITCHEN/CASHIER/MANAGER (không chấp nhận CUSTOMER)
|  - Token Sanctum với name = 'staff-spa'
*/
Route::prefix('staff')->group(function () {
    Route::post('/auth/login', [StaffAuthController::class, 'login'])->middleware('throttle:auth-login');

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::post('/auth/logout', [StaffAuthController::class, 'logout']);
        Route::get('/auth/me',      [StaffAuthController::class, 'me']);

        Route::middleware('staff:MANAGER,KITCHEN,SHIPPER')->group(function () {
            Route::get('/notifications', [\App\Http\Controllers\Api\StaffNotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\StaffNotificationController::class, 'unreadCount']);
            Route::patch('/notifications/{id}/read', [\App\Http\Controllers\Api\StaffNotificationController::class, 'read'])->whereUuid('id');
        });

        // ----- Tables + Table sessions -----
        Route::get('/tables',                       [StaffTableController::class, 'index'])->middleware('staff:WAITER,CASHIER,MANAGER');
        Route::patch('/tables/{id}/status',         [StaffTableController::class, 'updateStatus'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::patch('/tables/{id}/request-payment',[StaffTableController::class, 'requestPayment'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');

        Route::post('/table-sessions',               [StaffTableController::class, 'openSession'])->middleware('staff:WAITER,MANAGER');
        Route::patch('/table-sessions/{id}/move',   [StaffTableController::class, 'moveSession'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::patch('/table-sessions/{id}/close',  [StaffTableController::class, 'closeSession'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::patch('/table-sessions/{id}/cancel', [StaffTableController::class, 'cancelSession'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');

        // ----- Reservations (waiter xem, xác nhận) -----
        Route::get('/reservations',                        [StaffTableController::class, 'listReservations'])->middleware('staff:WAITER,CASHIER,MANAGER');
        Route::patch('/reservations/{id}/status',          [StaffTableController::class, 'updateReservationStatus'])->whereNumber('id')->middleware('staff:WAITER,CASHIER,MANAGER');

        // ----- Orders + Items -----
        Route::get('/orders',                                          [StaffOrderController::class, 'index'])->middleware('staff:WAITER,CASHIER,MANAGER');
        Route::post('/orders',                                         [StaffOrderController::class, 'store'])->middleware('staff:WAITER,MANAGER');
        Route::get('/orders/{id}',                                     [StaffOrderController::class, 'show'])->whereNumber('id')->middleware('staff:WAITER,CASHIER,MANAGER');
        Route::post('/orders/{id}/items',                              [StaffOrderController::class, 'addItem'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::post('/orders/{id}/submit-items', [StaffOrderController::class, 'submitItems'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::post('/orders/{id}/demo-pay', [StaffOrderController::class, 'demoPay'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::post('/orders/{id}/request-invoice', [StaffOrderController::class, 'requestInvoice'])->whereNumber('id')->middleware('staff:WAITER,MANAGER');
        Route::patch('/orders/{oid}/items/{iid}',                      [StaffOrderController::class, 'updateItem'])->whereNumber(['oid', 'iid'])->middleware('staff:WAITER,MANAGER');
        Route::patch('/orders/{oid}/items/{iid}/status',               [StaffOrderController::class, 'updateItemStatus'])->whereNumber(['oid', 'iid'])->middleware('staff:WAITER,MANAGER');
        // Cập nhật trạng thái tổng của đơn hàng (WAITER/CASHIER/MANAGER)
        Route::patch('/orders/{id}/status',                            [StaffOrderController::class, 'updateStatus'])->whereNumber('id')->middleware('staff:WAITER,CASHIER,MANAGER');

        // ----- Kitchen (bếp) -----
        Route::get('/service-notifications', [\App\Http\Controllers\Api\ServiceNotificationController::class, 'index'])->middleware('staff:WAITER,MANAGER');
        Route::post('/kitchen/items/{oid}/{iid}/remind', [\App\Http\Controllers\Api\ServiceNotificationController::class, 'remind'])->whereNumber(['oid', 'iid'])->middleware('staff:KITCHEN,MANAGER');
        Route::get('/kitchen/items',                                   [StaffKitchenController::class, 'items'])->middleware('staff:KITCHEN,MANAGER');
        Route::patch('/kitchen/items/{oid}/{iid}/status',               [StaffKitchenController::class, 'updateStatus'])->whereNumber(['oid', 'iid'])->middleware('staff:KITCHEN,MANAGER');
        Route::post('/kitchen/orders/{oid}/notify-shipper',             [StaffKitchenController::class, 'notifyShipper'])->whereNumber('oid')->middleware('staff:KITCHEN,MANAGER');

        // Sổ kho ngày: tài khoản bếp trưởng (KITCHEN) ghi/chốt, quản lý chỉ xem.
        Route::get('/daily-stock-sheets', [\App\Http\Controllers\Api\DailyStockSheetController::class, 'index'])->middleware('staff:KITCHEN,MANAGER');
        Route::get('/daily-stock-sheets/{date}', [\App\Http\Controllers\Api\DailyStockSheetController::class, 'show'])->middleware('staff:KITCHEN,MANAGER');
        Route::put('/daily-stock-sheets/{date}', [\App\Http\Controllers\Api\DailyStockSheetController::class, 'save'])->middleware('staff:KITCHEN');
        Route::post('/daily-stock-sheets/{date}/close', [\App\Http\Controllers\Api\DailyStockSheetController::class, 'close'])->middleware('staff:KITCHEN');

        // ----- Invoices (thu ngân) -----
        Route::post('/invoices/export-order/{orderId}', [StaffInvoiceController::class, 'exportOrder'])->whereNumber('orderId')->middleware('staff:CASHIER,MANAGER');
        Route::get('/invoices',                                        [StaffInvoiceController::class, 'index'])->middleware('staff:CASHIER,MANAGER');
        Route::get('/invoices/{id}',                                   [StaffInvoiceController::class, 'show'])->whereNumber('id')->middleware('staff:CASHIER,MANAGER');
        Route::post('/invoices/from-order/{orderId}',                  [StaffInvoiceController::class, 'createFromOrder'])->whereNumber('orderId')->middleware('staff:WAITER,CASHIER,MANAGER');
        Route::patch('/invoices/{id}/pay',                             [StaffInvoiceController::class, 'pay'])->whereNumber('id')->middleware('staff:CASHIER,MANAGER');
        // Cập nhật trạng thái hóa đơn thủ công (CASHIER/MANAGER)
        Route::patch('/invoices/{id}/status',                          [StaffInvoiceController::class, 'updateStatus'])->whereNumber('id')->middleware('staff:CASHIER,MANAGER');
        // Nhập giảm giá thủ công cho hóa đơn (chỉ MANAGER — quyết định tài chính)
        Route::patch('/invoices/{id}/discount',                        [StaffInvoiceController::class, 'updateDiscount'])->whereNumber('id')->middleware('staff:MANAGER');

        Route::middleware('staff:MANAGER')->prefix('dashboard')->group(function () {
            Route::get('/accounts', [\App\Http\Controllers\Api\ManagerAccountController::class, 'index']);
            Route::post('/accounts', [\App\Http\Controllers\Api\ManagerAccountController::class, 'save']);
            Route::patch('/accounts/{id}', [\App\Http\Controllers\Api\ManagerAccountController::class, 'save'])->whereNumber('id');
            Route::get('/roles', [\App\Http\Controllers\Api\ManagerAccountController::class, 'roles']);
            Route::get('/orders', [\App\Http\Controllers\Api\ManagerSalesController::class, 'index']);
            Route::get('/orders/{id}', [\App\Http\Controllers\Api\ManagerSalesController::class, 'order'])->whereNumber('id');
            Route::get('/invoices', [\App\Http\Controllers\Api\ManagerSalesController::class, 'invoices']);
            Route::get('/ingredients', [\App\Http\Controllers\Api\ManagerInventoryController::class, 'index']);
            Route::post('/ingredients', [\App\Http\Controllers\Api\ManagerInventoryController::class, 'save']);
            Route::patch('/ingredients/{id}', [\App\Http\Controllers\Api\ManagerInventoryController::class, 'save'])->whereNumber('id');
            Route::get('/inventory-transactions', [\App\Http\Controllers\Api\ManagerInventoryController::class, 'history']);
            Route::post('/inventory-transactions', [\App\Http\Controllers\Api\ManagerInventoryController::class, 'transact']);
            Route::post('/menu-items', [\App\Http\Controllers\Api\ManagerMenuController::class, 'save']);
            Route::post('/menu-images', [\App\Http\Controllers\Api\ManagerMenuController::class, 'upload']);
            Route::get('/categories', [\App\Http\Controllers\Api\ManagerMenuController::class, 'categories']);
            Route::post('/categories', [\App\Http\Controllers\Api\ManagerMenuController::class, 'saveCategory']);
            Route::patch('/categories/{id}', [\App\Http\Controllers\Api\ManagerMenuController::class, 'saveCategory'])->whereNumber('id');
        });
        // ----- Dashboard (quản lý) -----
        Route::get('/dashboard/stats',                                 [StaffDashboardController::class, 'stats'])->middleware('staff:MANAGER');

        // ----- Manager: CRUD Ingredients (Phase 2 module) -----
        Route::middleware('staff:MANAGER')->prefix('manager/ingredients')->group(function () {
            Route::get('categories', [\App\Http\Controllers\Api\ManagerIngredientController::class, 'categories']);
            Route::post('categories', [\App\Http\Controllers\Api\ManagerIngredientController::class, 'saveCategory']);
            Route::patch('categories/{id}', [\App\Http\Controllers\Api\ManagerIngredientController::class, 'saveCategory'])->whereNumber('id');
            Route::post('{ingredientId}/packagings', [\App\Http\Controllers\Api\ManagerIngredientController::class, 'savePackaging'])->whereNumber('ingredientId');
            Route::patch('{ingredientId}/packagings/{id}', [\App\Http\Controllers\Api\ManagerIngredientController::class, 'savePackaging'])->whereNumber('ingredientId')->whereNumber('id');
            Route::get('/',       [\App\Http\Controllers\Api\ManagerIngredientController::class, 'index']);
            Route::post('/',      [\App\Http\Controllers\Api\ManagerIngredientController::class, 'store']);
            Route::get('{id}',    [\App\Http\Controllers\Api\ManagerIngredientController::class, 'show'])->whereNumber('id');
            Route::patch('{id}',  [\App\Http\Controllers\Api\ManagerIngredientController::class, 'update'])->whereNumber('id');
            Route::get('{id}/blocking-references',
                        [\App\Http\Controllers\Api\ManagerIngredientController::class, 'blockingReferences'])->whereNumber('id');
        });

        // ----- Manager: Recipe (công thức món ăn) -----
        Route::middleware('staff:MANAGER')->prefix('manager/menu-items/{menuItem}/recipe')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ManagerRecipeController::class, 'show'])->whereNumber('menuItem');
            Route::put('/', [\App\Http\Controllers\Api\ManagerRecipeController::class, 'replace'])->whereNumber('menuItem');
        });

        // ----- Manager: Tồn dự kiến (Phase E) -----
        // GET /api/staff/manager/inventory-balance
        Route::get('/manager/inventory-balance',
                    [\App\Http\Controllers\Api\ManagerInventoryBalanceController::class, 'index'])
            ->middleware('staff:MANAGER');

        // ----- Manager: Stock Imports (Phase 4) -----
        Route::middleware('staff:MANAGER')->prefix('manager/stock-imports')->group(function () {
            Route::get('/',   [\App\Http\Controllers\Api\ManagerStockImportController::class, 'index']);
            Route::post('/',  [\App\Http\Controllers\Api\ManagerStockImportController::class, 'store']);
            Route::get('{id}',[\App\Http\Controllers\Api\ManagerStockImportController::class, 'show'])->whereNumber('id');
            Route::patch('{id}',[\App\Http\Controllers\Api\ManagerStockImportController::class, 'update'])->whereNumber('id');
            Route::delete('{id}',[\App\Http\Controllers\Api\ManagerStockImportController::class, 'destroy'])->whereNumber('id');
            Route::post('{id}/confirm',[\App\Http\Controllers\Api\ManagerStockImportController::class, 'confirm'])->whereNumber('id');
        });

        // ----- Manager: Stocktake (Phase 4) -----
        Route::middleware('staff:MANAGER')->prefix('manager/stocktakes')->group(function () {
            Route::get('/',   [\App\Http\Controllers\Api\ManagerStocktakeController::class, 'index']);
            Route::post('/',  [\App\Http\Controllers\Api\ManagerStocktakeController::class, 'store']);
            Route::get('{id}',[\App\Http\Controllers\Api\ManagerStocktakeController::class, 'show'])->whereNumber('id');
            Route::post('{id}/complete',[\App\Http\Controllers\Api\ManagerStocktakeController::class, 'complete'])->whereNumber('id');
            Route::post('{id}/cancel',  [\App\Http\Controllers\Api\ManagerStocktakeController::class, 'cancel'])->whereNumber('id');
        });

        // ----- Manager: Statistics (Phase 6) -----
        Route::middleware('staff:MANAGER')->prefix('manager/statistics')->group(function () {
            $ctrl = \App\Http\Controllers\Api\ManagerStatisticsController::class;
            Route::get('/overview',       [$ctrl, 'overview']);
            Route::get('/revenue',        [$ctrl, 'revenue']);
            Route::get('/orders',         [$ctrl, 'orders']);
            Route::get('/top-products',   [$ctrl, 'topProducts']);
            Route::get('/low-products',   [$ctrl, 'lowProducts']);
            Route::get('/categories',     [$ctrl, 'categories']);
            Route::get('/payments',       [$ctrl, 'payments']);
            Route::get('/order-types',    [$ctrl, 'orderTypes']);
            Route::get('/customers',      [$ctrl, 'customers']);
            Route::get('/reservations',   [$ctrl, 'reservations']);
            Route::get('/peak-hours',     [$ctrl, 'peakHours']);
            Route::get('/inventory',      [$ctrl, 'inventory']);
            Route::get('/top-ingredients',[$ctrl, 'topIngredients']);
            Route::get('/food-cost',      [$ctrl, 'foodCost']);
        });

        // ----- Manager: Voucher CRUD nâng cấp (Phase 3) -----
        Route::middleware('staff:MANAGER')->prefix('manager/vouchers')->group(function () {
            $ctrl = \App\Http\Controllers\Api\ManagerVoucherController::class;
            Route::get('/',               [$ctrl, 'index']);
            Route::post('/preview',       [$ctrl, 'preview']);
            Route::post('/',              [$ctrl, 'store']);
            Route::get('/{id}',           [$ctrl, 'show'])->whereNumber('id');
            Route::put('/{id}',           [$ctrl, 'update'])->whereNumber('id');
            Route::delete('/{id}',        [$ctrl, 'destroy'])->whereNumber('id');
            Route::post('/{id}/toggle',   [$ctrl, 'toggle'])->whereNumber('id');
            Route::get('/{id}/usages',    [$ctrl, 'usages'])->whereNumber('id');
        });

        // Phase 4 — Promotion CRUD (khuyến mãi tự động, không cần khách nhập mã).
        Route::middleware('staff:MANAGER')->prefix('manager/promotions')->group(function () {
            $ctrl = \App\Http\Controllers\Api\ManagerPromotionController::class;
            Route::get('/',               [$ctrl, 'index']);
            Route::post('/preview',       [$ctrl, 'preview']);
            Route::post('/',              [$ctrl, 'store']);
            Route::get('/{id}',           [$ctrl, 'show'])->whereNumber('id');
            Route::put('/{id}',           [$ctrl, 'update'])->whereNumber('id');
            Route::delete('/{id}',        [$ctrl, 'destroy'])->whereNumber('id');
            Route::post('/{id}/toggle',   [$ctrl, 'toggle'])->whereNumber('id');
            Route::get('/{id}/usages',    [$ctrl, 'usages'])->whereNumber('id');
        });

        // Phase 5 — Campaign CRUD (chiến dịch gom nhóm nhiều voucher/promotion).
        Route::middleware('staff:MANAGER')->prefix('manager/campaigns')->group(function () {
            $ctrl = \App\Http\Controllers\Api\ManagerCampaignController::class;
            Route::get('/',               [$ctrl, 'index']);
            Route::post('/',              [$ctrl, 'store']);
            Route::get('/{id}',           [$ctrl, 'show'])->whereNumber('id');
            Route::put('/{id}',           [$ctrl, 'update'])->whereNumber('id');
            Route::delete('/{id}',        [$ctrl, 'destroy'])->whereNumber('id');
            Route::post('/{id}/toggle',   [$ctrl, 'toggle'])->whereNumber('id');
            Route::get('/{id}/stats',     [$ctrl, 'stats'])->whereNumber('id');
        });

        // Phase 6.2 — Blog CRUD (tin tức, khuyến mãi, sự kiện nhà hàng).
        Route::middleware('staff:MANAGER')->prefix('manager/blogs')->group(function () {
            $ctrl = \App\Http\Controllers\Api\ManagerBlogController::class;
            Route::get('/',           [$ctrl, 'index']);
            Route::post('/',          [$ctrl, 'store']);
            Route::get('/{id}',       [$ctrl, 'show'])->whereNumber('id');
            Route::put('/{id}',       [$ctrl, 'update'])->whereNumber('id');
            Route::delete('/{id}',    [$ctrl, 'destroy'])->whereNumber('id');
            Route::post('/{id}/publish', [$ctrl, 'publish'])->whereNumber('id');
            Route::post('/{id}/archive', [$ctrl, 'archive'])->whereNumber('id');
            // Upload ảnh (cover + inline cho TinyMCE).
            Route::post('/upload-image', [\App\Http\Controllers\Api\ManagerBlogUploadController::class, 'store']);
        });

        Route::get('/cashier-reports', [\App\Http\Controllers\Api\CashierReportController::class, 'index'])->middleware('staff:CASHIER,MANAGER');
        Route::get('/cashier-reports/{id}', [\App\Http\Controllers\Api\CashierReportController::class, 'show'])->whereNumber('id')->middleware('staff:CASHIER,MANAGER');
        Route::post('/cashier-reports/preview', [\App\Http\Controllers\Api\CashierReportController::class, 'preview'])->middleware('staff:CASHIER,MANAGER');
        Route::post('/cashier-reports/close', [\App\Http\Controllers\Api\CashierReportController::class, 'close'])->middleware('staff:CASHIER,MANAGER');
        Route::get('/dashboard/revenue',                               [StaffDashboardController::class, 'revenue'])->middleware('staff:CASHIER,MANAGER');
        Route::get('/dashboard/menu-items',                           [\App\Http\Controllers\Api\ManagerMenuController::class, 'index'])->middleware('staff:MANAGER');
        Route::patch('/dashboard/menu-items/{id}',                    [\App\Http\Controllers\Api\ManagerMenuController::class, 'save'])->whereNumber('id')->middleware('staff:MANAGER');
        Route::patch('/menu-items/{id}/availability',                  [\App\Http\Controllers\Api\ManagerMenuController::class, 'setAvailability'])->whereNumber('id')->middleware('staff:KITCHEN,MANAGER');
        Route::get('/menu-items/availability',                         [\App\Http\Controllers\Api\ManagerMenuController::class, 'availabilityIndex'])->middleware('staff:KITCHEN,MANAGER');
        Route::patch('/menu-items/{id}/auto-availability',             [\App\Http\Controllers\Api\ManagerMenuController::class, 'setAutoAvailability'])->whereNumber('id')->middleware('staff:MANAGER');
        Route::get('/dashboard/staff-users',                          [StaffDashboardController::class, 'staffUsers'])->middleware('staff:MANAGER');
        Route::patch('/dashboard/staff-users/{id}',                   [StaffDashboardController::class, 'updateStaffUser'])->whereNumber('id')->middleware('staff:MANAGER');

        // ----- Reports (export Excel/PDF) -----
        // Endpoint duy nhất cho cả 5 loại. Phân quyền chi tiết xử lý trong ReportService::authorizeType (CASHIER chỉ export revenue/orders/shift).
        Route::get('/reports/export', [\App\Http\Controllers\Api\ReportController::class, 'export'])->middleware('auth:sanctum', 'staff:CASHIER,MANAGER');

        // ----- Loyalty tier adjustment (manager chỉnh tay total_spent) -----
        Route::post('/loyalty/{user}/adjust', [\App\Http\Controllers\Api\LoyaltyTierController::class, 'adjust'])
            ->whereNumber('user')
            ->middleware('auth:sanctum', 'staff:MANAGER');
    });
});

// Online orders: payment and fulfillment have separate transitions.
Route::middleware('auth:sanctum')->post('/orders/me/{id}/simulate-payment', [\App\Http\Controllers\Api\OnlineOrderController::class, 'pay'])->whereNumber('id');
Route::middleware('auth:sanctum')->post('/orders/me/{id}/cancel', [\App\Http\Controllers\Api\OnlineOrderController::class, 'customerCancel'])->whereNumber('id');
// Shipper stats (đếm đơn + tổng COD thu hôm nay)
Route::middleware(['auth:sanctum', 'staff:SHIPPER,MANAGER'])
    ->get('/staff/deliveries/stats', [\App\Http\Controllers\Api\OnlineOrderController::class, 'deliveryStats']);
Route::middleware(['auth:sanctum', 'staff:CASHIER,MANAGER'])->prefix('staff/online-orders')->group(function () {
    $controller = \App\Http\Controllers\Api\OnlineOrderController::class;
    Route::get('/', [$controller, 'index']);
    Route::get('/shippers', [$controller, 'shippers']);
    Route::post('/{id}/confirm', [$controller, 'confirm'])->whereNumber('id');
    Route::post('/{id}/reject', [$controller, 'reject'])->whereNumber('id');
    Route::post('/{id}/handover', [$controller, 'handover'])->whereNumber('id');
});
Route::middleware(['auth:sanctum', 'staff:SHIPPER'])->prefix('staff/deliveries')->group(function () {
    $controller = \App\Http\Controllers\Api\OnlineOrderController::class;
    Route::get('/', [$controller, 'deliveries']);
    Route::get('/{id}/route', [$controller, 'deliveryRoute'])->whereNumber('id'); // Chỉ đường từ quán → khách
    Route::post('/{id}/pickup',   [$controller, 'pickedUp'])->whereNumber('id');   // Bước 1: Đã lấy món
    Route::post('/{id}/arrived',  [$controller, 'arrived'])->whereNumber('id');   // Bước 2: Đã tới điểm giao
    Route::post('/{id}/complete', [$controller, 'delivered'])->whereNumber('id');  // Bước 3: Giao thành công
    Route::post('/{id}/issue',   [$controller, 'issue'])->whereNumber('id');      // Sự cố
});

/*
|--------------------------------------------------------------------------
| Phase 6.1 — AI Marketing Studio (chỉ MANAGER)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'staff:MANAGER'])->prefix('staff/manager/marketing')->group(function () {
    // Context gợi ý cho form tạo asset (campaigns, menu items, promotions, tones,...)
    Route::get('/context', [\App\Http\Controllers\Api\Manager\MarketingContextController::class, 'show']);

    // CRUD asset + sinh version mới
    $assets = \App\Http\Controllers\Api\Manager\MarketingAssetController::class;
    Route::get('/assets',                        [$assets, 'index']);
    Route::post('/assets',                       [$assets, 'store']);
    Route::get('/assets/{asset}',                [$assets, 'show'])->whereNumber('asset');
    Route::post('/assets/{asset}/generate',      [$assets, 'generate'])->whereNumber('asset');
    Route::delete('/assets/{asset}',            [$assets, 'destroy'])->whereNumber('asset');
    // Restore asset (ARCHIVED → DRAFT) + hard delete (xoá vĩnh viễn, chỉ khi đã archive).
    Route::patch('/assets/{asset}/restore',     [$assets, 'restore'])->whereNumber('asset');
    Route::delete('/assets/{asset}/force',      [$assets, 'forceDestroy'])->whereNumber('asset');
    // Phase 6.2 — đẩy 1 version lên Blog (tạo Blog DRAFT).
    Route::post('/assets/{asset}/publish-to-blog', [$assets, 'publishToBlog'])->whereNumber('asset');

    // Duyệt version
    $versions = \App\Http\Controllers\Api\Manager\MarketingVersionController::class;
    Route::patch('/assets/{asset}/versions/{version}',
        [$versions, 'update'])
        ->whereNumber(['asset', 'version']);
    Route::patch('/assets/{asset}/versions/{version}/select',
        [$versions, 'select'])
        ->whereNumber(['asset', 'version']);
});

/*
|--------------------------------------------------------------------------
| Phase 11/12/C3 — Chatbot (AI Service + Confirmation Flow)
|--------------------------------------------------------------------------
|
| Read-only chatbot: search menu, list KM, view cart, ...
| User đăng nhập hoặc guest đều dùng được (guest định danh qua cookie).
| Auth KHÔNG bắt buộc — frontend tự quyết (Sanctum optional).
|
| Phase C3: Write tools (cart_add_item, reservation_create) require confirmation.
*/
Route::prefix('chatbot')->group(function () {
    $rateLimit = (string) config('chatbot.rate_limit', '60,1');
    $chatbot = \App\Http\Controllers\Api\ChatbotController::class;

    Route::middleware('throttle:' . $rateLimit)->group(function () use ($chatbot) {
        // Sanctum must be *resolving*, not *required*. Without any auth
        // middleware $request->user() is always null, so a logged-in customer
        // got a guest session (user_id = NULL) and then 404'd on their own
        // session, and the bot never received the user_token its cart/order
        // tools need. auth:sanctum would reject guests, so the middleware runs
        // unauthenticated requests through instead of blocking them.
        Route::middleware(\App\Http\Middleware\AuthenticateSanctumOrGuest::class)->group(function () use ($chatbot) {
        Route::post('/sessions',                    [$chatbot, 'startSession']);
        Route::get('/sessions',                     [$chatbot, 'listSessions']);
        Route::get('/sessions/{id}',                [$chatbot, 'showSession'])->whereNumber('id');
        Route::post('/sessions/{id}/messages',      [$chatbot, 'sendMessage'])->whereNumber('id');
        Route::post('/sessions/{id}/close',         [$chatbot, 'closeSession'])->whereNumber('id');

        // Pending actions (require auth)
        Route::middleware('auth:sanctum')->group(function () use ($chatbot) {
            Route::post('/pending-actions/{id}/confirm',  [$chatbot, 'confirmPendingAction'])->whereNumber('id');
            Route::post('/pending-actions/{id}/cancel',   [$chatbot, 'cancelPendingAction'])->whereNumber('id');
            Route::get('/pending-actions/{id}',           [$chatbot, 'getPendingAction'])->whereNumber('id');
            Route::get('/sessions/{id}/pending-actions', [$chatbot, 'getSessionPendingActions'])->whereNumber('id');
        });
        });
    });
});

/*
|--------------------------------------------------------------------------
| Tư vấn trực tiếp — Live Chat
|--------------------------------------------------------------------------
|
| Chạy SONG SONG với chatbot ở trên: cùng một khách có thể vừa hỏi trợ lý ảo,
| vừa mở phiên tư vấn với nhân viên. Hệ thống này tách hoàn toàn khỏi
| `chat_sessions` nên luồng chatbot cũ không bị ảnh hưởng.
|
| Khách: BẮT BUỘC đăng nhập. Dùng `AuthenticateSanctumOrGuest` (resolve user
| nếu có token, không chặn request) rồi controller tự trả 401 kèm cờ
| `requires_login` — widget cần biết để mời khách đăng nhập thay vì báo lỗi
| kỹ thuật. Khách vãng lai dùng chatbot (không cần đăng nhập) nhưng tư vấn
| trực tiếp cần biết khách là ai để nhân viên phục vụ và lưu lịch sử.
|
| Nhân viên: yêu cầu đăng nhập + role quản lý/nhân viên.
*/
Route::prefix('live-chat')->group(function () {
    $liveChat = \App\Http\Controllers\Api\LiveChatController::class;
    $manager = \App\Http\Controllers\Api\LiveChatManagerController::class;

    Route::middleware(\App\Http\Middleware\AuthenticateSanctumOrGuest::class)->group(function () use ($liveChat) {
        Route::post('/sessions',                    [$liveChat, 'start']);
        Route::get('/sessions/{id}',                [$liveChat, 'show'])->whereNumber('id');
        Route::post('/sessions/{id}/messages',      [$liveChat, 'sendMessage'])->whereNumber('id');
        Route::post('/sessions/{id}/close',         [$liveChat, 'close'])->whereNumber('id');
    });

    // Xác thực kênh realtime riêng cho live chat: route broadcasting chuẩn bị
    // khoá cho nhân viên nên khách không đi qua được.
    Route::middleware(\App\Http\Middleware\AuthenticateSanctumOrGuest::class)
        ->post('/broadcast-auth', [\App\Http\Controllers\Api\LiveChatBroadcastAuthController::class, 'authenticate']);

    // Phía nhân viên: xem hàng chờ, nhận và trả lời phiên.
    Route::middleware(['auth:sanctum', 'staff:MANAGER,WAITER,CASHIER,KITCHEN,SHIPPER'])
        ->prefix('manager')
        ->group(function () use ($manager) {
            Route::get('/queue',                          [$manager, 'queue']);
            Route::get('/sessions/{id}',                 [$manager, 'show'])->whereNumber('id');
            Route::get('/sessions/{id}/suggestions',     [$manager, 'suggestions'])->whereNumber('id');
            Route::post('/sessions/{id}/accept',         [$manager, 'accept'])->whereNumber('id');
            Route::post('/sessions/{id}/messages',       [$manager, 'sendMessage'])->whereNumber('id');
            Route::post('/sessions/{id}/close',          [$manager, 'close'])->whereNumber('id');
        });
});
