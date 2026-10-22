<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->checkOutboundHttps();
        \App\Models\Order::saved(function ($order) {
            if ($order->wasRecentlyCreated || $order->wasChanged()) {
                \App\Events\StaffOrderUpdated::dispatch((int) $order->id, $order->shipper_id ? (int) $order->shipper_id : null);
            }
        });
        \App\Models\OrderItem::saved(function ($item) {
            if (!$item->wasRecentlyCreated && !$item->wasChanged()) return;
            $order = $item->order;
            if ($order) {
                \App\Events\StaffOrderUpdated::dispatch((int) $order->id, $order->shipper_id ? (int) $order->shipper_id : null);
            }
        });
    }

    /**
     * Outbound HTTPS health check.
     *
     * Every payment failure in this project traced back to the same silent cause:
     * the runtime could not reach VNPay, so payments stayed in PENDING forever and
     * the customer saw "the restaurant must verify this payment". Two distinct
     * misconfigurations cause it, and neither raises a visible error:
     *
     *  1. The curl extension is not loaded. Guzzle then falls back to StreamHandler,
     *     which cannot speak HTTPS ("Unable to find the wrapper \"https\"").
     *  2. curl is loaded but has no CA bundle, so verification fails with
     *     "cURL error 60: unable to get local issuer certificate".
     *
     * Detect both at boot and log the exact remediation instead of failing silently
     * on the customer's payment.
     */
    private function checkOutboundHttps(): void
    {
        $queryUrl = (string) config('vnpay.query_url');
        if ($queryUrl === '' || !str_starts_with($queryUrl, 'https://')) {
            return;
        }
        $bundled = base_path('storage/app/cacert.pem');

        if (!\extension_loaded('curl')) {
            logger()->error('PHP curl extension is NOT loaded, so no outbound HTTPS request can succeed. VNPay payments cannot be reconciled.', [
                'loaded_configuration_file' => PHP_CONFIG_FILE_PATH,
                'extension_dir' => (string) ini_get('extension_dir'),
                'hint' => 'Set extension_dir to your real PHP ext folder and uncomment extension=curl and extension=openssl in php.ini.',
            ]);
            return;
        }

        $info = \curl_version();
        $cainfo = (string) ($info['cainfo'] ?? '');
        if ($cainfo !== '' && is_file($cainfo)) {
            return;
        }
        logger()->warning('PHP cURL has no CA bundle, so HTTPS verification fails with "cURL error 60" and VNPay payments stay PENDING.', [
            'curl_cainfo' => $cainfo ?: '(empty)',
            'php_ini' => PHP_CONFIG_FILE_PATH,
            'hint' => is_file($bundled)
                ? 'Add to php.ini: curl.cainfo = '.$bundled
                : 'Download https://curl.se/ca/cacert.pem to storage/app/cacert.pem and set curl.cainfo to it.',
        ]);
    }

    /**
     * Cấu hình rate limiter cho các endpoint nhạy cảm.
     *
     *  - auth-login:    5 attempts / 1 phút / IP+email
     *  - auth-register: 3 attempts / 5 phút / IP
     *  - auth-forgot:   3 attempts / 5 phút / IP
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $identifier = strtolower(trim((string) $request->input('identifier', $request->input('email', ''))));
            return [
                Limit::perMinute(5)->by($request->ip() . '|' . $identifier)
                    ->response(fn () => response()->json([
                        'success' => false,
                        'message' => 'Quá nhiều lần thử. Vui lòng đợi 1 phút.',
                    ], 429)),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinutes(5, 3)->by($request->ip())
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Quá nhiều lần đăng ký. Vui lòng thử lại sau.',
                ], 429));
        });

        RateLimiter::for('auth-forgot', function (Request $request) {
            return Limit::perMinutes(5, 3)->by($request->ip() . '|' . $request->input('email', ''))
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Quá nhiều yêu cầu. Vui lòng thử lại sau 5 phút.',
                ], 429));
        });
    }
}
