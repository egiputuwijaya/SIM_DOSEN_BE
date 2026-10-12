<?php
declare(strict_types=1);

/**
 * Konfigurasi & koneksi database (PDO) untuk SIM Dosen API.
 *
 * Konfigurasi dibaca lewat getenv() (lihat config/env.php):
 *   DB_HOST      (default: 127.0.0.1)
 *   DB_PORT      (default: 3306)
 *   DB_DATABASE  (default: sim_dosen)  — alias lama: DB_NAME
 *   DB_USERNAME  (default: root)       — alias lama: DB_USER
 *   DB_PASSWORD  (default: "" / kosong, seperti XAMPP standar)
 *                                        — alias lama: DB_PASS
 *
 * Database `sim_dosen` beserta tabelnya dianggap sudah tersedia
 * (lihat sim_dosen.sql); kode ini TIDAK membuat database/tabel apa pun.
 *
 * Password kosong tetap didukung: bila DB_PASSWORD diset menjadi string
 * kosong, nilai kosong itulah yang dipakai (bukan nilai default).
 */

/**
 * Exception khusus untuk kegagalan koneksi database.
 * Pesannya sengaja generik — detail asli (kredensial/DSN) hanya masuk error_log().
 */
class DatabaseConnectionException extends RuntimeException
{
}

/**
 * Konfigurasi database dari environment variable (getenv).
 *
 * @return array{host: string, port: string, dbname: string, username: string, password: string, charset: string}
 */
function db_config(): array
{
    static $config = null;

    if ($config === null) {
        $password = env('DB_PASSWORD', env('DB_PASS', ''));

        $config = [
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '3306'),
            'dbname'   => env('DB_DATABASE', env('DB_NAME', 'sim_dosen')),
            'username' => env('DB_USERNAME', env('DB_USER', 'root')),
            // env() membedakan "tidak diset" (pakai default) dan "diset kosong"
            // (tetap string kosong) — password kosong = sah.
            'password' => $password ?? '',
            'charset'  => 'utf8mb4',
        ];
    }

    return $config;
}

/**
 * Koneksi PDO (singleton) dengan prepared statement native.
 *
 * @throws DatabaseConnectionException Bila gagal terhubung (detail di error_log()).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c   = db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $c['host'],
        $c['port'],
        $c['dbname'],
        $c['charset']
    );

    try {
        $pdo = new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // prepared statement asli MySQL
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        // Detail koneksi (host, user, pesan driver) HANYA dicatat di log server,
        // tidak pernah dikirim ke klien.
        error_log('[sim-dosen-api] Koneksi database gagal: ' . $e->getMessage());
        throw new DatabaseConnectionException('Tidak dapat terhubung ke database', 0, $e);
    }

    return $pdo;
}
