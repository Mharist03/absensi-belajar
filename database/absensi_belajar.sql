CREATE DATABASE IF NOT EXISTS absensi_belajar
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE absensi_belajar;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'guru',
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_nama (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sistem ini hanya dapat diakses oleh GURU.
-- Akun awal: Guru / GantiPassword123!
INSERT INTO users (nama, password_hash, role, aktif)
SELECT 'Guru', '$2y$12$W7pnMS..FHS7DleT5Xvq7u070QbvigvC0SgJ3xybNcJcLOCoOCsI6', 'guru', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE nama = 'Guru');

-- Jika sebelumnya pernah ada akun Admin, ubah menjadi role guru agar
-- database lama tetap kompatibel dengan sistem guru-only.
UPDATE users SET role = 'guru';

CREATE TABLE IF NOT EXISTS app_state (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    state_key VARCHAR(50) NOT NULL,
    data_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_state_key (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_state (state_key, data_json)
SELECT 'global', '{"user":null,"kelas":[{"id":"paud","nama":"PAUD","wali":"Ibu Sari"},{"id":"sda","nama":"SD A","wali":"Bapak Andi"},{"id":"sdb","nama":"SD B","wali":"Ibu Rina"},{"id":"praremaja","nama":"Pra Remaja","wali":"Bapak Joko"},{"id":"mudamudi","nama":"Muda-Mudi","wali":"Ibu Dewi"}],"siswa":[],"absensi":{},"materi":[],"tugas":[],"catatan":[],"aktivitas":[]}'
WHERE NOT EXISTS (SELECT 1 FROM app_state WHERE state_key = 'global');
