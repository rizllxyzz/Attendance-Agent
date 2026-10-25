<?php
/**
 * admin/_data.php
 * ---------------------------------------------------------------------------
 * Lapisan baca data untuk panel admin. Semua angka yang dipakai lebih dari
 * satu halaman dihitung di sini, supaya dashboard, halaman absensi, dan
 * halaman izin tidak pernah menampilkan angka yang berbeda untuk hal yang
 * sama.
 *
 * Halaman pemanggil:
 *   require_once __DIR__ . '/_bootstrap.php';
 *   require_once __DIR__ . '/_data.php';
 *
 * Aturan angka yang berlaku di seluruh file ini:
 *   1. "Hadir" selalu dihitung dari scan MASUK pada tanggal itu, bukan dari
 *      jumlah baris. Satu siswa yang scan masuk dan pulang tetap dihitung
 *      satu orang.
 *   2. "Belum absen" dihitung dari siswa AKTIF dikurangi yang hadir, dan
 *      dikurangi lagi yang izin/sakit-nya sudah disetujui pada tanggal itu.
 *   3. Tanggal selalu dikirim sebagai parameter, tidak pernah dirangkai ke
 *      SQL, dan tidak pernah memakai fungsi tanggal bawaan database. Dengan
 *      begitu hasil query sama persis di SQLite maupun MySQL.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (!function_exists('admin_flash')) {
    http_response_code(500);
    exit('Halaman tidak lengkap dimuat.');
}

/* ==================================================================
 *  KELAS
 * ================================================================== */

/**
 * Daftar kelas urut tingkat lalu nama.
 * Tahun ajaran default dipakai sebagai penyaring supaya panel tidak
 * mencampur kelas tahun lalu dan tahun ini dalam satu daftar.
 */
