<?php
/**
 * config/agent-config.php
 * ---------------------------------------------------------------------------
 * Konfigurasi untuk lapisan agent (server Node lokal, lihat /agent).
 *
 * Sengaja dipisah dari app-config.php: app-config.php adalah aturan inti
 * presensi dan README melarang mengubahnya tanpa alasan. Semua yang berkaitan
 * dengan agent (URL, batas permintaan, timeout) tinggal di sini.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Alamat server agent lokal. Jalankan: cd agent && npm run agent */
if (!defined('AGENT_URL')) {
    define('AGENT_URL', getenv('AGENT_URL') ?: 'http://127.0.0.1:8788');
}
if (!defined('AGENT_SHARED_TOKEN')) {
    define('AGENT_SHARED_TOKEN', getenv('AGENT_SHARED_TOKEN') ?: '');
}

/** Batas permintaan tanya-agent: 20 permintaan per 60 detik per sesi. */
if (!defined('AGENT_MAX_PER_WINDOW')) {
    define('AGENT_MAX_PER_WINDOW', 20);
}
if (!defined('AGENT_WINDOW_SEC')) {
    define('AGENT_WINDOW_SEC', 60);
}

/**
 * Timeout panggilan ke agent (detik).
 *
 * Agent kini menjalankan satu putaran penuh (rencana -> ambil data -> nilai
 * -> susun jawaban), jadi bisa memakan beberapa panggilan model. 90 detik
 * memberi ruang sampai dua putaran tanpa membuat halaman macet kelamaan.
 */
if (!defined('AGENT_TIMEOUT_SEC')) {
    define('AGENT_TIMEOUT_SEC', 90);
}

/** Panjang maksimum pertanyaan guru. */
if (!defined('AGENT_MAX_QUESTION_LEN')) {
    define('AGENT_MAX_QUESTION_LEN', 500);
}
function agent_auth_headers(): array
{
    return AGENT_SHARED_TOKEN !== '' ? ['X-Agent-Token: ' . AGENT_SHARED_TOKEN] : [];
}
