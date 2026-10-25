<?php
/**
 * HAPUS FILE INI SETELAH DIPAKAI!
 * Reset scan_attempts di semua sesi aktif.
 */
require_once __DIR__ . '/config/app-config.php';

session_name(SESSION_NAME);
session_start();

$before = $_SESSION['scan_attempts'] ?? 0;
$lockBefore = $_SESSION['scan_lockout_until'] ?? null;

$_SESSION['scan_attempts'] = 0;
unset($_SESSION['scan_lockout_until']);

session_write_close();

echo "<h2>Reset Berhasil</h2>";
echo "<p>scan_attempts sebelumnya: <b>{$before}</b> → sekarang: <b>0</b></p>";
if ($lockBefore) {
    $sisa = $lockBefore - time();
    echo "<p>Lockout aktif (sisa {$sisa}s) → <b>dihapus</b></p>";
} else {
    echo "<p>Tidak ada lockout aktif.</p>";
}
echo "<p><a href='/siswa/scan-absen.php'>→ Ke halaman scan</a></p>";
echo "<hr><p style='color:red'><b>HAPUS file ini setelah selesai!</b></p>";
