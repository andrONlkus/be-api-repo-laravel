# Panduan API be-api-repo (Laravel)

API ini terbagi dua:

| Bagian | Prefix | Autentikasi | Format respons |
| --- | --- | --- | --- |
| Endpoint lama Ijazah & Transkrip | `/api`, `/api/document`, `/api/download_file` | Tidak ada | Sama persis dengan versi CodeIgniter |
| API Arsip Umum | `/api/master/*`, `/api/archive*` | `X-API-KEY` atau token Sanctum | Format be-new-lms `{status, message, data}` |

**Base URL.** Contoh memakai variabel Postman `{{base_url_laravel}}`, misalnya `http://127.0.0.1:8002` (lokal) atau `https://be-dev.prasetiyamulya.ac.id/<path-laravel>` (dev). Tidak perlu `index.php`.

---

## Bagian 1. Endpoint lama Ijazah & Transkrip

Dipertahankan identik dengan CodeIgniter (`controllers/Api.php`) karena sudah dipakai aplikasi lain. Jangan ubah format respons di bagian ini.

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api` | Health check |
| GET | `/api/document?nim={nim}` atau `/api/document/nim/{nim}` | Dokumen Ijazah/Transkrip aktif terbaru milik NIM |
| POST | `/api/document` | Upload Ijazah/Transkrip (create, atau ganti file bila NIM sudah ada) |
| GET | `/api/download_file/{filename}/{id_doc}` | Unduh file |

Variasi URL lama REST_Controller juga diterima: `/api/index`, akhiran `.json` (`/api/document.json`, `/api/document/nim/{nim}.json`), `/format/json`, dan `?format=json`. Format XML tidak didukung.

### GET /api/document

```json
{
  "status": "S",
  "code": "200",
  "Message": "Success",
  "data": {
    "keyword": "Ijazah,Transkrip,TEST0000001",
    "summary": "Ijazah dan Transkrip - TEST0000001",
    "ListOfFiles": "https://host/index.php/api/download_file/DOC_2026-1017918727650.pdf/13375|Ijazah;"
  }
}
```

| Kondisi | HTTP | Respons |
| --- | --- | --- |
| `?nim=` kosong | 404 | `{"status":"E","code":"400","Message":"NIM is empty"}` |
| NIM tidak ditemukan | 404 | `{"status":"E","code":"400","Message":"Record is empty","data":""}` |

URL di `ListOfFiles` memakai awalan `ARSIP_LEGACY_BASE_URL` (.env) lalu `index.php/api/download_file/...`, sama dengan CodeIgniter.

### POST /api/document

Body `multipart/form-data`: `nim`, `school` (`SBE` = kategori 1075, `STEM` = 1076), `doc_date` (YYYY-MM), `expired_date`, `exp_public`, `userfile[]`.

- NIM belum punya dokumen aktif: dokumen baru dibuat (`id_doc` berupa angka).
- NIM sudah ada: dokumen yang sama di-update, **semua file lama diganti**. `kode_arsip` dan kategori tidak berubah, `school` tidak wajib (`id_doc` berupa string, sama dengan CodeIgniter).

```json
{ "status": "S", "code": "200", "data": { "NIM": "TEST0000001", "id_doc": 13375, "KodeArsip": "26/10/AKD.001.001.001/1" } }
```

| Kondisi | HTTP | Respons |
| --- | --- | --- |
| `nim` kosong | 400 | `{"status":"E","code":"400","Message":"NIM is empty"}` |
| NIM baru dan `school` bukan SBE/STEM | 400 | `{"status":"E","code":"400","Message":"school must be SBE or STEM"}` |
| File melebihi `upload_limit` departemen 12 | 200 | `"wrong_format"` |

### GET /api/download_file/{filename}/{id_doc}

Mengembalikan file biner dengan nama `realname.ext`. Menambah log `Download via API` di `doc_log`. File tidak ada: 404 `{"status":"E","code":"400","Message":"file not found, please contact administrator."}`.

---

## Bagian 2. API Arsip Umum

| Method | Endpoint | Fungsi | Izin token |
| --- | --- | --- | --- |
| GET | `/api/master/categories` | Daftar kategori arsip | `archive:read` |
| GET | `/api/master/departements` | Daftar departemen aktif + batas upload | `archive:read` |
| GET | `/api/master/tipefiles` | Daftar tipe file aktif | `archive:read` |
| GET | `/api/archive` | Pencarian arsip (paging) | `archive:read` |
| GET | `/api/archive/{id_doc}` | Detail satu arsip | `archive:read` |
| POST | `/api/archive` | Membuat arsip baru + upload file | `archive:write` |
| POST | `/api/archive/{id_doc}` | Mengubah metadata dan/atau file | `archive:write` |
| GET | `/api/archive/{id_doc}/file/{filename}` | Mengunduh file | `archive:read` |

Belum ada endpoint hapus arsip. File dihapus lewat update (`delete_files[]` atau `file_mode=replace`).

### Format respons

Sukses:

```json
{ "status": "S", "message": "Data arsip berhasil diambil.", "data": { } }
```

Error validasi (HTTP 422). Setiap field berisi **array** pesan:

```json
{
  "status": "E",
  "message": "Validation Error",
  "errors": {
    "id_category": ["Kategori tidak ditemukan, tidak aktif, atau level 1 (pilih level 2-4)."],
    "doc_date": ["Format Y-m."],
    "userfile": ["Minimal 1 file wajib diupload (field userfile[])."]
  }
}
```

Error lain (401, 403, 404, 405, 500, 503):

```json
{ "status": "E", "message": "Arsip tidak ditemukan.", "errors": null }
```

Gunakan HTTP status untuk menentukan jenis hasil; respons tidak memiliki field `code`.

### Autentikasi

Pilih salah satu:

1. **API key**, akses penuh (read dan write):
   ```
   X-API-KEY: <api key>
   ```
   Key didaftarkan di `.env`: `ARSIP_API_KEYS=key1:siakad,key2:aplikasi-hr` (format `key:nama-aplikasi`, dipisah koma).

2. **Token Sanctum**, bisa dibatasi read-only dan diberi masa berlaku:
   ```
   Authorization: Bearer <token>
   ```
   Dibuat di server:
   ```bash
   php artisan arsip:client-create siakad              # read + write
   php artisan arsip:client-create portal --read-only  # hanya archive:read
   php artisan arsip:client-create hr --days=365       # kedaluwarsa 1 tahun
   php artisan arsip:client-list
   php artisan arsip:client-revoke siakad
   ```

Khusus endpoint download, API key boleh lewat query `?api_key=...` untuk link langsung di browser. Di endpoint lain query ini ditolak.

| Kondisi | HTTP | `message` |
| --- | --- | --- |
| Tidak mengirim key/token | 401 | API key atau token wajib dikirim. |
| API key salah | 401 | API key tidak valid. |
| Token salah/kedaluwarsa/client nonaktif | 401 | Token tidak valid atau sudah kedaluwarsa. |
| Token tanpa izin write memanggil create/update | 403 | Token tidak memiliki izin archive:write. |

### GET /api/master/categories

| Query | Keterangan |
| --- | --- |
| `level` | 1-4 |
| `parent` | ID kategori induk (angka >= 1) |
| `q` | Kata kunci nama atau kode kategori |

```json
{
  "status": "S",
  "message": "Data kategori berhasil diambil.",
  "data": [
    { "id_category": 1998, "code_full": "SPM.003.013", "name_category": "Laporan", "level": 3, "id_parent": 1990, "doc_status": "...", "doc_life": 5, "can_upload": true }
  ]
}
```

Hanya kategori `can_upload: true` (level 2-4) yang bisa dipakai upload. `doc_life` (tahun) menentukan `active_until` default.

### GET /api/master/departements

```json
{ "status": "S", "message": "Data departemen berhasil diambil.", "data": [ { "id_departement": 30, "nama_departement": "IT Development", "upload_limit": 20 } ] }
```

`upload_limit` = batas ukuran per file dalam MB.

### GET /api/master/tipefiles

```json
{ "status": "S", "message": "Data tipe file berhasil diambil.", "data": [ { "id_tipefile": 8, "tipefile": "Laporan" } ] }
```

### GET /api/archive (pencarian)

Semua parameter opsional.

| Query | Format | Keterangan |
| --- | --- | --- |
| `q` | teks | Cari di keyword, summary, kode_arsip, dan nama asli file |
| `id_category` | angka | Filter kategori |
| `include_sub` | 1 | `1` = sertakan sub-kategori. Nilai lain dianggap 0 |
| `departement_owner` | angka | Filter departemen |
| `tipefile` | angka | Filter tipe file |
| `year` | YYYY | Tahun dokumen |
| `doc_date` | YYYY-MM | Bulan dokumen |
| `created_from`, `created_to` | YYYY-MM-DD | Rentang tanggal dibuat |
| `public` | 0/1 | Status publik |
| `page` | angka | Default 1 |
| `limit` | 1-100 | Default 20 |
| `sort` | teks | `created_date` (default), `kode_arsip`, `doc_date`, `id_doc` |
| `order` | teks | `desc` (default) atau `asc`, tidak peka huruf besar |

```json
{
  "status": "S",
  "message": "Data arsip berhasil diambil.",
  "data": {
    "data": [ { "id_doc": 13377, "kode_arsip": "26/10/SPM.003.013/4", "files": [] } ],
    "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 }
  }
}
```

Setiap item di `data.data` berstruktur sama dengan respons detail. Bila tidak ada hasil, `total` = 0 dan `last_page` = 1.

### GET /api/archive/{id_doc} (detail)

```json
{
  "status": "S",
  "message": "Data arsip berhasil diambil.",
  "data": {
    "id_doc": 13377,
    "kode_arsip": "26/10/SPM.003.013/4",
    "doc_date": "2026-10",
    "category": { "id_category": 1998, "code_full": "SPM.003.013", "name_category": "Laporan", "doc_status": "..." },
    "departement": { "id_departement": 30, "nama_departement": "IT Development" },
    "tipefile": { "id_tipefile": 8, "tipefile": "Laporan" },
    "keyword": "TESTAPIARSIP,uji",
    "summary": "Uji API arsip umum",
    "public": "0",
    "expired_public": null,
    "active_until": "2031-10-13",
    "link": "",
    "created_by": 819,
    "created_date": "2026-10-13 10:00:00",
    "edited_by": null,
    "edited_date": null,
    "files": [
      { "filename": "DOC_2026-1017918727650.pdf", "realname": "laporan", "url": "https://host/api/archive/13377/file/DOC_2026-1017918727650.pdf" }
    ]
  }
}
```

ID tidak ada: 404 `Arsip tidak ditemukan.`

### POST /api/archive (create)

Body `multipart/form-data`.

| Field | Wajib | Keterangan |
| --- | --- | --- |
| `id_category` | Ya | ID kategori level 2-4 |
| `departement_owner` | Ya | ID departemen aktif |
| `tipefile` | Ya | ID tipe file aktif |
| `doc_date` | Ya | YYYY-MM |
| `keyword` | Ya | Dipisah koma |
| `summary` | Ya | Ringkasan dokumen |
| `userfile[]` | Ya | Minimal 1 file (`userfile` tunggal juga diterima). Ekstensi sesuai `ARSIP_ALLOWED_TYPES`, isi file harus sesuai ekstensinya, ukuran maks `upload_limit` departemen |
| `active_until` | Tidak | YYYY-MM-DD. Default hari ini + `doc_life` kategori |
| `public` | Tidak | 0/1, default 0 |
| `expired_public` | Tidak | YYYY-MM-DD |
| `link` | Tidak | Maks 155 karakter |
| `created_by` | Tidak | idusers aktif, default 819 |
| `validate_access` | Tidak | `1` = cek hak akses departemen ke kategori (access 3, termasuk kategori induk) |

```bash
curl -X POST "{{base_url_laravel}}/api/archive" \
  -H "X-API-KEY: <key>" \
  -F "id_category=1998" -F "departement_owner=30" -F "tipefile=8" \
  -F "doc_date=2026-10" -F "keyword=TESTAPIARSIP,uji" -F "summary=Uji API arsip umum" \
  -F "userfile[]=@/path/laporan.pdf" -F "userfile[]=@/path/lampiran.xlsx"
