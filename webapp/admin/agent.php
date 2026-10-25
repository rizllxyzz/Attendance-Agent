<?php
/**
 * admin/agent.php
 * ---------------------------------------------------------------------------
 * SmartJournal AI - Terintegrasi Alami di Halaman Panel Admin (Clean White Theme).
 * Mengalir langsung di bawah header standar tanpa card/ruangan terisolasi.
 * 100% Ikon SVG Modern, Bebas Emoji, Responsif PC & HP tanpa kejepit.
 * Terintegrasi penuh dengan Zetaris Virtualization Fabric & Database Presensi.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/agent-config.php';
require_once __DIR__ . '/../config/zetaris.php';

$pageKey   = 'agent';
$pageTitle = 'SmartJournal AI';
$pageSub   = 'Asisten Cerdas Analitik Presensi Berbasis Zetaris Data Virtualization';

$currentUser = current_user();
$userName    = $currentUser['nama_lengkap'] ?? $currentUser['username'] ?? 'Bapak/Ibu Guru';
$userInitial = strtoupper(substr($userName, 0, 1));

/* Tombol aksi di kanan atas header dashboard admin */
$pageActionsHtml = '
    <div class="gm-topbar-status" title="Terhubung ke Zetaris Data Fabric">
        <span class="gm-live-dot"></span>
        <span class="gm-live-text">Zetaris Connected</span>
    </div>
    <button type="button" class="btn btn--ghost" id="btnNewChat">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px">
            <path d="M12 5v14M5 12h14"/>
        </svg>
        Chat Baru
    </button>
';

/* 4 Prompt rekomendasi bergaya Google Gemini (Tanpa Emoji, 100% SVG) */
$promptSuggestions = [
    [
        'type'  => 'clock',
        'title' => 'Siswa Belum Pulang',
        'desc'  => 'Deteksi siswa yang hadir masuk tetapi belum scan absen pulang',
        'query' => 'Siapa saja yang belum absen pulang hari ini?',
        'tag'   => 'Rekonsiliasi'
    ],
    [
        'type'  => 'stopwatch',
        'title' => 'Siswa Terlambat',
        'desc'  => 'Daftar siswa yang datang melewati batas waktu toleransi jam sekolah',
        'query' => 'Siapa saja siswa yang terlambat hari ini?',
        'tag'   => 'Kedisiplinan'
    ],
    [
        'type'  => 'chart',
        'title' => 'Rekapitulasi Kehadiran',
        'desc'  => 'Ringkasan statistik hadir, pulang, izin, sakit, dan alpa hari ini',
        'query' => 'Ringkas kehadiran hari ini.',
        'tag'   => 'Statistik'
    ],
    [
        'type'  => 'student',
        'title' => 'Daftar Seluruh Siswa',
        'desc'  => 'Tampilkan seluruh siswa terdaftar beserta kelas dan nomor induk',
        'query' => 'Tampilkan data siswa',
        'tag'   => 'Database'
    ]
];

require __DIR__ . '/../templates/admin-head.php';
?>

<style>
/* ==================================================================
   GOOGLE GEMINI NATIVE CHAT FLOW (SEAMLESS IN ADMIN, NO ISOLATED CARD)
   ================================================================== */
