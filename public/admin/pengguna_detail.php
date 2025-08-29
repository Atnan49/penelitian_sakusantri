<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

// Detect optional soft delete column on transaksi
$hasSoftDelete=false;
if($colChk = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='transaksi' AND COLUMN_NAME='deleted_at' LIMIT 1")){
  if(mysqli_fetch_row($colChk)) $hasSoftDelete=true;
}
$softWhere = $hasSoftDelete ? ' AND deleted_at IS NULL' : '';
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if($id<=0){ header('Location: '.url('admin/pengguna')); exit; }
$pesan = $pesan_error = null;

// Data user
$stmt = mysqli_prepare($conn, "SELECT id, nama_wali, nama_santri, saldo FROM users WHERE id=? AND role='wali_santri' LIMIT 1");
if(!$stmt){ die('DB err'); }
mysqli_stmt_bind_param($stmt,'i',$id); mysqli_stmt_execute($stmt); $resU = mysqli_stmt_get_result($stmt); $user = $resU?mysqli_fetch_assoc($resU):null;
if(!$user){ header('Location: '.url('admin/pengguna')); exit; }

// Hapus tagihan SPP individual (hanya status menunggu_pembayaran)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='hapus_tagihan'){
  $token = $_POST['csrf_token'] ?? '';
  $tid = (int)($_POST['transaksi_id'] ?? 0);
  if(!verify_csrf_token($token)){
    $pesan_error = 'Token tidak valid.';
  } elseif($tid>0){
  $stmtChk = mysqli_prepare($conn, $hasSoftDelete ? "SELECT id,status FROM transaksi WHERE id=? AND user_id=? AND jenis_transaksi='spp' AND deleted_at IS NULL LIMIT 1" : "SELECT id,status FROM transaksi WHERE id=? AND user_id=? AND jenis_transaksi='spp' LIMIT 1");
    if($stmtChk){
      mysqli_stmt_bind_param($stmtChk,'ii',$tid,$id);
      mysqli_stmt_execute($stmtChk);
      $resChk = mysqli_stmt_get_result($stmtChk);
      $rowChk = $resChk?mysqli_fetch_assoc($resChk):null;
      if($rowChk){
        if($rowChk['status']==='menunggu_pembayaran'){
          if($hasSoftDelete){
            mysqli_query($conn, "UPDATE transaksi SET deleted_at=NOW() WHERE id=".$tid." AND deleted_at IS NULL");
          } else {
            // Fallback hard delete if no soft delete column
            mysqli_query($conn, "DELETE FROM transaksi WHERE id=".$tid." LIMIT 1");
          }
          if(mysqli_affected_rows($conn)>0){
            $pesan = 'Tagihan berhasil dihapus (soft delete).';
            @add_notification($conn, $id, 'spp_delete', 'Tagihan SPP dihapus oleh admin.');
            if(function_exists('audit_log')){ audit_log($conn, (int)($_SESSION['user_id']??null), 'delete_tagihan', 'transaksi', $tid, ['page'=>'pengguna_detail','status_before'=>$rowChk['status']]); }
          } else { $pesan_error='Gagal menghapus tagihan.'; }
        } else { $pesan_error='Tagihan sudah diproses dan tidak bisa dihapus.'; }
      } else { $pesan_error='Tagihan tidak ditemukan.'; }
    } else { $pesan_error='Query hapus gagal disiapkan.'; }
  }
}

// Generate SPP tagihan (backfill) if requested
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='generate_spp'){
  $token = $_POST['csrf_token'] ?? '';
  if(!verify_csrf_token($token)){
    $pesan_error = 'Token tidak valid.';
  } else {
    $currentYear  = (int)date('Y');
    $currentMonth = (int)date('n');
    $bulanIndo = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    // Ambil deskripsi existing utk user ini
    $existing = [];
  if($rsE = mysqli_query($conn, "SELECT deskripsi FROM transaksi WHERE user_id=$id AND jenis_transaksi='spp'")){
      while($rw=mysqli_fetch_assoc($rsE)){ $existing[strtolower($rw['deskripsi'])]=true; }
    }
    $defaultNominal = 0.0;
    if($rsLast = mysqli_query($conn, "SELECT jumlah FROM transaksi WHERE jenis_transaksi='spp' ORDER BY id DESC LIMIT 1")){
      if($rowLast = mysqli_fetch_assoc($rsLast)){ $defaultNominal = (float)$rowLast['jumlah']; }
    }
    if($defaultNominal < 0) $defaultNominal = 0;
    $insT = mysqli_prepare($conn, "INSERT INTO transaksi (user_id, jenis_transaksi, deskripsi, jumlah, status) VALUES (?, 'spp', ?, ?, 'menunggu_pembayaran')");
    if($insT){
      $created = 0;
      for($m=$currentMonth;$m<=12;$m++){
        $desc = 'SPP Bulan ' . $bulanIndo[$m] . ' ' . $currentYear;
        if(isset($existing[strtolower($desc)])) continue; // skip existing
        mysqli_stmt_bind_param($insT,'isd',$id,$desc,$defaultNominal);
        mysqli_stmt_execute($insT);
        if(mysqli_stmt_affected_rows($insT)>0) $created++;
      }
  add_notification($conn, $id, 'spp_backfill', 'Tagihan SPP '.$created.' bulan dibuat (backfill) untuk pengguna ini.');
    $pesan = $created>0 ? ("Berhasil membuat $created tagihan SPP.") : 'Tidak ada tagihan baru (semua sudah ada).';
    } else {
      $pesan_error = 'Gagal menyiapkan query insert.';
    }
  }
}

