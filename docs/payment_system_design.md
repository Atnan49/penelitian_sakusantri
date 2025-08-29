# Desain Ulang Sistem Pembayaran (v2)

Dokumen ini menjadi blueprint implementasi sistem pembayaran yang lebih terstruktur, dapat diaudit, dan mudah diekstensikan (gateway, wallet, notifikasi, partial payment, dsb).

---
## 1. Tujuan Utama
1. Pisahkan konsep: Tagihan (Invoice) vs Pembayaran (Payment) vs Pencatatan Akuntansi (Ledger).
2. Mendukung: pembayaran manual (upload bukti), top-up dompet (wallet), pemotongan saldo wallet, dan integrasi gateway nanti.
3. Partial payment (cicilan) tetap konsisten: invoice.paid_amount tidak boleh > invoice.amount.
4. Riwayat status (history) lengkap untuk audit (immutable append-only).
5. Idempotensi untuk mencegah duplikasi (idempotency_key pada payment).
6. Overdue logic otomatis (status invoice berubah menjadi overdue ketika lewat due_date & belum lunas).
7. Dapat dipantau: anomaly detection (lebih bayar, mismatch ledger vs invoice), ringkasan aging.

---
## 2. Model Data Inti
(Tabel dasar sudah ada, ditandai ✅)

| Entitas | Tabel | Status | Catatan |
|---------|-------|--------|---------|
| Invoice | `invoice` ✅ | Sudah | Perlu tambah kolom `source` opsional (misal 'spp_bulk') & `meta_json` untuk fleksibilitas. |
| Payment | `payment` ✅ | Sudah | Tambah kolom `proof_file` (sudah via migration 002) dan `meta_json` untuk gateway response. |
| Ledger  | `ledger_entries` ✅ | Sudah | Perlu enforce double-entry secara logis (pasangan debit/kredit) di fase lanjutan. |
| Invoice History | `invoice_history` ✅ | Sudah | OK. |
| Payment History | `payment_history` ✅ | Sudah | OK. |
| User Wallet View | `v_wallet_balance` ✅ | Sudah | Mungkin buat materialized summary periodik jika besar. |

### 2.1 Usulan Tambahan Kolom
```
ALTER TABLE invoice ADD COLUMN source VARCHAR(32) NULL AFTER type;
ALTER TABLE invoice ADD COLUMN meta_json JSON NULL AFTER notes;
ALTER TABLE payment ADD COLUMN meta_json JSON NULL AFTER note;
```

### 2.2 Index Tambahan
```
ALTER TABLE payment ADD INDEX idx_invoice_status (invoice_id, status);
ALTER TABLE invoice ADD INDEX idx_user_status (user_id, status);
```

---
## 3. Status & Transisi
### Invoice Status
`pending` -> `partial` -> `paid` | (opsional) -> `overdue` | terminal: `canceled`

Aturan:
- `partial` jika ada payment settled dan paid_amount < amount.
- `paid` jika paid_amount >= amount (dibulatkan ke 2 desimal / toleransi 0.01).
- `overdue` jika `NOW() > due_date` dan status masih `pending` atau `partial` (dibuat oleh job).
- `canceled` hanya manual admin; tidak boleh ada payment settled setelah canceled (blokir di code).

### Payment Status
`initiated` -> (`awaiting_proof`|`awaiting_gateway`|`awaiting_confirmation`) -> `settled` | `failed` | `reversed`

Simplifikasi saat ini (manual transfer):
- `initiated` langsung transisi ke `awaiting_confirmation` saat user submit form (versi awal) atau ke `awaiting_proof` kalau pakai bukti.
- Upload bukti: `initiated/awaiting_proof` -> `awaiting_confirmation`.
- Admin verifikasi: `awaiting_confirmation` -> `settled` atau `failed`.
- Reversal: `settled` -> `reversed` (membuat ledger balancing reversal + rollback ke invoice jika perlu).

---
## 4. Invarians (Harus Selalu Benar)
1. `invoice.paid_amount <= invoice.amount`.
2. Jumlah pembayaran settled untuk invoice (secara sum) == invoice.paid_amount.
3. Jika invoice.status = paid => paid_amount == amount.
4. Ledger saldo WALLET per user = sum(debit-credit) untuk account WALLET (sinkron dengan view).
5. Payment (method = wallet, settled) harus punya sepasang ledger: Dr AR_SPP / Cr WALLET (invoice payment) atau Dr WALLET / Cr CASH_IN_TRANSIT (top-up) di fase penyederhanaan.
6. Reversal membuat net effect 0 terhadap AR & WALLET dibanding sebelum payment.

---
## 5. Flow Utama
### 5.1 Generate SPP Bulk
Admin pilih periode + nominal -> buat invoice `type='spp'` untuk semua wali yang belum ada invoice periode itu.

### 5.2 Wali Bayar (Manual Transfer – Tahap Saat Ini)
1. Wali buka invoice detail.
2. Input nominal (<= sisa) -> create payment (initiated).
3. Upload bukti -> status `awaiting_confirmation`.
4. Admin: review, set `settled` -> invoice_update (partial/paid). 

