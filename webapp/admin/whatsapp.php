<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
if (!$isAdmin) { http_response_code(403); exit('Akses ditolak.'); }

function wa_env(string $key, string $fallback = ''): string
{
    $v = getenv($key);
    if ($v !== false && trim((string) $v) !== '') return trim((string) $v);
    $file = dirname(__DIR__) . '/.env';
    if (!is_readable($file)) return $fallback;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || strpos($line, '=') === false) continue;
        [$n, $val] = explode('=', $line, 2);
        if (trim($n) === $key) return trim($val, " \t\\\"'");
    }
    return $fallback;
}

$message = null;
$tone = 'info';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) { http_response_code(419); exit('Token tidak valid.'); }
    $action = (string) ($_POST['action'] ?? '');

    $evolutionUrl = wa_env('EVOLUTION_URL', 'http://localhost:8081');
    $evolutionKey = wa_env('EVOLUTION_API_KEY', 'hackathon-key');
    $instance = wa_env('EVOLUTION_INSTANCE', 'presensi');

    if ($action === 'save_config') {
        $url = trim((string) ($_POST['evolution_url'] ?? ''));
        $key = trim((string) ($_POST['evolution_key'] ?? ''));
        $inst = trim((string) ($_POST['evolution_instance'] ?? 'presensi'));
        $webhookToken = trim((string) ($_POST['webhook_token'] ?? ''));
        $provider = trim((string) ($_POST['provider'] ?? 'evolution'));

        $envPath = dirname(__DIR__) . '/.env';
        $existing = is_file($envPath) ? file_get_contents($envPath) : '';
        $lines = preg_split('/\R/', (string) $existing);
        $set = [
            'WHATSAPP_PROVIDER' => $provider,
            'EVOLUTION_URL' => $url,
            'EVOLUTION_API_KEY' => $key,
            'EVOLUTION_INSTANCE' => $inst,
            'WHATSAPP_WEBHOOK_TOKEN' => $webhookToken,
        ];
        foreach ($set as $k => $v) {
            $found = false;
            foreach ($lines as &$line) {
                if (preg_match('/^\s*' . preg_quote($k, '/') . '\s*=/', $line)) {
                    $line = $k . '=' . str_replace(["\r", "\n"], '', $v);
                    $found = true;
                    break;
                }
            }
            unset($line);
            if (!$found) $lines[] = $k . '=' . str_replace(["\r", "\n"], '', $v);
        }
        if (@file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            $message = 'Gagal menyimpan konfigurasi.';
            $tone = 'warn';
        } else {
            $message = 'Konfigurasi tersimpan.';
            $tone = 'ok';
        }
    }
}

$evolutionUrl = wa_env('EVOLUTION_URL', 'http://localhost:8081');
$evolutionKey = wa_env('EVOLUTION_API_KEY', 'hackathon-key');
$evolutionInstance = wa_env('EVOLUTION_INSTANCE', 'presensi');
$provider = wa_env('WHATSAPP_PROVIDER', 'evolution');
$webhookToken = wa_env('WHATSAPP_WEBHOOK_TOKEN', '');

$pageKey = 'whatsapp';
$pageTitle = 'WhatsApp Bot';
$pageSub = 'Hubungkan WhatsApp untuk notifikasi & tanya jawab orang tua';
require __DIR__ . '/../templates/admin-head.php';
?>

