# SIM Dosen API

REST API untuk aplikasi **SIM Dosen** (Sistem Informasi Manajemen Dosen) —
PHP Native (murni), tanpa framework, **tanpa Composer/vendor**, MySQL/MariaDB,
PDO, respons JSON konsisten.

- PHP ≥ 8.1 (dikembangkan & diuji di PHP 8.3) dengan ekstensi `pdo_mysql` & `mbstring`
- MySQL / MariaDB
- `mod_rewrite` Apache (untuk URL rapi & pengamanan file) — atau Nginx / PHP built-in server
- Semua query database memakai **PDO prepared statement** (anti SQL injection)
- Konfigurasi dibaca dari **environment variable** (`getenv()`), dengan
  pembaca `.env` PHP-murni di `config/env.php` (tanpa vlucas/phpdotenv)

## Daftar Isi

1. [Struktur Folder](#struktur-folder)
2. [Persyaratan](#persyaratan)
3. [Persiapan Database](#persiapan-database)
4. [Konfigurasi .env](#konfigurasi-env)
5. [Menjalankan di Localhost](#menjalankan-di-localhost)
6. [Autentikasi SSO (SIM_MAHASISWA_BE)](#autentikasi-sso-sim_mahasiswa_be)
7. [Daftar Endpoint](#daftar-endpoint)
8. [Standar Respons JSON](#standar-respons-json)
9. [Contoh Pengujian dengan curl](#contoh-pengujian-dengan-curl)
10. [Pengujian dengan Postman](#pengujian-dengan-postman)
11. [Pengaturan CORS](#pengaturan-cors)
12. [Keamanan & Perlindungan File Konfigurasi](#keamanan--perlindungan-file-konfigurasi)
13. [Catatan untuk Nginx & PHP-FPM](#catatan-untuk-nginx--php-fpm)
14. [Endpoint Tambahan (Tabel Pendukung)](#endpoint-tambahan-tabel-pendukung)
15. [Troubleshooting](#troubleshooting)

---

## Struktur Folder

```
sim-dosen-api/
├── config/
│   ├── env.php               # Pembaca .env (PHP murni) + env()
│   └── database.php          # Koneksi PDO, konfigurasi dari getenv()
├── helpers/
│   ├── response.php          # Respons JSON, CORS, baca body, validasi field
│   └── auth.php              # Autentikasi SSO (klien SIM_MAHASISWA_BE)
├── api/
│   └── dosen.php             # CRUD dosen + search/filter/pagination
├── .env                      # Kredensial lokal (TIDAK di-commit)
├── .env.example              # Template .env tanpa kredensial asli
├── .gitignore                # Mengabaikan .env & file rahasia
├── .htaccess                 # Apache: routing + blokir file sensitif
├── index.php                 # Front controller & router sederhana
├── sim_dosen.sql             # Dump struktur database (acuan skema)
└── README.md
```

Cara kerja: semua request diteruskan ke `index.php`, lalu router mencocokkan
`method + path` ke handler di folder `api/`. File-file lain tidak pernah
dieksekusi langsung via HTTP.

## Persyaratan

| Komponen     | Keterangan                                                  |
|--------------|-------------------------------------------------------------|
| PHP          | ≥ 8.1, kompatibel PHP 8.3; ekstensi `pdo_mysql`, `mbstring` |
| Database     | MySQL / MariaDB (diuji: MariaDB 10.11)                      |
| Web server  | Apache (mod_rewrite) / Nginx / PHP built-in server          |
| Composer     | **Tidak diperlukan** — tidak ada `vendor/`                  |

Cek ekstensi yang aktif:

```bash
php -m | grep -E "pdo_mysql|mbstring"
```

---

## Persiapan Database

Skema diambil dari **`sim_dosen.sql`** yang sudah tersedia di proyek.
API ini **tidak membuat/mengubah** database atau tabel apa pun.

Import dump (bila belum):

```bash
mysql -u root -p sim_dosen < sim_dosen.sql
```

Atau lewat phpMyAdmin: pilih database `sim_dosen` → Import → pilih `sim_dosen.sql`.

Satu-satunya tabel pada dump adalah **`dosen`**:

| Kolom               | Tipe              | Keterangan                          |
|---------------------|-------------------|-------------------------------------|
| `id`                | bigint unsigned   | PK, auto-increment                  |
| `nomor_registrasi`  | varchar(50)       | Opsional                            |
| `nuptk`             | varchar(30)       | Opsional                            |
| `nama`              | varchar(150)      | **Wajib** (untuk POST/PUT)          |
| `nip`               | varchar(30)       | Opsional                            |
| `jabatan_fungsional`| varchar(100)      | Opsional                            |
| `kepangkatan`       | varchar(100)      | Opsional                            |
| `pendidikan_terakhir`| varchar(100)     | Opsional                            |
| `rumpun_ilmu`       | varchar(150)      | Opsional                            |
| `status_serdos`     | varchar(50)       | Opsional                            |
| `status_pegawai`    | varchar(50)       | Opsional                            |
| `ikatan_kerja`      | varchar(100)      | Opsional                            |
| `status_aktivitas`  | varchar(50)       | Opsional                            |
| `penempatan`        | varchar(150)      | Opsional                            |
| `created_at`        | timestamp         | Otomatis dikelola DB                |
| `updated_at`        | timestamp         | Otomatis dikelola DB                |

---

## Konfigurasi .env

Salin template lalu sesuaikan:

```bash
cp .env.example .env
```

Isi `.env`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sim_dosen
DB_USERNAME=root
DB_PASSWORD=
ALLOWED_ORIGINS=http://localhost:3000,http://localhost:5173,http://localhost:8080
SSO_APP_KEY=
SSO_BASE_URL=
SSO_WRITE_ROLES=ADMIN,OPERATOR_PRODI
```

| Variabel          | Default     | Keterangan |
|-------------------|-------------|------------|
| `DB_HOST`         | `127.0.0.1` | Host database |
| `DB_PORT`         | `3306`      | Port database |
| `DB_DATABASE`     | `sim_dosen` | Nama database |
| `DB_USERNAME`     | `root`      | Username database |
| `DB_PASSWORD`     | *(kosong)*  | Password database; **string kosong dihormati** (XAMPP default) |
| `SSO_APP_KEY`     | *(wajib)*   | Secret SSO yang **sama persis** dengan `.env` SIM_MAHASISWA_BE; kosong = semua endpoint ditolak |
| `SSO_BASE_URL`    | *(opsional)*| Base URL SIM_MAHASISWA_BE untuk verifikasi hibrida; kosong = verifikasi lokal saja |
| `SSO_WRITE_ROLES` | `ADMIN,OPERATOR_PRODI` | Role yang boleh menulis data dosen (dipisah koma) |
| `ALLOWED_ORIGINS` | lihat [CORS](#pengaturan-cors) | Origin frontend yang diizinkan (dipisah koma) |

Aturan pembaca `.env` (`config/env.php`, PHP murni):

- Menangani baris kosong, komentar (`#` / `;`), nilai kosong, nilai apit
  kutip tunggal `'...'` dan ganda `"..."`, serta komentar inline `# ...`.
- **Tidak pernah menimpa** variabel yang sudah tersedia di lingkungan —
  environment asli (shell/`SetEnv`/`fastcgi_param`) selalu lebih prioritas.
- Nama variabel lama masih didukung sebagai fallback:
  `DB_NAME` → `DB_DATABASE`, `DB_USER` → `DB_USERNAME`, `DB_PASS` → `DB_PASSWORD`.
- `.env` dibaca dari root proyek (path dihitung relatif terhadap `config/env.php`,
  bukan direktori kerja), jadi aman dipanggil dari mana pun.

> **Produksi:** sebaiknya set variabel environment asli, bukan file `.env`:
> - Apache mod_php: `SetEnv DB_PASSWORD "rahasia"` di VirtualHost (lihat contoh di `.htaccess`)
> - PHP-FPM: `env[DB_PASSWORD] =rahasia` di pool `www.conf`, atau `fastcgi_param`
> - Shell/CLI: `export DB_PASSWORD=rahasia`

`.env` sudah masuk `.gitignore` (bersama `.env.*`), sementara `.env.example`
tetap di-commit karena tidak berisi kredensial asli.

---

## Menjalankan di Localhost

### Opsi A — PHP built-in server (paling cepat)

Dari folder proyek:

```bash
php -S localhost:8000 index.php
```

`index.php` dipakai sebagai *router script*, sehingga routing tetap berfungsi
(`.htaccess` tidak dibaca oleh server bawaan PHP). Buka:

```
http://localhost:8000/api/dosen
```

### Opsi B — XAMPP (Apache, Windows/Linux)

1. Salin folder proyek ke `C:\xampp\htdocs\sim-dosen-api\` (atau `/var/www/html/`).
2. Start **Apache** dan **MySQL** di XAMPP Control Panel.
3. Import `sim_dosen.sql` lewat phpMyAdmin (lihat [Persiapan Database](#persiapan-database)).
4. Buka `http://localhost/sim-dosen-api/` — muncul JSON
   `{"success":true,"message":"SIM Dosen API aktif",...}` berarti siap.
5. Default XAMPP: user `root`, password kosong, `127.0.0.1:3306` —
   sudah sesuai nilai default `.env.example`.

### Opsi C — Apache manual (Linux)

```bash
sudo cp -r sim-dosen-api /var/www/html/
sudo chown -R www-data:www-data /var/www/html/sim-dosen-api

sudo a2enmod rewrite
# Pastikan direktori web mengizinkan override, di /etc/apache2/sites-available/000-default.conf:
#   <Directory /var/www/html>
#       Options Indexes FollowSymLinks
#       AllowOverride All
#       Require all granted
#   </Directory>
sudo systemctl restart apache2
```

`.htaccess` menangani routing sekaligus blokir akses file sensitif;
`mod_rewrite` wajib aktif.

---

## Autentikasi SSO (SIM_MAHASISWA_BE)

Login **tidak dibuat sendiri** di API ini — SIM DOSEN BE adalah **klien SSO**
dari **SIM_MAHASISWA_BE** (penyedia login). Token yang diterbitkan di sana
dipakai utuh di sini.

**Alur:**

1. Klien login ke SIM_MAHASISWA_BE:
   `POST {SSO_BASE_URL}/api/v1/login` → mendapat `token`
   (`base64url(payload).HMAC-SHA256`, payload `{uid, un, role, iat, exp}`,
   TTL `AUTH_TOKEN_TTL`, default 3600 detik).
2. Klien memakai token yang sama untuk API ini — sertakan header
   `Authorization: Bearer <token>` pada **semua** request `/api/*`.
3. API ini memverifikasi token:
   - **Lokal** (semua request): hitung ulang HMAC dengan `SSO_APP_KEY` yang
     sama + cek kadaluarsa (`hash_equals`, timing-safe).
   - **Remote** (`PUT`/`PATCH`/`DELETE`): `GET {SSO_BASE_URL}/api/v1/verify`
     agar status akun & role terkini ikut diperiksa. Bila SIM_MAHASISWA_BE
     tidak terjangkau → respons `503` (jujur, bukan lolos diam-diam).

**Kebijakan akses:**

| Aksi | Method | Syarat |
|------|--------|--------|
| Baca (daftar/detail dosen) | `GET` | Token valid — role apa pun |
| Tulis (tambah/ubah/hapus) | `POST`/`PUT`/`PATCH`/`DELETE` | Token valid + role `ADMIN`/`OPERATOR_PRODI` (sesuai `SSO_WRITE_ROLES`) |

**Status auth:** `401` token tidak ada/kadaluarsa/ditolak SSO · `403` role
tidak berwenang · `503` layanan SSO tidak terjangkau (mode hibrida) ·
`500` `SSO_APP_KEY` belum diatur di server.

Catatan:

- API ini sengaja **tidak** menerima token via query string (`?token=`) —
  mudah bocor ke log akses; hanya header `Authorization`.
- `SSO_APP_KEY` harus identik di `.env` kedua proyek dan hanya HTTPS.
- Ambil token untuk pengujian (port/akun disesuaikan SIM_MAHASISWA_BE):

```bash
TOKEN=$(curl -s -X POST "http://localhost:8001/api/v1/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"rahasia"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"]["token"] ?? "";')
echo "$TOKEN"
```

---

## Daftar Endpoint

Base URL contoh: `http://localhost:8000` (built-in) /
`http://localhost/sim-dosen-api` (Apache).

> **Semua endpoint `/api/*` dilindungi token SSO** (header
> `Authorization: Bearer <token>`) — lihat
> [Autentikasi SSO](#autentikasi-sso-sim_mahasiswa_be).
> Hanya `GET /` yang publik (health check).

| Method | Endpoint               | Keterangan |
|--------|------------------------|------------|
| GET    | `/`                    | Ringkasan endpoint (health check) |
| GET    | `/api/dosen`           | Daftar dosen — pagination, pencarian, filter |
| POST   | `/api/dosen`           | Tambah dosen (`201 Created`) |
| GET    | `/api/dosen/{id}`      | Detail satu dosen |
| PUT    | `/api/dosen/{id}`      | Perbarui **seluruh** data dosen (replace) |
| PATCH  | `/api/dosen/{id}`      | Perbarui **sebagian** data dosen |
| DELETE | `/api/dosen/{id}`      | Hapus dosen |

### Parameter query `GET /api/dosen`

| Parameter            | Tipe  | Default | Keterangan |
|----------------------|-------|---------|------------|
| `search`             | teks  | –       | Pencarian **mengandung** di `nama`, `nip`, `nuptk` |
| `status_pegawai`     | teks  | –       | Filter persis `status_pegawai` |
| `status_aktivitas`   | teks  | –       | Filter persis `status_aktivitas` |
| `jabatan_fungsional` | teks  | –       | Filter persis `jabatan_fungsional` |
| `page`               | angka | `1`     | Nomor halaman (≥ 1) |
| `per_page`           | angka | `10`    | Jumlah data per halaman (1–100) |

### Kolom yang diterima (whitelist)

`POST` / `PUT` / `PATCH` **hanya** menerima kolom berikut (kolom lain → `422`).
`id`, `created_at`, `updated_at` dikelola otomatis oleh database:

```
nomor_registrasi, nuptk, nama, nip, jabatan_fungsional, kepangkatan,
pendidikan_terakhir, rumpun_ilmu, status_serdos, status_pegawai,
ikatan_kerja, status_aktivitas, penempatan
```

Perilaku per method:

- **POST** — wajib `nama` (teks, maks 150 karakter); kolom lain opsional (string/null).
- **PUT** — wajib `nama`; **penggantian penuh**: kolom whitelist yang tidak
  dikirim menjadi `NULL`.
- **PATCH** — tidak ada kolom wajib, tapi **minimal satu kolom** harus dikirim;
  hanya kolom yang dikirim yang diubah.
- Semua nilai harus berupa **string atau null**; string kosong pada kolom
  opsional menjadi `NULL`.

### Kode status yang dipakai`200` OK · `201` Created · `204` Preflight CORS (OPTIONS) · `400` Body/param/ID
tidak valid · `401` Token SSO tidak ada/tidak valid/kadaluarsa · `403` Role
tidak berwenang · `404` Data/endpoint tidak ditemukan · `405` Method tidak
diizinkan (disertai header `Allow`) · `422` Validasi gagal (disertai `errors`)
· `500` Kesalahan server (pesan generik; detail hanya di `error_log()`) ·
`503` Layanan verifikasi SSO tidak tersedia.

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
  "data": [
    { "id": 1, "nama": "Dr. Ahmad Fauzi, M.T.", "nip": "197501012005011001" }
  ],
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

Ganti base URL sesuai instalasi Anda. **Semua request `/api/*` memerlukan
token SSO** (lihat [Autentikasi SSO](#autentikasi-sso-sim_mahasiswa_be)) —
set dulu sekali per sesi:

```bash
TOKEN="<token dari SIM_MAHASISWA_BE>"
```

Lalu sertakan `-H "Authorization: Bearer $TOKEN"` pada setiap request
(sudah disertai pada contoh di bawah).

### 1. Daftar dosen (pagination + pencarian + filter)

```bash
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen?page=1&per_page=2"
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen?search=Siti"
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen?search=1982"
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen?status_pegawai=Tidak+Tetap"
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen?status_aktivitas=Mengajar&jabatan_fungsional=Lektor"
```

### 2. Detail dosen

```bash
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen/2"
```

```json
{
  "success": true,
  "message": "Data dosen berhasil diambil",
  "data": { "id": 2, "nama": "Siti Nurhaliza, S.Kom., M.Kom.", "nip": "198202022008012002" }
}
```

ID tidak ditemukan → `404`; ID bukan angka (mis. `/api/dosen/abc`) → `400`.

### 3. Tambah dosen (POST → 201)

```bash
curl -X POST "http://localhost:8000/api/dosen" \
  -H "Authorization: Bearer $TOKEN" \
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
  "data": { "id": 7, "nama": "Dr. Rina Marlina, M.Kom.", "nip": "198801012015042007" }
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
curl -X PUT "http://localhost:8000/api/dosen/6" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "nama": "Andi Wijaya, S.T., M.T.",
    "jabatan_fungsional": "Lektor",
    "status_pegawai": "Tetap"
  }'
```

Kolom whitelist yang tidak dikirim (mis. `nuptk`, `penempatan`) menjadi `NULL`.

### 5. Perbarui sebagian data (PATCH)

```bash
curl -X PATCH "http://localhost:8000/api/dosen/7" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status_aktivitas": "Cuti", "penempatan": "Gedung F"}'
```

Body `{}` (tanpa kolom) → `422`.

### 6. Hapus dosen

```bash
curl -X DELETE -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/dosen/7"
```

```json
{ "success": true, "message": "Data dosen berhasil dihapus", "data": null }
```

ID sudah tidak ada → `404`.

### 7. Preflight CORS (OPTIONS)

```bash
curl -i -X OPTIONS "http://localhost:8000/api/dosen" \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type"
```

Menghasilkan `204 No Content` + header `Access-Control-Allow-*` (bila origin terdaftar).

### 8. Akses file sensitif ditolak

```bash
curl -i "http://localhost:8000/.env"
curl -i "http://localhost:8000/sim_dosen.sql"
```

Keduanya → `404` (data JSON), tanpa membocorkan isi file.

---

## Pengujian dengan Postman

1. Buka Postman → **Create Request**.
2. Pilih method (mis. `GET`) dan URL:
   `http://localhost:8000/api/dosen?page=1&per_page=5`.
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
6. (Opsional) uji preflight CORS: method `OPTIONS`, tambahkan header
   `Origin: http://localhost:3000` dan `Access-Control-Request-Method: POST`.
7. Untuk semua request `/api/*`: tab **Authorization** → Type **Bearer Token**
   → tempel token dari login SIM_MAHASISWA_BE (tanpa prefix `Bearer ` —
   Postman menambahkannya otomatis).
8. (Opsional) simpan request ke Collection `SIM Dosen API` agar bisa
   dipakai ulang/dibagikan ke tim.

---

## Pengaturan CORS

CORS hanya diizinkan untuk origin frontend yang terdaftar di env
`ALLOWED_ORIGINS` (dipisah koma). Default bila tidak diset:

```
http://localhost:3000,http://localhost:5173,http://localhost:8080
```

(frontend React/Vue/Angular dev server umum). Saat frontend memakai domain
berbeda, daftarkan originnya di `.env` atau environment:

```env
ALLOWED_ORIGINS=http://localhost:3000,https://frontend.example.com
```

Perilaku:

- Origin terdaftar → respons disertai `Access-Control-Allow-Origin: <origin>` dan `Vary: Origin`.
- Origin tidak terdaftar → header CORS **tidak** dikirim; browser memblokir
  akses dari halaman tersebut (curl/Postman tidak terpengaruh).
- `OPTIONS` (preflight) dijawab `204` + header `Access-Control-Allow-Methods`
  (`GET, POST, PUT, PATCH, DELETE, OPTIONS`) dan
  `Access-Control-Allow-Headers` (`Content-Type, Authorization, X-Requested-With`).

---

## Keamanan & Perlindungan File Konfigurasi

Sudah diterapkan:

- **Autentikasi SSO** — semua endpoint `/api/*` memerlukan token dari
  SIM_MAHASISWA_BE (verifikasi HMAC lokal + remote untuk aksi sensitif),
  dengan pembatasan role per method — lihat [Autentikasi SSO](#autentikasi-sso-sim_mahasiswa_be).
- **Prepared statement** PDO (`ATTR_EMULATE_PREPARES = false`); karakter
  khusus LIKE (`%`, `_`, `!`) di-escape agar input pencarian tidak menjadi wildcard.
- **Whitelist kolom**: request hanya diterima untuk kolom yang ada di skema;
  `id`, `created_at`, `updated_at` tidak bisa dikontrol dari request.
- **Validasi input**: tipe (string/null), kolom wajib, panjang maksimal sesuai
  skema, ID URL hanya bilangan bulat positif.
- **Error aman**: pesan error database/detail internal tidak pernah dikirim
  ke klien (`display_errors` dimatikan); detail dicatat via `error_log()`.
- **Kredensial terpisah**: dibaca dari environment/.env (tidak di-hardcode),
  `.env` masuk `.gitignore`, `.env.example` bebas kredensial.
- **Blokir akses file sensitif**:
  - Apache (`.htaccess`): `FilesMatch` untuk `.env*` dan `*.sql`, serta
    RewriteRule `[F]` untuk `config/`, `helpers/`, `api/` → HTTP `403`.
  - PHP built-in server & SAPI lain: guard di `index.php`
    (`block_sensitive_path()`) → HTTP `404`.
  - Nginx: blokir via `location` — lihat [bagian Nginx](#catatan-untuk-nginx--php-fpm).

> ### ✅ Autentikasi sudah terpasang
>
> Endpoint perubahan data terlindungi **SSO dari SIM_MAHASISWA_BE**:
> token HMAC (`SSO_APP_KEY` identik di kedua proyek), role-based
> (`ADMIN`/`OPERATOR_PRODI` untuk tulis), dan verifikasi remote
> (`SSO_BASE_URL`) untuk PUT/PATCH/DELETE. Yang perlu dijaga di produksi:
> `SSO_APP_KEY` hanya di `.env`, komunikasi HTTPS, dan sinkronisasi jam
> antar server (token memakai `exp` berbasis waktu).

Tips produksi: jalankan di HTTPS, batasi rate pada endpoint tulis,
aktifkan backup database berkala.

---

## Catatan untuk Nginx & PHP-FPM

**`.htaccess` tidak berlaku di Nginx.** Semua aturannya harus dipindahkan
ke konfigurasi server Nginx. Contoh blok server:

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/sim-dosen-api;
    index index.php;

    # Routing: request tanpa file fisik diteruskan ke index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # --- Blokir file/folder sensitif (pengganti aturan .htaccess) ---
    location ~ /\.env            { deny all; }
    location ~ \.sql$            { deny all; }
    location ~ ^/(config|helpers)/ { deny all; }
    location ~ ^/api/.*\.php$    { deny all; }   # /api/dosen tanpa .php tetap jalan

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Catatan PHP-FPM:

- `SetEnv` Apache **tidak terbaca** PHP-FPM. Set kredensial lewat:
  - `env[DB_HOST]`, `env[DB_USERNAME]`, `env[DB_PASSWORD]`, … di
    `/etc/php/8.3/fpm/pool.d/www.conf`, lalu `sudo systemctl restart php8.3-fpm`; atau
  - `clear_env = no` pada pool agar variabel dari systemd/shell terbaca; atau
  - file `.env` di root proyek (tetap didukung; nilai `DB_PASSWORD` kosong sah).
- `mod_rewrite` tidak dipakai Nginx — routing sepenuhnya oleh `try_files` di atas.

---

## Endpoint Tambahan (Tabel Pendukung)

`sim_dosen.sql` **hanya** berisi tabel `dosen` — tidak ada tabel pendukung
lain (mis. `users`, `prodi`, `pengajaran`). Karena itu API ini juga hanya
menyediakan resource `dosen`; **tidak ada tabel/kolom yang dikarang**.

Bila skema dikembangkan nanti, tambahkan resource baru dengan pola yang sama
(buat file `api/<resource>.nya` + daftarkan di router `index.php`), misalnya:

- Tabel **`users`** — tidak diperlukan untuk autentikasi: API ini sudah
  memakai SSO dari SIM_MAHASISWA_BE (lihat [Autentikasi SSO](#autentikasi-sso-sim_mahasiswa_be)).
- Tabel **`riwayat_pendidikan` / `riwayat_pengajaran`** dengan FK ke
  `dosen.id` → endpoint `/api/dosen/{id}/riwayat-...` (nested resource).

Pastikan setiap kolom endpoint baru benar-benar ada di tabel terkait.

---

## Troubleshooting

| Gejala | Solusi |
|--------|--------|
| `401 Token tidak ditemukan` / `Token tidak valid atau kadaluarsa` | Sertakan header `Authorization: Bearer <token>`; token diambil dari `POST /api/v1/login` SIM_MAHASISWA_BE; pastikan `SSO_APP_KEY` **sama persis** di kedua `.env` dan server sudah sinkron jamnya |
| `500 Autentikasi belum dikonfigurasi pada server` | `SSO_APP_KEY` masih kosong di `.env` API dosen — isi dengan APP_KEY milik SIM_MAHASISWA_BE |
| `403 Role "..." tidak memiliki akses` | Pemakai bukan ADMIN/OPERATOR_PRODI — tambah role di `SSO_WRITE_ROLES` bila memang berwenang |
| `503 Layanan verifikasi SSO tidak tersedia` | `SSO_BASE_URL` salah atau SIM_MAHASISWA_BE sedang mati — aksi sensitif (PUT/PATCH/DELETE) ditahan; GET tetap jalan (verifikasi lokal) |
| URL `/api/dosen` mengembalikan `404`/`403` dari Apache (bukan JSON) | `mod_rewrite` belum aktif (`a2enmod rewrite`) atau `AllowOverride` bukan `All` |
| Halaman 500 kosong | Cek log error Apache (`/var/log/apache2/error.log`) atau lihat output terminal `php -S`; API sengaja tidak menampilkan detail error ke klien |
| `{"success":false,"message":"Tidak dapat terhubung ke database",...}` | Periksa `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`, pastikan database `sim_dosen` sudah di-import; detail ada di `error_log()` |
| `mb_strlen` undefined | Aktifkan ekstensi `mbstring` di `php.ini` |
| CORS ditolak browser | Daftarkan origin frontend di `ALLOWED_ORIGINS`; origin harus sama persis termasuk protokol & port |
| `/.env` bisa diakses di Nginx | Tambahkan blok `location ~ /\.env { deny all; }` — `.htaccess` tidak berlaku di Nginx |

---

Diuji dengan PHP 8.3 + MariaDB 10.11 (PHP built-in server maupun Apache).
Seluruh endpoint, kode status, pagination, pencarian, filter, validasi,
CORS, blokir file sensitif, serta autentikasi SSO (token valid/tidak
sah/kadaluarsa, role, verifikasi remote) diuji end-to-end terhadap skema
`sim_dosen.sql`.
