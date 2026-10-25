<?php
/**
 * Keluar dari sesi.
 *
 * Hanya POST dengan token CSRF. Kalau menerima GET, siapa pun yang
 * menulis tautan /auth/logout.php di halaman mana saja bisa memaksa
 * siswa keluar dari sesinya tanpa sepengetahuan mereka.
 */

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        /* Token tidak cocok. Sesi yang masih sah dibiarkan utuh,
         * karena mengakhirkannya karena permintaan palsu justru
         * memberi alat untuk mengganggu orang lain. */
        http_response_code(400);
        echo 'Permintaan tidak valid. Kembali ke <a href="/">beranda</a>.';
        exit;
    }
    logout_user();
    /* 303 supaya peramban melakukan GET berikutnya ke login.php,
     * bukan mengulang POST ini. */
    header('Location: /auth/login.php', true, 303);
    exit;
}

/* Tanpa POST yang sah, halaman ini tidak melakukan apa pun. */
http_response_code(405);
header('Allow: POST');
echo 'Gunakan tombol Keluar di beranda.';
exit;

