<?php
/**
 * index.php
 * ---------------------------------------------------------------------------
 * Entry point document root. Aplikasi ini tidak punya halaman depan publik,
 * jadi root langsung dialihkan ke halaman login.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/auth/session.php';

start_session();

/* Sudah masuk? Langsung ke beranda sesuai peran. */
$user = current_user();
if ($user) {
    $beranda = ($user['role'] === 'siswa') ? '/siswa/index.php' : '/admin/index.php';
    header('Location: ' . $beranda);
    exit;
}

header('Location: /auth/login.php');
exit;