.agent-workspace{display:grid;grid-template-columns:260px minmax(0,860px);gap:18px;max-width:1160px;margin:10px auto 0;align-items:start}.agent-history{position:sticky;top:16px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px;box-shadow:0 6px 18px rgba(15,23,42,.05)}.agent-history__head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.agent-history__title{font-size:13px;font-weight:700;color:#0f172a}.agent-history__search{width:100%;padding:9px 11px;border:1px solid #e2e8f0;border-radius:10px;font:inherit;font-size:13px;margin-bottom:10px}.agent-history__list{display:grid;gap:4px;max-height:calc(100vh - 230px);overflow:auto}.agent-history__item{border:0;background:transparent;text-align:left;padding:10px;border-radius:10px;cursor:pointer;color:#475569;font-size:12px;line-height:1.35}.agent-history__item:hover,.agent-history__item.is-active{background:#eff6ff;color:#1d4ed8}.agent-history__empty{font-size:12px;color:#94a3b8;padding:12px 6px}.agent-history__clear{border:0;background:transparent;color:#64748b;font-size:11px;cursor:pointer}.gemini-stream-container{margin:0;max-width:none;min-width:0}@media(max-width:900px){.agent-workspace{grid-template-columns:1fr}.agent-history{position:static}.agent-history__list{display:flex;overflow-x:auto;max-height:none}.agent-history__item{min-width:190px;background:#f8fafc}.gemini-stream-container{min-height:calc(100dvh - 250px)}}@media(max-width:600px){.agent-history{padding:10px}.agent-history__search{font-size:16px}.gemini-bottom-dock{bottom:8px}.gemini-disclaimer-foot{padding:0 8px}}

:root {
    --gm-blue: #1a73e8;
    --gm-blue-hover: #1557b0;
    --gm-blue-light: #eff6ff;
    --gm-border: #e2e8f0;
    --gm-border-subtle: #f1f5f9;
    --gm-text-main: #0f172a;
    --gm-text-sub: #475569;
    --gm-text-muted: #94a3b8;
    --gm-bubble-user: #f0f4f9;
    --gm-grad-gemini: linear-gradient(135deg, #4285F4 0%, #9B72CB 50%, #D96570 100%);
    --gm-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

/* Status Pill di Header Atas Dashboard */
.gm-topbar-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 14px;
    border-radius: 999px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-size: 12.5px;
    font-weight: 600;
}
.gm-live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #22c55e;
    box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.25);
    animation: gmPulseDot 2s infinite;
}
@keyframes gmPulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

/* Container Utama Alur Percakapan (Menyatu di Halaman, Tanpa Card/Kotak) */
.gemini-stream-container {
    width: 100%;
    max-width: 860px;
    margin: 10px auto 0;
    min-height: calc(100vh - 290px);
    display: flex;
    flex-direction: column;
    position: relative;
    font-family: var(--gm-font);
}

/* Area Daftar Pesan */
.gemini-messages-list {
    display: flex;
    flex-direction: column;
    gap: 24px;
    flex: 1;
    padding-bottom: 24px;
}

/* Welcome Center (Saat Belum Ada Percakapan) */
.gemini-welcome-box {
    margin: auto;
    text-align: center;
    padding: 30px 10px 20px;
    width: 100%;
    animation: gmFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.gemini-sparkle-hero-wrap {
    width: 60px;
    height: 60px;
    margin: 0 auto 16px;
    border-radius: 20px;
    background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 24px -4px rgba(59, 130, 246, 0.16);
    animation: gmHeroFloat 4s ease-in-out infinite;
}
@keyframes gmHeroFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}
.gemini-welcome-box h2 {
    font-size: 28px;
    font-weight: 700;
    color: var(--gm-text-main);
    margin: 0 0 8px;
    letter-spacing: -0.02em;
}
.gemini-welcome-grad {
    background: var(--gm-grad-gemini);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.gemini-welcome-box p {
    font-size: 15px;
    color: var(--gm-text-sub);
    margin: 0 0 32px;
    line-height: 1.6;
}

/* 4 Prompt Cards Grid (Spacious 2x2, Responsif) */
.gemini-prompt-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    text-align: left;
}
.gemini-prompt-card {
    background: #ffffff;
    border: 1px solid var(--gm-border);
    border-radius: 16px;
    padding: 18px 20px;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.gemini-prompt-card:hover {
    border-color: #93c5fd;
    background: #f8fafc;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -4px rgba(59, 130, 246, 0.1);
}
.gemini-prompt-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.gemini-prompt-icon-wrap {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #eff6ff;
    display: flex;
    align-items: center;
    justify-content: center;
}
.gemini-prompt-tag {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    background: #f1f5f9;
    padding: 3px 8px;
    border-radius: 6px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.gemini-prompt-title {
    font-size: 14.5px;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 4px;
}
.gemini-prompt-desc {
    font-size: 13px;
    color: #64748b;
    line-height: 1.45;
}

/* User Message (Right Aligned Capsule) */
.gm-msg-user {
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
    gap: 10px;
    max-width: 82%;
    margin-left: auto;
    animation: gmFadeIn 0.2s ease;
}
.gm-bubble-user {
    background: var(--gm-bubble-user);
    color: #1e293b;
    padding: 12px 18px;
    border-radius: 20px 20px 4px 20px;
    font-size: 15px;
    line-height: 1.55;
    word-break: break-word;
    border: 1px solid #e2e8f0;
}
.gm-avatar-user {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #cbd5e1;
    color: #334155;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

/* Assistant AI Message (Left Aligned) */
.gm-msg-ai {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    max-width: 100%;
    margin-right: auto;
    animation: gmFadeIn 0.2s ease;
}
.gm-avatar-ai {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 2px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}
.gm-content-ai {
    flex: 1;
    min-width: 0;
}
.gm-ai-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}
.gm-ai-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--gm-text-main);
}
.gm-ai-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.gm-ai-time {
    font-size: 11px;
    color: #94a3b8;
    margin-left: auto;
}
.gm-bubble-ai {
    background: transparent;
    color: #0f172a;
    font-size: 15px;
    line-height: 1.65;
    word-break: break-word;
}

