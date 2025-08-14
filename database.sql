CREATE TABLE IF NOT EXISTS spp (
    id_spp INT NOT NULL AUTO_INCREMENT,
    tahun VARCHAR(10) NOT NULL,
    nominal INT NOT NULL,
    PRIMARY KEY (id_spp)
);

CREATE TABLE IF NOT EXISTS kelas (
    id_kelas INT NOT NULL AUTO_INCREMENT,
    nama_kelas VARCHAR(50) NOT NULL,
    kompetensi_keahlian VARCHAR(50),
    PRIMARY KEY (id_kelas)
);

CREATE TABLE IF NOT EXISTS users (
    id_user INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    level ENUM('Admin','Petugas','Siswa') DEFAULT 'Siswa',
    gambar VARCHAR(100),
    remember_token VARCHAR(125),
    PRIMARY KEY (id_user)
);

CREATE TABLE IF NOT EXISTS petugas (
    id_petugas INT NOT NULL AUTO_INCREMENT,
    id_user INT NOT NULL,
    nama_petugas VARCHAR(50) NOT NULL,
    no_hp_petugas VARCHAR(15),
    alamat_petugas TEXT,
    PRIMARY KEY (id_petugas),
    FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS santri (
    id_santri INT NOT NULL AUTO_INCREMENT,
    id_user INT NOT NULL,
    id_spp INT NOT NULL,
    id_kelas INT NOT NULL,
    nisn VARCHAR(10) UNIQUE,
    nis VARCHAR(8) UNIQUE,
    nama_santri VARCHAR(50) NOT NULL,
    alamat TEXT,
    no_telepon VARCHAR(15),
    PRIMARY KEY (id_santri),
    FOREIGN KEY (id_kelas) REFERENCES kelas(id_kelas) ON DELETE CASCADE,
    FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
    FOREIGN KEY (id_spp) REFERENCES spp(id_spp) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pembayaran (
    id_pembayaran INT NOT NULL AUTO_INCREMENT,
    id_santri INT NOT NULL,
    id_petugas INT NOT NULL,
    id_spp INT NOT NULL,
    tgl_bayar DATE,
    bulan_bayar VARCHAR(20),
    tahun_bayar YEAR,
    jumlah DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id_pembayaran),
    FOREIGN KEY (id_santri) REFERENCES santri(id_santri) ON DELETE CASCADE,
    FOREIGN KEY (id_petugas) REFERENCES petugas(id_petugas) ON DELETE CASCADE,
    FOREIGN KEY (id_spp) REFERENCES spp(id_spp) ON DELETE CASCADE
);