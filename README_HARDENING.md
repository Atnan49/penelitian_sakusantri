# Saku Santri Hardening & Operasional

## Ringkasan Perubahan Keamanan
- Transaksi atomic untuk pembayaran & reversal.
- CSRF ditambahkan pada endpoint gateway init.
- Rate limiting login (session + IP, window 10 menit).
- Header keamanan: CSP dasar, X-Frame-Options, nosniff, Permissions-Policy.
- Session cookie: Secure, HttpOnly, SameSite=Strict.
- Upload bukti: helper terpusat, validasi MIME, blok eksekusi skrip.
- HMAC opsional (X-TIMESTAMP + X-SIGNATURE) untuk webhook (gateway_callback) & cron_overdue.
- Enum status terpusat (`status.php`) mencegah inkonsistensi dan mempertahankan overdue.
- Sinkronisasi otomatis kolom `users.saldo` via ledger_post.
- Auto schema runtime dinonaktifkan (aktifkan hanya dengan `AUTO_MIGRATE_RUNTIME=true`).

## Migrasi Produksi
1. Jalankan skrip migrasi SQL di folder `scripts/migrations/` secara berurutan pada database produksi.
2. Pastikan kolom / tabel baru sesuai (`invoice`, `payment`, `ledger_entries`, dsb).
3. Set file `src/includes/config.php` dengan variabel rahasia:
   - `GATEWAY_SECRET`
   - `CRON_SECRET`
   - `INTEGRITY_KEY`
4. (Opsional) Hapus `AUTO_MIGRATE_RUNTIME` atau pastikan false.
5. Deploy `.htaccess` di `public/uploads/payment_proof/`.

## HMAC Webhook & Cron
Kirim header:
```
X-TIMESTAMP: <epoch detik>
X-SIGNATURE: hex sha256 HMAC dari: "<timestamp>\n<body>"
```
Gunakan key: `GATEWAY_SECRET` / `CRON_SECRET` sesuai endpoint.

## Health Check
Endpoint: `/health.php` -> JSON `{ ok, time, version }`.

## Catatan Lanjutan (Belum Dilakukan)
- Integrasi test otomatis & audit script (future).
- CSP tanpa `'unsafe-inline'` memerlukan refactor inline script ke file eksternal.
- 2FA admin dan OTP login lanjutan.

## Recovery Scenario
Jika ledger rusak, jalankan ulang integritas `scripts/verify_integrity.php` dan bandingkan paid_amount vs sum payments.

## Lisensi & Kredit
Dokumen hardening internal. Gunakan secara privat.