/* Markdown Tables & Clean Elements */
.gm-bubble-ai h3 {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    margin: 12px 0 10px;
}
.gm-bubble-ai p {
    margin: 0 0 10px;
}
.gm-bubble-ai table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin: 14px 0 16px;
    font-size: 13.5px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.gm-bubble-ai th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-align: left;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
}
.gm-bubble-ai td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
}
.gm-bubble-ai tr:last-child td {
    border-bottom: none;
}
.gm-bubble-ai tr:hover td {
    background: #f8fafc;
}
.gm-bubble-ai blockquote {
    margin: 12px 0;
    padding: 12px 16px;
    background: #f8fafc;
    border-left: 3.5px solid var(--gm-blue);
    border-radius: 0 8px 8px 0;
    font-size: 13.5px;
    color: #475569;
}
.gm-bubble-ai code {
    background: #f1f5f9;
    padding: 2px 7px;
    border-radius: 6px;
    font-size: 12.5px;
    color: #0f172a;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

/* Action Toolbar (100% SVG Icons) */
.gm-action-bar {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 12px;
}
.gm-action-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: transparent;
    border: none;
    color: #94a3b8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.gm-action-btn:hover {
    background: #f1f5f9;
    color: #1e293b;
}
.gm-action-btn.is-active-like {
    color: #1a73e8;
    background: #eff6ff;
}
.gm-action-btn.is-active-dislike {
    color: #e11d48;
    background: #ffe4e6;
}
.gm-trace-pill {
    margin-left: 8px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 11.5px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.gm-trace-pill:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #cbd5e1;
}

/* Trace Step Box */
.gm-trace-box {
    margin-top: 12px;
    padding: 12px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    font-size: 12px;
    color: #475569;
    display: none;
}
.gm-trace-box.is-open {
    display: block;
    animation: gmFadeIn 0.2s ease;
}
.gm-trace-title {
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.gm-trace-list {
    margin: 0;
    padding-left: 18px;
    line-height: 1.7;
}

/* Typing Indicator Animation */
.gm-typing-indicator {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 12px;
}
.gm-typing-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #94a3b8;
    animation: gmTyping 1.4s infinite ease-in-out both;
}
.gm-typing-dot:nth-child(1) { animation-delay: -0.32s; }
.gm-typing-dot:nth-child(2) { animation-delay: -0.16s; }
@keyframes gmTyping {
    0%, 80%, 100% { transform: scale(0); opacity: 0.3; }
    40% { transform: scale(1); opacity: 1; }
}

/* ------------------------------------------------------------------
   FLOATING INPUT CAPSULE BAR (PINNED BOTTOM OF PAGE)
   ------------------------------------------------------------------ */
.gemini-bottom-dock {
    position: sticky;
    bottom: 20px;
    width: 100%;
    max-width: 860px;
    margin: 20px auto 0;
    z-index: 40;
}
.gemini-pill-capsule {
    width: 100%;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 9999px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 4px 10px -2px rgba(0, 0, 0, 0.03);
    display: flex;
    align-items: center;
    padding: 6px 10px 6px 14px;
    gap: 8px;
    transition: all 0.2s ease;
    position: relative;
}
.gemini-pill-capsule:focus-within {
    border-color: #3b82f6;
    box-shadow: 0 12px 28px -4px rgba(59, 130, 246, 0.16), 0 4px 12px -2px rgba(59, 130, 246, 0.08);
}
.gemini-btn-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: transparent;
    border: none;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.15s ease;
}
.gemini-btn-icon:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.gemini-textarea {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-family: inherit;
    font-size: 14.5px;
    color: #0f172a;
    resize: none;
    max-height: 120px;
    min-height: 24px;
    line-height: 24px;
    padding: 6px 0;
}
.gemini-textarea::placeholder {
    color: #94a3b8;
}
.gemini-model-badge-input {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 11px;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    font-size: 12px;
    font-weight: 600;
    color: #1a73e8;
    flex-shrink: 0;
    user-select: none;
}
.gemini-btn-send {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #1a73e8;
    border: none;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.gemini-btn-send:disabled {
    background: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
    transform: scale(0.95);
}
.gemini-btn-send:not(:disabled):hover {
    background: #1557b0;
    transform: scale(1.05);
}
.gemini-disclaimer-foot {
    font-size: 11.5px;
    color: #94a3b8;
    margin-top: 8px;
    text-align: center;
}

/* Quick Tools Popover Menu */
.gemini-quick-tools {
    position: absolute;
    bottom: 56px;
    left: 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
    padding: 6px;
    display: none;
    width: 250px;
    z-index: 100;
}
.gemini-quick-tools.is-show {
    display: block;
    animation: gmFadeIn 0.15s ease;
}
.gemini-quick-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 8px;
    color: #334155;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.15s ease;
}
.gemini-quick-item:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.gemini-quick-item svg {
    color: #1a73e8;
    flex-shrink: 0;
}

