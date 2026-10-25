<?php
/**
 * auth/login.php
 * ---------------------------------------------------------------------------
 * Login siswa, guru, dan admin.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/lang.php';

$error    = '';
$username = '';
$next     = clean($_GET['next'] ?? '');

/* Kanal login: 'siswa' (murid) atau 'guru' (guru & admin).
 * Nilai awal bisa dipra-pilih lewat ?akses=guru. Pilihan dari formulir
 * selalu menimpanya supaya tab tidak melompat kembali setelah error. */
$akses = (($_GET['akses'] ?? '') === 'guru') ? 'guru' : 'siswa';

/* Sudah masuk? Langsung ke tujuan. */
$currentUser = current_user();
if ($currentUser) {
    $beranda = ($currentUser['role'] === 'siswa') ? '/siswa/index.php' : '/admin/index.php';
    header('Location: ' . $beranda);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = t('login.err_expired');
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $akses    = (($_POST['akses'] ?? 'siswa') === 'guru') ? 'guru' : 'siswa';

        $user = db_one('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);

        /* Pesan sengaja sama untuk akun tidak ada maupun sandi salah,
         * supaya tidak bisa dipakai menebak username yang valid. */
        $generic = t('login.err_generic');

        if (!$user) {
            password_verify($password, '$2y$04$usesomesillystringfoeeoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            $error = $generic;
        } elseif ((int) $user['is_active'] !== 1) {
            $error = t('login.err_inactive');
        } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $sisa = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            $error = t('login.err_locked', ['menit' => $sisa]);
        } elseif (!password_verify($password, $user['password_hash'])) {
            $n = (int) $user['login_attempts'] + 1;
            $lock = null;
            if ($n >= LOGIN_MAX_ATTEMPTS) {
                $lock = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS);
                $n = 0;
            }
            db_exec(
                'UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?',
                [$n, $lock, (int) $user['id']]
            );
            audit_log((int) $user['id'], 'login_failed', 'warning', 'Percobaan ' . ($n + 1) . '.');
            $error = $lock
                ? 'Terlalu banyak percobaan salah. Akun dikunci 15 menit.'
                : $generic;
        } else {
            /* Sandi benar. Sebelum masuk, pastikan pilihan tab cocok dengan
             * peran akun. Murid hanya lewat tab Murid; guru DAN admin lewat
             * tab Guru (admin = guru berakses tertinggi). Pemisahan ini demi
             * kejelasan, bukan batas keamanan: peran tetap ditegakkan server
             * di require_login() tiap halaman. */
            $grupAkun = ($user['role'] === 'siswa') ? 'siswa' : 'guru';

            if ($grupAkun !== $akses) {
                $error = ($akses === 'guru')
                    ? t('login.err_role_siswa')
                    : t('login.err_role_guru');
            } else {
                if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ?',
                        [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), (int) $user['id']]);
                }
                db_exec(
                    'UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = ' . db_now_sql() . ' WHERE id = ?',
                    [(int) $user['id']]
                );
                audit_log((int) $user['id'], 'login_success', 'info', 'Peran ' . $user['role'] . '.');
                login_user($user);

                $role    = $user['role'];
                $default = $role === 'admin' || $role === 'guru' ? '/admin/index.php' : '/siswa/index.php';
                $target  = ($next !== '' && $next[0] === '/' && strpos($next, '//') !== 0) ? $next : $default;
                header('Location: ' . $target);
                exit;
            }
        }
    }
}