function admin_daftar_kelas(bool $hanyaAktif = true, ?string $tahunAjaran = null): array
{
    $sql = 'SELECT k.id, k.nama_kelas, k.tingkat, k.jurusan, k.tahun_ajaran, k.is_active,
                   k.wali_kelas, u.nama_lengkap AS wali_nama,
                   (SELECT COUNT(*) FROM users s WHERE s.kelas_id = k.id AND s.role = \'siswa\') AS jumlah_siswa
              FROM kelas k
         LEFT JOIN users u ON u.id = k.wali_kelas';

    $where = [];
    $args  = [];
    if ($hanyaAktif) {
        $where[] = 'k.is_active = 1';
    }
    if ($tahunAjaran !== null && $tahunAjaran !== '') {
        $where[] = 'k.tahun_ajaran = ?';
        $args[]  = $tahunAjaran;
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY k.tahun_ajaran DESC, k.tingkat ASC, k.nama_kelas ASC';

    return db_all($sql, $args);
}

/** Satu baris kelas, atau null. */
function admin_kelas(int $id): ?array
{
    return db_one(
        'SELECT k.*, u.nama_lengkap AS wali_nama
           FROM kelas k
      LEFT JOIN users u ON u.id = k.wali_kelas
          WHERE k.id = ? LIMIT 1',
        [$id],
        null
    );
}

/** Hitung jumlah siswa per kelas, untuk tabel rekap. */
function admin_jumlah_siswa_per_kelas(array $kelasIds): array
{
    if ($kelasIds === []) {
        return [];
    }
    $tanda  = implode(',', array_fill(0, count($kelasIds), '?'));
    $baris  = db_all(
        "SELECT kelas_id, COUNT(*) AS n
           FROM users
          WHERE role = 'siswa' AND is_active = 1 AND kelas_id IN ($tanda)
          GROUP BY kelas_id",
        $kelasIds
    );
    $hasil = [];
    foreach ($baris as $b) {
        $hasil[(int) $b['kelas_id']] = (int) $b['n'];
    }
    return $hasil;
}

/* ==================================================================
 *  PENGGUNA
 * ================================================================== */

/**
 * Guru yang bisa dipilih sebagai wali kelas.
 * Admin juga ikut, karena di sekolah kecil kepala sekolah sekaligus
 * biasa memegang kelas.
 */
function admin_daftar_wali(): array
{
    return db_all(
        "SELECT id, nama_lengkap, username
           FROM users
          WHERE is_active = 1 AND role IN ('guru', 'admin')
       ORDER BY nama_lengkap ASC"
    );
}

/** Jumlah siswa aktif dengan filter opsional. */
function admin_jumlah_siswa(?int $kelasId = null, string $cari = '', bool $hanyaAktif = true): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'siswa'";
    $args = [];
    if ($hanyaAktif) {
        $sql .= ' AND is_active = 1';
    }
    if ($kelasId !== null) {
        $sql .= ' AND kelas_id = ?';
        $args[] = $kelasId;
    }
    if ($cari !== '') {
        $sql .= ' AND (nama_lengkap LIKE ? OR username LIKE ? OR nis LIKE ? OR nisn LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle, $needle);
    }
    return (int) db_value($sql, $args, 0);
}

/**
 * Daftar siswa untuk tabel, sudah dipaginasi.
 * $orderOnly membatasi kolom ORDER BY ke daftar putih supaya nilai dari
 * query string tidak bisa disisipkan ke SQL.
 */
function admin_daftar_siswa(
    ?int $kelasId,
    string $cari,
    string $urut = 'nama',
    string $arah = 'ASC',
    int $batas = 0,
    int $offset = 0,
    ?string $tahunAjaran = null,
    ?string $statusFilter = null
): array {
    $sql = "SELECT s.id, s.username, s.nama_lengkap, s.nis, s.nisn, s.kelas_id, s.is_active,
                   s.last_login, s.login_attempts, s.locked_until, s.jenis_kelamin, s.created_at,
                   k.nama_kelas, k.tingkat, k.jurusan,
                   (SELECT MAX(created_at) FROM attendance a WHERE a.user_id = s.id) AS absen_terakhir
              FROM users s
         LEFT JOIN kelas k ON k.id = s.kelas_id
             WHERE s.role = 'siswa'";

    $args = [];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    if ($tahunAjaran !== null && $tahunAjaran !== '') {
        $sql .= ' AND (k.tahun_ajaran = ? OR s.kelas_id IS NULL)';
        $args[] = $tahunAjaran;
    }
    if ($cari !== '') {
        $sql .= ' AND (s.nama_lengkap LIKE ? OR s.username LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle, $needle);
    }
    /* null = semua status (termasuk nonaktif), 'aktif', 'nonaktif'. */
    if ($statusFilter === 'aktif') {
        $sql .= ' AND s.is_active = 1';
    } elseif ($statusFilter === 'nonaktif') {
        $sql .= ' AND s.is_active = 0';
    }

    $kolomUrut = [
        'nama'  => 's.nama_lengkap',
        'kelas' => 'k.nama_kelas',
        'absen' => 'absen_terakhir',
        'status'=> 's.is_active',
        'sekolah' => 's.created_at',
    ];
    $kolom  = $kolomUrut[$urut] ?? $kolomUrut['nama'];
    $arah   = strtoupper($arah) === 'DESC' ? 'DESC' : 'ASC';
    /* NULL selalu di akhir, apa pun arahnya: "belum pernah absen" tidak
     * boleh muncul paling atas daftar yang diurutkan dari A-Z. */
    $sql   .= " ORDER BY $kolom IS NULL ASC, $kolom $arah, s.id ASC";

    if ($batas > 0) {
        $sql .= ' LIMIT ' . (int) $batas . ' OFFSET ' . (int) $offset;
    }

    return db_all($sql, $args);
}

/**
 * Jumlah siswa yang cocok dengan filter tabel siswa.
 *
 * Sengaja memakai nama dan parameter berbeda dari admin_jumlah_siswa():
 * yang itu hanya bisa menyaring "aktif" atau "semua", sehingga dipakai
 * untuk penghitung di dashboard. Halaman daftar siswa butuh tiga filter
 * sekaligus (kelas, tahun, status), dan penghitungnya harus sama persis
 * dengan syarat admin_daftar_siswa(). Kalau tidak, pilihan filter
 * "nonaktif" bisa menampilkan angka total siswa aktif, dan paginator
 * membuat halaman kosong.
 *
 * @param string|null $statusFilter null = semua, 'aktif', atau 'nonaktif'
 */
function admin_hitung_siswa(
    ?int $kelasId = null,
    string $cari = '',
    ?string $tahunAjaran = null,
    ?string $statusFilter = null
): int {
    $sql = "SELECT COUNT(*)
              FROM users s
         LEFT JOIN kelas k ON k.id = s.kelas_id
             WHERE s.role = 'siswa'";
    $args = [];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    if ($tahunAjaran !== null && $tahunAjaran !== '') {
        $sql .= ' AND (k.tahun_ajaran = ? OR s.kelas_id IS NULL)';
        $args[] = $tahunAjaran;
    }
    if ($cari !== '') {
        $sql .= ' AND (s.nama_lengkap LIKE ? OR s.username LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle, $needle);
    }
    if ($statusFilter === 'aktif') {
        $sql .= ' AND s.is_active = 1';
    } elseif ($statusFilter === 'nonaktif') {
        $sql .= ' AND s.is_active = 0';
    }
    return (int) db_value($sql, $args, 0);
}

/** Satu akun, untuk form ubah. */
function admin_siswa(int $id): ?array
{
    return db_one(
        'SELECT s.*, k.nama_kelas, k.tahun_ajaran
           FROM users s
      LEFT JOIN kelas k ON k.id = s.kelas_id
          WHERE s.id = ? AND s.role = \'siswa\'
          LIMIT 1',
        [$id],
        null
    );
}

/* ==================================================================
 *  ABSENSI
 * ================================================================== */

/** Total siswa aktif — penyebut untuk perhitungan "belum absen". */
function admin_total_siswa_aktif(?int $kelasId = null): int
{
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'siswa' AND is_active = 1";
    $args = [];
    if ($kelasId !== null) {
        $sql .= ' AND kelas_id = ?';
        $args[] = $kelasId;
    }
    return (int) db_value($sql, $args, 0);
}

/** Kolom + join standar untuk daftar scan, dipakai daftar dan CSV. */
function _sql_scan(): string
{
    return "SELECT a.id, a.user_id, a.tanggal, a.waktu, a.scan_type, a.status,
                   a.is_late, a.late_minutes, a.gps_verdict, a.distance_m,
                   a.face_distance, a.face_confidence, a.created_at,
                   s.nama_lengkap, s.username, k.nama_kelas
              FROM attendance a
              JOIN users s ON s.id = a.user_id
         LEFT JOIN kelas k ON k.id = s.kelas_id
             WHERE s.role = 'siswa' AND a.tanggal = ?";
}

/** Jumlah scan pada satu tanggal, untuk paginasi riwayat. */
function admin_jumlah_scan(string $tanggal, ?int $kelasId = null): int
{
    $sql = "SELECT COUNT(*)
              FROM attendance a
              JOIN users s ON s.id = a.user_id
             WHERE s.role = 'siswa' AND a.tanggal = ?";
    $args = [$tanggal];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    return (int) db_value($sql, $args, 0);
}

/**
 * Daftar scan pada satu tanggal, terbaru dulu.
 *
 * @return array<int, array<string, mixed>>
 */
function admin_daftar_scan(string $tanggal, ?int $kelasId = null, int $limit = 25, int $offset = 0): array
{
    $sql = _sql_scan();
    $args = [$tanggal];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    return db_all($sql, $args);
}

/** Semua scan pada satu tanggal untuk diekspor, tanpa paginasi. */
function admin_semua_scan(string $tanggal, ?int $kelasId = null): array
{
    $sql = _sql_scan();
    $args = [$tanggal];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $sql .= ' ORDER BY a.created_at ASC, a.id ASC';
    return db_all($sql, $args);
}

/**
 * Siswa aktif yang belum punya scan masuk pada satu tanggal.
 *
 * Yang sudah punya scan "pulang" tetap masuk daftar: masuk dan pulang
 * adalah dua scan terpisah, jadi adanya scan pulang tidak membuktikan
 * bahwa siswa datang tepat waktu.
 *
 * @return array<int, array<string, mixed>>
 */
/**
 * Siswa aktif yang belum melakukan scan masuk pada satu tanggal.
 *
 * Izin yang disetujui untuk tanggal tersebut dikeluarkan, sama seperti
 * di KPI rekap. Kalau tidak, tab "belum" menampilkan lebih banyak orang
 * daripada angka besar di atasnya. Izin yang masih MENUNGGU tetap
 * masuk: siswa itu memang belum absen, statusnya baru diproses.
 */
function admin_daftar_belum_absen(string $tanggal, ?int $kelasId = null, int $limit = 25, int $offset = 0): array
{
    $sql = "SELECT s.id, s.nama_lengkap, s.username, k.nama_kelas,
                   (SELECT MAX(a2.tanggal) FROM attendance a2
                     WHERE a2.user_id = s.id AND a2.scan_type = 'masuk') AS absen_terakhir,
                   EXISTS(SELECT 1 FROM attendance a3
                           WHERE a3.user_id = s.id AND a3.tanggal = ?
                             AND a3.scan_type = 'pulang') AS sudah_pulang,
                   EXISTS(SELECT 1 FROM izin_requests ir
                           WHERE ir.user_id = s.id AND ir.status = 'menunggu'
                             AND ir.tanggal_mulai <= ? AND ir.tanggal_selesai >= ?) AS ada_izin
              FROM users s
         LEFT JOIN kelas k ON k.id = s.kelas_id
             WHERE s.role = 'siswa' AND s.is_active = 1
               AND NOT EXISTS(SELECT 1 FROM attendance a
                                WHERE a.user_id = s.id AND a.tanggal = ?
                                  AND a.scan_type = 'masuk')";
    $args = [$tanggal, $tanggal, $tanggal, $tanggal];
    if (admin_izin_tabel_ada()) {
        $sql .= " AND NOT EXISTS(SELECT 1 FROM izin_requests iz
                                  WHERE iz.user_id = s.id AND iz.status = 'disetujui'
                                    AND iz.tanggal_mulai <= ? AND iz.tanggal_selesai >= ?)";
        /* Dua placeholder, jadi tanggal harus diikutkan dua kali. Kalau hanya
         * satu, PDO mengikat placeholder sisanya sebagai NULL, filter kelas
         * ikut gagal, dan tab "belum" tampak kosong. */
        $args[] = $tanggal;
        $args[] = $tanggal;
    }
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $sql .= ' ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC'
          . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    return db_all($sql, $args);
}

