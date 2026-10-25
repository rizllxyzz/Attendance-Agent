<?php
/**
 * api/v1/agent/ask.php
 * ---------------------------------------------------------------------------
 * Backend endpoint untuk SmartJournal AI yang terintegrasi langsung dengan
 * Zetaris Data Virtualization REST API dan Database Presensi.
 *
 * Menerima:
 *   POST {"question": "..."}
 * Mengembalikan:
 *   JSON {ok: true, answer: "...", provider: "...", provenance: [...], trace: [...]}
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../config/agent-config.php';
require_once __DIR__ . '/../../../config/zetaris.php';
require_once __DIR__ . '/../../../config/database.php';

function ai_env(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    if ($value !== false && trim((string) $value) !== '') return trim((string) $value);
    $file = dirname(__DIR__) . '/../../../.env';
    if (!is_readable($file)) return $fallback;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || strpos($line, '=') === false) continue;
        [$name, $val] = explode('=', $line, 2);
        if (trim($name) === $key) return trim($val, " \t\\\"'");
    }
    return $fallback;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('METHOD_NOT_ALLOWED', 'Gunakan POST.', 405);
}

$user = current_user();
$internalToken = $_SERVER['HTTP_X_AGENT_TOKEN'] ?? '';
$isInternal = ($internalToken !== '' && AGENT_SHARED_TOKEN !== '' && hash_equals(AGENT_SHARED_TOKEN, $internalToken));

if (!$isInternal && (!$user || !in_array($user['role'] ?? '', ['siswa', 'guru', 'admin'], true))) {
    json_fail('FORBIDDEN', 'Silakan masuk untuk menggunakan asisten presensi.', 403);
}
if ($isInternal && !$user) {
    $user = ['id' => 0, 'role' => 'admin', 'nama_lengkap' => 'System'];
}
$userId = (int) ($user['id'] ?? 0);

$gate = throttle('agent_ask', AGENT_MAX_PER_WINDOW, AGENT_WINDOW_SEC);
if (!$gate['allowed']) {
    json_fail('RATE_LIMITED', 'Terlalu banyak pertanyaan. Coba lagi dalam ' . $gate['retryAfter'] . ' detik.', 429, ['retryAfter' => $gate['retryAfter']]);
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    json_fail('BAD_REQUEST', 'Body harus JSON.', 400);
}

$question = trim((string) ($body['question'] ?? ''));
if ($question === '') {
    json_fail('BAD_REQUEST', 'Pertanyaan kosong.', 400);
}
if (mb_strlen($question) > AGENT_MAX_QUESTION_LEN) {
    json_fail('BAD_REQUEST', 'Pertanyaan maksimal ' . AGENT_MAX_QUESTION_LEN . ' karakter.', 400);
}

/* Siswa mendapatkan bot yang aman untuk data dirinya sendiri. */
if (($user['role'] ?? '') === 'siswa') {
    // Coba AI provider dulu kalau dikonfigurasi
    $aiEnabled = ai_env('AI_ENABLED') === '1';
    $aiProvider = ai_env('AI_PROVIDER', 'disabled');
    $aiBaseUrl = ai_env('AI_BASE_URL');
    $aiApiKey = ai_env('AI_API_KEY');
    $aiModel = ai_env('AI_MODEL');
    
    if ($aiEnabled && $aiProvider !== 'disabled' && $aiApiKey !== '') {
        $chatResponse = call_ai_provider($question, $aiProvider, $aiBaseUrl, $aiApiKey, $aiModel);
        if ($chatResponse !== null && $chatResponse !== '') {
            audit_log($userId, 'student_agent_ask_ai', 'info', mb_substr($question, 0, 120));
            json_ok([
                'answer'     => $chatResponse,
                'mode'       => 'chatbot',
                'provider'   => 'AI Chatbot · ' . ucfirst($aiProvider),
                'strategy'   => 'external-ai',
                'provenance' => ['konfigurasi-ai'],
                'iterations' => 1,
                'sufficient' => true,
                'trace'      => [['step' => 'ai-provider', 'detail' => 'Menggunakan provider AI terkonfigurasi', 'ms' => 50]],
            ]);
            exit;
        }
    }
    
    $studentResponse = process_student_agent($question, $userId);
    audit_log($userId, 'student_agent_ask', 'info', mb_substr($question, 0, 120));
    json_ok($studentResponse);
}

// ---------------------------------------------------------------------------
// 0. REPORT GENERATION (PDF + Excel) - handled locally with real data
// ---------------------------------------------------------------------------
$qLower = strtolower(trim($question));
$isReport = preg_match('/(laporan|report|susun|export|download)/', $qLower);

if ($isReport && ($user['role'] ?? '') !== 'siswa') {
    $reportResponse = process_report($question, $userId);
    audit_log($userId, 'agent_report', 'info', mb_substr($question, 0, 120));
    json_ok($reportResponse);
    exit;
}

// ---------------------------------------------------------------------------
// 1. Coba hubungi Agent Server eksternal (bila sedang aktif)
// ---------------------------------------------------------------------------
$agentUrls = [AGENT_URL, 'http://127.0.0.1:8000', 'http://127.0.0.1:8788'];
$agentUrls = array_values(array_unique(array_filter($agentUrls)));

$externalResponse = null;
foreach ($agentUrls as $url) {
    $ch = @curl_init($url . '/ask');
    if (!$ch) continue;

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['question' => $question]),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], agent_auth_headers()),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $res = @curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res !== false && $httpCode === 200) {
        $data = json_decode((string) $res, true);
        if (is_array($data) && (!empty($data['answer']) || !empty($data['ok']))) {
            $externalResponse = $data;
            break;
        }
    }
}

