<?php
declare(strict_types=1);

/**
 * SIM Dosen API — front controller & router sederhana.
 *
 * Semua request diarahkan ke file ini (oleh .htaccess di Apache,
 * atau oleh router script pada PHP built-in server).
 */

// Jangan tampilkan detail error ke klien; catat ke log server.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/helpers/response.php';
require __DIR__ . '/config/database.php';

cors_handling();

$routeMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path        = request_path();

try {
    route($routeMethod, $path);
} catch (PDOException $e) {
    // Detail SQL hanya di log server, tidak pernah dikirim ke klien.
    error_log('[sim-dosen-api] Database error: ' . $e->getMessage());
    json_response(false, 'Terjadi kesalahan pada server', null, 500);
} catch (Throwable $e) {
    error_log('[sim-dosen-api] ' . $e::class . ': ' . $e->getMessage());
    json_response(false, 'Terjadi kesalahan pada server', null, 500);
}

/**
 * CORS: izinkan hanya origin frontend yang terdaftar di env ALLOWED_ORIGINS
 * (dipisah koma). Request OPTIONS (preflight) dijawab 204.
 */
function cors_handling(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

    $allowedEnv = getenv('ALLOWED_ORIGINS');
    if ($allowedEnv === false || trim($allowedEnv) === '') {
        // Default: origin frontend yang umum dipakai saat development.
        $allowedEnv = 'http://localhost:3000,http://localhost:5173,http://localhost:8080';
    }

    $allowed = array_map('trim', explode(',', $allowedEnv));

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }
    // Origin di luar daftar: header CORS tidak dikirim; browser yang memblokir.

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Path URL yang diminta, sudah lepas dari nama folder instalasi
 * (mis. /sim-dosen-api/api/dosen -> /api/dosen).
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

    return '/' . trim($path, '/');
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

    if ($resource === 'dosen' || $resource === 'profil-universitas') {
        if ($count < 2 || $count > 3) {
            not_found();
        }

        $id = $count === 3 ? route_id($segments[2]) : null;

        if ($resource === 'dosen') {
            require_once __DIR__ . '/api/dosen.php';
            handle_dosen($method, $id);
        } else {
            require_once __DIR__ . '/api/profil-universitas.php';
            handle_profil($method, $id);
        }

        return;
    }

    not_found();
}

/**
 * Validasi segmen ID dari URL: hanya bilangan bulat positif.
 */
function route_id(string $segment): int
{
    if ($segment === '' || !ctype_digit($segment) || strlen($segment) > 19 || (int) $segment < 1) {
        json_response(false, 'ID tidak valid', null, 400);
    }

    return (int) $segment;
}

/**
 * GET / — ringkasan endpoint (untuk pengecekan cepat).
 */
function home_endpoint(): void
{
    json_response(true, 'SIM Dosen API aktif', [
        'endpoints' => [
            ['method' => 'GET',    'path' => '/api/dosen',                 'keterangan' => 'Daftar dosen (pagination, search, filter)'],
            ['method' => 'POST',   'path' => '/api/dosen',                 'keterangan' => 'Tambah dosen'],
            ['method' => 'GET',    'path' => '/api/dosen/{id}',            'keterangan' => 'Detail dosen'],
            ['method' => 'PUT',    'path' => '/api/dosen/{id}',            'keterangan' => 'Perbarui seluruh data dosen'],
            ['method' => 'PATCH',  'path' => '/api/dosen/{id}',            'keterangan' => 'Perbarui sebagian data dosen'],
            ['method' => 'DELETE', 'path' => '/api/dosen/{id}',            'keterangan' => 'Hapus dosen'],
            ['method' => 'GET',    'path' => '/api/profil-universitas',    'keterangan' => 'Daftar profil universitas'],
            ['method' => 'PUT',    'path' => '/api/profil-universitas/{id}', 'keterangan' => 'Perbarui profil universitas'],
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
