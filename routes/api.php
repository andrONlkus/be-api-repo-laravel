<?php

use App\Http\Controllers\Archive\ArchiveController;
use App\Http\Controllers\Archive\MasterController;
use App\Http\Controllers\Legacy\LegacyApiController;
use Illuminate\Support\Facades\Route;

/*
| Endpoint lama Ijazah & Transkrip (kompatibel dengan versi CodeIgniter, tanpa autentikasi)
*/
Route::get('/', [LegacyApiController::class, 'index'])->name('legacy.index');
Route::get('document', [LegacyApiController::class, 'document'])->name('legacy.document');
Route::get('document/nim/{nim}', [LegacyApiController::class, 'document'])->name('legacy.document.nim');
Route::post('document', [LegacyApiController::class, 'upload'])->name('legacy.document.upload');
Route::get('download_file/{filename}/{idDoc?}', [LegacyApiController::class, 'downloadFile'])->name('legacy.download');

// Variasi URL REST_Controller: /api/index, akhiran .json dan /format/json (XML tidak didukung)
foreach (['index', 'index.json', 'index/format/json', 'format/json'] as $uri) {
    Route::get($uri, [LegacyApiController::class, 'index']);
}
foreach (['document.json', 'document/format/json'] as $uri) {
    Route::get($uri, [LegacyApiController::class, 'document']);
    Route::post($uri, [LegacyApiController::class, 'upload']);
}
Route::get('document/nim/{nim}/format/json', [LegacyApiController::class, 'document']);

/*
| API arsip umum: wajib X-API-KEY atau token Sanctum (Authorization: Bearer)
*/
Route::prefix('master')->name('master.')->middleware('arsip.client:read')->group(function () {
    Route::get('categories', [MasterController::class, 'categories'])->name('categories');
    Route::get('departements', [MasterController::class, 'departements'])->name('departements');
    Route::get('tipefiles', [MasterController::class, 'tipefiles'])->name('tipefiles');
});

Route::prefix('archive')->name('archive.')->group(function () {
    Route::middleware('arsip.client:read')->group(function () {
        Route::get('/', [ArchiveController::class, 'index'])->name('index');
        Route::get('{idDoc}', [ArchiveController::class, 'show'])->whereNumber('idDoc')->name('show');
    });

    Route::middleware('arsip.client:write')->group(function () {
        Route::post('/', [ArchiveController::class, 'store'])->name('store');
        Route::post('{idDoc}', [ArchiveController::class, 'update'])->whereNumber('idDoc')->name('update');
    });

    // ?api_key= diizinkan untuk link download langsung
    Route::get('{idDoc}/file/{filename}', [ArchiveController::class, 'download'])
        ->whereNumber('idDoc')->middleware('arsip.client:read,query')->name('file');
});