/** Jumlah siswa yang belum absen, untuk paginasi tab "belum". */
function admin_jumlah_belum_absen(string $tanggal, ?int $kelasId = null): int
{
    $sql = "SELECT COUNT(*)
              FROM users s
             WHERE s.role = 'siswa' AND s.is_active = 1
               AND NOT EXISTS(SELECT 1 FROM attendance a
                                WHERE a.user_id = s.id AND a.tanggal = ?
                                  AND a.scan_type = 'masuk')";
    $args = [$tanggal];
    if (admin_izin_tabel_ada()) {
        $sql .= " AND NOT EXISTS(SELECT 1 FROM izin_requests iz
                                  WHERE iz.user_id = s.id AND iz.status = 'disetujui'
                                    AND iz.tanggal_mulai <= ? AND iz.tanggal_selesai >= ?)";
        /* Dua placeholder, jadi tanggal harus diikutkan dua kali. Lihat catatan
         * di admin_daftar_belum_absen() di atas. */
        $args[] = $tanggal;
        $args[] = $tanggal;
    }
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    return (int) db_value($sql, $args, 0);
}

/**
 * Unduh rekap absensi satu tanggal sebagai CSV.
 *
 * Memakai filter yang sedang aktif, jadi file yang diunduh selalu sama
 * dengan yang terlihat di layar — bukan versi dengan filter default.
 */
