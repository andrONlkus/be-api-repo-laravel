<?php

use App\Models\ApiClient;
use Illuminate\Support\Facades\Artisan;

/*
| Manajemen aplikasi pemanggil API arsip yang memakai token Sanctum.
| (Alternatif lain: API key di .env ARSIP_API_KEYS)
*/

Artisan::command('arsip:client-create {name : Nama aplikasi, mis. siakad} {--read-only : Hanya boleh membaca/mencari/download} {--days= : Masa berlaku token (hari), kosong = tidak kedaluwarsa}', function (string $name) {
    $client = ApiClient::firstOrCreate(['name' => $name], ['is_active' => true]);
    $client->update(['is_active' => true]);

    $abilities = $this->option('read-only') ? ['archive:read'] : ['archive:read', 'archive:write'];
    $expiresAt = $this->option('days') ? now()->addDays((int) $this->option('days')) : null;
    $token = $client->createToken('arsip-api', $abilities, $expiresAt);

    $this->info("Token untuk {$name} (simpan sekarang, tidak bisa ditampilkan lagi):");
    $this->line($token->plainTextToken);
    $this->line('Abilities: '.implode(', ', $abilities).' | Kedaluwarsa: '.($expiresAt?->toDateTimeString() ?? 'tidak'));
})->purpose('Buat aplikasi pemanggil API arsip + token Sanctum');

Artisan::command('arsip:client-revoke {name}', function (string $name) {
    $client = ApiClient::where('name', $name)->first();
    if (! $client) {
        $this->error("Client {$name} tidak ditemukan.");

        return 1;
    }
    $count = $client->tokens()->delete();
    $client->update(['is_active' => false]);
    $this->info("Client {$name} dinonaktifkan, {$count} token dicabut.");
})->purpose('Nonaktifkan aplikasi pemanggil dan cabut semua tokennya');

Artisan::command('arsip:client-list', function () {
    $this->table(['Nama', 'Aktif', 'Jumlah token', 'Terakhir dipakai'], ApiClient::withCount('tokens')->get()->map(fn ($c) => [
        $c->name,
        $c->is_active ? 'ya' : 'tidak',
        $c->tokens_count,
        $c->tokens()->max('last_used_at') ?? '-',
    ]));
})->purpose('Daftar aplikasi pemanggil API arsip');
