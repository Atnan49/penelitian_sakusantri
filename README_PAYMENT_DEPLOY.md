# Deployment Panduan Sistem Pembayaran v2

Ringkas langkah produksi (lihat dokumen desain lengkap di docs/payment_system_design.md)

## 1. Backup
mysqldump / salin folder uploads.

## 2. Migrasi (urut)
1. 001 (skema invoice/payment/ledger/history) – jika belum.
2. 002 (proof_file)
3. 003 (meta_json + source + index) – opsional awal, disarankan jalan.
4. 004 (notifications.data_json)

Jika MySQL lama tidak dukung JSON -> ubah kolom menjadi TEXT sementara.

## 3. Folder Upload
Buat `public/uploads/payment_proof` writable (chmod 775 atau permission Windows). 

## 4. Fitur Kunci Uji Coba
- Generate SPP: admin/invoice.php
- Manual payment + bukti upload
- Settle oleh admin -> invoice ter-update
- Top-up wallet & settle -> saldo wallet naik
- Bayar invoice via wallet -> invoice paid/partial
- Overdue runner: admin/invoice_overdue_run.php
- Reversal: admin/invoice_detail (payment settled)
- Notifikasi: periksa admin/notifikasi & wali/notifikasi
- Integrity: scripts/verify_integrity.php (pastikan ISSUES kosong)

## 5. Cron (contoh Linux)
Tambahkan (ubah domain & path):
```
# Jalankan overdue jam 00:20
20 0 * * * curl -fsS https://yourdomain/admin/invoice_overdue_run.php?key=SECRET > /dev/null 2>&1
# Integrity report jam 00:30 (opsional simpan log)
30 0 * * * curl -fsS https://yourdomain/scripts/verify_integrity.php > /var/log/sakusantri_integrity.log 2>&1
```
Tambahkan cek ?key=SECRET dengan validasi sederhana di file target jika perlu security.

## 6. Reversal SOP
1. Buka invoice detail admin.
2. Klik Reverse pada payment settled salah.
3. Pastikan notifikasi reversal muncul dan invoice paid_amount berkurang.
4. Jalankan integrity script.

## 7. Ledger Manual Payment (double-entry)
Saat payment manual disettle: Dr CASH_IN, Cr AR_SPP.
Reversal membalik: Cr CASH_IN, Dr AR_SPP.
Wallet payment: Cr WALLET, Dr AR_SPP.
Top-up: Dr WALLET (via settle), (nanti bisa Cr CASH_IN / BANK jika dibutuhkan fase lanjut).

## 8. Troubleshooting Cepat
| Gejala | Aksi |
|--------|------|
| Invoice status tidak berubah saat settle | Cek payment_update_status & invoice_apply_payment error log (tambahkan logging) |
| Wallet negatif | Jalankan integrity script, cari payment reversal ganda |
| Notifikasi tidak muncul | Pastikan kolom data_json ada atau fallback add_notification tanpa data_json |
| Upload gagal | Periksa permission folder payment_proof |

## 9. Next Steps Rekomendasi
- Tambah auth token sederhana untuk endpoints maintenance.
- UI riwayat wallet detail.
- Gateway stub & webhook.
- Audit log ledger diff.

## 10. Versi
Dokumen ini sesuai commit penambahan ledger double-entry manual & payload notifikasi.