function admin_csv_absensi(): void
{
    $tz        = new DateTimeZone(TIMEZONE);
    $hariIni   = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    /* Filter berasal dari form POST (hidden field), bukan dari query string.
     * Hanya membaca GET membuat berkas selalu berisi hari ini dan seluruh
     * kelas, jadi filter yang tampil di layar seperti tidak berlaku. */
    $tanggal   = (string) post('tanggal', get('tanggal', $hariIni));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = $hariIni;
    }
    $kelasPilih = (int) post('kelas', get('kelas', 0));
    $kelasPilih = $kelasPilih > 0 ? $kelasPilih : null;

    $kelas  = $kelasPilih ? admin_nama_kelas($kelasPilih) : 'Semua kelas';
    $kepala = [
        'Tanggal', 'Kelas', 'Nama', 'Username', 'Waktu', 'Tipe',
        'Status', 'Telat (menit)', 'GPS', 'Jarak (m)', 'Keyakinan wajah (%)',
    ];

    $baris = [];
    foreach (admin_semua_scan($tanggal, $kelasPilih) as $s) {
        $baris[] = [
            $tanggal,
            /* Placeholder harus berupa teks. Tanda '-' akan ikut diberi
             * awalan anti-formula oleh admin_csv() dan hasilnya jadi '-. */
            $s['nama_kelas'] ?? 'Tanpa kelas',
            $s['nama_lengkap'],
            $s['username'],
            $s['waktu'],
            $s['scan_type'] === 'pulang' ? 'Pulang' : 'Masuk',
            $s['status'],
            $s['late_minutes'] !== null ? (int) $s['late_minutes'] : '',
            (string) ($s['gps_verdict'] ?? ''),
            $s['distance_m'] !== null ? round((float) $s['distance_m']) : '',
            $s['face_confidence'] !== null ? round((float) $s['face_confidence'] * 100) : '',
        ];
    }

    if ($baris === []) {
        $baris[] = [$tanggal, $kelas, 'Tidak ada scan', '', '', '', '', '', '', '', ''];
    }

    admin_csv($baris, $kepala, 'absensi-' . $tanggal);
}

/**
 * Angka kehadiran satu tanggal untuk seluruh sekolah (atau satu kelas).
 * Mengembalikan: hadir, tepat, telat, pulang, belum, izin, total, persen.
 */
function admin_rekap_tanggal(string $tanggal, ?int $kelasId = null): array
{
    $sql = "SELECT
                  COUNT(DISTINCT CASE WHEN a.scan_type = 'masuk' THEN a.user_id END) AS hadir,
                  COUNT(DISTINCT CASE WHEN a.scan_type = 'masuk' AND a.is_late = 1 THEN a.user_id END) AS telat,
                  COUNT(DISTINCT CASE WHEN a.scan_type = 'pulang' THEN a.user_id END) AS pulang
              FROM attendance a
              JOIN users s ON s.id = a.user_id AND s.role = 'siswa'
             WHERE a.tanggal = ?";
    $args = [$tanggal];

    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }

    $row = db_one($sql, $args, []) ?: [];
    $hadir  = (int) ($row['hadir']  ?? 0);
    $telat  = (int) ($row['telat']  ?? 0);
    $pulang = (int) ($row['pulang'] ?? 0);

    $total = admin_total_siswa_aktif($kelasId);
    $izin  = admin_jumlah_izin_disetujui($tanggal, $kelasId);

    $tepat  = max(0, $hadir - $telat);
    $belum  = max(0, $total - $hadir - $izin);
    $sudah  = max(0, $total - $belum);

    return [
        'tanggal' => $tanggal,
        'hadir'   => $hadir,
        'tepat'   => $tepat,
        'telat'   => $telat,
        'pulang'  => $pulang,
        'belum'   => $belum,
        'izin'    => $izin,
        'total'   => $total,
        'persen'  => $total > 0 ? (int) round(($sudah / $total) * 100) : 0,
    ];
}

/**
 * Pasangan (siswa, tanggal) yang tercakup izin yang sudah disetujui di
 * rentang [$dari, $sampai].
 *
 * Yang dikembalikan adalah daftar PASANGAN, bukan daftar tanggal dan bukan
 * daftar nama. Itu penting karena tiga pemanggil butuh tiga jawaban
 * berbeda dari data yang sama:
 *
 *  - satu hari, seluruh kelas  -> "berapa siswa yang berizin" (per orang)
 *  - satu hari, satu kelas     -> sama seperti di atas
 *  - rentang beberapa hari     -> "berapa hari-siswa terlewati" (per orang
 *                                 per tanggal), supaya angka per tanggal
 *                                 bisa dijumlahkan tanpa dobel
 *
 * Pengajuan yang saling tumpang tindih untuk siswa yang sama di hari yang
 * sama tetap menghasilkan satu pasangan, dan hari Sabtu-Minggu tidak
 * dihitung karena tidak masuk hari kerja.
 *
 * @return array<int, array{user_id:int, tanggal:string}>
 */
function admin_pasangan_izin(string $dari, string $sampai, ?int $kelasId = null): array
{
    if ($dari > $sampai || !admin_izin_tabel_ada()) {
        return [];
    }
    try {
        $sql = "SELECT ir.user_id, ir.tanggal_mulai, ir.tanggal_selesai
                   FROM izin_requests ir
                   JOIN users s ON s.id = ir.user_id AND s.role = 'siswa'
                  WHERE ir.status = 'disetujui'
                    AND ir.tanggal_mulai <= ?
                    AND ir.tanggal_selesai >= ?";
        $args = [$sampai, $dari];
        if ($kelasId !== null) {
            $sql .= ' AND s.kelas_id = ?';
            $args[] = $kelasId;
        }
        $baris = db_all($sql, $args);
    } catch (Throwable $e) {
        return [];
    }

    /* Batasi perulangan tanggal supaya rentang yang salah ketik (misalnya
     * tahun 0000) tidak menggantung. */
    if ((strtotime($sampai) - strtotime($dari)) > 366 * 3 * 86400) {
        $sampai = date('Y-m-d', strtotime($dari . ' +3 years'));
    }

    $pasangan = [];
    foreach ($baris as $b) {
        $mulai = max($dari, (string) $b['tanggal_mulai']);
        $akhir = min($sampai, (string) $b['tanggal_selesai']);
        if ($akhir < $mulai) {
            continue;
        }
        $uid = (int) $b['user_id'];
        for ($d = $mulai; $d <= $akhir; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ((int) date('N', strtotime($d)) <= 5) {
                $pasangan[$uid . '|' . $d] = ['user_id' => $uid, 'tanggal' => $d];
            }
        }
    }
    return array_values($pasangan);
}

