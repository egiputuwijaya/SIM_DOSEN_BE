<?php
declare(strict_types=1);

/**
 * Endpoint REST /api/dosen — CRUD, pencarian, filter, dan pagination.
 *
 *   GET    /api/dosen        daftar dosen (pagination + search + filter)
 *   POST   /api/dosen        tambah dosen
 *   GET    /api/dosen/{id}   detail dosen
 *   PUT    /api/dosen/{id}   perbarui seluruh data dosen (replace)
 *   PATCH  /api/dosen/{id}   perbarui sebagian data dosen
 *   DELETE /api/dosen/{id}   hapus dosen
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Whitelist kolom yang boleh diterima dari request (disesuaikan dengan
 * skema tabel `dosen`). Kolom `id`, `created_at`, `updated_at` tidak
 * boleh dikontrol dari request.
 *
 * @param bool $namaWajib true untuk POST/PUT, false untuk PATCH.
 * @return array<string, array>
 */
function dosen_rules(bool $namaWajib = true): array
{
    $rules = [
        'nomor_registrasi'    => ['max' => 50],
        'nuptk'               => ['max' => 30],
        'nama'                => ['max' => 150],
        'nip'                 => ['max' => 30],
        'jabatan_fungsional'  => ['max' => 100],
        'kepangkatan'         => ['max' => 100],
        'pendidikan_terakhir' => ['max' => 100],
        'rumpun_ilmu'         => ['max' => 150],
        'status_serdos'       => ['max' => 50],
        'status_pegawai'      => ['max' => 50],
        'ikatan_kerja'        => ['max' => 100],
        'status_aktivitas'    => ['max' => 50],
        'penempatan'          => ['max' => 150],
    ];

    if ($namaWajib) {
        $rules['nama']['required'] = true;
    }

    return $rules;
}

/**
 * Ambil satu baris dosen berdasarkan ID. null bila tidak ada.
 */
function dosen_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM dosen WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Tulis kolom-kolom values ke baris dosen tertentu (prepared statement).
 *
 * @param array<string, string|null> $values Hanya kolom dari whitelist.
 */
