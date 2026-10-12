<?php
declare(strict_types=1);

/**
 * Helper respons JSON dan utilitas request untuk SIM Dosen API.
 *
 * Format respons sukses:
 *   { "success": true,  "message": "...", "data": ... }
 * Format respons error:
 *   { "success": false, "message": "...", "data": null }
 *
 * Penambahan opsional (bila ada):
 *   - "meta"   : informasi pagination (daftar data)
 *   - "errors" : detail field yang gagal validasi (HTTP 422)
 */

/**
 * Kirim respons JSON lalu hentikan eksekusi.
 *
 * @param bool     $success Status keberhasilan.
 * @param string   $message Pesan untuk klien.
 * @param mixed    $data    Data payload (null untuk error).
 * @param int      $status  HTTP status code.
 * @param ?array   $extra   Key tambahan opsional, mis. ['meta' => [...]].
 */
function json_response(bool $success, string $message, mixed $data = null, int $status = 200, ?array $extra = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $payload = [
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ];

    if ($extra !== null) {
        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Respons validasi gagal (HTTP 422).
 *
 * @param array<string, string[]> $errors Contoh: ['nama' => ['Wajib diisi']]
 */
function validation_response(array $errors): never
{
    json_response(false, 'Data tidak valid', null, 422, ['errors' => $errors]);
}

/**
 * Baca dan decode body request sebagai JSON.
 *
 * @return array<string, mixed> Objek JSON (associative). [] bila body kosong.
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        json_response(false, 'Body request bukan JSON yang valid', null, 400);
    }

    if (!is_array($decoded)) {
        json_response(false, 'Body request harus berupa objek JSON', null, 400);
    }

    // Array JSON (mis. [1,2,3]) bukan objek — tolak.
    if ($decoded !== [] && array_is_list($decoded)) {
        json_response(false, 'Body request harus berupa objek JSON', null, 400);
    }

    return $decoded;
}

/**
 * Ambil parameter query string berupa teks (aman dari array injection).
 */
function query_str(string $key, int $maxLen = 100): string
{
    if (!isset($_GET[$key])) {
        return '';
    }

    $value = $_GET[$key];
    if (!is_scalar($value)) {
        json_response(false, "Parameter '$key' tidak valid", null, 400);
    }

    $value = trim((string) $value);
    if (mb_strlen($value) > $maxLen) {
        json_response(false, "Parameter '$key' maksimal $maxLen karakter", null, 400);
    }

    return $value;
}

/**
 * Ambil parameter query string berupa bilangan bulat dalam rentang tertentu.
 */
function query_int(string $key, int $default, int $min, int $max): int
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    $value = $_GET[$key];
    if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
        json_response(false, "Parameter '$key' harus berupa angka", null, 400);
    }

    $number = (int) $value;
    if ($number < $min || $number > $max) {
        json_response(false, "Parameter '$key' harus antara $min dan $max", null, 400);
    }

    return $number;
}

/**
 * Validasi body request terhadap daftar kolom yang diizinkan (whitelist).
 *
 * - Kolom di luar $rules ditolak (tidak menerima kolom sembarang).
 * - Kolom bertanda 'required' wajib berisi teks non-kosong.
 * - Semua nilai harus berupa teks (string) atau null, maksimal 'max' karakter.
 *
 * @param array<string, mixed> $body  Hasil read_json_body()
 * @param array<string, array> $rules ['kolom' => ['required' => true, 'max' => 150], ...]
 * @return array<string, string|null> Nilai yang sudah bersih per kolom yang dikirim.
 */
function validate_fields(array $body, array $rules): array
{
    $errors = [];
    $clean  = [];

    // 1) Tolak kolom yang tidak dikenal / tidak diizinkan.
    foreach ($body as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, $rules)) {
            $errors[(string) $key][] = 'Kolom tidak dikenal atau tidak diizinkan';
        }
    }

    // 2) Validasi tiap kolom yang diizinkan.
    foreach ($rules as $field => $rule) {
        $required = (bool) ($rule['required'] ?? false);
        $max      = (int) ($rule['max'] ?? 255);

        if (!array_key_exists($field, $body)) {
            if ($required) {
                $errors[$field][] = 'Wajib diisi';
            }
            continue;
        }

        $value = $body[$field];

        if ($value === null) {
            if ($required) {
                $errors[$field][] = 'Wajib diisi';
                continue;
            }
            $clean[$field] = null;
            continue;
        }

        if (!is_string($value)) {
            $errors[$field][] = 'Harus berupa teks';
            continue;
        }

        $value = trim($value);

        if ($required && $value === '') {
            $errors[$field][] = 'Wajib diisi';
            continue;
        }

        if (mb_strlen($value) > $max) {
            $errors[$field][] = "Maksimal $max karakter";
            continue;
        }

        // String kosong pada kolom opsional diseragamkan menjadi NULL.
        $clean[$field] = $value === '' ? null : $value;
    }

    if ($errors !== []) {
        validation_response($errors);
    }

    return $clean;
}
