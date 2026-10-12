<?php
declare(strict_types=1);

/**
 * Endpoint REST /api/profil-universitas.
 *
 *   GET /api/profil-universitas        daftar profil universitas
 *   PUT /api/profil-universitas/{id}   perbarui profil universitas
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Whitelist kolom yang boleh diterima dari request.
 * Kolom `id`, `created_at`, `updated_at` tidak boleh dikontrol dari request.
 *
 * @return array<string, array>
 */
function profil_rules(): array
{
    return [
        'universitas'   => ['max' => 255],
        'fakultas'      => ['max' => 255],
        'program_studi' => ['max' => 255],
    ];
}

/**
 * Ambil satu baris profil universitas. null bila tidak ada.
 */
function profil_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM profil_universitas WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Dispatcher endpoint /api/profil-universitas.
 */
function handle_profil(string $method, ?int $id): void
{
    if ($id === null) {
        if ($method === 'GET') {
            profil_index();
            return;
        }
        header('Allow: GET');
        json_response(false, 'Metode request tidak diizinkan untuk endpoint ini', null, 405);
    }

    if ($method === 'PUT') {
        profil_update($id);
        return;
    }

    header('Allow: PUT');
    json_response(false, 'Metode request tidak diizinkan untuk endpoint ini', null, 405);
}

/**
 * GET /api/profil-universitas — daftar profil universitas.
 */
function profil_index(): void
{
    $stmt = db()->query('SELECT * FROM profil_universitas ORDER BY id ASC');
    $rows = $stmt->fetchAll();

    json_response(true, 'Data profil universitas berhasil diambil', $rows, 200);
}

/**
 * PUT /api/profil-universitas/{id} — perbarui kolom yang dikirim.
 * Minimal satu kolom (universitas / fakultas / program_studi).
 */
function profil_update(int $id): void
{
    $body = read_json_body();

    if (profil_find($id) === null) {
        json_response(false, 'Data profil universitas tidak ditemukan', null, 404);
    }

    $data = validate_fields($body, profil_rules());

    if ($data === []) {
        validation_response(['_body' => ['Minimal satu kolom harus dikirim: universitas, fakultas, atau program_studi']]);
    }

    $sets   = [];
    $params = [];
    foreach ($data as $column => $value) {
        $sets[]        = "`$column` = :$column";
        $params[":$column"] = $value;
    }
    $params[':id'] = $id;

    $sql  = 'UPDATE profil_universitas SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    json_response(true, 'Data profil universitas berhasil diperbarui', profil_find($id), 200);
}
