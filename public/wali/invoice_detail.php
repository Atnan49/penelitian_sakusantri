<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
require_once BASE_PATH.'/src/includes/upload_helper.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
$iid = (int)($_GET['id'] ?? 0);
if(!$iid){ header('Location: invoice.php'); exit; }
$stmt = mysqli_prepare($conn,'SELECT * FROM invoice WHERE id=? AND user_id=? LIMIT 1');
if(!$stmt){ die('DB error'); }
mysqli_stmt_bind_param($stmt,'ii',$iid,$uid); mysqli_stmt_execute($stmt); $r=mysqli_stmt_get_result($stmt); $inv=$r?mysqli_fetch_assoc($r):null;
if(!$inv){ require_once BASE_PATH.'/src/includes/header.php'; echo '<main class="container"><div class="alert error">Invoice tidak ditemukan.</div></main>'; require_once BASE_PATH.'/src/includes/footer.php'; exit; }

// Ambil payments invoice ini
$payments=[]; $pr = mysqli_query($conn,'SELECT * FROM payment WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($pr && $row=mysqli_fetch_assoc($pr)) $payments[]=$row;
$hist_inv=[]; $hr = mysqli_query($conn,'SELECT * FROM invoice_history WHERE invoice_id='.(int)$inv['id'].' ORDER BY id DESC');
while($hr && $row=mysqli_fetch_assoc($hr)) $hist_inv[]=$row;

$msg=$err=null; $do=$_POST['do'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')) $err='Token tidak valid';
  else if($do==='start_manual_payment'){
    if(!in_array($inv['status'],['pending','partial'])) $err='Invoice tidak bisa dibayar.'; else {
      $remaining = (float)$inv['amount'] - (float)$inv['paid_amount'];
  $amt = normalize_amount($_POST['amount'] ?? 0); if($amt<=0 || $amt>$remaining) $err='Nominal tidak valid'; else {
  $pid = payment_initiate($conn,$uid,$inv['id'],'manual_transfer',$amt,'','wali start');
  if($pid){ payment_update_status($conn,$pid,'awaiting_proof',$uid,'menunggu upload bukti'); $msg='Pembayaran dibuat (#'.$pid.'). Silakan upload bukti.'; }
        else $err='Gagal membuat payment';
      }
    }
  } else if($do==='upload_proof'){
    $pid = (int)($_POST['payment_id'] ?? 0);
    if(!$pid){ $err='Payment tidak valid'; }
    else {
      $pr = mysqli_query($conn,'SELECT * FROM payment WHERE id='.(int)$pid.' AND invoice_id='.(int)$inv['id'].' AND user_id='.(int)$uid.' LIMIT 1');
      $prow = $pr?mysqli_fetch_assoc($pr):null;
      if(!$prow) $err='Payment tidak ditemukan';
  else if(!in_array($prow['status'],['initiated','awaiting_proof'])) $err='Status payment tidak bisa upload (sudah menunggu konfirmasi atau selesai)';
      else {
        $upRes = handle_payment_proof_upload('proof');
        if(!$upRes['ok']) $err=$upRes['error']; else {
          $newName = $upRes['file'];
          $upd = mysqli_prepare($conn,'UPDATE payment SET proof_file=?, status=? , updated_at=NOW() WHERE id=?');
          $newStatus = 'awaiting_confirmation';
          if($upd){ mysqli_stmt_bind_param($upd,'ssi',$newName,$newStatus,$pid); mysqli_stmt_execute($upd); }
          payment_history_add($conn,$pid,$prow['status'],'awaiting_confirmation',$uid,'upload proof');
          $msg='Bukti diupload.';
        }
      }
    }
  }
  else if($do==='wallet_pay'){
  $amt = isset($_POST['amount']) ? normalize_amount($_POST['amount']) : null;
    if($amt!==null && $amt<=0) { $err='Nominal wallet tidak valid'; }
    else {
      $res = wallet_pay_invoice($conn,$iid,$uid,$amt);
      if(!$res['ok']) $err=$res['msg']; else $msg=$res['msg'];
    }
  }
  if($msg && !$err){ header('Location: invoice_detail.php?id='.$iid.'&msg='.urlencode($msg)); exit; }
}
if(isset($_GET['msg'])) $msg=$_GET['msg'];

$progress = $inv['amount']>0 ? min(100, round(($inv['paid_amount']/$inv['amount'])*100)) : 0;
function human_status_local($s){
  switch($s){
    case 'pending': return 'Belum Dibayar';
    case 'partial': return 'Sebagian';
    case 'paid': return 'Lunas';
    case 'overdue': return 'Terlambat';
    case 'canceled': return 'Dibatalkan';
    default: return ucfirst($s);
  }
}
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
        <h3 style="margin:0 0 8px;font-size:15px">DETAIL</h3>
        <div class="meta-line"><span>Periode</span><b><?= e($inv['period']) ?></b></div>
        <div class="meta-line"><span>Nominal</span><b>Rp <?= number_format($inv['amount'],0,',','.') ?></b></div>
        <div class="meta-line"><span>Dibayar</span><b>Rp <?= number_format($inv['paid_amount'],0,',','.') ?></b></div>
  <div class="meta-line"><span>Status</span><b><span class="status-<?= e($inv['status']) ?>"><?= e(human_status_local($inv['status'])) ?></span></b></div>
        <div class="meta-line"><span>Jatuh Tempo</span><b><?= e($inv['due_date']) ?></b></div>
        <div class="meta-line"><span>Dibuat</span><b><?= e($inv['created_at']) ?></b></div>
      </div>
      <div style="flex:1 1 300px">
        <h3 style="margin:0 0 8px;font-size:15px">PROGRESS</h3>
        <div style="background:#eee;border-radius:6px;overflow:hidden;height:18px;position:relative;margin:0 0 8px">
          <div style="background:#3b82f6;height:100%;width:<?= $progress ?>%;transition:width .4s"></div>
        </div>
  <div style="font-size:12px;color:#555;margin:0 0 12px"><?= $progress ?>% terbayar (Rp <?= number_format($inv['paid_amount'],0,',','.') ?> dari Rp <?= number_format($inv['amount'],0,',','.') ?>)</div>
        <?php if(in_array($inv['status'],['pending','partial'])): ?>
        <?php $walletSaldo = wallet_balance($conn,$uid); $remaining = (float)$inv['amount'] - (float)$inv['paid_amount']; ?>
        <?php if($walletSaldo>0): ?>
          <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="wallet_pay">
            <div style="display:flex;flex-direction:column;gap:4px">
              <label style="font-size:11px;font-weight:600">Bayar Pakai Wallet (Saldo Rp <?= number_format($walletSaldo,0,',','.') ?>)</label>
              <input type="number" step="1000" min="1000" name="amount" value="<?= (int)min($walletSaldo,$remaining) ?>" max="<?= (int)min($walletSaldo,$remaining) ?>" style="padding:8px 10px;width:180px" required>
            </div>
            <button class="btn-action outline" style="height:40px;padding:0 20px">Bayar Wallet</button>
          </form>
        <?php endif; ?>
        <form method="post" action="javascript:void(0)" onsubmit="initGatewayPayment(this)" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px">
          <div style="display:flex;flex-direction:column;gap:4px">
            <label style="font-size:11px;font-weight:600">Gateway (Virtual)</label>
            <input type="number" step="1000" min="1000" value="<?= (int)$remaining ?>" max="<?= (int)$remaining ?>" name="gw_amount" style="padding:8px 10px;width:160px" required>
          </div>
          <button class="btn-action" style="height:40px;padding:0 24px">Bayar via Gateway</button>
        </form>
        <script>
        async function initGatewayPayment(f){
          const amt = f.gw_amount.value;
          try{
            const res = await fetch('../gateway_init.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'invoice_id=<?= (int)$inv['id'] ?>&amount='+encodeURIComponent(amt)});
            const j = await res.json();
            if(!j.ok){ alert('Gagal inisiasi gateway'); return; }
            window.location = j.redirect;
          }catch(e){ alert('Error: '+e); }
        }
        </script>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="start_manual_payment">
          <?php $remaining = (float)$inv['amount'] - (float)$inv['paid_amount']; ?>
          <div style="display:flex;flex-direction:column;gap:4px">
            <label style="font-size:11px;font-weight:600">Nominal Bayar</label>
            <input type="number" step="1000" name="amount" value="<?= (int)$remaining ?>" max="<?= (int)$remaining ?>" style="padding:8px 10px;width:160px" required>
          </div>
          <button class="btn-action primary" style="height:40px;padding:0 24px">Bayar Sekarang</button>
        </form>
  <div style="font-size:11px;color:#666;margin-top:6px">Gunakan salah satu metode di atas. Bukti transfer manual diperlukan untuk pembayaran manual.</div>
        <?php else: ?>
          <div style="font-size:12px;color:#666">Invoice sudah <?= e($inv['status']) ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="panel" style="margin-bottom:28px">
  <h2 style="margin:0 0 14px;font-size:18px">Payments</h2>
    <div class="table-wrap" style="overflow-x:auto">
      <table class="table" style="min-width:620px">
  <thead><tr><th>ID</th><th>Method</th><th>Amount</th><th>Status</th><th>Dibuat</th><th>Bukti</th></tr></thead>
        <tbody>
          <?php if(!$payments): ?><tr><td colspan="5" style="text-align:center;font-size:12px;color:#777">Belum ada payment.</td></tr><?php else: foreach($payments as $p): ?>
            <tr>
              <td>#<?= (int)$p['id'] ?></td>
              <td><?= e($p['method']) ?></td>
              <td>Rp <?= number_format($p['amount'],0,',','.') ?></td>
              <td><span class="status-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
              <td><?= e($p['created_at']) ?></td>
              <td style="font-size:11px">
                <?php if(!empty($p['proof_file'])): ?>
                  <a href="../uploads/payment_proof/<?= e($p['proof_file']) ?>" target="_blank">Lihat</a>
                <?php else: ?>-
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php 
      $canUpload = null; 
  foreach($payments as $pp){ if(in_array($pp['status'],['initiated','awaiting_proof']) && empty($pp['proof_file'])){ $canUpload=$pp; break; } }
      if($canUpload): ?>
      <div style="margin-top:16px">
        <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="upload_proof">
            <input type="hidden" name="payment_id" value="<?= (int)$canUpload['id'] ?>">
            <div style="display:flex;flex-direction:column;gap:4px">
              <label style="font-size:11px;font-weight:600">Upload Bukti (jpg/png/pdf)</label>
              <input type="file" name="proof" accept="image/*,.pdf" required style="padding:6px 8px;background:#fff;border:1px solid #ccc;border-radius:6px">
            </div>
            <button class="btn-action primary" style="height:40px;padding:0 26px">Kirim Bukti</button>
        </form>
        <div style="font-size:11px;color:#666;margin-top:6px">Setelah upload, admin akan verifikasi.</div>
      </div>
    <?php endif; ?>
  </div>
  <div class="panel">
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
</main>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
