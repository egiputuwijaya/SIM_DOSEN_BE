# SIM Dosen API

REST API untuk aplikasi **SIM Dosen** (Sistem Informasi Manajemen Dosen) —
PHP Native (tanpa framework), MySQL/MariaDB, PDO, respons JSON.

- PHP ≥ 8.1 dengan ekstensi `pdo_mysql` & `mbstring`
- MySQL / MariaDB
- `mod_rewrite` Apache (untuk URL rapi), atau PHP built-in server untuk pengembangan
- Semua query database memakai **prepared statement** (anti SQL injection)

## Daftar Isi

1. [Struktur Folder](#struktur-folder)
2. [Persiapan Database](#persiapan-database)
3. [Instalasi di XAMPP (Windows)](#instalasi-di-xampp-windows)
4. [Instalasi di Apache (Linux)](#instalasi-di-apache-linux)
5. [Konfigurasi Koneksi Database](#konfigurasi-koneksi-database)
6. [Menjalankan Tanpa Apache (Development)](#menjalankan-tanpa-apache-development)
7. [Daftar Endpoint](#daftar-endpoint)
8. [Standar Respons JSON](#standar-respons-json)
9. [Contoh Pengujian dengan curl](#contoh-pengujian-dengan-curl)
10. [Pengujian dengan Postman](#pengujian-dengan-postman)
11. [Pengaturan CORS](#pengaturan-cors)
12. [Keamanan & Catatan Produksi](#keamanan--catatan-produksi)
13. [Troubleshooting](#troubleshooting)

---

## Struktur Folder

```
sim-dosen-api/
├── config/
│   └── database.php          # Koneksi PDO + konfigurasi (env vars)
├── helpers/
│   └── response.php          # Respons JSON, baca body, validasi field
├── api/
│   ├── dosen.php             # CRUD dosen + search/filter/pagination
│   └── profil-universitas.php# Profil universitas
├── .htaccess                 # mod_rewrite (URL rapi) + contoh SetEnv
├── index.php                 # Front controller & router sederhana
└── README.md
```

Cara kerja: semua request diteruskan ke `index.php`, lalu router mencocokkan
`method + path` ke handler di folder `api/`.

---

## Persiapan Database

Database `sim_dosen` beserta tabel `dosen` dan `profil_universitas`
**dianggap sudah tersedia** — API ini tidak membuat/mengubah skema.

Import dump yang tersedia (bila belum):

```bash
mysql -u root -p sim_dosen < sim_dosen.sql
```

> **Catatan:** file dump `sim_dosen.sql` hanya berisi tabel `dosen`.
> Bila tabel `profil_universitas` belum ada, buat dulu (tidak mengubah
> tabel yang sudah ada):

```sql
CREATE TABLE IF NOT EXISTS `profil_universitas` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `universitas` varchar(255) DEFAULT NULL,
  `fakultas` varchar(255) DEFAULT NULL,
  `program_studi` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Instalasi di XAMPP (Windows)

1. Salin folder `sim-dosen-api` ke `C:\xampp\htdocs\`.
   Hasilnya: `C:\xampp\htdocs\sim-dosen-api\`.
2. Buka **XAMPP Control Panel** → start modul **Apache** (dan **MySQL**).
3. Buka `http://localhost/phpmyadmin` → pilih/import database `sim_dosen`
   (lihat [Persiapan Database](#persiapan-database)).
4. Buka `http://localhost/sim-dosen-api/` — bila muncul JSON
   `{"success":true,"message":"SIM Dosen API aktif",...}`, API siap dipakai.
5. Default koneksi XAMPP: user `root`, password kosong, host `127.0.0.1:3306`
   — sudah sesuai nilai default API ini.

## Instalasi di Apache (Linux)

Contoh distro Debian/Ubuntu dengan Apache + PHP (mis. XAMPP for Linux juga sama):

```bash
# 1. Salin folder proyek
sudo cp -r sim-dosen-api /var/www/html/
sudo chown -R www-data:www-data /var/www/html/sim-dosen-api

# 2. Aktifkan mod_rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2

# 3. Izinkan .htaccess override pada direktori web
#    Edit /etc/apache2/sites-available/000-default.conf:
<Directory /var/www/html>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

sudo systemctl reload apache2
```

API dapat diakses di `http://localhost/sim-dosen-api/api/dosen`.
`.htaccess` menangani routing; mod_rewrite wajib aktif.

> **Catatan hosting cPanel:** biasanya sudah aktif. Bila URL API mengembalikan
> halaman 404/403 dari server, minta admin mengaktifkan `mod_rewrite` dan
> `AllowOverride All`.

---

## Konfigurasi Koneksi Database

Semua konfigurasi memakai **environment variable** (nilai default untuk
lingkungan lokal XAMPP):

| Variabel          | Default     | Keterangan                          |
|-------------------|-------------|-------------------------------------|
| `DB_HOST`         | `127.0.0.1` | Host database                       |
| `DB_PORT`         | `3306`      | Port database                       |
| `DB_NAME`         | `sim_dosen` | Nama database                       |
| `DB_USER`         | `root`      | Username                            |
| `DB_PASS`         | *(kosong)*  | Password                            |
| `ALLOWED_ORIGINS` | lihat [CORS](#pengaturan-cors) | Origin frontend yang diizinkan |

**Apache (mod_php / XAMPP):** tambahkan `SetEnv` di `.htaccess`
(komentar contoh sudah disediakan di file tersebut):

```apache
SetEnv DB_HOST 127.0.0.1
SetEnv DB_PORT 3306
SetEnv DB_NAME sim_dosen
SetEnv DB_USER root
SetEnv DB_PASS "rahasia123"
```

**PHP-FPM:** gunakan `fastcgi_param DB_USER root;` di konfigurasi pool FPM,
atau `env[...]` pada `www.conf` (SetEnv tidak terbaca di PHP-FPM).

**Shell / CLI:**

```bash
export DB_HOST=127.0.0.1 DB_USER=root DB_PASS="rahasia123"
```

Kredensial tidak pernah dikirim ke klien; kegagalan koneksi hanya
menghasilkan `500` dengan pesan generik, detailnya di log server.

---

## Menjalankan Tanpa Apache (Development)

Dari dalam folder `sim-dosen-api`:

```bash
php -S localhost:8000 index.php
```

`index.php` dipakai sebagai *router script*, sehingga URL rapi tetap berfungsi
(`.htaccess` hanya dibaca Apache). Lalu buka `http://localhost:8000/api/dosen`.

---

## Daftar Endpoint

| Method | Endpoint                        | Keterangan |
|--------|---------------------------------|------------|
| GET    | `/api/dosen`                    | Daftar dosen — pagination, pencarian, filter |
| POST   | `/api/dosen`                    | Tambah dosen (`201 Created`) |
| GET    | `/api/dosen/{id}`               | Detail satu dosen |
| PUT    | `/api/dosen/{id}`               | Perbarui **seluruh** data dosen (replace) |
| PATCH  | `/api/dosen/{id}`               | Perbarui **sebagian** data dosen |
| DELETE | `/api/dosen/{id}`               | Hapus dosen |
| GET    | `/api/profil-universitas`       | Daftar profil universitas |
| PUT    | `/api/profil-universitas/{id}`  | Perbarui profil universitas |

### Parameter query `GET /api/dosen`

| Parameter            | Tipe   | Default | Keterangan |
|----------------------|--------|---------|------------|
| `search`             | teks   | –       | Pencarian di **nama**, **NIP**, dan **NUPTK** (mengandung, bukan persis) |
| `status_pegawai`     | teks   | –       | Filter persis `status_pegawai` (mis. `Tetap`, `Tidak Tetap`) |
| `status_aktivitas`   | teks   | –       | Filter persis `status_aktivitas` (mis. `Mengajar`, `Non-Aktif`) |
| `jabatan_fungsional` | teks   | –       | Filter persis `jabatan_fungsional` (mis. `Lektor`) |
| `page`               | angka  | `1`     | Nomor halaman (≥ 1) |
| `per_page`           | angka  | `10`    | Jumlah data per halaman (1–100) |

### Kolom yang diterima (whitelist)

`POST` / `PUT` / `PATCH` **hanya** menerima kolom berikut (kolom lain → `422`);
`id`, `created_at`, `updated_at` dikelola otomatis oleh database:

```
nomor_registrasi, nuptk, nama, nip, jabatan_fungsional, kepangkatan,
pendidikan_terakhir, rumpun_ilmu, status_serdos, status_pegawai,
ikatan_kerja, status_aktivitas, penempatan
```

Perilaku per method:

- **POST** — wajib `nama` (maks 150 karakter), kolom lain opsional (string/null).
- **PUT** — wajib `nama`; **penggantian penuh**: kolom yang tidak dikirim menjadi `NULL`.
- **PATCH** — tidak ada kolom wajib, tetapi **minimal satu kolom** harus dikirim;
  hanya kolom yang dikirim yang diubah.
- **PUT profil-universitas** — minimal satu kolom dari `universitas`,
  `fakultas`, `program_studi`; hanya kolom yang dikirim yang diubah.

### Kode status yang dipakai

`200` OK · `201` Created · `204` Preflight CORS (OPTIONS) · `400` Body/param/ID
tidak valid · `404` Data/endpoint tidak ditemukan · `405` Method tidak
diizinkan (disertai header `Allow`) · `422` Validasi gagal (disertai `errors`)
· `500` Kesalahan server.

---

## Standar Respons JSON

Sukses:

```json
{
  "success": true,
  "message": "Data dosen berhasil diambil",
  "data": []
}
```

Error:

```json
{
  "success": false,
  "message": "Data dosen tidak ditemukan",
  "data": null
}
```

Daftar dosen menambahkan `meta` pagination:

```json
{
  "success": true,
  "message": "Data dosen berhasil diambil",
  "data": [ { "id": 1, "nama": "Dr. Ahmad Fauzi, M.T.", "...": "..." } ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 6,
    "last_page": 3,
    "from": 1,
    "to": 2
  }
}
```

Validasi gagal (`422`) menambahkan `errors` per kolom:

```json
{
  "success": false,
  "message": "Data tidak valid",
  "data": null,
  "errors": { "nama": ["Wajib diisi"] }
}
```

---

## Contoh Pengujian dengan curl

Ganti `http://localhost/sim-dosen-api` dengan URL instalasi Anda
(pada PHP built-in server: `http://localhost:8000`).

### 1. Daftar dosen (pagination + pencarian + filter)

```bash
curl "http://localhost/sim-dosen-api/api/dosen?page=1&per_page=2"
curl "http://localhost/sim-dosen-api/api/dosen?search=Siti"
curl "http://localhost/sim-dosen-api/api/dosen?search=1982"
curl "http://localhost/sim-dosen-api/api/dosen?status_pegawai=Tidak+Tetap"
curl "http://localhost/sim-dosen-api/api/dosen?status_aktivitas=Mengajar&jabatan_fungsional=Lektor"
```

Respons `?per_page=2` (ringkas):

```json
{
  "success": true,
  "message": "Data dosen berhasil diambil",
  "data": [
    { "id": 1, "nama": "Dr. Ahmad Fauzi, M.T.", "nip": "197501012005011001", "...": "..." },
    { "id": 2, "nama": "Siti Nurhaliza, S.Kom., M.Kom.", "nip": "198202022008012002", "...": "..." }
  ],
  "meta": { "current_page": 1, "per_page": 2, "total": 6, "last_page": 3, "from": 1, "to": 2 }
}
```

### 2. Detail dosen

```bash
curl "http://localhost/sim-dosen-api/api/dosen/2"
```

```json
{
  "success": true,
  "message": "Data dosen berhasil diambil",
  "data": { "id": 2, "nama": "Siti Nurhaliza, S.Kom., M.Kom.", "nip": "198202022008012002", "...": "..." }
}
```

ID tidak ditemukan → `404`; ID bukan angka (mis. `/api/dosen/abc`) → `400`.

### 3. Tambah dosen (POST → 201)

```bash
curl -X POST "http://localhost/sim-dosen-api/api/dosen" \
  -H "Content-Type: application/json" \
  -d '{
    "nama": "Dr. Rina Marlina, M.Kom.",
    "nip": "198801012015042007",
    "nuptk": "198801012015042007",
    "jabatan_fungsional": "Lektor",
    "status_pegawai": "Tetap",
    "status_aktivitas": "Mengajar",
    "penempatan": "Gedung E"
  }'
```

```json
{
  "success": true,
  "message": "Data dosen berhasil ditambahkan",
  "data": { "id": 7, "nama": "Dr. Rina Marlina, M.Kom.", "nip": "198801012015042007", "...": "..." }
}
```

Tanpa `nama` → `422`:

```json
{
  "success": false,
  "message": "Data tidak valid",
  "data": null,
  "errors": { "nama": ["Wajib diisi"] }
}
```

Kolom di luar whitelist (mis. `created_at`) → `422`
`"Kolom tidak dikenal atau tidak diizinkan"`.

### 4. Perbarui seluruh data (PUT — replace)

```bash
curl -X PUT "http://localhost/sim-dosen-api/api/dosen/6" \
  -H "Content-Type: application/json" \
  -d '{
    "nama": "Andi Wijaya, S.T., M.T.",
    "jabatan_fungsional": "Lektor",
    "status_pegawai": "Tetap"
  }'
```

Kolom yang tidak dikirim (mis. `nuptk`, `penempatan`) menjadi `NULL`.

### 5. Perbarui sebagian data (PATCH)

```bash
curl -X PATCH "http://localhost/sim-dosen-api/api/dosen/7" \
  -H "Content-Type: application/json" \
  -d '{"status_aktivitas": "Cuti", "penempatan": "Gedung F"}'
```

Body `{}` (tanpa kolom) → `422`.

### 6. Hapus dosen

```bash
curl -X DELETE "http://localhost/sim-dosen-api/api/dosen/7"
```

```json
{ "success": true, "message": "Data dosen berhasil dihapus", "data": null }
```

ID sudah tidak ada → `404`.

### 7. Profil universitas

```bash
curl "http://localhost/sim-dosen-api/api/profil-universitas"

curl -X PUT "http://localhost/sim-dosen-api/api/profil-universitas/1" \
  -H "Content-Type: application/json" \
  -d '{"program_studi": "Sistem Informasi"}'
```

```json
{
  "success": true,
  "message": "Data profil universitas berhasil diperbarui",
  "data": {
    "id": 1,
    "universitas": "Universitas Contoh Nusantara",
    "fakultas": "Fakultas Teknik",
    "program_studi": "Sistem Informasi",
    "created_at": "2026-10-12 11:56:37",
    "updated_at": "2026-10-12 11:57:58"
  }
}
```

### 8. Preflight CORS (OPTIONS)

```bash
curl -i -X OPTIONS "http://localhost/sim-dosen-api/api/dosen" \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type"
```

Menghasilkan `204 No Content` + header `Access-Control-Allow-*`
(bila origin terdaftar).

---

## Pengujian dengan Postman

1. Buka Postman → **Create Request**.
2. Pilih method (mis. `GET`) dan URL:
   `http://localhost/sim-dosen-api/api/dosen?page=1&per_page=5`.
   Untuk parameter, buka tab **Params** dan isi key/value
   (`page` = `1`, `per_page` = `5`, `search` = `Siti`, dst.).
3. Untuk `POST`/`PUT`/`PATCH`: pilih method → tab **Body** → **raw** →
   pilih type **JSON** → tulis body, mis.:

   ```json
   {
     "nama": "Dr. Rina Marlina, M.Kom.",
     "status_pegawai": "Tetap"
   }
   ```

   Header `Content-Type: application/json` otomatis ditambahkan Postman
   (bila perlu, tambahkan manual di tab **Headers**).
4. Klik **Send**. Cek status code (200/201/404/422/dst.) dan respons JSON.
5. (Opsional) uji preflight CORS: method `OPTIONS`, tambahkan header
   `Origin: http://localhost:3000` dan `Access-Control-Request-Method: POST`.
6. (Opsional) simpan request ke Collection `SIM Dosen API` agar bisa
   dipakai ulang/dibagikan ke tim.

---

## Pengaturan CORS

CORS hanya diizinkan untuk origin frontend yang terdaftar di env
`ALLOWED_ORIGINS` (dipisah koma). Default bila tidak diset:

```
http://localhost:3000,http://localhost:5173,http://localhost:8080
```

(frontend React/Vue/Angular dev server umum). Saat frontend memakai domain
berbeda, daftarkan originnya:

**Apache (.htaccess):**

```apache
SetEnv ALLOWED_ORIGINS "http://localhost:3000,https://frontend.example.com"
```

**Shell:**

```bash
export ALLOWED_ORIGINS="http://localhost:3000,https://frontend.example.com"
```

Perilaku:

- Origin terdaftar → respons disertai `Access-Control-Allow-Origin: <origin>`
  dan `Vary: Origin`.
- Origin tidak terdaftar → header CORS **tidak** dikirim; browser memblokir
  akses dari halaman tersebut (non-browser seperti curl/Postman tidak terpengaruh).
- `OPTIONS` (preflight) dijawab `204` + header `Access-Control-Allow-Methods`
  (`GET, POST, PUT, PATCH, DELETE, OPTIONS`) dan
  `Access-Control-Allow-Headers` (`Content-Type, Authorization, X-Requested-With`).

---

## Keamanan & Catatan Produksi

Sudah diterapkan:

- Semua query database memakai **PDO prepared statement**
  (`ATTR_EMULATE_PREPARES = false`); karakter khusus LIKE (`%`, `_`) di-escape
  sehingga input pencarian tidak menjadi wildcard/injection.
- **Whitelist kolom**: request hanya diterima untuk kolom yang didefinisikan
  di kode; `id`, `created_at`, `updated_at` tidak bisa dikontrol dari request.
- Validasi tipe data (semua kolom harus teks/null), kolom wajib, panjang
  maksimal sesuai skema, serta validasi ID pada URL (hanya angka bulat positif).
- Detail error SQL/koneksi **tidak pernah** dikirim ke klien (hanya pesan
  generik + `error_log` di server); `display_errors` dimatikan.
- Kredensial database dipisah di `config/database.php` dan dibaca dari
  environment variable.
- CORS dibatasi ke origin yang diizinkan.

> ### ⚠️ Perlu diperhatikan sebelum produksi
>
> Endpoint perubahan data (`POST`, `PUT`, `PATCH`, `DELETE`) saat ini
> **belum memiliki autentikasi/otorisasi** — sesuai lingkup pembuatan awal.
> Sebelum dipakai di lingkungan produksi, lindungi endpoint tersebut, mis.
> dengan: API key sederhana pada header (`Authorization: Bearer <key>`
> yang dicek di `index.php` sebelum routing), Basic Auth, atau token
> JWT/OAuth untuk frontend login. Referensi implementasi:
> - API key: bandingkan header `Authorization` dengan nilai dari env var,
>   balas `401` bila tidak cocok, atau
> - JWT: verifikasi token lalu cocokkan role (admin/staf) sebelum handler berjalan.
>
> Jangan tayangkan endpoint perubahan data ke publik tanpa lapisan tersebut.

Tips tambahan untuk produksi: jalankan di HTTPS, batani rate-limit pada
endpoint tulis, dan aktifkan backup database berkala.

---

## Troubleshooting

| Gejala | Solusi |
|--------|--------|
| URL `/api/dosen` mengembalikan `404` dari Apache (bukan JSON) | `mod_rewrite` belum aktif → `sudo a2enmod rewrite && sudo systemctl restart apache2`, dan pastikan `AllowOverride All` |
| Halaman `500` kosong | Cek log error Apache (`/var/log/apache2/error.log`) atau jalankan `php -S localhost:8000 index.php` untuk melihat log di terminal; API sengaja tidak menampilkan detail error ke klien |
| `{"success":false,"message":"Tidak dapat terhubung ke database",...}` | Periksa `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS`, pastikan database `sim_dosen` sudah di-import |
| `Data profil universitas tidak ditemukan` / error 500 di endpoint profil | Tabel `profil_universitas` belum ada — lihat [Persiapan Database](#persiapan-database) |
| CORS ditolak browser | Daftarkan origin frontend di `ALLOWED_ORIGINS` (lihat [Pengaturan CORS](#pengaturan-cors)); ingat origin harus sama persis termasuk protokol & port |
| `mb_strlen` undefined | Aktifkan ekstensi `mbstring` di `php.ini` |

---

Diuji dengan PHP 8.3 + MariaDB 10.11 (Apache maupun PHP built-in server).
Seluruh endpoint, kode status, pagination, pencarian, filter, validasi, dan
CORS sudah diuji end-to-end.
