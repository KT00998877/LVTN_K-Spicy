<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        \App\Console\Commands\RagAnswerCommand::class,
        \App\Console\Commands\RagChunkCommand::class,
        \App\Console\Commands\RagEmbedCommand::class,
        \App\Console\Commands\RagExportCommand::class,
        \App\Console\Commands\RagHybridSearchCommand::class,
        \App\Console\Commands\RagSearchCommand::class,
        \App\Console\Commands\RagValidateCommand::class,
        \App\Console\Commands\BenchmarkResetCommand::class,
        \App\Console\Commands\BenchmarkStatsCommand::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'staff:MANAGER,KITCHEN,SHIPPER'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaffRole::class,
        ]);

        // This backend serves a Vue SPA, not server-rendered pages, so there is
        // no `login` route to redirect guests to. Without this, Sanctum's
        // unauthenticated redirect raises "Route [login] not defined" and every
        // auth:sanctum API route answers 500 instead of 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API routes: trả về 401 JSON khi chưa đăng nhập
        // (thay vì redirect về route 'login' không tồn tại)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Unauthenticated.',
                ], 401);
            }
            // Web routes: redirect về /staff/login
            return response()->redirectTo('/staff/login');
        });
        // Hide SQL, stack traces and configuration in payment/checkout responses, including local debug mode.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/payments/*', 'api/cart*', 'api/checkout/*', 'api/orders/me*')) return null;
            if ($request->is('api/payments/vnpay/ipn')) {
                return response()->json(['RspCode' => '99', 'Message' => 'Unable to process payment']);
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['success' => false, 'message' => 'Vui lòng kiểm tra lại thông tin.', 'errors' => $e->errors()], 422);
            }
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status >= 500 ? 'Chưa xử lý được yêu cầu. Vui lòng thử lại hoặc liên hệ nhà hàng.'
                : ($status === 404 ? 'Không tìm thấy đơn hàng hoặc giao dịch.' : $e->getMessage());
            return response()->json(['success' => false, 'message' => $message], $status);
        });
    })->create();