start_session();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(lang_active(), ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= te('login.doc_title') ?> &mdash; <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></title>
<meta name="description" content="<?= te('login.description', ['app' => APP_NAME]) ?>">
<meta name="robots" content="noindex, follow">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#a3241a">
<!-- Favicon inline, warna merah yang sama dengan portal siswa supaya
     ikon di tab tidak berubah-ubah antara halaman login dan beranda. -->
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23a3241a'/%3E%3Cpath d='M16 8l10 4.5-10 4.5-10-4.5L16 8z' fill='white'/%3E%3Cpath d='M10 15v4c0 1.7 2.7 3 6 3s6-1.3 6-3v-4' stroke='white' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<!-- Token dan reset diambil dari app.css; seluruh tampilan kartu ada di
     login.css supaya tidak perlu menyentuh halaman lain. -->
<link rel="stylesheet" href="/assets/css/app.css?v=5">
<link rel="stylesheet" href="/assets/css/login.css?v=8">
</head>
<body class="lg-page">

  <div class="lg-wrap">

    <!-- ============ KARTU LOGIN UTAMA ============ -->
    <main class="lg-card">

      <!-- Baris Atas: Pemilih Bahasa -->
      <div class="lg-card__top">
        <div class="lg-lang">
          <a class="<?= lang_is('id') ? 'is-on' : '' ?>"
             href="<?= htmlspecialchars(lang_switch_url('id'), ENT_QUOTES) ?>">ID</a>
          <a class="<?= lang_is('en') ? 'is-on' : '' ?>"
             href="<?= htmlspecialchars(lang_switch_url('en'), ENT_QUOTES) ?>">EN</a>
        </div>
      </div>

      <!-- Identitas Aplikasi (Logo, Nama, Tagline) di Dalam Kartu -->
      <div class="lg-brand-box">
        <span class="lg-brand__mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"
               stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2.6 3.6 6.2v5.5c0 4.9 3.6 8.8 8.4 10.1 4.8-1.3 8.4-5.2 8.4-10.1V6.2L12 2.6Z"/>
            <path d="M12 6.4 7.2 8.9v3c0 2.3 2 4.2 4.8 4.9 2.8-.7 4.8-2.6 4.8-4.9v-3L12 6.4Z" opacity=".55"/>
            <path d="m10.3 11.9 1.3 1.3 2.6-2.6"/>
          </svg>
        </span>
        <h1 class="lg-app"><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></h1>
        <p class="lg-app__sub"><?= te('app.tagline') ?></p>
      </div>

      <!-- Teks Sambutan -->
      <div class="lg-welcome-box">
        <h2 class="lg-title"><?= te('login.welcome') ?></h2>
        <p class="lg-lead"><?= te('login.lead') ?></p>
      </div>

      <!-- Pesan kesalahan -->
      <?php if ($error !== ''): ?>
        <div class="alert alert-error lg-alert--error shake" role="alert" id="login-error" style="margin-top:0.75rem">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
        </div>
      <?php endif; ?>

      <!-- Formulir Masuk -->
      <form method="post" class="lg-form" autocomplete="on" id="login-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

        <!-- Pemilih jenis akun (Murid / Guru) -->
        <div class="role-switch" role="radiogroup" aria-label="<?= te('login.account_type') ?>">
          <label class="role-tab">
            <input type="radio" name="akses" value="siswa" <?= $akses === 'siswa' ? 'checked' : '' ?>>
            <span><?= te('login.role_siswa') ?></span>
          </label>
          <label class="role-tab">
            <input type="radio" name="akses" value="guru" <?= $akses === 'guru' ? 'checked' : '' ?>>
            <span><?= te('login.role_guru') ?></span>
          </label>
        </div>
        <p class="role-hint"><?= te('login.role_hint') ?></p>

        <div class="field">
          <label class="field-label" for="username"><?= te('login.username') ?></label>
          <div class="field-input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
            <input type="text" id="username" name="username" required autofocus
                   autocapitalize="none" autocorrect="off" spellcheck="false"
                   autocomplete="username"
                   placeholder="<?= te('login.username_ph') ?>"
                   value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="password"><?= te('login.password') ?></label>
          <div class="field-input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password"
                   placeholder="<?= te('login.password_ph') ?>">
            <button type="button" class="field-toggle-pw" id="toggle-pw"
                    aria-label="<?= te('login.show_pw') ?>" aria-pressed="false">
              <svg class="lg-eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <svg class="lg-eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden>
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block lg-submit" id="btn-login">
          <span class="lg-submit__text"><?= te('login.submit') ?></span>
          <span class="lg-submit__spin" aria-hidden="true">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
            </svg>
          </span>
        </button>
      </form>

      <p class="lg-foot">
        <?= te('login.foot') ?>
      </p>

    </main>

    <p class="lg-legal">
      <b><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></b> &middot; <?= te('login.legal') ?>
    </p>

  </div>

<script>
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  /* Tampilkan / sembunyikan password.
     Dua ikon ditukar lewat atribut `hidden`, bukan lewat
     style.display. Alasannya: `hidden` dipahami pembaca layar
     sebagai "tidak ada", sedangkan display:none yang dipasang
     lewat JS kadang tetap terbaca sebagai teks. */
  var toggleBtn = $('toggle-pw');
  var pwInput   = $('password');
  if (toggleBtn && pwInput) {
    var eyeOpen   = toggleBtn.querySelector('.lg-eye-open');
    var eyeClosed = toggleBtn.querySelector('.lg-eye-closed');

    toggleBtn.addEventListener('click', function () {
      var showing = pwInput.type === 'text';
      pwInput.type = showing ? 'password' : 'text';

      if (eyeOpen)   eyeOpen.hidden   = !showing;
      if (eyeClosed) eyeClosed.hidden = showing;

      toggleBtn.setAttribute('aria-pressed', showing ? 'false' : 'true');
      toggleBtn.setAttribute('aria-label', showing
          ? <?= json_encode(t('login.show_pw')) ?>
          : <?= json_encode(t('login.hide_pw')) ?>);
    });
  }

  /* Keadaan sibuk saat dikirim.
     Validasi isian diserahkan ke peramban, jadi event submit
     HANYA terjadi kalau isian sudah valid. Di titik ini tombol
     cukup dikunci supaya tidak terkirim dua kali. */
  var form     = $('login-form');
  var btnLogin = $('btn-login');
  if (form && btnLogin) {
    form.addEventListener('submit', function () {
      btnLogin.disabled = true;
      btnLogin.classList.add('is-busy');
    });
  }
})();
</script>
</body>
</html>
