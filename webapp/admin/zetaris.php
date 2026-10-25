<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
if (!$isAdmin) { http_response_code(403); exit('Akses ditolak.'); }
require_once __DIR__ . '/../config/zetaris.php';
$message = null; $tone = 'info';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) { http_response_code(419); exit('Token keamanan tidak valid. Muat ulang halaman.'); }
    $url = trim((string) ($_POST['rest_url'] ?? ''));
    $org = trim((string) ($_POST['org_id'] ?? ''));
    $key = trim((string) ($_POST['api_key'] ?? ''));
    if (!filter_var($url, FILTER_VALIDATE_URL) || $org === '' || $key === '') { $message = 'URL, Org ID, dan API key wajib diisi.'; $tone='warn'; }
    else {
        $envPath = dirname(__DIR__) . '/.env';
        $existing = is_file($envPath) ? file_get_contents($envPath) : '';
        $lines = preg_split('/\R/', (string) $existing);
        $set = ['ZETARIS_REST_URL'=>$url,'ZETARIS_ORG_ID'=>$org,'ZETARIS_API_KEY'=>$key];
        foreach ($set as $k=>$v) { $found=false; foreach ($lines as &$line) { if (preg_match('/^\s*'.preg_quote($k,'/').'\s*=/', $line)) {$line=$k.'='.str_replace(["\r","\n"],'',$v);$found=true;break;} } unset($line); if(!$found)$lines[]=$k.'='.str_replace(["\r","\n"],'',$v); }
        if (@file_put_contents($envPath, implode(PHP_EOL,$lines).PHP_EOL, LOCK_EX) === false) { $message='Gagal menulis konfigurasi .env. Periksa izin folder.'; $tone='warn'; }
        else { $message = 'Konfigurasi tersimpan. Lakukan tes koneksi untuk memastikan gateway aktif.'; $tone='ok'; }
    }
}
$health = null;
if (isset($_GET['test'])) $health = zetaris_health();
$pageKey='pengaturan'; $pageTitle='Integrasi Zetaris'; $pageSub='Setup koneksi data virtualisasi secara aman';
require __DIR__ . '/../templates/admin-head.php';
?>
<div class="card zetaris-setup-card"><div class="card__head"><div><h2 class="card__title">Setup Zetaris</h2><p class="card__sub">API key disimpan di `.env` server dan tidak ditampilkan ulang.</p></div><?php if($health!==null): ?><span class="badge badge--<?= $health?'ok':'danger' ?>"><i class="dot"></i><?= $health?'Terhubung':'Belum terhubung' ?></span><?php endif; ?></div><div class="card__body">
<?php if($message): ?><div class="note note--<?= esc($tone) ?>" role="status"><i class="fa-solid fa-circle-info note__ic"></i><div><?= esc($message) ?></div></div><?php endif; ?>
<form method="post" class="form" autocomplete="off"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><div class="field"><label class="field__label" for="rest_url">REST URL</label><input id="rest_url" name="rest_url" type="url" required value="<?= esc(getenv('ZETARIS_REST_URL') ?: ZETARIS_REST_URL) ?>" placeholder="http://127.0.0.1:8888/api/v1.0"></div><div class="formrow"><div class="field"><label class="field__label" for="org_id">Org ID</label><input id="org_id" name="org_id" required value="<?= esc(getenv('ZETARIS_ORG_ID') ?: ZETARIS_ORG_ID) ?>"></div><div class="field"><label class="field__label" for="api_key">API key</label><input id="api_key" name="api_key" type="password" required placeholder="Masukkan API key Zetaris"></div></div><div class="row" style="gap:8px;flex-wrap:wrap"><button class="btn btn--primary" type="submit"><i class="fa-solid fa-floppy-disk"></i>Simpan konfigurasi</button><a class="btn btn--ghost" href="/admin/zetaris.php?test=1"><i class="fa-solid fa-plug"></i>Tes koneksi</a></div></form></div></div>
<?php require __DIR__ . '/../templates/admin-foot.php'; ?>