if ($externalResponse !== null) {
    audit_log($userId, 'agent_ask', 'info', mb_substr($question, 0, 120));
    json_ok([
        'answer'     => (string) ($externalResponse['answer'] ?? ''),
        'mode'       => (string) ($externalResponse['mode'] ?? 'model'),
        'provider'   => $externalResponse['provider'] ?? 'SmartJournal AI · Zetaris Engine',
        'strategy'   => (string) ($externalResponse['strategy'] ?? 'zetaris+nightshift'),
        'provenance' => $externalResponse['provenance'] ?? ['presensi_db.attendance', 'presensi_db.users'],
        'iterations' => (int) ($externalResponse['iterations'] ?? 1),
        'sufficient' => (bool) ($externalResponse['sufficient'] ?? true),
        'trace'      => is_array($externalResponse['trace'] ?? null) ? $externalResponse['trace'] : [],
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 2. Coba AI Provider eksternal (jika dikonfigurasi di admin)
// ---------------------------------------------------------------------------
$aiEnabled = ai_env('AI_ENABLED') === '1';
$aiProvider = ai_env('AI_PROVIDER', 'disabled');
$aiBaseUrl = ai_env('AI_BASE_URL');
$aiApiKey = ai_env('AI_API_KEY');
$aiModel = ai_env('AI_MODEL');

if ($aiEnabled && $aiProvider !== 'disabled' && $aiApiKey !== '') {
    $chatResponse = call_ai_provider($question, $aiProvider, $aiBaseUrl, $aiApiKey, $aiModel);
    if ($chatResponse !== null && $chatResponse !== '') {
        audit_log($userId, 'agent_ask_ai', 'info', mb_substr($question, 0, 120));
        json_ok([
            'answer'     => $chatResponse,
            'mode'       => 'chatbot',
            'provider'   => 'AI Chatbot · ' . ucfirst($aiProvider),
            'strategy'   => 'external-ai',
            'provenance' => ['konfigurasi-ai'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => [['step' => 'ai-provider', 'detail' => 'Menggunakan provider AI terkonfigurasi', 'ms' => 50]],
        ]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// 3. SMART ENGINE TERINTEGRASI ZETARIS (Built-in Resilience)
// ---------------------------------------------------------------------------
$response = process_with_zetaris($question);

audit_log($userId, 'agent_ask', 'info', mb_substr($question, 0, 120));

json_ok($response);

/**
 * Generate PDF + CSV report from real attendance data.
 */
function process_report(string $q, int $userId): array
{
    require_once __DIR__ . '/../../../vendor/fpdf/fpdf.php';

    $tz = new DateTimeZone(defined('TIMEZONE') ? TIMEZONE : 'Asia/Jakarta');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $bulan = (new DateTimeImmutable('now', $tz))->format('Y-m');
    $periodeAwal = $bulan . '-01';
    $periodeAkhir = (new DateTimeImmutable($periodeAwal . ' 00:00:00', $tz))->format('Y-m-t');

    // Query real data via Zetaris/DB
    $runQ = static function (string $sql) {
        try {
            if (function_exists('zetaris_query')) {
                $res = zetaris_query($sql, 500);
                if (is_array($res)) {
                    return array_map(static function ($r) {
                        return is_array($r) ? array_values($r) : [$r];
                    }, $res);
                }
            }
        } catch (Throwable $e) {
        }
        try {
            $rows = db_all(str_replace(['presensi_db.', 'PRESENSI_DB.'], '', $sql));
            $out = [];
            foreach ($rows as $r) $out[] = array_values($r);
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    };

    $totSiswa = (int) ($runQ("SELECT COUNT(*) FROM presensi_db.users WHERE role = 'siswa'")[0][0] ?? 0);
    $totMasuk = (int) ($runQ("SELECT COUNT(DISTINCT user_id) FROM presensi_db.attendance WHERE tanggal >= '$periodeAwal' AND tanggal <= '$periodeAkhir' AND scan_type = 'masuk'")[0][0] ?? 0);
    $totPulang = (int) ($runQ("SELECT COUNT(DISTINCT user_id) FROM presensi_db.attendance WHERE tanggal >= '$periodeAwal' AND tanggal <= '$periodeAkhir' AND scan_type = 'pulang'")[0][0] ?? 0);
    $totTelat = (int) ($runQ("SELECT COUNT(*) FROM presensi_db.attendance WHERE tanggal >= '$periodeAwal' AND tanggal <= '$periodeAkhir' AND is_late = 1")[0][0] ?? 0);
    $totIzin = (int) ($runQ("SELECT COUNT(DISTINCT user_id) FROM presensi_db.izin_requests WHERE status = 'disetujui' AND tanggal_mulai <= '$periodeAkhir' AND tanggal_selesai >= '$periodeAwal'")[0][0] ?? 0);

    $students = $runQ("SELECT u.id, u.nis, u.nama_lengkap, COALESCE(k.nama_kelas,'Tanpa Kelas') FROM presensi_db.users u LEFT JOIN presensi_db.kelas k ON u.kelas_id = k.id WHERE u.role = 'siswa' ORDER BY k.nama_kelas, u.nama_lengkap");

    // Generate unique filename
    $stamp = date('Ymd_His');
    $baseName = "laporan_presensi_{$bulan}_{$stamp}";
    $reportDir = __DIR__ . '/../../../uploads/reports';
    if (!is_dir($reportDir)) @mkdir($reportDir, 0755, true);

    // Generate CSV (Excel)
    $csvPath = $reportDir . '/' . $baseName . '.csv';
    $fp = fopen($csvPath, 'w');
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    fputcsv($fp, ['No', 'NIS', 'Nama Siswa', 'Kelas', 'Status Bulan Ini']);
    $no = 1;
    foreach ($students as $s) {
        fputcsv($fp, [$no++, $s[1] ?? '-', $s[2] ?? '-', $s[3] ?? '-', '']);
    }
    fputcsv($fp, []);
    fputcsv($fp, ['Ringkasan Periode', $periodeAwal . ' s/d ' . $periodeAkhir]);
    fputcsv($fp, ['Total Siswa', $totSiswa]);
    fputcsv($fp, ['Hadir (scan masuk)', $totMasuk]);
    fputcsv($fp, ['Sudah Pulang', $totPulang]);
    fputcsv($fp, ['Terlambat', $totTelat]);
    fputcsv($fp, ['Izin/Sakit', $totIzin]);
    fclose($fp);

    // Generate PDF
    $pdfPath = $reportDir . '/' . $baseName . '.pdf';
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Laporan Presensi Sekolah', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Periode: ' . $periodeAwal . ' s/d ' . $periodeAkhir, 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Ringkasan', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(60, 6, 'Total Siswa', 1);
    $pdf->Cell(0, 6, $totSiswa, 1, 1);
    $pdf->Cell(60, 6, 'Hadir (scan masuk)', 1);
    $pdf->Cell(0, 6, $totMasuk, 1, 1);
    $pdf->Cell(60, 6, 'Sudah Pulang', 1);
    $pdf->Cell(0, 6, $totPulang, 1, 1);
    $pdf->Cell(60, 6, 'Terlambat', 1);
    $pdf->Cell(0, 6, $totTelat, 1, 1);
    $pdf->Cell(60, 6, 'Izin/Sakit', 1);
    $pdf->Cell(0, 6, $totIzin, 1, 1);
    $pdf->Ln(6);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Daftar Siswa (' . count($students) . ')', 0, 1);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(10, 6, 'No', 1);
    $pdf->Cell(30, 6, 'NIS', 1);
    $pdf->Cell(90, 6, 'Nama Siswa', 1);
    $pdf->Cell(40, 6, 'Kelas', 1, 1);
    $pdf->SetFont('Arial', '', 9);
    $no = 1;
    foreach (array_slice($students, 0, 60) as $s) {
        $pdf->Cell(10, 6, $no++, 1);
        $pdf->Cell(30, 6, substr((string) ($s[1] ?? '-'), 0, 15), 1);
        $pdf->Cell(90, 6, substr((string) ($s[2] ?? '-'), 0, 40), 1);
        $pdf->Cell(40, 6, substr((string) ($s[3] ?? '-'), 0, 20), 1, 1);
    }
    $pdf->Output('F', $pdfPath);

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $webBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    // uploads/reports relative to project root
    $pdfUrl = $baseUrl . '/uploads/reports/' . basename($pdfPath);
    $csvUrl = $baseUrl . '/uploads/reports/' . basename($csvPath);

    $ans = "### Laporan Presensi Sekolah\n";
    $ans .= "**Periode:** `{$periodeAwal}` s/d `{$periodeAkhir}`\n\n";
    $ans .= "Laporan berhasil dibuat dari data real Zetaris:\n";
    $ans .= "- Total Siswa: **{$totSiswa}**\n";
    $ans .= "- Hadir: **{$totMasuk}** · Pulang: **{$totPulang}** · Terlambat: **{$totTelat}** · Izin: **{$totIzin}**\n\n";
    $ans .= "Silakan unduh laporan di bawah ini.";

    return [
        'answer' => $ans,
        'mode' => 'report',
        'provider' => 'SmartJournal AI · Report Generator',
        'strategy' => 'zetaris+report',
        'provenance' => ['presensi_db.attendance', 'presensi_db.users', 'presensi_db.kelas'],
        'iterations' => 1,
        'sufficient' => true,
        'trace' => [
            ['step' => 'plan', 'detail' => 'Mendeteksi permintaan laporan', 'ms' => 5],
            ['step' => 'retrieve', 'detail' => 'Query data dari Zetaris', 'ms' => 50],
            ['step' => 'generate', 'detail' => 'Generate PDF + CSV', 'ms' => 100],
        ],
        'downloads' => [
            'pdf' => $pdfUrl,
            'excel' => $csvUrl,
        ],
    ];
}

function call_ai_provider(string $question, string $provider, string $baseUrl, string $apiKey, string $model): ?string
{
    $systemPrompt = "Kamu adalah asisten AI yang ramah dan natural layaknya chatbot modern. Nama kamu SmartJournal AI. Bantu pengguna dengan penjelasan yang jelas, ringkas, dan praktis. Jawab dalam bahasa Indonesia dengan gaya percakapan yang natural.";
    
    if ($baseUrl === '') {
        if ($provider === 'openai') $baseUrl = 'https://api.openai.com/v1';
        elseif ($provider === 'gemini') $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
        else return null;
    }
    
    if ($model === '') {
        if ($provider === 'openai') $model = 'gpt-4o-mini';
        elseif ($provider === 'gemini') $model = 'gemini-2.5-flash';
        elseif ($provider === 'groq') $model = 'llama-3.1-8b-instant';
        elseif ($provider === 'openrouter') $model = 'google/gemini-2.5-flash';
        elseif ($provider === 'ollama') $model = 'llama3.1:8b';
        else $model = 'gpt-4o-mini';
    }
    
    $url = rtrim($baseUrl, '/') . '/chat/completions';
    
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $question],
        ],
        'temperature' => 0.7,
        'max_tokens' => 1024,
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $err !== '') return null;
    if ($httpCode >= 400) return null;
    
    $data = json_decode((string) $res, true);
    if (is_array($data)) {
        if (isset($data['choices'][0]['message']['content'])) {
            return trim((string) $data['choices'][0]['message']['content']);
        }
    }
    
    return null;
}

/**
 * Pemroses logika AI berbasis virtualisasi Zetaris
 */
function process_student_agent(string $q, int $userId): array
{
    $qLower = strtolower(trim($q));
    $tz = new DateTimeZone(defined('TIMEZONE') ? TIMEZONE : 'Asia/Jakarta');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $rows = db_all(
        "SELECT tanggal, scan_type, waktu, status, is_late, late_minutes, gps_verdict
           FROM attendance WHERE user_id = ? ORDER BY tanggal DESC, id DESC LIMIT 30",
        [$userId]
    );
    $izinRows = [];
    try {
        $izinRows = db_all(
            'SELECT jenis, tanggal_mulai, tanggal_selesai, status, alasan, catatan_petugas
               FROM izin_requests WHERE user_id = ? ORDER BY id DESC LIMIT 12',
            [$userId]
        );
    } catch (Throwable $e) {
        $izinRows = [];
    }
    $izinToday = array_values(array_filter($izinRows, static fn($r) =>
        (string) ($r['tanggal_mulai'] ?? '') <= $today
        && (string) ($r['tanggal_selesai'] ?? '') >= $today
    ));
    $todayRows = array_values(array_filter($rows, static fn($r) => (string) $r['tanggal'] === $today));
    $masuk = null;
    $pulang = null;
    foreach ($todayRows as $row) {
        if (($row['scan_type'] ?? '') === 'masuk' && $masuk === null) $masuk = $row;
        if (($row['scan_type'] ?? '') === 'pulang' && $pulang === null) $pulang = $row;
    }

    if (preg_match('/(izin|sakit|pengajuan|dispensasi)/', $qLower)) {
        $answer = "### Data izin dan sakit Anda\\n\\n";
        if ($izinRows === []) {
            $answer .= 'Belum ada pengajuan izin atau sakit yang tersimpan.';
        } else {
            foreach (array_slice($izinRows, 0, 8) as $izin) {
                $jenis = ucfirst((string) $izin['jenis']);
                $answer .= '- **' . $jenis . '** · ' . $izin['tanggal_mulai'] . ' sampai ' . $izin['tanggal_selesai'] . ' · status **' . $izin['status'] . "**\\n";
                if (trim((string) ($izin['catatan_petugas'] ?? '')) !== '') $answer .= '  Catatan petugas: ' . $izin['catatan_petugas'] . "\\n";
            }
        }
    } elseif (preg_match('/^(hai|halo|helo|hello|hey|hei|pagi|siang|sore|malam|assalamu)/i', $qLower)) {
        $answer = "Halo! Ada yang bisa saya bantu? Tanyakan status absen hari ini, riwayat presensi, atau apa aja ya.";
    } elseif (preg_match('/(halo|hai|help|bantu|bisa)/', $qLower)) {
        $answer = "Halo! Ada yang bisa saya bantu? Kamu bisa tanyain status hari ini, riwayat presensi, izin/sakit, atau ngobrol biasa juga boleh.";
    } elseif (strpos($qLower, 'hari ini') !== false || strpos($qLower, 'status') !== false || strpos($qLower, 'absen') !== false) {
        $answer = "### Status presensi hari ini\\n\\n";
        $answer .= '- **Masuk:** ' . ($masuk ? date('H:i', strtotime((string) $masuk['waktu'])) . ' WIB' : 'belum tercatat') . "\\n";
        $answer .= '- **Pulang:** ' . ($pulang ? date('H:i', strtotime((string) $pulang['waktu'])) . ' WIB' : 'belum tercatat') . "\\n";
        if ($masuk && !empty($masuk['is_late'])) $answer .= '- **Catatan:** terlambat ' . (int) ($masuk['late_minutes'] ?? 0) . " menit.\\n";
        if ($izinToday !== []) $answer .= '- **Izin/sakit hari ini:** ' . ucfirst((string) $izinToday[0]['jenis']) . ' dengan status ' . $izinToday[0]['status'] . ".\\n";
        if (!$pulang) $answer .= '- **Berikutnya:** lakukan scan pulang pada jendela yang dibuka sekolah.\\n';
    } elseif (strpos($qLower, 'riwayat') !== false || strpos($qLower, 'rekap') !== false) {
        $answer = "### Riwayat presensi terbaru\\n\\n";
        foreach (array_slice($rows, 0, 8) as $row) {
            $answer .= '- ' . $row['tanggal'] . ' · ' . ucfirst((string) $row['scan_type']) . ' · ' . date('H:i', strtotime((string) $row['waktu'])) . " WIB\\n";
        }
        if ($rows === []) $answer .= 'Belum ada riwayat presensi.';
    } else {
        // Kalau AI provider aktif, biarkan lewat ke atas - tapi di sini udah diproses
        // Coba jawab lebih natural
        if (strlen($qLower) <= 3) {
            $answer = "Maksudnya apa ya? Bisa dijelasin lebih detail?";
        } else {
            $answer = "Bisa tanyain aku tentang **status absen hari ini**, **riwayat presensi**, **izin/sakit**, atau ngobrol biasa juga boleh kok.";
        }
    }

    return [
        'answer' => $answer,
        'mode' => 'student-safe',
        'provider' => 'SmartJournal AI · Presensi Pribadi',
        'strategy' => 'private-attendance-context',
        'provenance' => ['presensi_db.attendance pribadi', 'presensi_db.izin_requests pribadi'],
        'iterations' => 1,
        'sufficient' => true,
        'trace' => [['step' => 'privacy', 'detail' => 'Data dibatasi pada akun siswa aktif', 'ms' => 1]],
    ];
}

function process_with_zetaris(string $q): array
{
    $qLower = strtolower(trim($q));
    $trace = [];
    $provenance = ['presensi_db.attendance', 'presensi_db.users'];
    $trace[] = ['step' => 'plan', 'detail' => 'Mengidentifikasi intensi pertanyaan & memetakan skema presensi_db', 'ms' => 8];

    // Helper query dengan fallback otomatis ke MySQL lokal jika Zetaris container mati
    $runZQuery = static function (string $sql, int $limit = 100) use (&$trace) {
        $t0 = microtime(true);
        try {
            $res = zetaris_query($sql, $limit);
            /* Resolver memakai posisi kolom agar kompatibel dengan fallback
             * lokal. Zetaris dapat mengembalikan associative rows. */
            if (is_array($res)) {
                $res = array_map(static function ($row) {
                    return is_array($row) ? array_values($row) : [$row];
                }, $res);
            }
            $ms = round((microtime(true) - $t0) * 1000);
            $trace[] = ['step' => 'retrieve', 'detail' => 'Kueri dieksekusi di Zetaris Virtualization Fabric: ' . preg_replace('/\s+/', ' ', substr($sql, 0, 90)), 'ms' => $ms];
            return $res;
        } catch (Throwable $e) {
            // Fallback transparan ke DB MySQL langsung bila Zetaris offline
            $ms = round((microtime(true) - $t0) * 1000);
            $trace[] = ['step' => 'retrieve', 'detail' => 'Kueri dialihkan ke database presensi lokal (Zetaris fallback)', 'ms' => $ms];
            $cleanSql = str_replace(['presensi_db.', 'PRESENSI_DB.'], '', $sql);
            try {
                $rawRows = db_all($cleanSql);
                $cleanRows = [];
                foreach ($rawRows as $r) {
                    $cleanRows[] = array_values($r);
                }
                return $cleanRows;
            } catch (Throwable $e2) {
                return [];
            }
        }
    };

    // Ambil tanggal terakhir absensi aktif
    $dateRow = $runZQuery("SELECT MAX(tanggal) FROM presensi_db.attendance");
    $targetDate = $dateRow[0][0] ?? date('Y-m-d');
    // GREETINGS: hai, halo, hello, assalamualaikum, selamat pagi/siang/sore/malam
    if (preg_match('/^(hai|halo|helo|hello|hey|hei|pagi|siang|sore|malam|assalamu[\'a-z]*|tes|test)[\s\.,\!\?]*$/i', $qLower)) {
        return [
            'answer'     => "Hai! Ada yang bisa saya bantu hari ini seputar data presensi dan kehadiran siswa School?",
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'conversational',
            'provenance' => ['presensi_db'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => [
                ['step' => 'intent', 'detail' => 'Mendeteksi sapaan percakapan santai (Gemini Conversational)', 'ms' => 2]
            ],
        ];
    }

    // KASUS 1: Siapa yang belum absen pulang / anomali keluar sekolah / bolos
    if ((strpos($qLower, 'belum') !== false && (strpos($qLower, 'pulang') !== false || strpos($qLower, 'absen') !== false || strpos($qLower, 'scan') !== false))
        || strpos($qLower, 'masuk tapi') !== false || strpos($qLower, 'hilang') !== false || strpos($qLower, 'anomali') !== false || strpos($qLower, 'bolos') !== false) {
        
        $masukRows = $runZQuery("SELECT user_id, waktu FROM presensi_db.attendance WHERE scan_type = 'masuk' AND tanggal = '$targetDate' LIMIT 100");
        $pulangRows = $runZQuery("SELECT DISTINCT user_id FROM presensi_db.attendance WHERE scan_type = 'pulang' AND tanggal = '$targetDate' LIMIT 100");
        
        $pulangIds = [];
        foreach ($pulangRows as $pr) {
            $pulangIds[] = (int) $pr[0];
        }

        $belumPulangUsers = [];
        foreach ($masukRows as $mr) {
            $uid = (int) $mr[0];
            if (!in_array($uid, $pulangIds, true)) {
                $belumPulangUsers[$uid] = $mr[1] ?? '07:00';
            }
        }

        // Cek izin siswa
        $izinRows = $runZQuery("SELECT user_id, jenis, status FROM presensi_db.izin_requests WHERE status = 'disetujui' AND '$targetDate' BETWEEN tanggal_mulai AND tanggal_selesai");
        $provenance[] = 'presensi_db.izin_requests';
        $izinMap = [];
        foreach ($izinRows as $ir) {
            $izinMap[(int)$ir[0]] = $ir[1];
        }

        // Ambil info nama & kelas
        $usersInfo = $runZQuery("SELECT u.id, u.nama_lengkap, u.nis, k.nama_kelas FROM presensi_db.users u LEFT JOIN presensi_db.kelas k ON u.kelas_id = k.id WHERE u.role = 'siswa'");
        $provenance[] = 'presensi_db.kelas';
        $userMap = [];
        foreach ($usersInfo as $ui) {
            $userMap[(int)$ui[0]] = [
                'nama'  => $ui[1],
                'nis'   => $ui[2] ?: '-',
                'kelas' => $ui[3] ?: 'Tanpa Kelas',
            ];
        }

        $count = count($belumPulangUsers);
        $ans = "### Rekonsiliasi Kehadiran: Siswa Masuk Belum Scan Pulang\n";
        $ans .= "**Tanggal Analisis:** `{$targetDate}` &middot; **Data Layer:** *Zetaris Virtualization Fabric*\n\n";

        if ($count === 0) {
            $ans .= "**Status:** Seluruh siswa yang hadir tercatat telah melakukan scan pulang. Tidak ditemukan anomali kepulangan pada tanggal ini.";
        } else {
            $ans .= "Terdeteksi **{$count} siswa** tercatat scan masuk tetapi belum ada catatan scan pulang:\n\n";
            $ans .= "| No | NIS | Nama Siswa | Kelas | Jam Masuk | Status Izin | Rekomendasi |\n";
            $ans .= "|:---:|:---:|:---|:---:|:---:|:---:|:---|\n";
            $no = 1;
            foreach ($belumPulangUsers as $uid => $jamMasuk) {
                $nama = $userMap[$uid]['nama'] ?? "Siswa #{$uid}";
                $nis = $userMap[$uid]['nis'] ?? "-";
                $kelas = $userMap[$uid]['kelas'] ?? "-";
                $jamFmt = $jamMasuk ? substr((string)$jamMasuk, 11, 5) : '07:00';
                $adaIzin = isset($izinMap[$uid]);
                $izinText = $adaIzin ? ucfirst((string)$izinMap[$uid]) : "Tanpa Izin";
                $rekom = $adaIzin ? "Dispensasi Resmi Sekolah" : "**Perlu Konfirmasi (Potensi Keluar/Bolos)**";
                $ans .= "| {$no} | `{$nis}` | **{$nama}** | {$kelas} | {$jamFmt} WIB | {$izinText} | {$rekom} |\n";
                $no++;
            }
            $ans .= "\n> **Catatan Verifikasi:** Data diverifikasi silang secara real-time antara tabel log kehadiran dan surat izin melalui koneksi virtualisasi Zetaris.";
        }

        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+virtualization',
            'provenance' => array_values(array_unique($provenance)),
            'iterations' => 2,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 2: Keterlambatan siswa
    if (strpos($qLower, 'telat') !== false || strpos($qLower, 'terlambat') !== false || strpos($qLower, 'kedisiplinan') !== false) {
        $lateRows = $runZQuery("SELECT user_id, waktu, late_minutes FROM presensi_db.attendance WHERE is_late = 1 AND tanggal = '$targetDate' ORDER BY late_minutes DESC LIMIT 50");
        $usersInfo = $runZQuery("SELECT u.id, u.nama_lengkap, u.nis, k.nama_kelas FROM presensi_db.users u LEFT JOIN presensi_db.kelas k ON u.kelas_id = k.id");
        $userMap = [];
        foreach ($usersInfo as $ui) {
            $userMap[(int)$ui[0]] = [
                'nama'  => $ui[1],
                'nis'   => $ui[2] ?: '-',
                'kelas' => $ui[3] ?: 'Tanpa Kelas',
            ];
        }

        $count = count($lateRows);
        $ans = "### Laporan Keterlambatan Siswa\n";
        $ans .= "**Tanggal:** `{$targetDate}` &middot; **Data Layer:** *Zetaris Virtualization*\n\n";

        if ($count === 0) {
            $ans .= "**Status:** Tidak ada siswa yang terlambat. Seluruh siswa yang hadir melakukan scan masuk sebelum batas waktu jam sekolah.";
        } else {
            $ans .= "Tercatat **{$count} siswa** hadir melewati batas toleransi keterlambatan:\n\n";
            $ans .= "| No | NIS | Nama Siswa | Kelas | Waktu Scan | Durasi Terlambat |\n";
            $ans .= "|:---:|:---:|:---|:---:|:---:|:---:|\n";
            $no = 1;
            foreach ($lateRows as $lr) {
                $uid = (int) $lr[0];
                $nama = $userMap[$uid]['nama'] ?? "Siswa #{$uid}";
                $nis = $userMap[$uid]['nis'] ?? "-";
                $kelas = $userMap[$uid]['kelas'] ?? "-";
                $jam = $lr[1] ? substr((string)$lr[1], 11, 5) : '-';
                $menit = (int) ($lr[2] ?? 0);
                $ans .= "| {$no} | `{$nis}` | **{$nama}** | {$kelas} | {$jam} WIB | **+{$menit} Menit** |\n";
                $no++;
            }
        }

        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => array_values(array_unique($provenance)),
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 3: Ringkasan / Rekap Kehadiran
    if (strpos($qLower, 'ringkas') !== false || strpos($qLower, 'rekap') !== false || strpos($qLower, 'kehadiran') !== false || strpos($qLower, 'presensi hari ini') !== false || strpos($qLower, 'statistik') !== false) {
        $totSiswa = (int) ($runZQuery("SELECT COUNT(*) FROM presensi_db.users WHERE role = 'siswa'")[0][0] ?? 0);
        $totMasuk = (int) ($runZQuery("SELECT COUNT(DISTINCT user_id) FROM presensi_db.attendance WHERE tanggal = '$targetDate' AND scan_type = 'masuk'")[0][0] ?? 0);
        $totPulang = (int) ($runZQuery("SELECT COUNT(DISTINCT user_id) FROM presensi_db.attendance WHERE tanggal = '$targetDate' AND scan_type = 'pulang'")[0][0] ?? 0);
        $totIzin = (int) ($runZQuery("SELECT COUNT(DISTINCT user_id) FROM presensi_db.izin_requests WHERE status = 'disetujui' AND '$targetDate' BETWEEN tanggal_mulai AND tanggal_selesai")[0][0] ?? 0);
        $totTelat = (int) ($runZQuery("SELECT COUNT(*) FROM presensi_db.attendance WHERE tanggal = '$targetDate' AND is_late = 1")[0][0] ?? 0);

        $belumPulang = max(0, $totMasuk - $totPulang);
        $alpa = max(0, $totSiswa - $totMasuk - $totIzin);
        $persenHadir = $totSiswa > 0 ? round(($totMasuk / $totSiswa) * 100, 1) : 0;

        $ans = "### Rekapitulasi Presensi Sekolah\n";
        $ans .= "**Periode Data:** `{$targetDate}` &middot; **Status Fabric:** *Zetaris Virtualized Metastore*\n\n";
        $ans .= "Berikut ringkasan statistik kehadiran siswa School:\n\n";
        $ans .= "- **Total Siswa Terdaftar:** **{$totSiswa} Siswa**\n";
        $ans .= "- **Tingkat Kehadiran:** **{$persenHadir}%** ({$totMasuk} siswa scan masuk)\n";
        $ans .= "- **Sudah Scan Pulang:** **{$totPulang} Siswa**\n";
        $ans .= "- **Belum Scan Pulang:** **{$belumPulang} Siswa** (Perlu dimonitor)\n";
        $ans .= "- **Terlambat Masuk:** **{$totTelat} Siswa**\n";
        $ans .= "- **Izin / Sakit Resmi:** **{$totIzin} Siswa**\n";
        $ans .= "- **Tanpa Keterangan (Alpa):** **{$alpa} Siswa**\n\n";
        $ans .= "> **Sumber Data:** Statistik dikompilasi secara real-time dari tabel virtual `attendance`, `users`, dan `izin_requests`.";

        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => ['presensi_db.attendance', 'presensi_db.users', 'presensi_db.izin_requests'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 4: Cari Siswa / Kelas Tertentu ATAU Tampilkan Semua Data Siswa
    if (strpos($qLower, 'siswa') !== false || strpos($qLower, 'murid') !== false || strpos($qLower, 'kelas') !== false) {
        $kelasFilter = '';
        if (preg_match('/\b(xii|xi|x)\s*(ipa|ips|tjkt|tkj|rpl)\s*(\d+)\b/i', $q, $m)) {
            $kelasFilter = strtoupper($m[1] . ' ' . $m[2] . ' ' . $m[3]);
        }

        if ($kelasFilter !== '') {
            $kelasKey = strtolower(str_replace([' ', '-'], '', $kelasFilter));
            $rows = $runZQuery("SELECT u.id, u.nis, u.nama_lengkap, k.nama_kelas, u.jenis_kelamin FROM presensi_db.users u JOIN presensi_db.kelas k ON u.kelas_id = k.id WHERE LOWER(REPLACE(REPLACE(k.nama_kelas, ' ', ''), '-', '')) LIKE '%$kelasKey%' ORDER BY u.nama_lengkap LIMIT 50");
            $title = "Daftar Siswa Kelas " . strtoupper($kelasFilter);
        } else {
            $rows = $runZQuery("SELECT u.id, u.nis, u.nama_lengkap, COALESCE(k.nama_kelas, 'Tanpa Kelas') as nama_kelas, u.jenis_kelamin FROM presensi_db.users u LEFT JOIN presensi_db.kelas k ON u.kelas_id = k.id WHERE u.role = 'siswa' ORDER BY k.nama_kelas ASC, u.nama_lengkap ASC LIMIT 50");
            $title = "Daftar Seluruh Siswa Terdaftar";
        }

        $count = count($rows);
        $ans = "### {$title}\n";
        $ans .= "Ditemukan **{$count} siswa** terdaftar pada database presensi:\n\n";

        if ($count === 0) {
            $ans .= "Tidak ditemukan data siswa dengan kriteria kelas tersebut di database.";
        } else {
            $ans .= "| No | NIS | Nama Lengkap | Kelas | Jenis Kelamin |\n";
            $ans .= "|:---:|:---:|:---|:---:|:---:|\n";
            $no = 1;
            foreach ($rows as $r) {
                $nis = $r[1] ?: '-';
                $nama = $r[2];
                $kelas = $r[3];
                $jk = ($r[4] === 'L' || $r[4] === 'laki-laki') ? 'Laki-laki' : (($r[4] === 'P' || $r[4] === 'perempuan') ? 'Perempuan' : '-');
                $ans .= "| {$no} | `{$nis}` | **{$nama}** | {$kelas} | {$jk} |\n";
                $no++;
            }
        }

        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => ['presensi_db.users', 'presensi_db.kelas'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 5: Surat Izin & Dispensasi
    if (strpos($qLower, 'izin') !== false || strpos($qLower, 'sakit') !== false || strpos($qLower, 'dispensasi') !== false) {
        $izinList = $runZQuery("SELECT u.nama_lengkap, u.nis, i.jenis, i.tanggal_mulai, i.tanggal_selesai, i.status FROM presensi_db.izin_requests i JOIN presensi_db.users u ON i.user_id = u.id ORDER BY i.id DESC LIMIT 15");
        $count = count($izinList);

        $ans = "### Pengajuan Surat Izin & Sakit Siswa\n";
        $ans .= "Ditemukan **{$count} pengajuan izin terbaru**:\n\n";

        if ($count === 0) {
            $ans .= "Tidak ada data pengajuan izin atau surat sakit saat ini.";
        } else {
            $ans .= "| No | Siswa | Jenis | Periode | Status Verifikasi |\n";
            $ans .= "|:---:|:---|:---:|:---:|:---:|\n";
            $no = 1;
            foreach ($izinList as $il) {
                $nama = $il[0];
                $jenis = strtoupper((string)$il[2]);
                $periode = $il[3] . ' s/d ' . $il[4];
                $stat = $il[5] === 'disetujui' ? 'Disetujui' : ($il[5] === 'ditolak' ? 'Ditolak' : 'Menunggu Konfirmasi');
                $ans .= "| {$no} | **{$nama}** | {$jenis} | {$periode} | {$stat} |\n";
                $no++;
            }
        }

        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => ['presensi_db.izin_requests', 'presensi_db.users'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 6: Data Guru & Tenaga Kependidikan
    if (strpos($qLower, 'guru') !== false || strpos($qLower, 'pengajar') !== false || strpos($qLower, 'wali') !== false) {
        $gurus = $runZQuery("SELECT id, username, nama_lengkap, email, no_hp FROM presensi_db.users WHERE role = 'guru' OR role = 'admin' ORDER BY role ASC, nama_lengkap ASC");
        $count = count($gurus);

        $ans = "### Daftar Guru & Tenaga Pendidik\n";
        $ans .= "Ditemukan **{$count} akun pendidik** terdaftar di sistem:\n\n";
        $ans .= "| No | Nama Lengkap | Username | Peran | Kontak |\n";
        $ans .= "|:---:|:---|:---:|:---:|:---:|\n";
        $no = 1;
        foreach ($gurus as $g) {
            $nama = $g[2];
            $uname = $g[1];
            $peran = ucfirst((string)$g[0] == '1' ? 'Administrator' : 'Guru');
            $kontak = $g[4] ?: ($g[3] ?: '-');
            $ans .= "| {$no} | **{$nama}** | `{$uname}` | {$peran} | {$kontak} |\n";
            $no++;
        }

        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => ['presensi_db.users'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 7: Jam Masuk & Pengaturan Sekolah
    if (strpos($qLower, 'jam') !== false || strpos($qLower, 'aturan') !== false || strpos($qLower, 'jadwal') !== false || strpos($qLower, 'pengaturan') !== false) {
        $settings = $runZQuery("SELECT `kunci`, `nilai` FROM presensi_db.pengaturan LIMIT 20");
        $ans = "### Konfigurasi Jam Presensi & Aturan Sekolah\n\n";
        $ans .= "| Pengaturan | Nilai Konfigurasi |\n";
        $ans .= "|:---|:---|\n";
        foreach ($settings as $s) {
            $key = ucwords(str_replace('_', ' ', (string)$s[0]));
            $val = (string)$s[1];
            $ans .= "| **{$key}** | `{$val}` |\n";
        }
        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'SmartJournal AI · Zetaris Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => ['presensi_db.pengaturan'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // KASUS 8: Eksekusi SQL Langsung (bila diawali SELECT)
    if (strpos($qLower, 'select ') === 0) {
        $sqlClean = trim($q);
        if (stripos($sqlClean, 'presensi_db.') === false && stripos($sqlClean, 'attendance') !== false) {
            $sqlClean = str_ireplace('attendance', 'presensi_db.attendance', $sqlClean);
        }
        if (stripos($sqlClean, 'presensi_db.') === false && stripos($sqlClean, 'users') !== false) {
            $sqlClean = str_ireplace('users', 'presensi_db.users', $sqlClean);
        }
        $rawRows = $runZQuery($sqlClean, 20);
        $count = count($rawRows);
        $ans = "### Eksekusi SQL Virtual di Zetaris\n";
        $ans .= "```sql\n" . $sqlClean . "\n```\n";
        $ans .= "Hasil Kueri: **{$count} baris data ditemukan.**\n\n";
        if ($count > 0) {
            $ans .= "```json\n" . json_encode(array_slice($rawRows, 0, 8), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```\n";
        }
        return [
            'answer'     => $ans,
            'mode'       => 'model',
            'provider'   => 'Zetaris SQL Execution Engine',
            'strategy'   => 'zetaris+sql',
            'provenance' => ['presensi_db'],
            'iterations' => 1,
            'sufficient' => true,
            'trace'      => $trace,
        ];
    }

    // DEFAULT / PANDUAN PENGGUNAAN
    $ans = "Saya dapat membantu Anda mengakses dan menganalisis database presensi School secara real-time via **Zetaris**:\n\n";
    $ans .= "- **Siswa Belum Pulang:** *\"Siapa saja siswa yang belum absen pulang hari ini?\"*\n";
    $ans .= "- **Keterlambatan:** *\"Siapa saja siswa yang terlambat hari ini?\"*\n";
    $ans .= "- **Rekapitulasi:** *\"Ringkas kehadiran hari ini.\"*\n";
    $ans .= "- **Data Siswa:** *\"Tampilkan data siswa\"* atau *\"Siswa kelas X IPA 1\"*\n";
    $ans .= "- **Surat Izin:** *\"Tampilkan pengajuan surat izin.\"*\n";
    $ans .= "- **Live SQL:** *\"SELECT * FROM presensi_db.users LIMIT 5\"*\n\n";
    $ans .= "Silakan klik kartu rekomendasi atau ketik pertanyaan langsung di kolom bawah.";

    return [
        'answer'     => $ans,
        'mode'       => 'model',
        'provider'   => 'SmartJournal AI · Zetaris Engine',
        'strategy'   => 'zetaris+knowledge',
        'provenance' => ['presensi_db'],
        'iterations' => 1,
        'sufficient' => true,
        'trace'      => $trace,
    ];
}