/**
 * Jumlah siswa berizin pada satu tanggal.
 *
 * Yang dihitung hanya izin yang benar-benar dipakai: siswa yang izinnya
 * disetujui TETAPI tetap datang memindai dihitung sebagai hadir, bukan
 * sebagai izin. Kalau tidak, satu siswa masuk hitungan dua kali — sekali
 * di `hadir`, sekali di `izin` — sehingga `belum` jadi terlalu kecil dan
 * kelas bisa terlihat "sempurna" padahal ada yang tidak datang.
 *
 * Pengajuan yang masih menunggu tidak dihitung: selama belum disetujui,
 * siswanya masih dianggap bertanggung jawab atas kehadirannya.
 */
function admin_jumlah_izin_disetujui(string $tanggal, ?int $kelasId = null): int
{
    $nz = (int) date('N', strtotime($tanggal));
    if ($nz > 5) {
        return 0;
    }
    $hadir = admin_user_hadir_masuk($tanggal, $kelasId);
    $ids = [];
    foreach (admin_pasangan_izin($tanggal, $tanggal, $kelasId) as $p) {
        $uid = (int) $p['user_id'];
        if (!isset($hadir[$uid])) {
            $ids[$uid] = true;
        }
    }
    return count($ids);
}

/**
 * ID siswa yang punya scan masuk pada satu tanggal.
 *
 * Disimpan sebagai map supaya "¿sudah datang?" cukup satu isset, bukan satu
 * query per siswa.
 *
 * @return array<int, true>
 */
function admin_user_hadir_masuk(string $tanggal, ?int $kelasId = null): array
{
    $sql = "SELECT DISTINCT a.user_id
              FROM attendance a
              JOIN users s ON s.id = a.user_id AND s.role = 'siswa'
             WHERE a.tanggal = ? AND a.scan_type = 'masuk'";
    $args = [$tanggal];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $out = [];
    foreach (db_all($sql, $args) as $b) {
        $out[(int) $b['user_id']] = true;
    }
    return $out;
}

/**
 * Jumlah siswa berizin per kelas pada satu tanggal.
 *
 * Sama seperti admin_jumlah_izin_disetujui(), siswa yang meski berizin tetap
 * memindai tidak ikut dihitung. Yang dikembalikan di sini langsung dipakai
 * sebagai pengurang `belum`, jadi harus bebas tumpang tindih dengan `hadir`.
 *
 * @return array<int, int> peta kelas_id => jumlah
 */
function admin_jumlah_izin_per_kelas(string $tanggal, array $kelasIds): array
{
    if ($kelasIds === [] || !admin_izin_tabel_ada()) {
        return [];
    }
    $nz = (int) date('N', strtotime($tanggal));
    if ($nz > 5) {
        return [];
    }

    $hadir = admin_user_hadir_masuk($tanggal);
    $tanda = implode(',', array_fill(0, count($kelasIds), '?'));
    $args  = array_merge([$tanggal, $tanggal], $kelasIds);
    $kelasSiswa = [];
    try {
        $baris = db_all(
            "SELECT DISTINCT s.kelas_id, s.id
               FROM izin_requests ir
               JOIN users s ON s.id = ir.user_id AND s.role = 'siswa'
              WHERE ir.status = 'disetujui'
                AND ir.tanggal_mulai <= ? AND ir.tanggal_selesai >= ?
                AND s.kelas_id IN ($tanda)",
            $args
        );
        foreach ($baris as $b) {
            $kelasSiswa[(int) $b['kelas_id']][(int) $b['id']] = true;
        }
    } catch (Throwable $e) {
        return [];
    }

    $hasil = [];
    foreach ($kelasSiswa as $kid => $siswa) {
        $jumlah = 0;
        foreach ($siswa as $uid => $_) {
            if (!isset($hadir[$uid])) {
                $jumlah++;
            }
        }
        $hasil[$kid] = $jumlah;
    }
    return $hasil;
}

/**
 * Deret kehadiran N hari terakhir, termasuk hari yang tidak ada data
 * (akhir pekan, hari libur). Tanggal disusun di PHP supaya grafik tidak
 * punya hari yang hilang — hari kosong harus terlihat sebagai kosong,
 * bukan tidak ada sama sekali.
 */
