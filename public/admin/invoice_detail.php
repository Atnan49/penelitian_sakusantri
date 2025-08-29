<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';
$iid = (int)($_GET['id'] ?? 0);
if(!$iid){ header('Location: invoice.php'); exit; }
// Fetch invoice with user
$stmt = mysqli_prepare($conn,'SELECT i.*, u.nama_santri, u.nama_wali FROM invoice i JOIN users u ON i.user_id=u.id WHERE i.id=? LIMIT 1');
if(!$stmt){ die('DB error'); }
mysqli_stmt_bind_param($stmt,'i',$iid); mysqli_stmt_execute($stmt); $r = mysqli_stmt_get_result($stmt); $inv = $r?mysqli_fetch_assoc($r):null;
if(!$inv){ require_once BASE_PATH.'/src/includes/header.php'; echo '<main class="container"><div class="alert error">Invoice tidak ditemukan.</div></main>'; require_once BASE_PATH.'/src/includes/footer.php'; exit; }

// Load payment list
$payments=[]; $pr = mysqli_query($conn,'SELECT * FROM payment WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($pr && $row=mysqli_fetch_assoc($pr)) $payments[]=$row;
// Histories
$hist_inv=[]; $hr = mysqli_query($conn,'SELECT * FROM invoice_history WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($hr && $row=mysqli_fetch_assoc($hr)) $hist_inv[]=$row;
$hist_pay=[];
if($payments){
  $ids = implode(',',array_map('intval', array_column($payments,'id')));
  $hpr = mysqli_query($conn,'SELECT * FROM payment_history WHERE payment_id IN ('.$ids.') ORDER BY id DESC');
  while($hpr && $row=mysqli_fetch_assoc($hpr)) $hist_pay[]=$row;
}

// Admin actions: mark payment settled / failed / reverse; create manual payment record
$msg=$err=null; $do = $_POST['do'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')) $err='Token tidak valid';
  else if($do==='new_manual_payment'){
  $amt = normalize_amount($_POST['amount'] ?? 0);
  if($amt<=0) $err='Nominal harus > 0'; else {
      $pid = payment_initiate($conn,(int)$inv['user_id'],$inv['id'],'manual_transfer',$amt,'','manual add');
      if($pid){
        // langsung ubah status ke awaiting_confirmation
        payment_update_status($conn,$pid,'awaiting_confirmation',(int)($_SESSION['user_id']??null),'upload proof bypass');
        $msg='Payment draft dibuat (#'.$pid.')';
      } else $err='Gagal buat payment';
    }
  } else if($do==='set_status'){
    $pid = (int)($_POST['payment_id'] ?? 0); $to = $_POST['to'] ?? '';
    if(!$pid || !$to) $err='Data kurang';
    else if(!payment_update_status($conn,$pid,$to,(int)($_SESSION['user_id']??null),'admin manual')) $err='Gagal update status';
    else $msg='Status payment #'.$pid.' diubah ke '.$to;
  } else if($do==='reverse_payment'){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if(!$pid) $err='Payment tidak valid'; else {
      $res = payment_reversal($conn,$pid,(int)($_SESSION['user_id']??null),'admin reversal');
      if(!$res['ok']) $err=$res['msg']; else $msg=$res['msg'];
    }
  }
  // reload after post
  if($msg && !$err){ header('Location: invoice_detail.php?id='.$iid.'&msg='.urlencode($msg)); exit; }
}
if(isset($_GET['msg'])) $msg = $_GET['msg'];

require_once BASE_PATH.'/src/includes/header.php';
?>
<main class="container" style="padding-bottom:60px">
  <a href="invoice.php" style="text-decoration:none;font-size:12px;color:#555">&larr; Kembali</a>
  <h1 style="margin:8px 0 18px;font-size:26px">Invoice #<?= (int)$inv['id'] ?></h1>
  <?php if($msg): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <div class="tx-panel" style="margin-bottom:24px">
    <div style="display:flex;flex-wrap:wrap;gap:30px">
      <div style="flex:1 1 260px">
        <h3 style="margin:0 0 8px;font-size:15px;letter-spacing:.5px">DATA INVOICE</h3>
        <div class="meta-line"><span>Santri</span><b><?= e($inv['nama_santri']) ?></b></div>
        <div class="meta-line"><span>Wali</span><b><?= e($inv['nama_wali']) ?></b></div>
        <div class="meta-line"><span>Periode</span><b><?= e($inv['period']) ?></b></div>
        <div class="meta-line"><span>Nominal</span><b>Rp <?= number_format($inv['amount'],0,',','.') ?></b></div>
        <div class="meta-line"><span>Dibayar</span><b>Rp <?= number_format($inv['paid_amount'],0,',','.') ?></b></div>
        <div class="meta-line"><span>Status</span><b><span class="status-<?= e($inv['status']) ?>"><?= e($inv['status']) ?></span></b></div>
        <div class="meta-line"><span>Jatuh Tempo</span><b><?= e($inv['due_date']) ?></b></div>
        <div class="meta-line"><span>Dibuat</span><b><?= e($inv['created_at']) ?></b></div>
        <?php if($inv['updated_at']): ?><div class="meta-line"><span>Update</span><b><?= e($inv['updated_at']) ?></b></div><?php endif; ?>
      </div>
      <div style="flex:1 1 300px">
        <h3 style="margin:0 0 8px;font-size:15px">AKSI ADMIN</h3>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="new_manual_payment">
          <input type="number" required step="1000" name="amount" placeholder="Nominal" style="padding:8px 10px;width:140px">
          <button class="btn-action primary" style="padding:8px 18px">Tambah Payment</button>
        </form>
        <div style="font-size:11px;color:#666">Gunakan untuk input pembayaran yang dikirim manual / bukti fisik.</div>
      </div>
    </div>
  </div>
  <div class="panel" style="margin-bottom:28px">
  <h2 style="margin:0 0 14px;font-size:18px">Payments</h2>
    <div class="table-wrap" style="overflow-x:auto">
      <table class="table" style="min-width:620px">
  <thead><tr><th>ID</th><th>Method</th><th>Amount</th><th>Status</th><th>Created</th><th>Bukti</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if(!$payments): ?><tr><td colspan="6" style="text-align:center;font-size:12px;color:#777">Belum ada payment.</td></tr><?php else: foreach($payments as $p): ?>
          <tr>
            <td>#<?= (int)$p['id'] ?></td>
            <td><?= e($p['method']) ?></td>
            <td>Rp <?= number_format($p['amount'],0,',','.') ?></td>
            <td><span class="status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
            <td><?= e($p['created_at']) ?></td>
            <td style="font-size:11px">
              <?php if(!empty($p['proof_file'])): ?>
                <a href="../uploads/<?= e($p['proof_file']) ?>" target="_blank">Lihat</a>
              <?php else: ?>-
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;font-size:11px">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="do" value="set_status">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <select name="to" style="padding:4px 6px;font-size:11px">
                  <?php foreach(['awaiting_confirmation','settled','failed','reversed'] as $opt): if($opt===$p['status']) continue; ?>
                    <option value="<?= $opt ?>"><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
                <button style="padding:4px 8px;font-size:11px">Go</button>
              </form>
              <?php if($p['status']==='settled'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Reverse payment #<?= (int)$p['id'] ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="do" value="reverse_payment">
                  <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                  <button style="padding:4px 8px;font-size:11px;background:#b91c1c;color:#fff">Reverse</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="panel" style="margin-bottom:28px">
    <h2 style="margin:0 0 14px;font-size:18px">Riwayat Invoice</h2>
    <ul style="list-style:none;padding:0;margin:0;max-height:260px;overflow:auto">
      <?php if(!$hist_inv): ?><li style="font-size:12px;color:#777">Belum ada.</li><?php else: foreach($hist_inv as $h): ?>
        <li style="padding:6px 4px;border-bottom:1px solid #eee;font-size:12px">
          <b><?= e($h['from_status'] ?: '-') ?> &rarr; <?= e($h['to_status']) ?></b> <span style="color:#666">(<?= e($h['created_at']) ?>)</span>
          <?php if($h['note']): ?><i style="color:#999"> - <?= e($h['note']) ?></i><?php endif; ?>
        </li>
      <?php endforeach; endif; ?>
    </ul>
  </div>
  <div class="panel">
    <h2 style="margin:0 0 14px;font-size:18px">Riwayat Payment</h2>
    <ul style="list-style:none;padding:0;margin:0;max-height:260px;overflow:auto">
      <?php if(!$hist_pay): ?><li style="font-size:12px;color:#777">Belum ada.</li><?php else: foreach($hist_pay as $h): ?>
        <li style="padding:6px 4px;border-bottom:1px solid #eee;font-size:12px">
          <b>Payment #<?= (int)$h['payment_id'] ?>: <?= e($h['from_status'] ?: '-') ?> &rarr; <?= e($h['to_status']) ?></b> <span style="color:#666">(<?= e($h['created_at']) ?>)</span>
          <?php if($h['note']): ?><i style="color:#999"> - <?= e($h['note']) ?></i><?php endif; ?>
        </li>
      <?php endforeach; endif; ?>
    </ul>
  </div>
</main>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
