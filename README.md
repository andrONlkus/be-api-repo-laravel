# be-api-repo (Laravel)

REST API repository arsip dokumen Universitas Prasetiya Mulya. Versi Laravel 12 dari `be-api-repo` (CodeIgniter 3), dengan pola kode yang mengikuti `be-new-lms`.

## Endpoint

### Endpoint lama: Ijazah & Transkrip (tanpa autentikasi, respons identik dengan versi CodeIgniter)

| Method | URL | Keterangan |
|--------|-----|------------|
| GET | `/api` | Health check |
| GET | `/api/document?nim=` atau `/api/document/nim/{nim}` | Dokumen terbaru per NIM, `ListOfFiles` berupa string `URL\|nama;` |
| POST | `/api/document` | Upload Ijazah/Transkrip (`nim`, `school` SBE/STEM, `doc_date`, `expired_date`, `exp_public`, `userfile[]`) |
| GET | `/api/download_file/{filename}/{id_doc}` | Download file |

URL dengan `index.php/` (mis. `/index.php/api/document`) juga tetap berfungsi.

### API arsip umum (wajib autentikasi)

| Method | URL | Keterangan |
|--------|-----|------------|
| GET | `/api/master/categories?level=&parent=&q=` | Kategori aktif |
| GET | `/api/master/departements` | Departemen aktif |
| GET | `/api/master/tipefiles` | Tipe file aktif |
| GET | `/api/archive` | Pencarian (`q`, `id_category`, `include_sub`, `departement_owner`, `tipefile`, `year`, `doc_date`, `created_from`, `created_to`, `public`, `page`, `limit`, `sort`, `order`) |
| POST | `/api/archive` | Create arsip + upload file |
| GET | `/api/archive/{id_doc}` | Detail |
| POST | `/api/archive/{id_doc}` | Update data/file (`file_mode=append\|replace`, `delete_files[]`) |
| GET | `/api/archive/{id_doc}/file/{filename}` | Download (boleh `?api_key=` untuk link langsung) |

Format respons (sama dengan be-new-lms):

```json
{ "status": "S", "message": "Arsip berhasil dibuat.", "data": { ... } }
{ "status": "E", "message": "Validation Error", "errors": { "id_category": ["..."] } }
```

Pencarian mengembalikan `data.data[]` dan `data.meta` (`current_page`, `per_page`, `total`, `last_page`).

## Autentikasi aplikasi pemanggil (pilih salah satu)

1. **API key**: header `X-API-KEY`, didaftarkan di `.env`:
   ```
   ARSIP_API_KEYS=key1:siakad,key2:aplikasi-hr
   ```
   Buat key: `php -r 'echo bin2hex(random_bytes(20));'`. Akses penuh (baca & tulis).

2. **Token Sanctum**: header `Authorization: Bearer <token>`.
   ```bash
   php artisan arsip:client-create siakad              # baca + tulis
   php artisan arsip:client-create portal --read-only   # hanya baca/cari/download
   php artisan arsip:client-create hr --days=365        # token kedaluwarsa 1 tahun
   php artisan arsip:client-list
   php artisan arsip:client-revoke siakad
   ```
   Token hanya ditampilkan sekali saat dibuat.

## Struktur

```
app/Http/Controllers/Legacy/LegacyApiController.php    endpoint lama
app/Http/Controllers/Archive/ArchiveController.php     API arsip umum
app/Http/Controllers/Archive/MasterController.php      master data
app/Http/Middleware/AuthenticateArsipClient.php        API key / token Sanctum
app/Http/Requests/Archive/*                            validasi
app/Services/Archive/*                                 logika arsip, kode arsip, akses kategori, file
app/Services/Legacy/LegacyDocumentService.php          logika Ijazah/Transkrip
config/arsip.php                                       konfigurasi arsip
bootstrap/app.php                                      alias middleware & format error JSON
```

Database:
- koneksi `dms`: database DMS lama (`db_dms`). Tabelnya tidak dibuat atau diubah oleh migration.
- koneksi default: hanya untuk tabel Laravel (`api_clients`, `personal_access_tokens`). Bisa SQLite (`database/database.sqlite`) atau MySQL terpisah.

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi DB_DMS_*, ARSIP_PATH, ARSIP_API_KEYS di .env
php artisan migrate        # tabel api_clients & personal_access_tokens di koneksi default
php artisan serve
```

Server dev/production:
- PHP ≥ 8.2 (dev: 8.3.4), ekstensi pdo_mysql, fileinfo, mbstring.
- Document root diarahkan ke folder `public/`.
- `ARSIP_PATH=/mnt/ossfs/arsip`, dan folder bisa ditulis oleh user web server (apache).
- `ARSIP_LEGACY_BASE_URL=https://be-dev.prasetiyamulya.ac.id/be-api-repo` agar URL di `ListOfFiles` sama dengan versi CodeIgniter (`https://`).
- `storage/` dan `bootstrap/cache/` bisa ditulis oleh web server.

## Test

```bash
php artisan test
```

Feature test memakai SQLite in-memory untuk koneksi `dms` dan `Storage::fake('arsip')`, sehingga tidak menyentuh database dev.