function admin_tren_kehadiran(int $hari, ?int $kelasId = null): array
{
    $hari = max(1, min(90, $hari));
    $dari = date('Y-m-d', strtotime('-' . ($hari - 1) . ' day'));

    $sql = "SELECT a.tanggal,
                   COUNT(DISTINCT CASE WHEN a.scan_type = 'masuk' THEN a.user_id END) AS hadir,
                   COUNT(DISTINCT CASE WHEN a.scan_type = 'masuk' AND a.is_late = 1 THEN a.user_id END) AS telat,
                   COUNT(DISTINCT CASE WHEN a.scan_type = 'pulang' THEN a.user_id END) AS pulang
              FROM attendance a
              JOIN users s ON s.id = a.user_id AND s.role = 'siswa'
             WHERE a.tanggal >= ?";
    $args = [$dari];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $sql .= ' GROUP BY a.tanggal';

    $peta = [];
    foreach (db_all($sql, $args) as $b) {
        $peta[(string) $b['tanggal']] = $b;
    }

    $total = admin_total_siswa_aktif($kelasId);
    $out   = [];
    for ($i = $hari - 1; $i >= 0; $i--) {
        $tgl = date('Y-m-d', strtotime("-$i day"));
        $b   = $peta[$tgl] ?? null;
        $hadir = (int) ($b['hadir']  ?? 0);
        $telat = (int) ($b['telat']  ?? 0);
        $pulang = (int) ($b['pulang'] ?? 0);
        $out[] = [
            'tanggal' => $tgl,
            'hari'    => (int) date('N', strtotime($tgl)),
            'hadir'   => $hadir,
            'tepat'   => max(0, $hadir - $telat),
            'telat'   => $telat,
            'pulang'  => $pulang,
            'persen'  => $total > 0 ? (int) round(($hadir / $total) * 100) : 0,
            'is_libur' => (int) date('N', strtotime($tgl)) > 5,
        ];
    }
    return $out;
}

/** Rekap kehadiran per kelas untuk satu tanggal. */
function admin_rekap_per_kelas(string $tanggal, ?string $tahunAjaran = null): array
{
    $kelas = admin_daftar_kelas(false, $tahunAjaran);
    if ($kelas === []) {
        return [];
    }
    $ids  = array_map(static fn($k) => (int) $k['id'], $kelas);
    $tanda = implode(',', array_fill(0, count($ids), '?'));

    $hadirPerKelas = [];
    foreach (db_all(
        "SELECT s.kelas_id,
                COUNT(DISTINCT CASE WHEN a.scan_type = 'masuk' THEN a.user_id END) AS hadir,
                COUNT(DISTINCT CASE WHEN a.scan_type = 'masuk' AND a.is_late = 1 THEN a.user_id END) AS telat,
                COUNT(DISTINCT CASE WHEN a.scan_type = 'pulang' THEN a.user_id END) AS pulang
           FROM attendance a
           JOIN users s ON s.id = a.user_id AND s.role = 'siswa'
          WHERE a.tanggal = ? AND s.kelas_id IN ($tanda)
          GROUP BY s.kelas_id",
        array_merge([$tanggal], $ids)
    ) as $b) {
        $hadirPerKelas[(int) $b['kelas_id']] = $b;
    }

    $jumlahSiswa = admin_jumlah_siswa_per_kelas($ids);
    $izinPerKelas = admin_jumlah_izin_per_kelas($tanggal, $ids);

    $out = [];
    foreach ($kelas as $k) {
        $id     = (int) $k['id'];
        $siswa  = (int) ($jumlahSiswa[$id] ?? 0);
        $b      = $hadirPerKelas[$id] ?? null;
        $hadir  = (int) ($b['hadir']  ?? 0);
        $telat  = (int) ($b['telat']  ?? 0);
        $pulang = (int) ($b['pulang'] ?? 0);
        /* Izin yang disetujui dikeluarkan dari "belum", sama seperti
         * rekap_bulan() di sisi siswa. Tanpa ini siswa yang sakit dan sudah
         * disetujui akan tetap terlihat sebagai tidak absen. */
        $izin   = (int) ($izinPerKelas[$id] ?? 0);
        $belum  = max(0, $siswa - $hadir - $izin);
        $out[] = [
            'id'      => $id,
            'nama'    => (string) $k['nama_kelas'],
            'tingkat' => (string) $k['tingkat'],
            'jurusan' => (string) ($k['jurusan'] ?? ''),
            'aktif'   => (int) $k['is_active'] === 1,
            'siswa'   => $siswa,
            'hadir'   => $hadir,
            'tepat'   => max(0, $hadir - $telat),
            'telat'   => $telat,
            'pulang'  => $pulang,
            'izin'    => $izin,
            'belum'   => $belum,
            'persen'  => $siswa > 0 ? (int) round(($hadir / $siswa) * 100) : 0,
        ];
    }
    return $out;
}

/** Absensi terakhir seluruh sekolah, untuk feed dashboard. */
function admin_absensi_terakhir(int $batas = 8, ?int $kelasId = null): array
{
    $sql = "SELECT a.id, a.user_id, a.tanggal, a.waktu, a.scan_type, a.status, a.is_late,
                   a.late_minutes, a.gps_verdict, a.distance_m, a.face_distance, a.face_confidence,
                   s.nama_lengkap, s.username, k.nama_kelas
              FROM attendance a
              JOIN users s ON s.id = a.user_id
         LEFT JOIN kelas k ON k.id = s.kelas_id
             WHERE s.role = 'siswa'";
    $args = [];
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $batas;
    return db_all($sql, $args);
}

/** Siswa aktif yang belum punya satupun template wajah. */
function admin_siswa_belum_wajah(int $batas = 6): array
{
    return db_all(
        "SELECT s.id, s.nama_lengkap, s.username, k.nama_kelas
           FROM users s
      LEFT JOIN kelas k ON k.id = s.kelas_id
          WHERE s.role = 'siswa' AND s.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM face_templates f WHERE f.user_id = s.id)
       ORDER BY s.nama_lengkap ASC
          LIMIT " . (int) $batas
    );
}

/* ==================================================================
 *  FACE ID
 * ================================================================== */

