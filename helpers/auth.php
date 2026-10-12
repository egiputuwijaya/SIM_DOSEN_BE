<?php
declare(strict_types=1);

/**
 * Helper autentikasi SSO — SIM DOSEN BE bertindak sebagai klien dari
 * SIM_MAHASISWA_BE (penyedia login).
 *
 * Format token (sama persis dengan AuthController SIM_MAHASISWA_BE):
 *   base64url(JSON payload) + "." + HMAC-SHA256(base64url, SSO_APP_KEY)
 *   payload: { uid, un, role, iat, exp }
 *
 * Dua mode verifikasi (mode hibrida):
 *   1. Lokal  : hitung ulang HMAC dengan secret bersama (APP_KEY yang sama).
 *   2. Remote : GET {SSO_BASE_URL}/api/v1/verify untuk aksi sensitif
 *               (PUT/PATCH/DELETE), agar status akun/role terkini ikut diperiksa.
 *
 * Konfigurasi .env:
 *   SSO_APP_KEY     secret yang SAMA dengan .env SIM_MAHASISWA_BE (wajib)
 *   SSO_BASE_URL    base URL SIM_MAHASISWA_BE (opsional; kosong = lokal saja)
 *   SSO_WRITE_ROLES role yang boleh menulis data dosen (default: ADMIN,OPERATOR_PRODI)
 */

/**
 * Secret SSO. null bila belum dikonfigurasi (semua request ditolak).
 */
function auth_secret(): ?string
{
    $secret = env('SSO_APP_KEY', '');

    return ($secret === null || $secret === '') ? null : $secret;
}

/**
 * Ekstrak token dari header "Authorization: Bearer <token>".
 * Sengaja TIDAK mendukung ?token= (query string mudah bocor ke log akses).
 */
