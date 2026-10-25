<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
if (!$isAdmin) { http_response_code(403); exit('Akses ditolak.'); }
require_once __DIR__ . '/../config/agent-config.php';

$message = null;
$tone = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) { http_response_code(419); exit('Token keamanan tidak valid. Muat ulang halaman.'); }
    
    $provider = trim((string) ($_POST['ai_provider'] ?? 'disabled'));
    $base_url = trim((string) ($_POST['ai_base_url'] ?? ''));
    $api_key  = trim((string) ($_POST['ai_api_key'] ?? ''));
    $model    = trim((string) ($_POST['ai_model'] ?? ''));
    $enabled  = isset($_POST['ai_enabled']) ? '1' : '0';
    
    if ($enabled === '1' && $provider !== 'disabled') {
        if ($base_url === '' && !in_array($provider, ['disabled'], true)) {
            // allow empty only for some? but better require
        }
    }
    
    $envPath = dirname(__DIR__) . '/.env';
    $existing = is_file($envPath) ? file_get_contents($envPath) : '';
    $lines = preg_split('/\R/', (string) $existing);
    
    $set = [
        'AI_PROVIDER' => $provider,
        'AI_BASE_URL' => $base_url,
        'AI_API_KEY'  => $api_key,
        'AI_MODEL'    => $model,
        'AI_ENABLED'  => $enabled,
    ];
    
    foreach ($set as $k=>$v) {
        $found = false;
        foreach ($lines as &$line) {
            if (preg_match('/^\s*'.preg_quote($k,'/').'\s*=/', $line)) {
                $line = $k.'='.str_replace(["\r","\n"],'',$v);
                $found = true;
                break;
            }
        }
        unset($line);
        if (!$found) $lines[] = $k.'='.str_replace(["\r","\n"],'',$v);
    }
    
    if (@file_put_contents($envPath, implode(PHP_EOL,$lines).PHP_EOL, LOCK_EX) === false) {
        $message = 'Gagal menulis konfigurasi .env. Periksa izin folder.';
        $tone = 'warn';
    } else {
        $message = 'Konfigurasi AI berhasil disimpan.';
        $tone = 'ok';
    }
}

$aiProvider = getenv('AI_PROVIDER') ?: 'disabled';
$aiBaseUrl  = getenv('AI_BASE_URL') ?: '';
$aiApiKey   = getenv('AI_API_KEY') ?: '';
$aiModel    = getenv('AI_MODEL') ?: '';
$aiEnabled  = getenv('AI_ENABLED') === '1';

$pageKey='pengaturan';
$pageTitle='Koneksi AI';
$pageSub='Atur provider AI untuk asisten chatbot (Gemini, OpenAI, OpenRouter, Groq, Ollama)';
require __DIR__ . '/../templates/admin-head.php';
?>
<div class="card">
    <div class="card__head">
        <div>
            <h2 class="card__title">Koneksi AI Provider</h2>
            <p class="card__sub">Pilih provider AI yang ingin digunakan untuk membuat asisten lebih mirip chatbot. Bisa pakai API key sendiri atau layanan kompatibel OpenAI.</p>
        </div>
        <span class="badge badge--<?= $aiEnabled && $aiProvider !== 'disabled' ? 'ok' : 'muted' ?>"><i class="dot"></i><?= $aiEnabled && $aiProvider !== 'disabled' ? 'Aktif' : 'Nonaktif' ?></span>
    </div>
    <div class="card__body">
        <?php if($message): ?>
        <div class="note note--<?= esc($tone) ?>" role="status">
            <i class="fa-solid fa-circle-info note__ic"></i>
            <div><?= esc($message) ?></div>
        </div>
        <?php endif; ?>

        <form method="post" class="form form--grid" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
            
            <div class="form__full field">
                <label class="field__label" for="ai_provider">Provider</label>
                <select id="ai_provider" name="ai_provider" class="select">
                    <option value="disabled" <?= $aiProvider === 'disabled' ? 'selected' : '' ?>>Nonaktif (pakai mode bawaan)</option>
                    <option value="openai" <?= $aiProvider === 'openai' ? 'selected' : '' ?>>OpenAI (ChatGPT)</option>
                    <option value="gemini" <?= $aiProvider === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
                    <option value="openrouter" <?= $aiProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                    <option value="groq" <?= $aiProvider === 'groq' ? 'selected' : '' ?>>Groq</option>
                    <option value="ollama" <?= $aiProvider === 'ollama' ? 'selected' : '' ?>>Ollama (lokal)</option>
                    <option value="custom" <?= $aiProvider === 'custom' ? 'selected' : '' ?>>Custom (OpenAI Compatible)</option>
                </select>
                <p class="field__hint">Untuk OpenRouter/Groq/Ollama/Custom gunakan format kompatibel OpenAI (base URL + api key + model).</p>
            </div>

            <div class="form__full row" style="align-items:center">
                <label class="switch">
                    <input type="checkbox" name="ai_enabled" value="1" <?= $aiEnabled ? 'checked' : '' ?>>
                    <span class="switch__track"></span>
                </label>
                <span>Aktifkan koneksi AI</span>
            </div>

            <div class="field field--grow">
                <label class="field__label" for="ai_base_url">Base URL</label>
                <input id="ai_base_url" name="ai_base_url" type="url" value="<?= esc($aiBaseUrl) ?>" placeholder="https://api.openrouter.ai/v1 atau http://localhost:11434/v1">
                <p class="field__hint">Kosongkan untuk pakai default provider. Untuk Ollama biasanya http://localhost:11434/v1</p>
            </div>

            <div class="field field--grow">
                <label class="field__label" for="ai_model">Model</label>
                <input id="ai_model" name="ai_model" type="text" value="<?= esc($aiModel) ?>" placeholder="gemini-2.5-flash, gpt-4o-mini, llama-3.1-8b-instruct, dll">
            </div>

            <div class="form__full field">
                <label class="field__label" for="ai_api_key">API Key</label>
                <input id="ai_api_key" name="ai_api_key" type="password" value="<?= esc($aiApiKey) ?>" placeholder="sk-... atau AIza...">
                <p class="field__hint">API key disimpan di file `.env`. Tidak akan ditampilkan ulang setelah disimpan.</p>
            </div>

            <div class="form__full row row--end">
                <button type="submit" class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i>Simpan Konfigurasi</button>
            </div>
        </form>

        <div class="card__head" style="margin-top:16px">
            <div>
                <p class="card__title">Panduan Singkat</p>
                <p class="card__sub">Rekomendasi provider untuk hackathon/demo</p>
            </div>
        </div>
        <div class="tablewrap">
            <table class="table table--cards">
                <thead>
                    <tr><th>Provider</th><th>Base URL</th><th>Model Contoh</th><th>Catatan</th></tr>
                </thead>
                <tbody>
                    <tr><td>OpenRouter</td><td>https://openrouter.ai/api/v1</td><td>google/gemini-2.5-flash</td><td>Gratis/terjangkau, banyak model</td></tr>
                    <tr><td>Groq</td><td>https://api.groq.com/openai/v1</td><td>llama-3.1-8b-instant</td><td>Cepat & gratis untuk pemakaian ringan</td></tr>
                    <tr><td>Google Gemini</td><td>https://generativelanguage.googleapis.com/v1beta/openai/</td><td>gemini-2.5-flash</td><td>Bisa pakai endpoint kompatibel OpenAI</td></tr>
                    <tr><td>Ollama</td><td>http://localhost:11434/v1</td><td>llama3.1:8b</td><td>Lokal, butuh RAM/GPU</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../templates/admin-foot.php'; ?>