### 5.3 Wali Bayar (Wallet – Tahap Mendatang)
1. Wali top-up wallet: payment top-up (invoice_id NULL) -> setelah settled ledger menambah saldo.
2. Wali klik Bayar pakai wallet -> sistem cek saldo >= sisa -> buat payment `method='wallet'` langsung settle atomic (transaksi db) + ledger: DR WALLET CR AR_SPP, update invoice.

### 5.4 Overdue Job
Cron / endpoint manual:
```
UPDATE invoice 
SET status='overdue', updated_at=NOW() 
WHERE status IN ('pending','partial') AND due_date IS NOT NULL AND due_date < CURDATE();
INSERT invoice_history rows untuk setiap row yang berubah.
```

### 5.5 Reversal Payment
- Admin pilih payment settled -> create reversal payment (baru) dengan amount sama tapi status settled yang menambah kebalikan ledger & mengurangi paid_amount invoice.
Atau lebih sederhana: jalankan fungsi atomic: adjust invoice.paid_amount -= amount, insert ledger balancing (DEBIT dan CREDIT kembali), update history payment original ke `reversed`.

---
## 6. Struktur Kode yang Disarankan
```
src/
  includes/
    payments/ (folder)
      invoice_service.php
      payment_service.php
      ledger_service.php
      validation.php
      helpers.php (alias lama untuk kompatibilitas)
```
Sementara bisa tetap `payments.php` tunggal, refactor bertahap.

---
## 7. Fase Implementasi
| Fase | Fokus | Output | Status |
|------|-------|--------|--------|
| P0 | Skema dasar + helper minimal | invoice, payment, ledger, history | DONE sebagian |
| P1 | Invoice list + detail + manual payment + bukti | Halaman admin/wali | DONE (butuh polishing) |
| P2 | Upload bukti hardened + wallet top-up + metode wallet | Kolom secure, saldo realtime | DONE |
| P3 | Overdue job + notifikasi + reversal + anomaly check | Cron/endpoint + UI admin | DONE (notif hooks dasar, reversal, overdue, integrity script) |
| P4 | Gateway integrasi (stub) + webhook + idempotency kuat | payment gateway_* methods | Belum |
| P5 | Refactor modul + tambahan meta_json + test suite | Unit test / script verifikasi | Belum |

---
## 8. Hardening Upload Bukti (Rencana)
- Simpan di `public/uploads/payment_proof/`.
- Gunakan nama file random: sha1(payment_id . microtime) + ext.
- Validasi MIME (finfo) bukan hanya ekstensi.
- Batasi resolusi (jika image) & compress.
- Jangan dapat diakses index listing (tambahkan `.htaccess` jika Apache).

---
## 9. Endpoint / Page Tambahan
| Path | Peran | Deskripsi |
|------|-------|-----------|
| admin/invoice_overdue_run.php | Admin | Trigger manual penandaan overdue. |
| admin/wallet_topup.php | Admin | (Opsional) Tambah saldo manual untuk testing. |
| wali/wallet_topup.php | Wali | Form top-up (create payment top-up). |
| wali/wallet.php | Wali | Lihat riwayat saldo (ledger WALLET). |

---
## 10. Fungsi Service yang Akan Ditambah
```
wallet_topup_initiate($conn,$userId,$amount)
wallet_pay_invoice($conn,$invoiceId,$userId,$amount)
payment_reversal($conn,$paymentId,$actorId)
invoice_mark_overdue_bulk($conn)
validate_payment_amount($invoiceRow,$amount)
```

---
## 11. Pseudocode Kritis
### wallet_pay_invoice
```
BEGIN;
SELECT invoice FOR UPDATE;
IF status IN (paid,canceled) => fail;
IF remaining < amount => amount = remaining;
IF wallet_balance(user) < amount => fail;
// Ledger double entries sederhana:
// Debit: (tidak menambah saldo) kita treat WALLET sebagai asset; bayar invoice artinya mengurangi WALLET & mengurangi piutang (AR_SPP)
INSERT ledger_entries (user,account,debit,credit=amount,ref_type='invoice',ref_id=invoiceId,note='Pay via wallet'); -- credit WALLET
INSERT ledger_entries (user,account,debit=amount,credit,ref_type='invoice',ref_id=invoiceId,note='AR SPP settle'); -- debit AR_SPP
UPDATE invoice paid_amount += amount; status logic partial/paid.
INSERT payment (method='wallet', status='settled');
COMMIT;
```

### payment_reversal
```
BEGIN;
SELECT payment (settled) FOR UPDATE;
IF not settled => fail;
SELECT invoice FOR UPDATE;
// ledger balancing reverse
INSERT ledger_entries ... (balikkan arah)
UPDATE invoice paid_amount -= payment.amount (tidak <0) + status recalculation.
UPDATE payment SET status='reversed';
HISTORY entries.
COMMIT;
```

