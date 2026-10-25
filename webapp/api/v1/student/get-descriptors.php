<?php
/**
 * GET /api/v1/student/get-descriptors.php
 *
 * Kirim descriptor wajah untuk pencocokan di sisi klien.
 *
 * Cakupan:
 *   scope=self (default) - hanya descriptor milik pengguna yang login.
 *                          Dipakai untuk perangkat pribadi, sehingga
 *                          descriptor siswa lain tidak masuk browser.
 *   scope=all            - seluruh galeri, hanya untuk guru/admin di
 *                          kiosk. Akun siswa yang meminta 'all'
 *                          ditolak di sini, bukan di sisi klien.
 *
 * Descriptor dikirim sebagai base64 (512 byte menjadi 684 karakter),
 * bukan larik 128 angka: 1.200 siswa sebagai angka akan jadi ~1,5 MB
 * JSON dan lambat di-parse di ponsel.
 *
 * Catatan privasi: descriptor_face-api adalah vektor 128 float yang
 * sangat khas per orang, punya sifat seperti sidik jari, jadi tidak
 * seharusnya dikirim ke browser orang yang tidak ada hubungannya.
 * Verifikasi akhir tetap dilakukan server di scan-check.php; yang di
 * sini hanya prapenyaringan supaya foto buruk tidak perlu diunggah.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_fail('METHOD_NOT_ALLOWED', 'Gunakan GET.', 405);
}

/* Tidak ada syarat Content-Type di sini: endpoint ini GET dan tidak
 * menerima body sama sekali. Meminta header Accept dari klien hanya
 * menyebalkan, bukan menambah keamanan. */

$user = current_user();
if (!$user) {
    json_fail('UNAUTHENTICATED', 'Silakan masuk terlebih dahulu.', 401);
}

$userId = (int) $user['id'];
$role   = (string) ($user['role'] ?? '');
$scope  = (string) (get('scope', 'self'));

if (!in_array($scope, ['self', 'all'], true)) {
    json_fail('BAD_SCOPE', 'scope harus "self" atau "all".', 400);
}

/* Galeri penuh hanya untuk guru dan admin. Siswa tidak boleh,
 * walaupun permintaan itu sah, karena descriptor siswa lain bukan
 * haknya untuk dimuat di perangkat pribadi. */
if ($scope === 'all' && !in_array($role, ['guru', 'admin'], true)) {
    audit_log($userId, 'gallery_scope_denied', 'warning',
        'Role ' . $role . ' mencoba meminta scope=all.');
    json_fail('FORBIDDEN', 'Hanya guru atau admin yang dapat memuat seluruh galeri.', 403);
}

/* Batasi ukuran galeri agar satu permintaan tidak menjadi alat DOS. */
$maxRows = $scope === 'all' ? 5000 : 1;

/* Filter is_centroid = 1 WAJIB sama dengan yang dipakai scan-check.php
 * saat memuat galeri. Kalau hanya salah satu yang memfilter, klien
 * dan server bisa memakai himpunan template berbeda, sehingga hasil
 * pencocokan lokal dan pencatatan server tidak konsisten. */
$sql = 'SELECT u.id, u.nis, u.nama_lengkap, u.kelas_id, f.descriptor, f.quality, f.created_at
          FROM users u
          JOIN face_templates f ON f.user_id = u.id AND f.is_centroid = 1
         WHERE u.role = \'siswa\' AND u.is_active = 1';

$args = [];
if ($scope === 'self') {
    $sql   .= ' AND u.id = ?';
    $args[] = $userId;
}
$sql .= ' ORDER BY u.id LIMIT ' . (int) $maxRows;

$rows = db_all($sql, $args);

$descriptors = [];
$students    = [];
$skipped     = 0;

foreach ($rows as $r) {
    $blob = $r['descriptor'] ?? null;
    if (!is_string($blob) || $blob === '') {
        $skipped++;
        continue;
    }
    $descriptors[] = base64_encode($blob);
    $students[]    = [
        'id'   => (int) $r['id'],
        'name' => (string) $r['nama_lengkap'],
        'nis'  => (string) ($r['nis'] ?? ''),
        'kls'  => $r['kelas_id'] === null ? null : (int) $r['kelas_id'],
    ];
}

header('Cache-Control: private, no-store');
header('Vary: Cookie');

json_ok([
    'scope'       => $scope,
    'descriptors' => $descriptors,
    'students'    => $students,
    'count'       => count($students),
    'skipped'     => $skipped,
    'threshold'   => FACE_MATCH_THRESHOLD,
]);