<div class="card">
    <div class="card__head">
        <div>
            <h2 class="card__title">Koneksi WhatsApp (Evolution API)</h2>
            <p class="card__sub">Hubungkan WhatsApp sekolah agar orang tua bisa tanya kehadiran anak via chat.</p>
        </div>
    </div>
    <div class="card__body">
        <?php if ($message): ?>
        <div class="note note--<?= esc($tone) ?>" role="status">
            <i class="fa-solid fa-circle-info note__ic"></i>
            <div><?= esc($message) ?></div>
        </div>
        <?php endif; ?>

        <!-- Status Box -->
        <div class="note note--info" id="statusBox" style="margin-bottom:16px">
            <i class="fa-solid fa-spinner fa-spin note__ic" id="statusIcon"></i>
            <div>
                <p class="note__title" id="statusTitle">Memeriksa koneksi...</p>
                <p id="statusText" style="margin-top:2px;font-size:13px"></p>
            </div>
        </div>

        <!-- QR Code Area -->
        <div id="qrArea" style="display:none;text-align:center;margin:20px 0">
            <p style="font-weight:600;margin-bottom:10px">Scan QR Code dengan WhatsApp</p>
            <img id="qrImage" src="" alt="QR Code" style="max-width:280px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.1)">
            <p style="font-size:12px;color:var(--text-2);margin-top:8px">Buka WhatsApp → Perangkat tertaut → Tautkan perangkat</p>
        </div>

        <!-- Action Buttons -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
            <button type="button" class="btn btn--primary" id="btnCreate">
                <i class="fa-solid fa-plus"></i> Buat Instance
            </button>
            <button type="button" class="btn btn--soft" id="btnQR">
                <i class="fa-solid fa-qrcode"></i> Tampilkan QR Code
            </button>
            <button type="button" class="btn btn--soft" id="btnStatus">
                <i class="fa-solid fa-rotate"></i> Cek Status
            </button>
            <button type="button" class="btn btn--ghost" id="btnWebhook">
                <i class="fa-solid fa-link"></i> Set Webhook
            </button>
        </div>

        <hr style="margin:24px 0;border:none;border-top:1px solid var(--line)">

        <!-- Config Form -->
        <form method="post" class="form form--grid" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_config">

            <div class="field field--grow">
                <label class="field__label" for="provider">Provider</label>
                <select id="provider" name="provider" class="select">
                    <option value="evolution" <?= $provider === 'evolution' ? 'selected' : '' ?>>Evolution API (Baileys, lokal)</option>
                    <option value="meta" <?= $provider === 'meta' ? 'selected' : '' ?>>Meta Cloud API (official)</option>
                </select>
            </div>

            <div class="field field--grow">
                <label class="field__label" for="evolution_url">Evolution URL</label>
                <input id="evolution_url" name="evolution_url" type="url" value="<?= esc($evolutionUrl) ?>" placeholder="http://localhost:8081">
            </div>

            <div class="field field--grow">
                <label class="field__label" for="evolution_key">Evolution API Key</label>
                <input id="evolution_key" name="evolution_key" type="password" value="<?= esc($evolutionKey) ?>" placeholder="hackathon-key">
            </div>

            <div class="field field--grow">
                <label class="field__label" for="evolution_instance">Instance Name</label>
                <input id="evolution_instance" name="evolution_instance" type="text" value="<?= esc($evolutionInstance) ?>" placeholder="presensi">
            </div>

            <div class="field field--grow">
                <label class="field__label" for="webhook_token">Webhook Token</label>
                <input id="webhook_token" name="webhook_token" type="text" value="<?= esc($webhookToken) ?>" placeholder="token-random-panjang">
                <p class="field__hint">Token untuk verifikasi webhook dari Evolution/Meta</p>
            </div>

            <div class="form__full row row--end">
                <button type="submit" class="btn btn--primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Konfigurasi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Petunjuk -->
<div class="card" style="margin-top:16px">
    <div class="card__head">
        <div>
            <p class="card__title">Cara Setup WhatsApp (Evolution API)</p>
            <p class="card__sub">Langkah cepat agar orang tua bisa tanya via WhatsApp</p>
        </div>
    </div>
    <div class="card__body">
        <ol style="line-height:1.8;padding-left:20px">
            <li>Jalankan Evolution API: <code>docker compose --profile whatsapp up -d</code></li>
            <li>Buka <strong>http://localhost:8081/manager</strong> (atau 8082)</li>
            <li>Klik <strong>Create Instance</strong> → nama: <code>presensi</code></li>
            <li>Klik <strong>Tampilkan QR Code</strong> di atas → scan dengan WhatsApp</li>
            <li>Set Webhook di Evolution: URL = <code>http://web/api/v1/whatsapp/webhook.php</code> (jika di Docker) atau <code>http://host.docker.internal/api/v1/whatsapp/webhook.php</code> (jika Evolution di Docker, web di host), Header <code>X-WhatsApp-Webhook-Token</code> = token di atas</li>
            <li>Subscribe event <code>messages.upsert</code></li>
            <li>Test: kirim "Apakah Andi sudah pulang?" dari WhatsApp lain → bot balas otomatis</li>
        </ol>
        <p class="field__hint" style="margin-top:12px">
            <strong>Catatan:</strong> Untuk deployment lokal, gunakan <code>host.docker.internal</code> agar container Evolution bisa akses Apache host.
        </p>
    </div>
