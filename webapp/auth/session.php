<?php
/**
 * auth/session.php
 * ---------------------------------------------------------------------------
 * Pembuatan sesi dan proteksi halaman. Dimuat oleh setiap halaman yang
 * butuh tahu siapa penggunanya.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helper.php';

/**
 * Mulai sesi dengan cookie yang dikeraskan.
 * Dipanggil satu kali; pemanggilan berikutnya diabaikan.
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    /* Buang sesi yang sudah lewat masa, lalu mulai dari awal. */
    if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > SESSION_LIFETIME) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}

/** Pengguna yang sedang masuk, atau null. */
function current_user(): ?array
{
    start_session();
    $u = $_SESSION['user'] ?? null;
    return is_array($u) && !empty($u['id']) ? $u : null;
}

/** True bila pengguna masuk dengan peran yang diminta. */
function has_role(string ...$roles): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return in_array($u['role'] ?? '', $roles, true);
}

/** Paksa logout, lalu arahkan ke login. */
function require_login(string ...$roles): void
{
    $u = current_user();
    if (!$u) {
        header('Location: /auth/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    if ($roles && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
}

/**
 * Terapkan sidik jari akun ke sesi.
 * Dipanggil setelah password benar.
 */
function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => (int) $user['id'],
        'username'     => $user['username'],
        'nama_lengkap' => $user['nama_lengkap'],
        'role'         => $user['role'],
        'kelas_id'     => isset($user['kelas_id']) ? (int) $user['kelas_id'] : null,
    ];
    $_SESSION['login_at'] = time();
    unset($_SESSION['scan_attempts']);
}

/**
 * Berapa lama sesi ini sudah aktif, dalam detik.
 *
 * Dipakai untuk menahan tindakan sensitif seperti pendaftaran ulang
 * wajah. Kalau akun baru saja dipakai, ada kemungkinan besar ada yang
 * baru saja membajaknya, dan template yang ditulis saat itu tidak bisa
 * dipercaya.
 *
 * Meminta password lalu mengulang login jauh lebih kuat daripada
 * sekadar menunggu, tapi jeda waktu minimal ini sudah menaikkan
 * modalitas permintaan yang disengaja dari "bisa saja" menjadi
 * "hanya bisa dilakukan sengaja".
 */
function session_age(): int
{
    $at = (int) ($_SESSION['login_at'] ?? 0);
    if ($at <= 0) {
        /* Sesi tanpa stempel login dianggap baru saja, bukan dianggap
         * sudah aktif lama. Default yang aman. */
        return 0;
    }
    return max(0, time() - $at);
}

/** True kalau sesi cukup tua untuk tindakan sensitif. */
function session_is_old_enough(int $minAgeSeconds): bool
{
    return session_age() >= $minAgeSeconds;
}

/** Hapus sesi sepenuhnya. */
function logout_user(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Token CSRF untuk satu formulir.
 * Dikembalikan sebagai input tersembunyi, diperiksa saat pengiriman.
 *
 * Token diberi masa berlaku. Tanpa itu, satu token yang bocor dari
 * halaman yang tersimpan di cache peramban atau log proxies tetap
 * sah selamanya sampai sesi berakhir. CSRF_TOKEN_LIFETIME yang
 * berapa lama, dan token baru diterbitkan setelahnya lewat
 * csrf_token() yang dipanggil lagi oleh form.
 */
function csrf_token(): string
{
    start_session();
    $token = $_SESSION['csrf'] ?? '';
    $born  = (int) ($_SESSION['csrf_at'] ?? 0);

    if (!is_string($token) || $token === '' || $born <= 0
        || (time() - $born) > CSRF_TOKEN_LIFETIME) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf']    = $token;
        $_SESSION['csrf_at'] = time();
    }
    return $token;
}

function csrf_check(?string $token): bool
{
    start_session();

    $token    = is_string($token) ? $token : '';
    $expected = $_SESSION['csrf'] ?? '';
    $born     = (int) ($_SESSION['csrf_at'] ?? 0);

    if ($token === '' || !is_string($expected) || $expected === '' || $born <= 0) {
        return false;
    }
    if ((time() - $born) > CSRF_TOKEN_LIFETIME) {
        return false;
    }

    /* hash_equals waktu konstan supaya lamanya tidak membocorkan
     * berapa banyak karakter yang cocok. */
    return hash_equals($expected, $token);
}