---
## 12. Anomaly Checks (Admin Dashboard)
1. Invoice paid_amount > amount (should not happen) -> highlight merah.
2. Sum payments settled != invoice.paid_amount.
3. Payment settled tapi invoice_id NULL bukan top-up (invalid).
4. Ledger WALLET negatif.
5. Invoice overdue tapi status bukan `overdue`.

---
## 13. Testing & Verifikasi (Script Sederhana)
Buat script `scripts/verify_integrity.php`:
- Loop semua invoice: hitung ulang sum settled payments, bandingkan.
- Cek constraints di atas, print laporan.

---
## 14. Roadmap Aksi Berikutnya (Direkomendasikan)
1. Migration tambahan kolom meta_json & index (Fase P2 awal).
2. Refactor upload bukti (folder terpisah + nama hash).
3. Implement wallet top-up (UI wali + settle manual oleh admin dulu).
4. Implement wallet pay invoice (atomic transaction).
5. Job overdue (endpoint + cron doc).
6. Anomaly checker script.
7. Reversal util.
8. Notifikasi (tabel `notifications` sudah ada? Jika belum, buat). Tambah trigger logic.
9. Gateway stub (simulate callback sets payment to settled).
10. Modularisasi `payments.php` menjadi beberapa file service.

---
## 15. Keamanan
- CSRF (sudah ada) pastikan setiap POST.
- Validasi numeric (cast float + floor ke 2 desimal sebelum insert DB).
- Harden file upload (MIME, size, path traversal prevention, random name).
- Rate limit (nanti) untuk pembuatan payment (idempotency + minimal jeda).
- Jangan expose path internal; gunakan 404 jika unauthorized access invoice/payment.

---
## 16. Observasi Current Gap
| Area | Kondisi Saat Ini | Gap |
|------|------------------|-----|
| Upload Bukti | Hardened (random name, MIME, dir khusus) | Tambah limit ukuran & optional image compression |
| Partial Payment | Ada & update paid_amount | Perlu rounding guard central & tolerance config |
| Reversal | Implemented | Perlu otomatis generate ledger double-entry untuk semua metode non-wallet nanti |
| Wallet | Top-up + pay invoice aktif | Tambah halaman riwayat ledger WALLET detail |
| Overdue | Runner manual + history | Tambah cron otomatis & from_status akurat (partial/pending) per invoice |
| Notifikasi | Hooks invoice/payment/overdue/reversal | Perlu template unify & prefer queue untuk scale |
| Gateway | Belum | Stub + webhook |
| Integrity Checker | Script tersedia | Tambah output JSON mode + per-issue severity code |

---
## 17. Konvensi Penamaan
- File bukti: `payproof_<paymentId>_<hash>.ext`
- File topup proof: `topup_<paymentId>_<hash>.ext`
- Ref type ledger: `payment`, `invoice`, `adjustment`.

---
## 18. Checklist Quick Start Implementasi Lanjutan
[ ] Migration kolom tambahan (meta_json, index)
[ ] Refactor upload bukti (folder + hash + MIME)
[x] Wallet top-up flow (initiate + admin settle)
[x] Wallet pay invoice flow (atomic)
[x] Overdue runner + UI tombol manual
[x] Anomaly integrity script
[x] Reversal fungsi & UI admin
[x] Notifikasi hook (dasar)
[ ] Modularisasi service

---
## 19. Catatan Eksekusi
Untuk migrasi baru jangan pakai `ADD COLUMN IF NOT EXISTS` (tidak didukung MySQL lama). Lakukan pattern cek manual via `information_schema` jika perlu.

Silakan review & konfirmasi fase mana dieksekusi berikutnya.

---
## 20. Notifikasi (Implementasi Saat Ini)
Event yang sudah memicu insert ke tabel `notifications` (melalui `add_notification`):
- invoice_created: Setelah invoice baru sukses dibuat (bulk maupun single).
- payment_settled: Pembayaran manual (non-wallet) untuk invoice disetujui admin (status -> settled).
- wallet_topup_settled: Top-up wallet (payment tanpa invoice_id) berhasil disettle.
- wallet_invoice_payment: Pembayaran invoice via wallet (atomic) berhasil.
- invoice_overdue: Invoice otomatis ditandai overdue oleh runner.
- payment_reversed: Admin melakukan reversal terhadap payment settled.

Catatan lanjutan:
1. Perlu konsistensi penamaan tipe (misal pakai namespace: invoice.*, payment.*, wallet.*) di refactor berikutnya.
2. Tambah template mapping (type -> human readable) di layer view agar mudah ganti bahasa.
3. Tambah kolom optional `data_json` (meta) untuk payload (invoice_id, payment_id) supaya UI bisa taut langsung tanpa parsing message.
4. Pertimbangkan batching / queue jika volume tinggi (saat gateway terintegrasi).
5. Tambah endpoint atau job cleanup berbeda SLA (global vs user-specific).