</div>

<script>
(function() {
    const proxyBase = '/api/v1/whatsapp/proxy.php';
    const instance = <?= json_encode($evolutionInstance) ?>;

    function apiCall(action) {
        return fetch(proxyBase + '?action=' + action + '&instance=' + encodeURIComponent(instance))
            .then(r => r.json()).catch(e => ({ error: e.message }));
    }

    function setStatus(title, text, ok) {
        const box = document.getElementById('statusBox');
        const icon = document.getElementById('statusIcon');
        const t = document.getElementById('statusTitle');
        const x = document.getElementById('statusText');
        t.textContent = title;
        x.textContent = text;
        icon.className = 'fa-solid ' + (ok ? 'fa-circle-check' : 'fa-circle-exclamation') + ' note__ic';
        box.className = 'note note--' + (ok ? 'ok' : 'warn');
    }

    document.getElementById('btnStatus').addEventListener('click', async function() {
        setStatus('Memeriksa...', 'Menghubungi Evolution API', true);
        const res = await apiCall('status');
        if (res && res.error && res.error.includes('unreachable')) {
            setStatus('Evolution API tidak jalan', 'Jalankan: docker compose up -d (atau docker compose --profile whatsapp up -d)', false);
        } else if (res && res.instance) {
            const state = res.instance.state || res.state || 'unknown';
            setStatus('Status: ' + state, 'Instance: ' + instance, state === 'open');
        } else if (res && res.error) {
            setStatus('Tidak terhubung', res.error, false);
        } else {
            setStatus('Instance belum ada', 'Klik "Buat Instance" dulu', false);
        }
    });

    document.getElementById('btnCreate').addEventListener('click', async function() {
        setStatus('Membuat instance...', instance, true);
        const res = await apiCall('create');
        if (res && (res.instance || res.hash)) {
            setStatus('Instance dibuat', 'Klik "Tampilkan QR Code" untuk scan', true);
        } else {
            setStatus('Gagal', JSON.stringify(res).slice(0, 100), false);
        }
    });

    document.getElementById('btnQR').addEventListener('click', async function() {
        // Auto-create instance if not exists
        let res = await apiCall('qr');
        if (res && res.error && (res.error.includes('not found') || res.error.includes('does not exist'))) {
            await apiCall('create');
            res = await apiCall('qr');
        }
        const qr = res?.qrcode?.base64 || res?.base64 || res?.code;
        if (qr) {
            const img = document.getElementById('qrImage');
            img.src = qr.startsWith('data:') ? qr : 'data:image/png;base64,' + qr;
            document.getElementById('qrArea').style.display = 'block';
            setStatus('QR Code siap', 'Scan dengan WhatsApp', true);
        } else {
            setStatus('Gagal ambil QR', JSON.stringify(res).slice(0, 150), false);
        }
    });

    document.getElementById('btnWebhook').addEventListener('click', async function() {
        const res = await apiCall('webhook');
        if (res && !res.error) {
            setStatus('Webhook diset', 'OK', true);
        } else {
            setStatus('Gagal set webhook', JSON.stringify(res).slice(0, 100), false);
        }
    });

    // Auto check on load
    setTimeout(() => document.getElementById('btnStatus').click(), 500);
})();
</script>

<?php require __DIR__ . '/../templates/admin-foot.php'; ?>