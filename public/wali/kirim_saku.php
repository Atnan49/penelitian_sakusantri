<?php
require_once __DIR__ . '/../../src/includes/db_connect.php';
require_once __DIR__ . '/../../src/includes/csrf.php';
require_once __DIR__ . '/../../src/includes/payments.php';
// Ambil user_id dari session login wali
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    header('Location: '.url('login'));
    exit;
}
// Pastikan variabel utama terdefinisi
$stage = 'amount';
$pesan = null;
$pesan_error = null;
$jumlah_valid = 0;
$current_payment_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $pesan_error = 'Token tidak valid.';
    } else {
        $posted_stage = $_POST['stage'] ?? 'amount';
        if ($posted_stage === 'amount') {
            $jumlah_raw = (int)($_POST['jumlah'] ?? 0);
            if ($jumlah_raw <= 0) {
                $pesan_error = 'Jumlah harus lebih dari 0.';
            } else {
                $jumlah_valid = $jumlah_raw;
                // Create payment record (initiated -> awaiting_proof)
                // Pastikan argumen invoice_id benar-benar null untuk top up wallet
                $pid = payment_initiate($conn, $user_id, null, 'manual', $jumlah_valid, 'topup:' . md5($user_id . '|' . $jumlah_valid . '|' . microtime(true)), 'wallet topup');
                if ($pid) {
                    // Move to awaiting_proof to indicate we expect file
                    payment_update_status($conn, $pid, 'awaiting_proof', $user_id, 'upload proof');
                    $current_payment_id = $pid;
                    $stage = 'upload';
                } else {
                    $pesan_error = 'Gagal membuat payment.';
                }
            }
        } elseif ($posted_stage === 'upload' && isset($_FILES['bukti'])) {
            $jumlah_valid = (int)($_POST['jumlah'] ?? 0);
            $current_payment_id = (int)($_POST['pid'] ?? 0);
            if ($jumlah_valid <= 0 || !$current_payment_id) {
                $pesan_error = 'Data tidak valid.';
                $stage = 'amount';
            } else {
                // Upload proof and update payment
                $uploads_dir = realpath(__DIR__ . '/../uploads');
                if ($uploads_dir === false) { $uploads_dir = __DIR__ . '/../uploads'; @mkdir($uploads_dir, 0755, true); }
                $max_size = 5 * 1024 * 1024;
                $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                $original = $_FILES['bukti']['name'] ?? '';
                $tmp = $_FILES['bukti']['tmp_name'] ?? '';
                $size = (int)($_FILES['bukti']['size'] ?? 0);
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext, true)) { $pesan_error = 'Format file tidak didukung.'; $stage = 'upload'; }
                elseif ($size <= 0 || $size > $max_size) { $pesan_error = 'Ukuran file tidak valid (maks 5MB).'; $stage = 'upload'; }
                else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($tmp) ?: ''; $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
                    if (!in_array($mime, $allowed_mimes, true)) { $pesan_error = 'Tipe file tidak valid.'; $stage = 'upload'; }
                    else {
                        $safe_name = payments_random_name('proof', '.' . $ext);
                        $target_file = rtrim($uploads_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe_name;
                        if (move_uploaded_file($tmp, $target_file)) {
                            // Update payment proof_file & move status awaiting_confirmation
                            $stmt = mysqli_prepare($conn, 'UPDATE payment SET proof_file=?, updated_at=NOW() WHERE id=? AND user_id=?');
                            if ($stmt) { mysqli_stmt_bind_param($stmt, 'sii', $safe_name, $current_payment_id, $user_id); mysqli_stmt_execute($stmt); }
                            payment_update_status($conn, $current_payment_id, 'awaiting_confirmation', $user_id, 'proof uploaded');
                            if (function_exists('add_notification')) { @add_notification($conn, null, 'wallet_topup_submitted', 'Top-up wallet menunggu konfirmasi', ['payment_id' => $current_payment_id, 'amount' => $jumlah_valid]); }
                            $pesan = 'Top-Up berhasil diajukan. Menunggu konfirmasi admin.';
                            $stage = 'amount'; $jumlah_valid = 0; $current_payment_id = null;
                        } else { $pesan_error = 'Gagal mengupload bukti.'; $stage = 'upload'; }
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../../src/includes/header.php';
?>
<main class="container topup-container" style="max-width:840px;">
        <h1 class="wali-page-title">Top-Up Wallet</h1>
    <?php if(isset($pesan)) echo "<div class='alert success'>".e($pesan)."</div>"; ?>
    <?php if(isset($pesan_error)) echo "<div class='alert error'>".e($pesan_error)."</div>"; ?>

    <div class="topup-steps">
        <div class="step-item <?php echo $stage==='amount' ? 'active':($stage==='upload'?'done':''); ?>">1<span>Isi Nominal</span></div>
        <div class="step-line"></div>
        <div class="step-item <?php echo $stage==='upload' ? 'active':($stage==='amount'?'':''); ?>">2<span>Upload Bukti</span></div>
    </div>

        <?php if($stage==='amount'): ?>
        <div class="panel topup-panel">
                <h2 class="topup-h2">Masukkan Nominal</h2>
                <p class="topup-transfer-note">Transfer terlebih dahulu ke rekening: <strong>Bank ABC 123456789 a.n. Pondok Pesantren</strong>. Setelah transfer klik Lanjut untuk mengunggah bukti.</p>
            <form method="post" class="topup-form" novalidate>
                <label class="field">
                    <span>Jumlah Top-Up (Rp)</span>
                    <input type="text" id="jumlahDisplay" placeholder="Rp 0" inputmode="numeric" autocomplete="off" required />
                    <input type="hidden" name="jumlah" id="jumlah" />
                </label>
                <input type="hidden" name="stage" value="amount" />
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                <div class="form-actions">
                    <button type="submit" class="btn-pill primary">Lanjut</button>
                </div>
            </form>
        </div>
        <?php elseif($stage==='upload'): ?>
        <div class="panel topup-panel">
            <h2 class="topup-h2">Konfirmasi & Unggah Bukti</h2>
            <div class="confirm-box-amount">Nominal: <strong>Rp <?php echo number_format($jumlah_valid,0,',','.'); ?></strong></div>
            <p class="muted small">Pastikan jumlah sesuai. Jika salah kembali dan perbaiki.</p>
            <form method="post" enctype="multipart/form-data" class="topup-form" novalidate>
                <div class="upload-drop" data-drop>
                    <input type="file" name="bukti" id="bukti" accept="image/*" required />
                    <label for="bukti" class="upload-instructions">
                        <span class="u-icon">📎</span>
                        <span class="u-text">Pilih / tarik gambar bukti transfer (JPG/PNG/WebP, maks 5MB)</span>
                    </label>
                    <div class="file-chosen" id="fileChosen" hidden>
                        <span class="fc-label">File:</span> <span class="fc-name" id="fileChosenName"></span>
                        <button type="button" class="fc-clear" id="fileClearBtn" aria-label="Hapus file" title="Hapus file">×</button>
                    </div>
                </div>
                <input type="hidden" name="jumlah" value="<?php echo (int)$jumlah_valid; ?>" />
                <input type="hidden" name="pid" value="<?php echo (int)$current_payment_id; ?>" />
                <input type="hidden" name="stage" value="upload" />
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                <div class="form-actions between">
                    <button type="button" class="btn-secondary" data-action="back">Kembali</button>
                    <button type="submit" class="btn-pill primary">Kirim Bukti</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
    </main>
    <?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
    <script src="../assets/js/kirim_saku.js"></script>
