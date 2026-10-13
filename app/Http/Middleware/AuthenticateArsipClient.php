<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Autentikasi aplikasi pemanggil API arsip. Dua cara (pilih salah satu):
 * 1. API key: header X-API-KEY (daftar di ARSIP_API_KEYS). Akses penuh.
 * 2. Token Sanctum: header Authorization: Bearer <token> milik ApiClient,
 *    dengan ability archive:read dan/atau archive:write.
 *
 * Pemakaian di route: arsip.client:read atau arsip.client:write
 * Query ?api_key= hanya diterima bila route memakai parameter ke-2 "query" (link download).
 */
class AuthenticateArsipClient
{
    public function handle(Request $request, Closure $next, string $ability = 'read', ?string $allowQueryKey = null): Response
    {
        $key = $request->header('X-API-KEY');
        if (empty($key) && $allowQueryKey === 'query') {
            $key = $request->query('api_key');
        }

        if (! empty($key)) {
            $client = $this->findApiKey((string) $key);
            if ($client === null) {
                throw new UnauthorizedHttpException('X-API-KEY', 'API key tidak valid.');
            }
            $request->attributes->set('arsip_client', $client);
            $this->log($request, $client, 'api-key');

            return $next($request);
        }

        if ($request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();
            if (! $user instanceof ApiClient || ! $user->is_active) {
                throw new UnauthorizedHttpException('Bearer', 'Token tidak valid atau sudah kedaluwarsa.');
            }
            if (! $user->tokenCan('archive:'.$ability)) {
                throw new AccessDeniedHttpException('Token tidak memiliki izin archive:'.$ability.'.');
            }
            $request->attributes->set('arsip_client', $user->name);
            $this->log($request, $user->name, 'token');

            return $next($request);
        }

        throw new UnauthorizedHttpException('X-API-KEY', 'API key atau token wajib dikirim.');
    }

    private function findApiKey(string $key): ?string
    {
        foreach (config('arsip.api_keys', []) as $validKey => $app) {
            if (hash_equals((string) $validKey, $key)) {
                return $app;
            }
        }

        return null;
    }

    // Catat aplikasi pemanggil tanpa menyimpan key/token
    private function log(Request $request, string $client, string $via): void
    {
        Log::info('Arsip API', ['client' => $client, 'via' => $via, 'method' => $request->method(), 'path' => $request->path()]);
    }
}