@keyframes gmFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsif HP & Tablet (Tanpa Kejepit) */
@media (max-width: 768px) {
    .gemini-stream-container {
        margin-top: 4px;
    }
    .gemini-prompt-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    .gemini-model-badge-input {
        display: none;
    }
    .gm-msg-user {
        max-width: 90%;
    }
    .gemini-bottom-dock {
        bottom: 12px;
    }
}
</style>

<!-- REUSABLE SVG GRADIENT DEFS -->
<svg style="display:none;" width="0" height="0">
    <defs>
        <linearGradient id="gmGrad" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">
            <stop stop-color="#4285F4"/>
            <stop offset="0.5" stop-color="#9B72CB"/>
            <stop offset="1" stop-color="#D96570"/>
        </linearGradient>
    </defs>
</svg>

<!-- ==================================================================
     KONTEN ASISTEN AI (MENYATU LANGSUNG DI HALAMAN, TANPA CARD/RUANG TERISOLASI)
     ================================================================== -->
<div class="agent-workspace">
    <aside class="agent-history" aria-label="Riwayat percakapan">
        <div class="agent-history__head"><span class="agent-history__title">Riwayat percakapan</span><button type="button" class="agent-history__clear" id="clearAgentHistory">Hapus</button></div>
        <input class="agent-history__search" id="agentHistorySearch" type="search" placeholder="Cari percakapan..." aria-label="Cari percakapan">
        <div class="agent-history__list" id="agentHistoryList"><div class="agent-history__empty">Belum ada percakapan.</div></div>
    </aside>
    <div class="gemini-stream-container">

    <!-- Area Daftar Pesan & Welcome -->
    <div class="gemini-messages-list" id="chatFeed">

        <!-- Welcome Center State (Sebelum Ada Pesan) -->
        <div class="gemini-welcome-box" id="welcomeCenter">
            <div class="gemini-sparkle-hero-wrap">
                <svg viewBox="0 0 24 24" width="36" height="36" fill="none">
                    <path d="M12 2C12 7.52 7.52 12 2 12C7.52 12 12 16.48 12 22C12 16.48 16.48 12 22 12C16.48 12 12 7.52 12 2Z" fill="url(#gmGrad)"/>
                </svg>
            </div>
            <h2>Hai, <span class="gemini-welcome-grad"><?= esc($userName) ?></span>!</h2>
            <p>Ada yang bisa saya bantu hari ini? Tanyakan apa saja, saya siap membantu!</p>

            <div class="gemini-prompt-grid">
                <?php foreach ($promptSuggestions as $sug): ?>
                    <div class="gemini-prompt-card" data-query="<?= esc($sug['query']) ?>">
                        <div>
                            <div class="gemini-prompt-top">
                                <div class="gemini-prompt-icon-wrap">
                                    <?php if ($sug['type'] === 'clock'): ?>
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><circle cx="19" cy="11" r="2"/><path d="M19 13v2l1 1"/>
                                        </svg>
                                    <?php elseif ($sug['type'] === 'stopwatch'): ?>
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5"/><path d="M10 2h4"/>
                                        </svg>
                                    <?php elseif ($sug['type'] === 'chart'): ?>
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>
                                        </svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <span class="gemini-prompt-tag"><?= esc($sug['tag']) ?></span>
                            </div>
                            <div class="gemini-prompt-title"><?= esc($sug['title']) ?></div>
                            <div class="gemini-prompt-desc"><?= esc($sug['desc']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Pinned Floating Input Capsule di Bawah Halaman -->
    <div class="gemini-bottom-dock">
        <div class="gemini-pill-capsule">

            <!-- Popover Aksi Cepat -->
            <div class="gemini-quick-tools" id="quickTools">
                <div class="gemini-quick-item" data-q="Siapa saja yang belum absen pulang hari ini?">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Siswa Belum Pulang</span>
                </div>
                <div class="gemini-quick-item" data-q="Siapa saja siswa yang terlambat hari ini?">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2 2"/></svg>
                    <span>Siswa Terlambat</span>
                </div>
                <div class="gemini-quick-item" data-q="Ringkas kehadiran hari ini.">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    <span>Rekapitulasi Presensi</span>
                </div>
                <div class="gemini-quick-item" data-q="Tampilkan data siswa">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Daftar Semua Siswa</span>
                </div>
                <div class="gemini-quick-item" data-q="SELECT * FROM presensi_db.attendance LIMIT 5">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    <span>Kueri SQL Zetaris Live</span>
                </div>
                <a class="gemini-quick-item" href="/admin/reports.php">
                    <i class="fa-solid fa-file-export"></i><span>Download Laporan Excel / PDF per Kelas</span>
                </a>
            </div>

            <button type="button" class="gemini-btn-icon" id="btnTools" title="Aksi Cepat">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
            </button>

            <textarea id="geminiTextarea" class="gemini-textarea" rows="1" 
                      placeholder="Tanyakan apa saja tentang kehadiran siswa atau ketik SQL..."></textarea>

            <div class="gemini-model-badge-input" title="Terhubung ke Zetaris Fabric">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="#1a73e8"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <span>Zetaris Auto</span>
            </div>

            <button type="button" class="gemini-btn-icon" id="btnMic" title="Gunakan Suara (Speech Recognition)">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/>
                </svg>
            </button>

            <button type="button" class="gemini-btn-send" id="btnSend" disabled title="Kirim Pesan">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 19V5M5 12l7-7 7 7"/>
                </svg>
            </button>
        </div>

        <div class="gemini-disclaimer-foot">
            SmartJournal AI terhubung ke <strong>Zetaris Virtualization Fabric</strong>. Data diverifikasi real-time dari database presensi School.
        </div>
    </div>

    </div>
</div>

<script>
(function () {
    const chatFeed      = document.getElementById('chatFeed');
    const welcomeCenter = document.getElementById('welcomeCenter');
    const inputArea     = document.getElementById('geminiTextarea');
    const btnSend       = document.getElementById('btnSend');
    const btnNewChat    = document.getElementById('btnNewChat');
    const btnTools      = document.getElementById('btnTools');
    const quickTools    = document.getElementById('quickTools');
    const promptCards   = document.querySelectorAll('.gemini-prompt-card');
    const btnMic        = document.getElementById('btnMic');
    const historyList = document.getElementById('agentHistoryList');
    const historySearch = document.getElementById('agentHistorySearch');
    const clearHistory = document.getElementById('clearAgentHistory');
    const historyKey = 'presensi_agent_history_v2';
    let history = JSON.parse(localStorage.getItem(historyKey) || '[]');
    let activeHistory = null;
    function paintHistory(filter) {
        if (!historyList) return;
        const q = (filter || '').toLowerCase();
        const items = history.filter(x => !q || (x.title + ' ' + x.query).toLowerCase().includes(q));
        historyList.innerHTML = items.length ? items.map(x => `<button type="button" class="agent-history__item${x.id === activeHistory ? ' is-active' : ''}" data-history="${x.id}">${x.title}<br><small>${new Date(x.time).toLocaleString('id-ID')}</small></button>`).join('') : '<div class="agent-history__empty">Belum ada percakapan.</div>';
        historyList.querySelectorAll('[data-history]').forEach(btn => btn.addEventListener('click', () => { const item = history.find(x => String(x.id) === String(btn.dataset.history)); if (!item) return; activeHistory = item.id; chatFeed.innerHTML = item.html; inputArea.focus(); paintHistory(historySearch.value); }));
    }
    function saveHistory(query) {
        const item = {id: Date.now(), title: query.length > 38 ? query.slice(0,38) + '…' : query, query, time: new Date().toISOString(), html: chatFeed.innerHTML};
        history = [item, ...history.filter(x => x.query !== query)].slice(0, 30); activeHistory = item.id; localStorage.setItem(historyKey, JSON.stringify(history)); paintHistory(historySearch && historySearch.value);
    }
    paintHistory('');
    if (historySearch) historySearch.addEventListener('input', () => paintHistory(historySearch.value));
    if (clearHistory) clearHistory.addEventListener('click', () => { history = []; activeHistory = null; localStorage.removeItem(historyKey); paintHistory(''); });

    let isSubmitting = false;
    let lastQuery = '';

    // Quick Tools Popover Toggle
    if (btnTools && quickTools) {
        btnTools.addEventListener('click', function (e) {
            e.stopPropagation();
            quickTools.classList.toggle('is-show');
        });
        document.addEventListener('click', function (e) {
            if (!quickTools.contains(e.target) && e.target !== btnTools) {
                quickTools.classList.remove('is-show');
            }
        });
        quickTools.querySelectorAll('.gemini-quick-item').forEach(item => {
            item.addEventListener('click', function () {
                const q = this.dataset.q;
                quickTools.classList.remove('is-show');
                if (q) sendMessage(q);
            });
        });
    }

    // Auto-resize input textarea
    inputArea.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        btnSend.disabled = this.value.trim() === '' || isSubmitting;
    });

    // Enter to send (Shift+Enter for newline)
    inputArea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!btnSend.disabled && !isSubmitting) {
                sendMessage(this.value.trim());
            }
        }
    });

    // Send button click
    btnSend.addEventListener('click', function () {
        if (!this.disabled && !isSubmitting) {
            sendMessage(inputArea.value.trim());
        }
    });

    // Suggestion cards click
    promptCards.forEach(card => {
        card.addEventListener('click', function () {
            const query = this.dataset.query;
            if (query && !isSubmitting) sendMessage(query);
        });
    });

    // New Chat button
    if (btnNewChat) {
        btnNewChat.addEventListener('click', function () {
            if (isSubmitting) return;
            chatFeed.innerHTML = '';
            chatFeed.appendChild(welcomeCenter);
            welcomeCenter.style.display = 'block';
            inputArea.value = '';
            inputArea.style.height = 'auto';
            btnSend.disabled = true;
            inputArea.focus();
        });
    }

    // Voice recognition (Speech to Text)
    if (btnMic && ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRec();
        recognition.lang = 'id-ID';
        recognition.interimResults = false;

        btnMic.addEventListener('click', function () {
            btnMic.style.color = '#ef4444';
            recognition.start();
        });
        recognition.onresult = function (event) {
            const text = event.results[0][0].transcript;
            inputArea.value = text;
            inputArea.dispatchEvent(new Event('input'));
            btnMic.style.color = '';
        };
        recognition.onerror = function () { btnMic.style.color = ''; };
        recognition.onend = function () { btnMic.style.color = ''; };
    }

    // Format simple Markdown to HTML
    function formatMarkdown(text) {
        let raw = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        raw = raw.replace(/&middot;/g, ' · ').replace(/&nbsp;/g, ' ');
        
        // Code blocks
        raw = raw.replace(/```([a-z]*)\n([\s\S]*?)```/gi, function (_, lang, code) {
            return '<pre style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;overflow-x:auto"><code>' + code + '</code></pre>';
        });

        // Headers
        raw = raw.replace(/^### (.*$)/gim, '<h3>$1</h3>');
        raw = raw.replace(/^## (.*$)/gim, '<h2>$1</h2>');
        
        // Bold & Italic
        raw = raw.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        raw = raw.replace(/\*(.*?)\*/g, '<em>$1</em>');

        // Blockquotes
        raw = raw.replace(/^\> (.*$)/gim, '<blockquote>$1</blockquote>');

        // Inline code
        raw = raw.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Table renderer
        if (raw.indexOf('|') !== -1) {
            const lines = raw.split('\n');
            let inTable = false;
            let tableHtml = '<table>';
            let newLines = [];

            for (let i = 0; i < lines.length; i++) {
                const line = lines[i].trim();
                if (line.startsWith('|') && line.endsWith('|')) {
                    const cells = line.split('|').slice(1, -1);
                    if (cells.every(c => /^[:\-\s]+$/.test(c))) {
                        continue;
                    }
                    if (!inTable) {
                        inTable = true;
                        tableHtml += '<thead><tr>' + cells.map(c => '<th>' + c.trim() + '</th>').join('') + '</tr></thead><tbody>';
                    } else {
                        tableHtml += '<tr>' + cells.map(c => '<td>' + c.trim() + '</td>').join('') + '</tr>';
                    }
                } else {
                    if (inTable) {
                        inTable = false;
                        tableHtml += '</tbody></table>';
                        newLines.push(tableHtml);
                        tableHtml = '<table>';
                    }
                    newLines.push(line);
                }
            }
            if (inTable) {
                tableHtml += '</tbody></table>';
                newLines.push(tableHtml);
            }
            raw = newLines.join('\n');
        }

        raw = raw.replace(/\n\n/g, '<p></p>');
        return raw;
    }

    // Append User Message (Right Aligned Bubble)
    function appendUserMessage(text) {
        if (welcomeCenter) welcomeCenter.style.display = 'none';

        const msgDiv = document.createElement('div');
        msgDiv.className = 'gm-msg-user';
        msgDiv.innerHTML = `
            <div class="gm-bubble-user">${text.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</div>
            <div class="gm-avatar-user"><?= esc($userInitial) ?></div>
        `;
        chatFeed.appendChild(msgDiv);
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    }

    // Append Loading Indicator
    function appendLoadingBubble() {
        const id = 'loading_' + Date.now();
        const msgDiv = document.createElement('div');
        msgDiv.className = 'gm-msg-ai';
        msgDiv.id = id;
        msgDiv.innerHTML = `
            <div class="gm-avatar-ai">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                    <path d="M12 2C12 7.52 7.52 12 2 12C7.52 12 12 16.48 12 22C12 16.48 16.48 12 22 12C16.48 12 12 7.52 12 2Z" fill="url(#gmGrad)"/>
                </svg>
            </div>
            <div class="gm-content-ai">
                <div class="gm-ai-header">
                    <span class="gm-ai-name">SmartJournal AI</span>
                    <span class="gm-ai-badge">Kueri Zetaris...</span>
                </div>
                <div class="gm-bubble-ai">
                    <div class="gm-typing-indicator">
                        <span class="gm-typing-dot"></span>
                        <span class="gm-typing-dot"></span>
                        <span class="gm-typing-dot"></span>
                    </div>
                </div>
            </div>
        `;
        chatFeed.appendChild(msgDiv);
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        return id;
    }

    // Append AI Response (Left Aligned with Action Toolbar)
    function appendAiMessage(data, currentQuery) {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'gm-msg-ai';

        const formattedHtml = formatMarkdown(data.answer || '(Tidak ada data respons)');
        const traceItems = Array.isArray(data.trace) ? data.trace : [];
        const traceId = 'trace_' + Date.now();

        let traceHtml = '';
        if (traceItems.length > 0) {
            traceHtml = `
                <div class="gm-trace-box" id="${traceId}">
                    <div class="gm-trace-title">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        <span>Jejak Eksekusi Zetaris Fabric:</span>
                    </div>
                    <ul class="gm-trace-list">
                        ${traceItems.map(t => `<li><strong>${t.step.toUpperCase()}</strong>: ${t.detail} <span style="opacity:0.6">(${t.ms || 0}ms)</span></li>`).join('')}
                    </ul>
                </div>
            `;
        }

        const provenancePills = Array.isArray(data.provenance) && data.provenance.length
            ? data.provenance.map(p => `<span style="font-size:10.5px;padding:2px 7px;background:#f1f5f9;border-radius:4px;color:#475569;margin-right:4px">${String(p).replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`).join('')
            : '';

        msgDiv.innerHTML = `
            <div class="gm-avatar-ai">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
                    <path d="M12 2C12 7.52 7.52 12 2 12C7.52 12 12 16.48 12 22C12 16.48 16.48 12 22 12C16.48 12 12 7.52 12 2Z" fill="url(#gmGrad)"/>
                </svg>
            </div>
            <div class="gm-content-ai">
                <div class="gm-ai-header">
                    <span class="gm-ai-name">SmartJournal AI</span>
                    <span class="gm-ai-badge">
                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Zetaris Verified
                    </span>
                    <span class="gm-ai-time">${provenancePills}</span>
                </div>
                <div class="gm-bubble-ai">${formattedHtml}</div>
                ${data.downloads ? `
                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
                    ${data.downloads.pdf ? `<a href="${data.downloads.pdf}" target="_blank" class="btn btn--primary" style="padding:10px 20px;border-radius:10px;font-weight:600;font-size:13.5px;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        Download PDF
                    </a>` : ''}
                    ${data.downloads.excel ? `<a href="${data.downloads.excel}" target="_blank" class="btn btn--soft" style="padding:10px 20px;border-radius:10px;font-weight:600;font-size:13.5px;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                        Download Excel (CSV)
                    </a>` : ''}
                </div>
                ` : ''}
                <div class="gm-action-bar">
                    <button type="button" class="gm-action-btn btn-like" title="Bagus">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
                    </button>
                    <button type="button" class="gm-action-btn btn-dislike" title="Kurang Sesuai">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3"/></svg>
                    </button>
                    <button type="button" class="gm-action-btn btn-retry" title="Muat Ulang / Coba Lagi">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>
                    </button>
                    <button type="button" class="gm-action-btn btn-copy" title="Salin Jawaban">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect width="13" height="13" x="9" y="9" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    ${traceItems.length > 0 ? `
                        <button type="button" class="gm-trace-pill" data-target="${traceId}">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            <span>Zetaris Trace (${traceItems.length})</span>
                        </button>
                    ` : ''}
                </div>
                ${traceHtml}
            </div>
        `;

        chatFeed.appendChild(msgDiv);
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });

        // Copy button event
        const btnCopy = msgDiv.querySelector('.btn-copy');
        if (btnCopy) {
            btnCopy.addEventListener('click', function () {
                navigator.clipboard.writeText(data.answer || '').then(() => {
                    btnCopy.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
                    setTimeout(() => {
                        btnCopy.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect width="13" height="13" x="9" y="9" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                    }, 1500);
                });
            });
        }

        // Retry button event
        const btnRetry = msgDiv.querySelector('.btn-retry');
        if (btnRetry) {
            btnRetry.addEventListener('click', function () {
                if (currentQuery && !isSubmitting) sendMessage(currentQuery);
            });
        }

        // Like / Dislike toggle
        const btnLike = msgDiv.querySelector('.btn-like');
        const btnDislike = msgDiv.querySelector('.btn-dislike');
        if (btnLike && btnDislike) {
            btnLike.addEventListener('click', function () {
                btnLike.classList.toggle('is-active-like');
                btnDislike.classList.remove('is-active-dislike');
            });
            btnDislike.addEventListener('click', function () {
                btnDislike.classList.toggle('is-active-dislike');
                btnLike.classList.remove('is-active-like');
            });
        }

        // Toggle Trace
        const btnTrace = msgDiv.querySelector('.gm-trace-pill');
        if (btnTrace) {
            btnTrace.addEventListener('click', function () {
                const box = document.getElementById(this.dataset.target);
                if (box) box.classList.toggle('is-open');
            });
        }
    }

    // Main Send Message Function
    function sendMessage(text) {
        if (!text || isSubmitting) return;

        lastQuery = text;
        isSubmitting = true;
        btnSend.disabled = true;
        inputArea.value = '';
        inputArea.style.height = 'auto';

        appendUserMessage(text);
        const loadingId = appendLoadingBubble();

        fetch('/api/v1/agent/ask.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: text })
        })
        .then(res => res.json())
        .then(res => {
            const loadingElem = document.getElementById(loadingId);
            if (loadingElem) loadingElem.remove();

            if (res.success && res.data) {
                appendAiMessage(res.data, text);
            } else if (res.answer) {
                appendAiMessage(res, text);
            } else {
                appendAiMessage({
                    answer: '**Kendala Kueri:** ' + (res.message || res.error || 'Gagal memproses kueri.'),
                    provider: 'Zetaris Error Handler'
                }, text);
            }
        })
        .catch(err => {
            const loadingElem = document.getElementById(loadingId);
            if (loadingElem) loadingElem.remove();
            console.error('Agent fetch error:', err);

            appendAiMessage({
                answer: '**Koneksi Terputus:** Tidak dapat menghubungi endpoint AI presensi. Cek console browser (F12).',
                provider: 'System'
            }, text);
        })
        .finally(() => {
            isSubmitting = false;
            btnSend.disabled = inputArea.value.trim() === '';
            inputArea.focus();
            saveHistory(text);
        });
    }

    // Focus input on initial load
    inputArea.focus();
})();
</script>

<?php require __DIR__ . '/../templates/admin-foot.php';
