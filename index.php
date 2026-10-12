<?php
declare(strict_types=1);

/**
 * SIM Dosen API — front controller & router sederhana (tanpa framework).
 *
 * Semua request diarahkan ke file ini:
 *   - Apache    : oleh .htaccess (mod_rewrite)
 *   - Nginx     : oleh try_files ... /index.php (lihat README.md)
 *   - Dev lokal : `php -S localhost:8000 index.php` (router script)
 */

// Jangan tampilkan detail error ke klien; catat ke log server.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/config/env.php';       // Pembaca .env (PHP murni).
require __DIR__ . '/helpers/response.php';
require __DIR__ . '/config/database.php';

// Muat .env hanya bila ada; environment variable asli tetap lebih prioritas.
env_load(__DIR__ . '/.env');

// Keamanan: jangan pernah melayani file konfigurasi/ rahasia via HTTP.
block_sensitive_path();

cors_handling();

$routeMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path        = request_path();

try {
    route($routeMethod, $path);
} catch (DatabaseConnectionException $e) {
    // Detail (DSN, kredensial) sudah dicatat oleh config/database.php.
    json_response(false, $e->getMessage(), null, 500);
} catch (PDOException $e) {
    // Detail SQL hanya di log server, tidak pernah dikirim ke klien.
    error_log('[sim-dosen-api] Database error: ' . $e->getMessage());
    json_response(false, 'Terjadi kesalahan pada server', null, 500);
} catch (Throwable $e) {
    error_log('[sim-dosen-api] ' . $e::class . ': ' . $e->getMessage()
        . ' di ' . $e->getFile() . ':' . $e->getLine());
    json_response(false, 'Terjadi kesalahan pada server', null, 500);
}

/**
 * Tolak akses HTTP langsung ke file sensitif (.env, .env.example, *.sql,
 * folder config/ dan helper/) — berlaku di semua SAPI, termasuk PHP
 * built-in server yang mengabaikan .htaccess.
 */
function block_sensitive_path(): void
{
    $path = request_path();

    $blocked = preg_match('#(?:^|/)(\.env(?:\.[A-Za-z0-9_]+)?|[^/]+\.sql)$#i', $path) === 1
        || preg_match('#^/(config|helpers)(/|$)#i', $path) === 1
        || preg_match('#^/api/.*\.php$#i', $path) === 1;

    if ($blocked) {
        json_response(false, 'Endpoint tidak ditemukan', null, 404);
    }
}

/**
 * Path URL yang diminta, sudah lepas dari nama folder instalasi
 * (mis. /sim-dosen-api/api/dosen -> /api/dosen) dan di-decode.
 */
function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (basename($script) === 'index.php') {
        $base = rtrim(dirname($script), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
    }

    // Pengaksesan langsung /index.php dianggap sama dengan root.
    if (basename($path) === 'index.php') {
        $path = dirname($path);
    }

    return '/' . trim(rawurldecode($path), '/');
}

/**
 * Router: cocokkan method + path ke handler yang sesuai.
 */
function route(string $method, string $path): void
{
    if ($path === '/') {
        home_endpoint();
        return;
    }

    // Bagi path menjadi segmen; abaikan segmen kosong ganda.
    $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

    if (($segments[0] ?? '') !== 'api') {
        not_found();
    }

    $resource = $segments[1] ?? '';
    $count    = count($segments);

    if ($resource !== 'dosen') {
        not_found();
    }

    if ($count > 3) {
        not_found();
    }

    // /api/dosen -> koleksi (id null); /api/dosen/{id} -> item.
    $id = $count === 3 ? route_id($segments[2]) : null;

    require_once __DIR__ . '/api/dosen.php';
    handle_dosen($method, $id);
}

/**
 * Validasi segmen ID dari URL: hanya bilangan bulat positif
 * yang muat di tipe bigint unsigned.
 */
function route_id(string $segment): int
{
    if ($segment === '' || !ctype_digit($segment) || strlen($segment) > 20 || (int) $segment < 1) {
        json_response(false, 'ID tidak valid', null, 400);
    }

    return (int) $segment;
}

/**
 * GET / — ringkasan endpoint (untuk pengecekan cepat).
 */
function home_endpoint(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        header('Allow: GET, OPTIONS');
        json_response(false, 'Metode request tidak diizinkan untuk endpoint ini', null, 405);
    }

    json_response(true, 'SIM Dosen API aktif', [
        'endpoints' => [
            ['method' => 'GET',    'path' => '/api/dosen',      'keterangan' => 'Daftar dosen (pagination, search, filter)'],
            ['method' => 'POST',   'path' => '/api/dosen',      'keterangan' => 'Tambah dosen'],
            ['method' => 'GET',    'path' => '/api/dosen/{id}', 'keterangan' => 'Detail dosen'],
            ['method' => 'PUT',    'path' => '/api/dosen/{id}', 'keterangan' => 'Perbarui seluruh data dosen (replace)'],
            ['method' => 'PATCH',  'path' => '/api/dosen/{id}', 'keterangan' => 'Perbarui sebagian data dosen'],
            ['method' => 'DELETE', 'path' => '/api/dosen/{id}', 'keterangan' => 'Hapus dosen'],
        ],
        'dokumentasi' => 'Lihat README.md',
    ], 200);
}

/**
 * Respons 404 bawaan untuk path yang tidak dikenal.
 */
function not_found(): never
{
    json_response(false, 'Endpoint tidak ditemukan', null, 404);
}