// Ambil daftar tagihan SPP terakhir (limit 12)
$tagihan=[]; $rsT = mysqli_query($conn, "SELECT id, deskripsi, jumlah, status, tanggal_upload FROM transaksi WHERE user_id=$id AND jenis_transaksi='spp' $softWhere ORDER BY (status='menunggu_pembayaran') DESC, id DESC LIMIT 12");
while($rsT && $r=mysqli_fetch_assoc($rsT)){ $tagihan[]=$r; }
// Hitung tagihan belum bayar
// Hitung total tagihan belum bayar (seluruh, tidak hanya 12 terbaru)
$belum_bayar_total = 0; if($rsC = mysqli_query($conn, "SELECT COUNT(*) c FROM transaksi WHERE user_id=$id AND jenis_transaksi='spp' AND status='menunggu_pembayaran' $softWhere")){ if($rC = mysqli_fetch_assoc($rsC)) $belum_bayar_total=(int)$rC['c']; }
// Jumlah yang terlihat di list pendek
$belum_bayar_visible = 0; foreach($tagihan as $t){ if($t['status']==='menunggu_pembayaran') $belum_bayar_visible++; }
// Rekap saldo: ambil ledger (wallet_ledger) 30 terakhir
$ledger=[]; $rsL = mysqli_query($conn, "SELECT id, direction, amount, ref_type, note, created_at FROM wallet_ledger WHERE user_id=$id ORDER BY id DESC LIMIT 60");
while($rsL && $r=mysqli_fetch_assoc($rsL)){ $ledger[]=$r; }
// Ambil ringkas semua tagihan menunggu (opsi global)
$all_spp = [];
$rsAll = mysqli_query($conn, "SELECT t.id, t.deskripsi, t.jumlah, t.status, t.tanggal_upload, u.nama_santri FROM transaksi t JOIN users u ON t.user_id=u.id WHERE t.jenis_transaksi='spp' AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 120");
while($rsAll && $r=mysqli_fetch_assoc($rsAll)){ $all_spp[]=$r; }
require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell pengguna-detail-page enhanced">
  <div class="content-header">
    <h1><?= e($user['nama_santri']); ?></h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="<?= url('admin/pengguna'); ?>">&larr; Kembali</a>
      <a class="qa-btn" href="<?= url('kasir/transaksi?user_id='.$user['id']); ?>">Top-Up</a>
    </div>
  </div>
  <div class="user-summary-cards">
    <div class="u-card saldo"><h3>Saldo</h3><div class="val">Rp <?= number_format($user['saldo'],0,',','.') ?></div><div class="sub">Tabungan Santri</div></div>
  <div class="u-card spp <?= $belum_bayar_total>0?'warn':'' ?>"><h3>SPP Belum</h3><div class="val"><?= (int)$belum_bayar_total ?></div><div class="sub">Tagihan</div><?php if($belum_bayar_total>$belum_bayar_visible): ?><div class="sub" style="font-size:10px;color:#a15b00">Hanya menampilkan sebagian terbaru</div><?php endif; ?></div>
  </div>
  <?php if($pesan): ?><div class="alert success" role="alert"><?= e($pesan) ?></div><?php endif; ?>
  <?php if($pesan_error): ?><div class="alert error" role="alert"><?= e($pesan_error) ?></div><?php endif; ?>
  <div class="tabs-wrap user-tabs">
    <button class="tab-btn active" data-tab="spp">Tagihan SPP</button>
    <button class="tab-btn" data-tab="rekap">Rekap Saldo</button>
    <button class="tab-btn" data-tab="semua">Semua Tagihan</button>
  </div>
  <div class="tab-content active" id="tab-spp">
    <table class="t-spp">
      <thead><tr><th>No</th><th>Bulan</th><th>Jumlah (Rp)</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php $i=1; foreach($tagihan as $t): $bulan = $t['deskripsi'] ?: date('M Y', strtotime($t['tanggal_upload']??'now')); ?>
          <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo htmlspecialchars($bulan); ?></td>
            <td><?php echo number_format($t['jumlah'],0,',','.'); ?></td>
            <td class="st-<?php echo $t['status']; ?>"><?php echo $t['status']; ?></td>
            <td>
              <?php if($t['status']==='menunggu_pembayaran'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus tagihan ini?');">
                  <input type="hidden" name="aksi" value="hapus_tagihan" />
                  <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
                  <input type="hidden" name="transaksi_id" value="<?php echo (int)$t['id']; ?>" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                  <button type="submit" class="btn-inline" style="color:#c0392b;font-size:11px">Hapus</button>
                </form>
              <?php else: ?><span style="font-size:11px;color:#888">-</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if(empty($tagihan)): ?>
          <tr><td colspan="5" class="text-muted">
            Belum ada tagihan. <form action="" method="POST" style="display:inline;margin-left:10px">
              <input type="hidden" name="aksi" value="generate_spp" />
              <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
              <button type="submit" style="background:#5e765c;color:#fff;border:0;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer">Generate sisa tahun</button>
            </form>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="tab-content" id="tab-rekap">
    <div class="rekap-box">
      <?php
        if(empty($ledger)) { echo '<p class="text-muted">Belum ada data ledger.</p>'; }
        $groupIn = []; $groupOut=[];
        foreach($ledger as $l){ $d=date('d M Y', strtotime($l['created_at'])); if($l['direction']==='credit'){ $groupIn[$d][]=$l; } else { $groupOut[$d][]=$l; } }
        $sumIn=0; foreach($groupIn as $g){ foreach($g as $l){ $sumIn+=$l['amount']; } }
        $sumOut=0; foreach($groupOut as $g){ foreach($g as $l){ $sumOut+=$l['amount']; } }
      ?>
      <div class="rekap-summary">
        <div class="col in">
          <h4>Pemasukan</h4>
          <div class="total plus">+ Rp <?php echo number_format($sumIn,0,',','.'); ?></div>
          <?php foreach($groupIn as $date=>$rows): ?>
            <div class="date-group"><div class="dg-head"><?php echo $date; ?></div>
              <?php foreach($rows as $row): ?>
                <div class="row plus"><span class="icon">💰</span> + Rp <?php echo number_format($row['amount'],0,',','.'); ?><button class="detail-btn" onclick="alert('Detail ledger #<?php echo (int)$row['id']; ?>')">Detail</button></div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="divider"></div>
        <div class="col out">
          <h4>Pengeluaran</h4>
          <div class="total minus">- Rp <?php echo number_format($sumOut,0,',','.'); ?></div>
          <?php foreach($groupOut as $date=>$rows): ?>
            <div class="date-group"><div class="dg-head"><?php echo $date; ?></div>
              <?php foreach($rows as $row): ?>
                <div class="row minus"><span class="icon">💸</span> - Rp <?php echo number_format($row['amount'],0,',','.'); ?><button class="detail-btn" onclick="alert('Detail ledger #<?php echo (int)$row['id']; ?>')">Detail</button></div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="tab-content" id="tab-semua">
    <table class="t-spp">
      <thead><tr><th>No</th><th>Santri</th><th>Bulan</th><th>Jumlah (Rp)</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php $j=1; foreach($all_spp as $row): $bl = $row['deskripsi'] ?: date('M Y', strtotime($row['tanggal_upload']??'now')); ?>
          <tr>
            <td><?php echo $j++; ?></td>
            <td><?php echo htmlspecialchars($row['nama_santri']); ?></td>
            <td><?php echo htmlspecialchars($bl); ?></td>
            <td><?php echo number_format($row['jumlah'],0,',','.'); ?></td>
            <td class="st-<?php echo $row['status']; ?>"><?php echo $row['status']; ?></td>
            <td>
              <?php if($row['status']==='menunggu_pembayaran'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus tagihan ini?');">
                  <input type="hidden" name="aksi" value="hapus_tagihan" />
                  <input type="hidden" name="id" value="<?php echo (int)$id; ?>" />
                  <input type="hidden" name="transaksi_id" value="<?php echo (int)$row['id']; ?>" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                  <button type="submit" class="btn-inline" style="color:#c0392b;font-size:11px">Hapus</button>
                </form>
              <?php else: ?><span style="font-size:11px;color:#888">-</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if(empty($all_spp)): ?>
          <tr><td colspan="6" class="text-muted">Belum ada tagihan.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
// Tab switching
const tabBtns=document.querySelectorAll('.tab-btn');
const tabContents=document.querySelectorAll('.tab-content');
tabBtns.forEach(btn=>btn.addEventListener('click',()=>{tabBtns.forEach(b=>b.classList.remove('active'));btn.classList.add('active');const target=btn.getAttribute('data-tab');tabContents.forEach(c=>{c.classList.toggle('active', c.id==='tab-'+target);});}));
</script>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