function auth_extract_token(): ?string
{
    // Apache kadang me-rewrite ke REDIRECT_HTTP_AUTHORIZATION.
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * base64url -> string asli. false bila tidak valid.
 */
function auth_base64url_decode(string $data): ?string
{
    $remainder = strlen($data) % 4;
    if ($remainder === 1) {
        return null; // Panjang base64url tidak pernah bersisa 1.
    }
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(strtr($data, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
}

/**
 * Verifikasi token secara lokal (HMAC-SHA256 + cek kadaluarsa).
 * Mengembalikan payload bila valid, null bila tidak.
 */
function auth_verify_token(string $token): ?array
{
    $secret = auth_secret();
    $parts  = explode('.', $token);

    if ($secret === null || count($parts) !== 2) {
        return null;
    }

    [$encoded, $signature] = $parts;
    if ($encoded === '' || $signature === '') {
        return null;
    }

    // Bandingkan tanda tangan tanpa short-circuit (timing-safe).
    $expected = hash_hmac('sha256', $encoded, $secret);
    if (!hash_equals($expected, $signature)) {
        return null; // Token dipalsukan / secret berbeda.
    }

    $json = auth_base64url_decode($encoded);
    if ($json === null) {
        return null;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload) || !isset($payload['uid'], $payload['exp'])) {
        return null;
    }

    if ((int) $payload['exp'] < time()) {
        return null; // Kadaluarsa.
    }

    return $payload;
}

/**
 * Wajib dipanggil untuk endpoint terproteksi.
 * Mengembalikan payload token; bila tidak valid -> 401 + exit.
 */
function auth_require(): array
{
    if (auth_secret() === null) {
        // Gagal-tertutup: tanpa secret, tidak ada token yang bisa diverifikasi.
        error_log('[sim-dosen-api] SSO_APP_KEY belum diatur di .env — semua endpoint API ditolak.');
        json_response(false, 'Autentikasi belum dikonfigurasi pada server', null, 500);
    }

    $token = auth_extract_token();
    if ($token === null || $token === '') {
        json_response(false, 'Token tidak ditemukan. Sertakan header "Authorization: Bearer <token>".', null, 401);
    }

    $payload = auth_verify_token($token);
    if ($payload === null) {
        json_response(false, 'Token tidak valid atau kadaluarsa.', null, 401);
    }

    return $payload;
}

/**
 * Batasi akses berdasarkan role pada token, contoh:
 *   auth_require_role($payload, ['ADMIN', 'OPERATOR_PRODI']);
 */
function auth_require_role(array $payload, array $allowedRoles): void
{
    $role = (string) ($payload['role'] ?? '');

    if (!in_array($role, $allowedRoles, true)) {
        json_response(false, 'Role "' . $role . '" tidak memiliki akses melakukan aksi ini.', null, 403);
    }
}

/**
 * Role yang boleh menulis data dosen (dari env SSO_WRITE_ROLES).
 *
 * @return string[]
 */
function auth_write_roles(): array
{
    $raw   = env('SSO_WRITE_ROLES', 'ADMIN,OPERATOR_PRODI') ?? 'ADMIN,OPERATOR_PRODI';
    $roles = array_values(array_filter(array_map('trim', explode(',', $raw))));

    return $roles === [] ? ['ADMIN', 'OPERATOR_PRODI'] : $roles;
}

/**
 * Verifikasi remote ke SIM_MAHASISWA_BE (mode hibrida, untuk aksi sensitif).
 *
 * - SSO_BASE_URL kosong -> lewati (verifikasi lokal saja dianggap cukup).
 * - SIM_MAHASISWA_BE tidak terjangkau -> 503 (jujur: tidak bisa memastikan).
 * - Token ditolak SSO -> 401.
 * - Valid -> kembalikan profil terkini dari SSO.
 *
 * @return array<string, mixed> Profil pengguna versi SSO.
 */
function auth_remote_verify(): array
{
    $base = rtrim((string) env('SSO_BASE_URL', ''), '/');

    if ($base === '') {
        return []; // Mode lokal saja.
    }

    $token = auth_extract_token();
    if ($token === null) {
        json_response(false, 'Token tidak ditemukan.', null, 401);
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 5,
            'ignore_errors' => true, // Body 4xx/4xx tetap dibaca untuk pesan yang benar.
            'header'        => 'Authorization: Bearer ' . $token . "\r\n" . 'Accept: application/json' . "\r\n",
        ],
    ]);

    $body = @file_get_contents($base . '/api/v1/verify', false, $context);

    if ($body === false) {
        error_log('[sim-dosen-api] Gagal menghubungi layanan SSO: ' . $base . '/api/v1/verify');
        json_response(false, 'Layanan verifikasi SSO tidak tersedia. Coba lagi nanti.', null, 503);
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine . ' ', $matches);
    $status = (int) ($matches[1] ?? 0);

    $json = json_decode($body, true);

    if ($status === 200 && is_array($json) && ($json['success'] ?? false) === true
        && (($json['data']['valid'] ?? false) === true)) {
        return is_array($json['data']['profil'] ?? null) ? $json['data']['profil'] : [];
    }

    // Token ditolak oleh penyedia SSO (kadaluarsa, dinonaktifkan, dsb).
    json_response(false, 'Token tidak valid versi layanan SSO (periksa status akun Anda).', null, 401);
}

/**
 * Guard terpusat untuk semua endpoint /api/*.
 *
 * - Semua method  : token wajib valid (verifikasi lokal).
 * - Selain GET    : role harus termasuk SSO_WRITE_ROLES.
 * - PUT/PATCH/DELETE : verifikasi tambahan ke SIM_MAHASISWA_BE (mode hibrida).
 */
function auth_guard(string $method): void
{
    $payload = auth_require();

    if ($method === 'GET' || $method === 'HEAD') {
        return;
    }

    auth_require_role($payload, auth_write_roles());

    if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
        // Aksi sensitif: pastikan token masih dianggap sah oleh penyedia SSO.
        auth_remote_verify();
    }
}