```

Respons `201`:

```json
{
  "status": "S",
  "message": "Arsip berhasil dibuat.",
  "data": {
    "id_doc": 13377,
    "kode_arsip": "26/10/SPM.003.013/4",
    "files": [ { "filename": "DOC_2026-1017918727650.pdf", "realname": "laporan", "url": "https://host/api/archive/13377/file/DOC_2026-1017918727650.pdf" } ]
  }
}
```

Setiap create selalu membuat arsip baru, walaupun kategori dan nama file sama dengan arsip lain. File disimpan sebagai `DOC_{doc_date}{timestamp}{urutan}.{ext}`; nama asli disimpan di `realname`. `kode_arsip` berurutan per kode kategori per bulan (`YY/MM/code_full/N`).

### POST /api/archive/{id_doc} (update)

Semua field create opsional. **Hanya field yang dikirim dan tidak kosong yang diubah**, jadi frontend boleh mengirim seluruh isi form. Field kosong tidak menghapus nilai lama.

| Field tambahan | Keterangan |
| --- | --- |
| `userfile[]` | File baru |
| `file_mode` | `append` (default) menambah file. `replace` mengganti semua file lama (wajib kirim minimal 1 file) |
| `delete_files[]` | `filename` milik arsip ini yang dihapus (string tunggal juga diterima) |
| `edited_by` | idusers aktif, default 819 |

Aturan: arsip harus tetap punya minimal 1 file, dan request tanpa perubahan ditolak 422. `kode_arsip` tidak berubah. File fisik lama baru dihapus setelah database berhasil diperbarui.

Respons `200` dengan `message: "Arsip berhasil diperbarui."` dan `data` berisi detail terbaru.

### GET /api/archive/{id_doc}/file/{filename} (download)

Respons file biner, nama unduhan memakai `realname`. Setiap download dicatat di `doc_log`.

```
{{base_url_laravel}}/api/archive/13377/file/DOC_2026-1017918727650.pdf?api_key=<key>
```

File bukan milik arsip atau tidak ada di storage: 404 `File tidak ditemukan.`

### Ringkasan kode error

| HTTP | `message` | Penyebab |
| --- | --- | --- |
| 401 | lihat tabel autentikasi | Key/token kosong atau salah |
| 403 | Departemen tidak memiliki akses upload/edit ke kategori ini. | `validate_access=1` dan departemen tidak punya akses |
| 404 | Arsip tidak ditemukan. / File tidak ditemukan. | `id_doc` atau `filename` salah |
| 422 | Validation Error | Detail per field di `errors` |
| 500 | Internal server error | Gagal menyimpan file atau database; file baru dihapus kembali |
| 503 | Server sibuk, silakan coba lagi. | Kode arsip kategori yang sama sedang dikunci request lain |

---

## Konfigurasi .env yang wajib di server

| Key | Contoh | Keterangan |
| --- | --- | --- |
| `ARSIP_PATH` | `/mnt/ossfs/arsip` | Folder file arsip. Tanpa ini download 404 |
| `ARSIP_API_KEYS` | `key1:siakad` | API key aplikasi pemanggil |
| `ARSIP_LEGACY_BASE_URL` | `https://be-dev.prasetiyamulya.ac.id/<path-laravel>` | Awalan URL di `ListOfFiles`, wajib https |
| `DB_DMS_*` | | Koneksi database db_dms |

Apache harus meneruskan `/index.php/api/...` ke `public/index.php` (dipakai link `ListOfFiles`). Laravel sudah mengirim header CORS sendiri, jadi jangan tambahkan `Access-Control-Allow-Origin` lagi di Apache.
