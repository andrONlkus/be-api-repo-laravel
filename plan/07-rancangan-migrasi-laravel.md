# 07 — Migrasi ke Laravel (disetujui & diimplementasikan, 13 Okt 2026)

Tujuan: memindahkan `be-api-repo` (CodeIgniter 3.1.2) ke Laravel dengan gaya kode yang sama seperti project tim **`be-new-lms`**, tanpa mengganggu aplikasi yang sudah memakai API lama.

## 1. Acuan dari `be-new-lms`

| Aspek | Pola di be-new-lms | Dipakai di migrasi |
|-------|--------------------|--------------------|
| Versi | Laravel 10, PHP ^8.1 (Docker: PHP 8.3-fpm + nginx) | lihat Pertanyaan #2 |
| Struktur | Modul baru (RPS, Jadwal): **Controller tipis → Service**, tanpa interface/Repository | ✅ sama |
| Validasi | FormRequest per aksi (`StoreXxxRequest`), `$request->validated()` | ✅ sama |
| Error | Service melempar `ValidationException` / `NotFoundHttpException` / `AccessDeniedHttpException`, diubah ke JSON oleh `Exceptions/Handler.php` | ✅ sama |
| Respons | Trait `HttpResponses`: `{status: 'S'/'E', message, data}`; 422 → `{status:'E', message:'Validation Error', errors:{field:[...]}}` | lihat Pertanyaan #3 |
| Database | `DB::connection(...)` + Query Builder, transaksi `->transaction()` + `lockForUpdate()` | ✅ koneksi `dms` ke `db_dms`, tanpa migrasi skema |
| File | Disk khusus + `Storage::putFileAs()`, hapus file bila transaksi gagal, `Storage::download()` | ✅ disk `arsip` |
| Route | `routes/api.php`, prefix + `->name('modul.aksi')` | ✅ |
| Test | Feature test PHPUnit, SQLite in-memory, `Storage::fake()` | ✅ |
| Auth | Hanya Sanctum untuk user login; **tidak ada** auth antar aplikasi | perlu dibuat (Pertanyaan #4) |

Yang **tidak** ditiru: controller raksasa/legacy, `DatabaseService` raw SQL generik, `ResponseFormatter`, dan `LogActivityMiddleware` (menyimpan semua header termasuk token secara plain text).

## 2. Struktur Project Baru

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── LegacyDocumentController.php   # /api/document, /api/download_file (kompatibel API lama)
│   │   ├── ArchiveController.php          # /api/archive...
│   │   └── MasterController.php           # /api/master/...
│   ├── Middleware/ApiKeyMiddleware.php    # X-API-KEY / ?api_key=
│   └── Requests/Archive/
│       ├── StoreArchiveRequest.php
│       ├── UpdateArchiveRequest.php
│       └── SearchArchiveRequest.php
├── Services/Archive/
│   ├── ArchiveService.php                 # create/update/search/detail/download
│   ├── KodeArsipService.php               # nomor YY/MM/code_full/N + lock
│   ├── CategoryAccessService.php          # cek category_acc (access >= 3, termasuk induk)
│   └── LegacyDocumentService.php          # logika Ijazah/Transkrip lama
├── Traits/HttpResponses.php               # disalin dari be-new-lms
└── Exceptions/Handler.php
config/arsip.php                           # disk, allowed_types, api_keys (dari .env)
tests/Feature/{ArchiveApiTest, MasterApiTest, LegacyDocumentApiTest}.php
```

## 3. Pemetaan Endpoint

| Endpoint | Sekarang | Laravel |
|----------|----------|---------|
| `GET /api` | `Api::index_get` | sama (health check) |
| `GET /api/document?nim=` | `Api::document_get` | **respons identik** (`ListOfFiles` string), SQL Injection diperbaiki |
| `POST /api/document` | `Api::document_post` | **perilaku identik** (kategori 1075/1076, hapus & ganti file) + bug #16 diperbaiki |
| `GET /api/download_file/{f}/{id}` | `Api::download_file_get` | identik, file harus milik `id_doc` |
| `POST /api/test_ftp` | debug | **dihapus** |
| `/api/archive...`, `/api/master/...` | Archive/Master | sama, semua aturan bisnis & validasi dari dokumen 06 |

URL lama yang memakai `index.php/api/...` tetap bisa dilayani karena Laravel juga berjalan lewat `public/index.php`. Ini akan diverifikasi lewat test.

## 4. Konfigurasi (`.env`, tidak masuk git)

```
DB_DMS_HOST=...  DB_DMS_DATABASE=db_dms  DB_DMS_USERNAME=...  DB_DMS_PASSWORD=...
ARSIP_PATH=/mnt/ossfs/arsip            # lokal: storage/app/arsip
ARSIP_ALLOWED_TYPES=pdf,jpg,jpeg,png,doc,docx,xls,xlsx,ppt,pptx
ARSIP_API_KEYS=key1:siakad,key2:aplikasi-hr
```

## 5. Tahapan

1. Membuat project Laravel baru, koneksi `dms`, disk `arsip`, trait respons, handler, middleware API key.
2. Port API arsip umum (Archive/Master) beserta feature test.
3. Port endpoint lama, dengan test yang membandingkan respons dengan API CodeIgniter.
4. Deploy ke server dev **berdampingan** dengan versi CI (path berbeda), lalu uji paralel dengan Postman.
5. Setelah stabil, pindahkan path `/be-api-repo` ke versi Laravel. Versi CI disimpan untuk rollback.
6. (Opsional, terpisah) Ganti `ossfs` dengan driver S3 ke Alibaba OSS.

## 6. Keputusan

| # | Keputusan |
|---|-----------|
| 1 | Project terpisah: `/Users/andronikusmarintan/Projects/be-api-repo-laravel` |
| 2 | Laravel 12 (server dev PHP 8.3.4) |
| 3 | API arsip umum memakai format be-new-lms `{status, message, data}`; endpoint lama tetap `{status, code, Message, data}` |
| 4 | Autentikasi **dua-duanya**: `X-API-KEY` (dari `.env`) **atau** token Sanctum per aplikasi (`php artisan arsip:client-create`), dengan ability `archive:read` / `archive:write` |
| 5 | Endpoint Ijazah/Transkrip lama tetap tanpa autentikasi dan respons identik |

## 7. Implementasi

Lihat `README.md` di project Laravel. Perbedaan dari rancangan awal:
- Struktur controller: `Controllers/Legacy/LegacyApiController.php`, `Controllers/Archive/{ArchiveController, MasterController}.php`.
- Service: `Services/Archive/{ArchiveService, MasterDataService, CategoryAccessService, KodeArsipService, ArchiveFileStorage}.php`, `Services/Legacy/LegacyDocumentService.php`.
- Tabel Laravel (`api_clients`, `personal_access_tokens`) ada di koneksi default (lokal: SQLite), **bukan** di `db_dms`. Tidak ada perubahan pada database DMS.
- Kunci nomor kode arsip memakai `GET_LOCK('arsip_kode_<code_full>')`, nama yang sama dengan versi CodeIgniter, sehingga aman berjalan berdampingan.
- Timezone `Asia/Jakarta` (`APP_TIMEZONE`).
- Endpoint lama: `ConvertEmptyStringsToNull` dimatikan untuk `/api/document*` supaya `?nim=` kosong tetap menghasilkan "NIM is empty".

Perbaikan pada endpoint lama (respons tetap sama):
- Query NIM aman dari SQL Injection.
- Upload ulang NIM yang sama: file lama dihapus **setelah** file baru & database tersimpan, dan baris `files` lama selalu dibersihkan (bug #16).
- Upload gagal di tengah jalan: file yang sempat tersimpan dihapus kembali.
- `nim` kosong atau `school` bukan SBE/STEM → HTTP 400 (sebelumnya error 500 / data rusak).
- Log download hanya dicatat bila file benar-benar terkirim.
- Format XML (`/format/xml`) dari REST_Controller tidak didukung, hanya JSON.

## 8. Hasil Uji

**Feature test** (`php artisan test`): 17 test, 158 assertion, semua lulus (SQLite in-memory, tidak menyentuh DB dev).

**Perbandingan langsung CodeIgniter vs Laravel** pada DB dev (13 Okt 2026):

| Request | Hasil |
|---------|-------|
| `GET /api`, `/api/document?nim=TEST0000001`, `?nim=TIDAKADA999`, `?nim=`, `/api/document/nim/...` | **Identik** (status HTTP & body; beda hanya host di URL) |
| `GET /api/download_file/...` | Identik: isi file sama (937.245 byte), `Content-Disposition` sama |
| `GET /api/download_file/` file tidak ada | Identik |
| Detail, pencarian (`q`, `include_sub` total 192), master (1507 kategori, 40 departemen, 24 tipe file) | Isi data sama; amplop respons mengikuti format be-new-lms |

**Uji tulis di MySQL dev lewat Laravel:**
- Create `id_doc` **13377** → `26/10/SPM.003.013/4`, lalu update replace: OK.
- Upload lama `TEST0000001` (id_doc 13375): OK, 4 baris `files` lama yang menumpuk dibersihkan.

## 9. Langkah Deploy ke Server Dev

1. Buat repository git untuk project Laravel, push, lalu clone di server dev.
2. `composer install --no-dev --optimize-autoloader`
3. `cp .env.example .env`, `php artisan key:generate`, lalu isi:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=...`
   - `DB_DMS_*` (sama dengan `database.php` CodeIgniter di server)
   - `ARSIP_PATH=/mnt/ossfs/arsip`
   - `ARSIP_API_KEYS=...` (key server, bukan key lokal)
   - `ARSIP_LEGACY_BASE_URL=https://be-dev.prasetiyamulya.ac.id/<path-laravel>`
4. `touch database/database.sqlite && php artisan migrate --force`
5. Izin tulis untuk apache: `storage/`, `bootstrap/cache/`, `database/database.sqlite` (dan foldernya).
6. Apache: alias path baru (mis. `/be-api-repo-laravel`) ke folder `public/`, dengan `AllowOverride All`.
7. `php artisan config:cache && php artisan route:cache`
8. Uji paralel dengan Postman (environment khusus Laravel dev). Setelah stabil, path `/be-api-repo` dipindah ke Laravel.
