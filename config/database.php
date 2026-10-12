<?php
declare(strict_types=1);

/**
 * Konfigurasi & koneksi database (PDO) untuk SIM Dosen API.
 *
 * Kredensial dibaca dari environment variable bila lingkungan mendukung:
 *   DB_HOST  (default: 127.0.0.1)
 *   DB_PORT  (default: 3306)
 *   DB_NAME  (default: sim_dosen)
 *   DB_USER  (default: root)
 *   DB_PASS  (default: string kosong, seperti instalasi XAMPP standar)
 *
 * Database `sim_dosen` dan tabelnya dianggap sudah tersedia;
 * kode ini TIDAK membuat database/tabel apa pun.
 */

function db_config(): array
{
    static $config = null;

    if ($config === null) {
        $password = getenv('DB_PASS');
        $config = [
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('DB_PORT') ?: '3306',
            'dbname'   => getenv('DB_NAME') ?: 'sim_dosen',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => $password === false ? '' : $password,
            'charset'  => 'utf8mb4',
        ];
    }

    return $config;
}

/**
 * Koneksi PDO (singleton) dengan prepared statement sebagai default.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c    = db_config();
    $dsn  = sprintf(
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
            PDO::ATTR_EMULATE_PREPARES   => false, // native prepared statements
        ]);
    } catch (PDOException $e) {
        // Detail koneksi/SQL hanya dicatat di log server, tidak pernah
        // dikirim ke klien.
        error_log('[sim-dosen-api] Koneksi database gagal: ' . $e->getMessage());
        json_response(false, 'Tidak dapat terhubung ke database', null, 500);
    }

    return $pdo;
}
