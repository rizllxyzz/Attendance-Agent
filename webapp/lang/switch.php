<?php
/**
 * lang/switch.php
 * ---------------------------------------------------------------------------
 * Titik ganti bahasa. Dipanggil lewat tautan biasa (GET):
 *
 *   /lang/switch.php?to=en&next=/admin/index.php
 *   /lang/switch.php?to=id&next=/admin/pengaturan.php&global=1  (khusus admin)
 *
 * - `to`     : kode bahasa tujuan (id|en).
 * - `next`   : halaman yang dibuka setelah ganti; hanya path lokal diterima.
 * - `global` : bila '1' dan pengguna admin, jadikan juga bawaan aplikasi.
 *
 * Tidak memakai CSRF karena hanya mengubah preferensi tampilan, bukan data.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/../config/lang.php';
require_once __DIR__ . '/../auth/session.php';

start_session();

$to     = lang_normalize(get('to', 'id'));
$next   = (string) get('next', '');
$global = (get('global', '0') === '1');

$user   = current_user();
$adalahAdmin = $user !== null && ($user['role'] ?? '') === 'admin';

lang_set($to, $global && $adalahAdmin);

/* Hanya izinkan tujuan lokal ("/..."). Kalau tidak, kembali ke halaman
 * yang wajar menurut jenis akun. */
if ($next === '' || $next[0] !== '/' || substr($next, 0, 2) === '//') {
    if ($user === null) {
        $next = '/auth/login.php';
    } elseif (($user['role'] ?? '') === 'siswa') {
        $next = '/siswa/index.php';
    } else {
        $next = '/admin/index.php';
    }
}

header('Location: ' . $next);
exit;
