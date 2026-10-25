-- ===========================================================================
--  database.sql  -  Skema Sistem Presensi Sekolah
--
--  Kompatibilitas: MySQL 5.7+ / MariaDB 10.3+ (InfinityFree free hosting)
--
--  BATAS INFINITYFREE YANG MENGIKAT SKEMA INI
--  -------------------------------------------
--  Hak akses SQL pada free hosting hanya:
--    SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, LOCK TABLES
--
--  Karena itu skema ini SENGAJA tidak memakai:
--    - FOREIGN KEY       (tidak didukung MyISAM di free hosting)
--    - TRIGGER           (tidak diizinkan)
--    - STORED PROCEDURE  (tidak diizinkan)
--    - TEMPORARY TABLE   (dibatasi)
--
--  Relasi antar tabel dijaga di level aplikasi (lihat config/helper.php).
--  Semua kolom relasi tetap diberi INDEX agar query tetap cepat.
--
--  Import: phpMyAdmin -> tab Import -> pilih file ini -> Go
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- ---------------------------------------------------------------------------
--  users  -  Satu tabel untuk admin, guru, dan siswa (dibatasi kolom role)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(50)  NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `nama_lengkap`   VARCHAR(100) NOT NULL,
  `role`           ENUM('admin','guru','siswa') NOT NULL DEFAULT 'siswa',
  `nis`            VARCHAR(20)  DEFAULT NULL,
  `nisn`           VARCHAR(20)  DEFAULT NULL,
  `kelas_id`       INT UNSIGNED DEFAULT NULL,
  `jenis_kelamin`  ENUM('L','P') DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `login_attempts` TINYINT(3)   NOT NULL DEFAULT 0,
  `locked_until`   DATETIME     DEFAULT NULL,
  `last_login`     DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `ix_users_role` (`role`),
  KEY `ix_users_kelas` (`kelas_id`),
  KEY `ix_users_nis` (`nis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  kelas  -  Master data kelas / jurusan
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelas` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama_kelas`   VARCHAR(50)  NOT NULL,
  `tingkat`      VARCHAR(10)  NOT NULL,
  `jurusan`      VARCHAR(50)  DEFAULT NULL,
  `tahun_ajaran` VARCHAR(15)  NOT NULL,
  `wali_kelas`   INT UNSIGNED DEFAULT NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_kelas_nama` (`nama_kelas`),
  KEY `ix_kelas_tahun` (`tahun_ajaran`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  face_templates  -  "Face ID" siswa (TANPA menyimpan foto)
--
--  INI BAGIAN PENTING. Yang disimpan hanya vektor 128 float (512 byte),
--  bukan gambar. Vektor ini irreversible: tidak bisa dikembalikan menjadi
--  foto wajah, sehingga risiko penyalahgunaan data biometrik jauh lebih kecil.
--
--  Bentuk biner (BLOB), bukan JSON teks:
--    1200 siswa x 512 byte  = 614 KB   (BLOB, dipakai)
--    1200 siswa x ~1,5 KB   = 1,8 MB  (JSON teks, JANGAN dipakai)
--
--  Urutan byte harus persis float32 little-endian, sama seperti
--  Float32Array di JavaScript. Gunakan pack_descriptor() / unpack_descriptors()
--  di config/helper.php - jangan baca/tulis BLOB secara manual.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `face_templates` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `descriptor`  BLOB         NOT NULL COMMENT '128 float32 = 512 byte',
  `is_centroid` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = rata-rata sampel, dipakai untuk matching',
  `sample_no`   TINYINT(3)   NOT NULL DEFAULT 0,
  `quality`     DECIMAL(5,4) NOT NULL DEFAULT 0 COMMENT 'keyakinan saat pendaftaran 0..1',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_face_user` (`user_id`),
  KEY `ix_face_centroid` (`is_centroid`),
  KEY `ix_face_user_centroid` (`user_id`, `is_centroid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  lokasi_sekolah  -  Titik GPS sekolah untuk validasi radius
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lokasi_sekolah` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`       VARCHAR(100) NOT NULL,
  `lat`        DECIMAL(10,7) NOT NULL,
  `lng`        DECIMAL(10,7) NOT NULL,
  `radius_m`   INT UNSIGNED NOT NULL DEFAULT 100,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_lokasi_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  attendance  -  Rekap absensi
--
--  Estimasi ukuran: 1200 siswa x 2 scan/hari x 200 hari = 480.000 baris/tahun
--  Sekitar 100 byte per baris -> 48 MB/tahun. Karena itu database produksi
--  harus MySQL (400 MB), atau data perlu diarsipkan berkala; SQLite limited 10 MB.
--
--  UNIQUE (user_id, tanggal, scan_type) mencegah absen ganda untuk
--  tipe yang sama pada tanggal yang sama - ini yang menahan tombol refresh.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `tanggal`         DATE          NOT NULL,
  `scan_type`       ENUM('masuk','pulang') NOT NULL DEFAULT 'masuk',
  `waktu`           DATETIME      NOT NULL,
  `status`          ENUM('hadir','izin','sakit','alpa') NOT NULL DEFAULT 'hadir',

  -- Hasil verifikasi lokasi (dihitung ulang di server, bukan dari browser)
  `lat`             DECIMAL(10,7) DEFAULT NULL,
  `lng`             DECIMAL(10,7) DEFAULT NULL,
  `accuracy_m`      DECIMAL(8,2)  DEFAULT NULL,
  `distance_m`    DECIMAL(8,1)  DEFAULT NULL,
  `gps_verdict`   ENUM('DITERIMA','PERLU_DITINJAU','DITOLAK') NOT NULL DEFAULT 'DITOLAK',
  `gps_score`     SMALLINT      NOT NULL DEFAULT 0,

  -- Keterlambatan, dihitung dari jam server
  `is_late`       TINYINT(1)    NOT NULL DEFAULT 0,
  `late_minutes`  SMALLINT UNSIGNED DEFAULT NULL,

  -- Hasil verifikasi wajah
  `face_distance`   DECIMAL(5,4)  DEFAULT NULL,
  `face_confidence` DECIMAL(5,4)  DEFAULT NULL,
  `liveness_codes`  VARCHAR(191)  DEFAULT NULL COMMENT 'challenge yang terpenuhi',

  -- Sidik perangkat, membantu mendeteksi emulator dan browser yang
  -- mencoba memalsukan Environment
  `device_fingerprint` VARCHAR(64) DEFAULT NULL,

  `ip_address`      VARCHAR(45)   DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_absen` (`user_id`, `tanggal`, `scan_type`),
  KEY `ix_absen_tanggal` (`tanggal`),
  KEY `ix_absen_status` (`tanggal`, `status`),
  KEY `ix_absen_kelas_tanggal` (`tanggal`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  scan_nonces  -  Anti-replay
--
--  Setiap token scan hanya boleh dipakai sekali. Tahan serangan kirim ulang
--  payload absen yang sudah pernah berhasil.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scan_nonces` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nonce`    VARCHAR(64)  NOT NULL,
  `user_id`  INT UNSIGNED NOT NULL,
  `used_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nonce` (`nonce`),
  KEY `ix_nonce_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  audit_log  -  Catatan percobaan kecurangan
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `event_type` VARCHAR(50)  NOT NULL COMMENT 'FAKE_GPS, PHOTO_ATTACK, OUT_OF_RADIUS, ...',
  `severity`   ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  `details`    TEXT         DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_user` (`user_id`),
  KEY `ix_audit_event` (`event_type`),
  KEY `ix_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  pengaturan  -  Konfigurasi runtime yang bisa diubah dari dashboard
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengaturan` (
  `key_name`   VARCHAR(50)  NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  Device fingerprints  -  Deteksi akun yang berpindah perangkat
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `device_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `fingerprint` VARCHAR(64) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `seen_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device` (`user_id`, `fingerprint`),
  KEY `ix_device_fp` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  izin_requests  -  Pengajuan izin / sakit oleh siswa
--
--  Sengaja dipisah dari `attendance`. Tabel attendance berisi hasil scan,
--  yaitu bukti kehadiran yang tidak bisa diganggu gugat. Tabel ini berisi PERMOHONAN yang
--  belum tentu disetujui, jadi tidak boleh ikut memengaruhi rekap kehadiran
--  sebelum petugas memutuskan.
--
--  Status: menunggu -> disetujui | ditolak
--  Yang boleh mengubah status hanya sisi petugas, bukan siswa.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `izin_requests` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `jenis`           ENUM('izin','sakit') NOT NULL DEFAULT 'izin',
  `tanggal_mulai`   DATE          NOT NULL,
  `tanggal_selesai` DATE          NOT NULL,
  `alasan`          VARCHAR(500)  DEFAULT NULL,
  `status`          ENUM('menunggu','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
  `catatan_petugas` VARCHAR(500)  DEFAULT NULL,
  `diproses_oleh`   INT UNSIGNED  DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_izin_user`   (`user_id`),
  KEY `ix_izin_status` (`status`),
  KEY `ix_izin_periode` (`tanggal_mulai`, `tanggal_selesai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  laporan_aman  -  Laporan siswa: perundungan, keluarga, tekanan emosi
--
--  user_id boleh NULL: laporan anonim sengaja tidak menautkan ke akun mana
--  pun. Tabel ini TIDAK menyimpan IP maupun user agent, karena satu jejak
--  saja cukup untuk membongkar identitas pelapor anonim.
--
--  Urgensi adalah PERKIRAAN AWAL (pilihan siswa digabung deteksi kata
--  kunci), bukan penilaian ahli. Keputusan tetap di guru BK.
--
--  Status: baru -> diproses -> selesai
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `laporan_aman` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode_akses`     VARCHAR(12)  NOT NULL,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `anonim`         TINYINT(1)   NOT NULL DEFAULT 0,
  `kategori`       ENUM('perundungan','keluarga','kesehatan','keselamatan','lainnya')
                   NOT NULL DEFAULT 'lainnya',
  `urgensi`        ENUM('biasa','perhatian','darurat') NOT NULL DEFAULT 'biasa',
  `isi`            TEXT         NOT NULL,
  `status`         ENUM('baru','diproses','selesai') NOT NULL DEFAULT 'baru',
  `catatan_bk`     VARCHAR(500) DEFAULT NULL,
  `ditangani_oleh` INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ditangani_at`   DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_laporan_kode` (`kode_akses`),
  KEY `ix_laporan_status`  (`status`),
  KEY `ix_laporan_urgensi` (`urgensi`),
  KEY `ix_laporan_user`    (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  face_requests  -  Permintaan pendaftaran ulang wajah
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `face_requests` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `alasan`       VARCHAR(300) DEFAULT NULL,
  `status`       ENUM('menunggu','selesai','batal') NOT NULL DEFAULT 'menunggu',
  `diminta_oleh` INT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `selesai_at`   DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wajah_req_user` (`user_id`),
  KEY `ix_wajah_req_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  Data awal
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `lokasi_sekolah` (`nama`, `lat`, `lng`, `radius_m`) VALUES
  ('Gerbang Utama', -6.2000000, 106.8166660, 100);

INSERT IGNORE INTO `pengaturan` (`key_name`, `value`) VALUES
  ('nama_sekolah',  'SMA Negeri Contoh'),
  ('face_threshold', '0.55'),
  ('gps_radius_m',  '100'),
  ('allow_offline', '0');

SET FOREIGN_KEY_CHECKS = 1;
