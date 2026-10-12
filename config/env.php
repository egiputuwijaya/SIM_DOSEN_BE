<?php
declare(strict_types=1);

/**
 * Pembaca file .env sederhana — PHP murni, tanpa Composer/library eksternal.
 *
 * Fitur:
 *   - Menangani baris kosong, komentar (`#` dan `;`), nilai kosong,
 *     nilai apit kutip tunggal ('...') dan ganda ("..."), serta
 *     komentar inline pada nilai tanpa kutip.
 *   - TIDAK menimpa environment variable yang sudah tersedia
 *     (prioritas: variabel environment asli > file .env).
 *   - Nilai kosong (mis. `DB_PASSWORD=`) tetap dihormati — password
 *     kosong pada instalasi lokal (mis. XAMPP) tetap bisa dipakai.
 *   - Variabel yang belum diset mengembalikan nilai default (bisa null).
 */

/**
 * Muat pasangan key=value dari file .env ke environment.
 *
 * Hanya menambah variabel yang BELUM ada; variabel yang sudah tersedia
 * dari shell / Apache SetEnv / PHP-FPM tidak pernah ditimpa.
 *
 * @param ?string $path Path absolut ke file .env.
 *                       Default: <root proyek>/.env
 * @return int Jumlah variabel yang berhasil dimuat (0 bila file tidak ada).
 */
function env_load(?string $path = null): int
{
    $path ??= dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if ($path === '' || !is_file($path) || !is_readable($path)) {
        // Bukan error: di produksi biasanya memakai environment variable asli.
        return 0;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        error_log('[env] Gagal membaca file .env: ' . $path);
        return 0;
    }

    $loaded = 0;

    foreach ($lines as $line) {
        $line = trim($line);

        // Lewati baris kosong dan komentar (penuh baris).
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }

        // Format yang diharapkan: KEY=VALUE
        $equalsPos = strpos($line, '=');
        if ($equalsPos === false) {
            continue; // Baris rusak — abaikan.
        }

        $key   = trim(substr($line, 0, $equalsPos));
        $value = trim(substr($line, $equalsPos + 1));

        // Nama variabel valid ala POSIX: huruf/underscore, lalu huruf/angka/underscore.
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        // Jangan pernah menimpa environment variable yang sudah tersedia.
        if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
            continue;
        }

        $value = env_strip_value($value);

        putenv($key . '=' . $value); // putenv("KEY=") -> string kosong, getenv() mengembalikan "".
        $_ENV[$key] = $value;

        $loaded++;
    }

    return $loaded;
}

/**
 * Normalisasi nilai: hapus apit kutip dan komentar inline, proses escape
 * untuk kutip ganda. Kutip tunggal bersifat literal.
 */
function env_strip_value(string $value): string
{
    if ($value === '') {
        return '';
    }

    $quote = $value[0];

    // Nilai diapit kutip tunggal atau ganda.
    if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && substr($value, -1) === $quote) {
        $inner = substr($value, 1, -1);

        if ($quote === '"') {
            // Dalam kutip ganda, proses escape umum.
            $inner = str_replace(
                ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $inner
            );
        }

        return $inner;
    }

    // Tanpa kutip: buang komentar inline (" # ..." atau "\t# ...").
    if (($pos = strpos($value, ' #')) !== false) {
        $value = substr($value, 0, $pos);
    } elseif (($pos = strpos($value, "\t#")) !== false) {
        $value = substr($value, 0, $pos);
    }

    return trim($value);
}

/**
 * Ambil konfigurasi dari environment (getenv), dengan nilai default.
 *
 * Mengembalikan string ('' = nilai kosong yang valid, mis. password kosong)
 * atau $default bila variabel tidak diset sama sekali.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    // Fallback bila getenv() tidak melihat variabel dari SAPI tertentu
    // (mis. mod_php SetEnv yang hanya masuk $_SERVER).
    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    if ($value === null || $value === false) {
        return $default;
    }

    return (string) $value;
}
