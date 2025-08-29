-- Application schema for Saku Santri (aligns with current PHP code)
CREATE DATABASE IF NOT EXISTS db_saku_santri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_saku_santri;

-- Users table used by the app
CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT,
  nama_wali VARCHAR(100) NOT NULL,
  nama_santri VARCHAR(100) NOT NULL,
  nisn VARCHAR(20) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','wali_santri') NOT NULL DEFAULT 'wali_santri',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_nisn (nisn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transaksi table used by the app
CREATE TABLE IF NOT EXISTS transaksi (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  jenis_transaksi ENUM('spp','uang_saku') NOT NULL,
  deskripsi VARCHAR(255) DEFAULT NULL,
  jumlah DECIMAL(12,2) NOT NULL,
  bukti_pembayaran VARCHAR(255) DEFAULT NULL,
  status ENUM('menunggu_pembayaran','menunggu_konfirmasi','lunas','ditolak') NOT NULL DEFAULT 'menunggu_pembayaran',
  tanggal_upload DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  CONSTRAINT fk_transaksi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log table
CREATE TABLE IF NOT EXISTS audit_log (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NULL,
  detail TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_user (user_id),
  KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications table (missing previously)
CREATE TABLE IF NOT EXISTS notifications (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  type VARCHAR(50) NOT NULL,
  message VARCHAR(255) NOT NULL,
  read_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional maintenance (run via cron / manual):
--   DELETE FROM notifications WHERE (read_at IS NOT NULL AND read_at < NOW() - INTERVAL 90 DAY)
--      OR created_at < NOW() - INTERVAL 180 DAY;

-- Seed admin if none exists (idempotent approach via INSERT IGNORE + temp password)
-- Change the password after first login.
--INSERT INTO users (nama_wali, nama_santri, nisn, password, role)
--SELECT 'Administrator', '-', 'admin', '$2y$10$0iuJm5.1xTgG1mM3v5YlYOGQ7kX3pTgrmEHmG8vGgmq8Nt0kMkPpC', 'admin'
--WHERE NOT EXISTS (SELECT 1 FROM users WHERE role='admin' LIMIT 1);

-- Note: The hashed password above is for plaintext: admin123
