<?php

use App\Http\Middleware\AuthenticateArsipClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'arsip.client' => AuthenticateArsipClient::class,
        ]);
        // Endpoint lama membedakan ?nim= (kosong) dan tanpa nim, seperti CodeIgniter
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/document', 'api/document/*'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Format error sama dengan be-new-lms: {status: 'E', message, errors}
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 'E',
                    'message' => 'Validation Error',
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $defaults = [401 => 'Unauthenticated.', 403 => 'Akses ditolak.', 404 => 'Data tidak ditemukan.', 405 => 'Method tidak diizinkan.'];

                return response()->json([
                    'status' => 'E',
                    'message' => $e->getMessage() ?: ($defaults[$e->getStatusCode()] ?? 'Request gagal.'),
                    'errors' => null,
                ], $e->getStatusCode(), $e->getHeaders());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return response()->json([
                    'status' => 'E',
                    'message' => 'Internal server error',
                    'errors' => null,
                ], 500);
            }
        });
    })->create();