function dosen_write(int $id, array $values): void
{
    if ($values === []) {
        return;
    }

    $sets   = [];
    $params = [];
    foreach ($values as $column => $value) {
        $sets[]   = "`$column` = :$column";
        $params[":$column"] = $value;
    }
    $params[':id'] = $id;

    $sql = 'UPDATE dosen SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

/**
 * Dispatcher endpoint /api/dosen.
 */
function handle_dosen(string $method, ?int $id): void
{
    if ($id === null) {
        switch ($method) {
            case 'GET':
                dosen_index();
                return;
            case 'POST':
                dosen_store();
                return;
            default:
                header('Allow: GET, POST');
                json_response(false, 'Metode request tidak diizinkan untuk endpoint ini', null, 405);
        }
    }

    switch ($method) {
        case 'GET':
            dosen_show($id);
            return;
        case 'PUT':
            dosen_replace($id);
            return;
        case 'PATCH':
            dosen_patch($id);
            return;
        case 'DELETE':
            dosen_destroy($id);
            return;
        default:
            header('Allow: GET, PUT, PATCH, DELETE');
            json_response(false, 'Metode request tidak diizinkan untuk endpoint ini', null, 405);
    }
}

/**
 * GET /api/dosen — daftar dosen + pagination + pencarian + filter.
 *
 * Parameter query:
 *   - search              cari di nama / nip / nuptk (sebagian)
 *   - status_pegawai      filter persis
 *   - status_aktivitas    filter persis
 *   - jabatan_fungsional  filter persis
 *   - page                halaman (default 1)
 *   - per_page            per halaman (default 10, maks 100)
 */
function dosen_index(): void
{
    $where  = [];
    $params = [];

    // Pencarian teks: nama, NIP, NUPTK
    $search = query_str('search', 100);
    if ($search !== '') {
        // Escape karakter khusus LIKE (%, _, !) agar input tidak jadi wildcard.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where[] = "(nama LIKE :s1 ESCAPE '!' OR nip LIKE :s2 ESCAPE '!' OR nuptk LIKE :s3 ESCAPE '!')";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
    }

    // Filter persis (exact match) berdasarkan status & jabatan.
    foreach (['status_pegawai', 'status_aktivitas', 'jabatan_fungsional'] as $column) {
        $value = query_str($column, 100);
        if ($value !== '') {
            $where[] = "`$column` = :f_$column";
            $params[":f_$column"] = $value;
        }
    }

    $page    = query_int('page', 1, 1, 1000000);
    $perPage = query_int('per_page', 10, 1, 100);

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    // Total data untuk pagination.
    $stmt = db()->prepare('SELECT COUNT(*) FROM dosen' . $whereSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $lastPage = max(1, (int) ceil($total / $perPage));
    $offset   = ($page - 1) * $perPage;

    // Data halaman ini (LIMIT/OFFSET diikat sebagai integer).
    $stmt = db()->prepare('SELECT * FROM dosen' . $whereSql . ' ORDER BY id ASC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $from = ($total > 0 && $offset < $total) ? $offset + 1 : null;
    $to   = ($total > 0 && $offset < $total) ? min($offset + $perPage, $total) : null;

    json_response(true, 'Data dosen berhasil diambil', $rows, 200, [
        'meta' => [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => $lastPage,
            'from'         => $from,
            'to'           => $to,
        ],
    ]);
}

/**
 * GET /api/dosen/{id} — detail satu dosen.
 */
function dosen_show(int $id): void
{
    $row = dosen_find($id);
    if ($row === null) {
        json_response(false, 'Data dosen tidak ditemukan', null, 404);
    }

    json_response(true, 'Data dosen berhasil diambil', $row, 200);
}

/**
 * POST /api/dosen — tambah dosen baru (HTTP 201).
 */
function dosen_store(): void
{
    $body = read_json_body();
    $data = validate_fields($body, dosen_rules(true));

    $columns = array_keys($data);
    $marks   = [];
    $params  = [];
    foreach ($columns as $column) {
        $marks[]           = ":$column";
        $params[":$column"] = $data[$column];
    }

    $sql = 'INSERT INTO dosen (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $marks) . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $row = dosen_find((int) db()->lastInsertId());
    json_response(true, 'Data dosen berhasil ditambahkan', $row, 201);
}

/**
 * PUT /api/dosen/{id} — perbarui SELURUH data dosen (replace).
 * Kolom yang tidak dikirim menjadi NULL (kecuali timestamp otomatis DB).
 */
function dosen_replace(int $id): void
{
    $body  = read_json_body();
    $rules = dosen_rules(true);

    if (dosen_find($id) === null) {
        json_response(false, 'Data dosen tidak ditemukan', null, 404);
    }

    $data = validate_fields($body, $rules);

    $values = [];
    foreach (array_keys($rules) as $column) {
        $values[$column] = $data[$column] ?? null;
    }
    dosen_write($id, $values);

    json_response(true, 'Data dosen berhasil diperbarui', dosen_find($id), 200);
}

/**
 * PATCH /api/dosen/{id} — perbarui SEBAGIAN data dosen.
 * Hanya kolom yang dikirim yang diubah; minimal satu kolom.
 */
function dosen_patch(int $id): void
{
    $body = read_json_body();

    if (dosen_find($id) === null) {
        json_response(false, 'Data dosen tidak ditemukan', null, 404);
    }

    // PATCH: tidak ada kolom wajib.
    $data = validate_fields($body, dosen_rules(false));

    if ($data === []) {
        validation_response(['_body' => ['Minimal satu kolom harus dikirim pada permintaan PATCH']]);
    }

    dosen_write($id, $data);

    json_response(true, 'Data dosen berhasil diperbarui', dosen_find($id), 200);
}

/**
 * DELETE /api/dosen/{id} — hapus dosen.
 */
function dosen_destroy(int $id): void
{
    if (dosen_find($id) === null) {
        json_response(false, 'Data dosen tidak ditemukan', null, 404);
    }

    $stmt = db()->prepare('DELETE FROM dosen WHERE id = :id');
    $stmt->execute([':id' => $id]);

    json_response(true, 'Data dosen berhasil dihapus', null, 200);
}