/** Angka kualitas pendaftaran wajah seluruh siswa. */
function admin_statistik_wajah(): array
{
    $jumlahSiswa = admin_total_siswa_aktif();

    $terdaftar = (int) db_value(
        "SELECT COUNT(DISTINCT user_id) FROM face_templates",
        [],
        0
    );

    /* Template dari akun yang sudah tidak aktif tidak dihitung sebagai
     * wajah aktif, supaya angka dashboard tidak lebih besar dari jumlah
     * siswa yang benar-benar bisa absen. */
    $aktif = (int) db_value(
        "SELECT COUNT(DISTINCT f.user_id)
           FROM face_templates f
           JOIN users s ON s.id = f.user_id
          WHERE s.role = 'siswa' AND s.is_active = 1",
        [],
        0
    );

    $rataMutu = db_value(
        "SELECT AVG(quality) FROM face_templates WHERE is_centroid = 1",
        [],
        null
    );

    return [
        'siswa'    => $jumlahSiswa,
        'terdaftar' => $aktif,
        'belum'    => max(0, $jumlahSiswa - $aktif),
        'persen'   => $jumlahSiswa > 0 ? (int) round(($aktif / $jumlahSiswa) * 100) : 0,
        'mutu'     => $rataMutu === null ? null : round((float) $rataMutu, 3),
    ];
}

/**
 * Daftar siswa dengan status pendaftaran wajahnya.
 * $cariMemakai WHERE pada subquery, bukan LEFT JOIN + HAVING, supaya
 * indeks face_templates(user_id) tetap terpakai.
 */
function admin_daftar_wajah(
    string $cari,
    string $filter = 'semua',
    int $batas = 0,
    int $offset = 0
): array {
    $sql = "SELECT s.id, s.nama_lengkap, s.username, s.is_active, k.nama_kelas,
                   f.created_at AS sejak, f.quality, f.is_centroid, f.descriptor IS NOT NULL AS punya,
                   (SELECT COUNT(*) FROM face_templates f2 WHERE f2.user_id = s.id) AS jumlah_sampel
              FROM users s
         LEFT JOIN kelas k ON k.id = s.kelas_id
         LEFT JOIN face_templates f ON f.user_id = s.id AND f.is_centroid = 1
             WHERE s.role = 'siswa' AND s.is_active = 1";

    if ($filter === 'terdaftar') {
        $sql .= ' AND EXISTS (SELECT 1 FROM face_templates fx WHERE fx.user_id = s.id)';
    } elseif ($filter === 'belum') {
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM face_templates fx WHERE fx.user_id = s.id)';
    }

    $args = [];
    if ($cari !== '') {
        $sql .= ' AND (s.nama_lengkap LIKE ? OR s.username LIKE ? OR s.nis LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle);
    }

    $sql .= ' ORDER BY s.nama_lengkap ASC';
    if ($batas > 0) {
        $sql .= ' LIMIT ' . (int) $batas . ' OFFSET ' . (int) $offset;
    }
    return db_all($sql, $args);
}

function admin_jumlah_daftar_wajah(string $cari, string $filter = 'semua'): int
{
    $sql = "SELECT COUNT(*) FROM users s
             LEFT JOIN face_templates f ON f.user_id = s.id AND f.is_centroid = 1
             WHERE s.role = 'siswa' AND s.is_active = 1";
    if ($filter === 'terdaftar') {
        $sql .= ' AND EXISTS (SELECT 1 FROM face_templates fx WHERE fx.user_id = s.id)';
    } elseif ($filter === 'belum') {
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM face_templates fx WHERE fx.user_id = s.id)';
    }
    $args = [];
    if ($cari !== '') {
        $sql .= ' AND (s.nama_lengkap LIKE ? OR s.username LIKE ? OR s.nis LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle);
    }
    return (int) db_value($sql, $args, 0);
}

/* ==================================================================
 *  IZIN
 * ================================================================== */

/** Daftar pengajuan izin dengan filter. */
function admin_daftar_izin(
    string $status = 'semua',
    ?int $kelasId = null,
    string $cari = '',
    int $batas = 0,
    int $offset = 0
): array {
    $sql = "SELECT ir.id, ir.user_id, ir.jenis, ir.tanggal_mulai, ir.tanggal_selesai,
                   ir.alasan, ir.status, ir.catatan_petugas, ir.created_at,
                   s.nama_lengkap, s.username, s.nis, k.nama_kelas,
                   p.nama_lengkap AS petugas
              FROM izin_requests ir
              JOIN users s ON s.id = ir.user_id
         LEFT JOIN kelas k ON k.id = s.kelas_id
         LEFT JOIN users p ON p.id = ir.diproses_oleh
             WHERE 1 = 1";
    $args = [];

    if ($status !== 'semua' && $status !== '') {
        $sql .= ' AND ir.status = ?';
        $args[] = $status;
    }
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    if ($cari !== '') {
        $sql .= ' AND (s.nama_lengkap LIKE ? OR s.username LIKE ? OR s.nis LIKE ? OR ir.alasan LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle, $needle);
    }

    /* Antrean kerja didorong ke atas supaya permintaan yang masih
     * menunggu selalu terlihat, lalu diurutkan dari yang paling lama
     * menunggu: yang paling lama justru paling perlu ditangani
     * lebih dulu.
     *
     * "Paling lama" di sini berarti tanggal_mulai terkecil, bukan
     * terbesar. Urutan DESC di bawah hanya dipakai untuk breakup
     * saat tanggal_mulai sama, supaya hasil tidak bergantung pada
     * urutan baris acak SQLite. */
    $sql .= " ORDER BY CASE WHEN ir.status = 'menunggu' THEN 0 ELSE 1 END ASC,"
          . ' ir.tanggal_mulai ASC, ir.id ASC';

    if ($batas > 0) {
        $sql .= ' LIMIT ' . (int) $batas . ' OFFSET ' . (int) $offset;
    }
    return db_all($sql, $args);
}

function admin_jumlah_izin(string $status = 'semua', ?int $kelasId = null, string $cari = ''): int
{
    $sql = "SELECT COUNT(*) FROM izin_requests ir JOIN users s ON s.id = ir.user_id WHERE 1 = 1";
    $args = [];
    if ($status !== 'semua' && $status !== '') {
        $sql .= ' AND ir.status = ?';
        $args[] = $status;
    }
    if ($kelasId !== null) {
        $sql .= ' AND s.kelas_id = ?';
        $args[] = $kelasId;
    }
    if ($cari !== '') {
        $sql .= ' AND (s.nama_lengkap LIKE ? OR s.username LIKE ? OR s.nis LIKE ? OR ir.alasan LIKE ?)';
        $needle = '%' . $cari . '%';
        array_push($args, $needle, $needle, $needle, $needle);
    }
    return (int) db_value($sql, $args, 0);
}

/** Jumlah pengajuan per status, untuk tab. */
function admin_izin_per_status(?int $kelasId = null): array
{
    $sql = "SELECT ir.status, COUNT(*) AS n
              FROM izin_requests ir
              JOIN users s ON s.id = ir.user_id";
    $args = [];
    if ($kelasId !== null) {
        $sql .= ' WHERE s.kelas_id = ?';
        $args[] = $kelasId;
    }
    $sql .= ' GROUP BY ir.status';

    $hasil = ['menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0, 'batal' => 0];
    foreach (db_all($sql, $args) as $b) {
        $hasil[(string) $b['status']] = (int) $b['n'];
    }
    return $hasil;
}

/* ==================================================================
 *  PENGATURAN
 * ================================================================== */

/**
 * Ubah teks koordinat menjadi float, atau null kalau tidak bisa dipercaya.
 *
 * filter_var dengan FILTER_VALIDATE_FLOAT seemed cukup, tapi ia menerima
 * "1e5" dan beberapa bentuk eksponen lain. Untuk koordinat sekolah itu
 * hanya sumber input salah ketik, jadi lebih baik ditolak di sini.
 *
 * Koma dianggap pemisah desimal karena itu yang diketik orang Indonesia
 * memakai keyboard lokal. Satu angka yang ditolak gara-gara koma adalah
 * koordinat yang gagal disimpan tanpa alasan yang jelas.
 */
function parse_koordinat($teks): ?float
{
    $s = str_replace(' ', '', trim((string) $teks));
    $s = str_replace(',', '.', $s);
    if ($s === '' || !preg_match('/^-?\d{1,3}(\.\d+)?$/', $s)) {
        return null;
    }
    return (float) $s;
}

/**
 * Semua nilai tabel pengaturan sebagai asosiasi. */
function admin_semua_pengaturan(): array
{
    $hasil = [];
    foreach (db_all('SELECT key_name, value FROM pengaturan ORDER BY key_name') as $b) {
        $hasil[(string) $b['key_name']] = (string) ($b['value'] ?? '');
    }
    return $hasil;
}

/**
 * Lokasi sekolah yang aktif, untuk ditampilkan di pengaturan.
 *
 * Baris yang dipakai runtime adalah yang pertama aktif menurut id
 * (lihat api/v1/attendance/scan-nonce.php dan scan-check.php), jadi
 * form pengaturan harus menyasar baris yang sama.
 */
function admin_lokasi_aktif(): ?array
{
    try {
        return db_one(
            'SELECT id, nama, lat, lng, radius_m, is_active
               FROM lokasi_sekolah
              WHERE is_active = 1
           ORDER BY id ASC LIMIT 1',
            [],
            null
        );
    } catch (Throwable $e) {
        error_log('[ADMIN] Gagal membaca lokasi_sekolah: ' . $e->getMessage());
        return null;
    }
}

/**
 * Semua baris lokasi aktif.
 *
 * Dipakai untuk memberi tahu admin kalau ada lebih dari satu patokan
 * aktif: runtime hanya memakai yang pertama, jadi patokan lain diam-diam
 * menganggur dan itu sumber kebingungan yang sulit ditebak.
 */
function admin_lokasi_aktif_semua(): array
{
    try {
        return db_all(
            'SELECT id, nama, lat, lng, radius_m
               FROM lokasi_sekolah
              WHERE is_active = 1
           ORDER BY id ASC'
        );
    } catch (Throwable $e) {
        error_log('[ADMIN] Gagal membaca lokasi_sekolah: ' . $e->getMessage());
        return [];
    }
}

/* ==================================================================
 *  AKTIVITAS
 * ================================================================== */

/** Jejak audit terbaru untuk panel admin. */
function admin_audit_terbaru(int $batas = 6): array
{
    return db_all(
        'SELECT a.id, a.event_type, a.severity, a.details, a.created_at, a.user_id,
                u.nama_lengkap, u.username, a.ip_address
           FROM audit_log a
      LEFT JOIN users u ON u.id = a.user_id
       ORDER BY a.id DESC
          LIMIT ' . (int) $batas
    );
}

/** Jumlah aktivitas penting dalam 24 jam terakhir, untuk kartu peringatan. */
function admin_aktivitas_24jam(): array
{
    $sejak = date('Y-m-d H:i:s', strtotime('-24 hours'));
    try {
        $peringatan = (int) db_value(
            "SELECT COUNT(*) FROM audit_log WHERE severity IN ('warning', 'error') AND created_at >= ?",
            [$sejak],
            0
        );
    } catch (Throwable $e) {
        $peringatan = 0;
    }
    return ['peringatan' => $peringatan];
}
