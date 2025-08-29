<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';

// Handle status transitions (settle / fail) for top-ups
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['aksi'], $_POST['pid'])){
  if(verify_csrf_token($_POST['csrf_token'] ?? '')){
    $pid=(int)$_POST['pid'];
    $aksi=$_POST['aksi'];
    if($aksi==='settle'){
      payment_update_status($conn,$pid,'settled',(int)($_SESSION['user_id']??0),'admin settle');
    } elseif($aksi==='fail'){
      payment_update_status($conn,$pid,'failed',(int)($_SESSION['user_id']??0),'admin mark failed');
    }
  }
  header('Location: '.url('admin/wallet_topups.php')); exit;
}

// Listing of recent wallet top-up payments (only payments without invoice)
$rows=[]; $sql="SELECT p.id,p.user_id,u.nama_wali,u.nama_santri,p.amount,p.status,p.settled_at,p.created_at,p.proof_file FROM payment p JOIN users u ON p.user_id=u.id WHERE p.invoice_id IS NULL ORDER BY p.id DESC LIMIT 200"; $res=mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)) $rows[]=$r;
// Filters & search
$filter_status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
// Re-query with filters
$params=[]; $types='';
$where = 'p.invoice_id IS NULL';
if($filter_status!==''){
  $where.=' AND p.status=?'; $params[]=$filter_status; $types.='s';
}
if($q!==''){
  $where.=' AND (u.nama_wali LIKE ? OR u.nama_santri LIKE ?)';
  $like='%'.$q.'%'; $params[]=$like; $params[]=$like; $types.='ss';
}
$rows=[]; $sqlBase="SELECT p.id,p.user_id,u.nama_wali,u.nama_santri,p.amount,p.status,p.settled_at,p.created_at,p.proof_file FROM payment p JOIN users u ON p.user_id=u.id WHERE $where ORDER BY p.id DESC LIMIT 300";
if($stmt = mysqli_prepare($conn,$sqlBase)){
  if($params){ mysqli_stmt_bind_param($stmt,$types,...$params); }
  mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt); while($res && $r=mysqli_fetch_assoc($res)) $rows[]=$r;
}
// Status distribution
$statusMap=['initiated'=>0,'awaiting_proof'=>0,'awaiting_confirmation'=>0,'settled'=>0,'failed'=>0];
$sqlDist="SELECT status,COUNT(*) c FROM payment p WHERE p.invoice_id IS NULL GROUP BY status";
$dres=mysqli_query($conn,$sqlDist); while($dres && $dr=mysqli_fetch_assoc($dres)){ $s=$dr['status']; if(isset($statusMap[$dr['status']])) $statusMap[$s]=(int)$dr['c']; }
require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell wallet-topups-page">
  <div class="content-header">
    <h1>Top-Up Wallet</h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="invoice.php">Invoice</a>
      <a class="qa-btn" href="generate_spp.php">Generate SPP</a>
    </div>
  </div>
  <p class="page-intro">Monitoring top-up saldo wallet terbaru. Settle setelah verifikasi bukti transfer.</p>
  <div class="inv-chips topup-chips" aria-label="Ringkasan status top-up">
    <div class="inv-chip info"><span class="k">Initiated</span><span class="v"><?= number_format($statusMap['initiated']) ?></span></div>
    <div class="inv-chip warn"><span class="k">Need Proof</span><span class="v"><?= number_format($statusMap['awaiting_proof']) ?></span></div>
    <div class="inv-chip warn"><span class="k">Need Confirm</span><span class="v"><?= number_format($statusMap['awaiting_confirmation']) ?></span></div>
    <div class="inv-chip ok"><span class="k">Settled</span><span class="v"><?= number_format($statusMap['settled']) ?></span></div>
    <div class="inv-chip danger"><span class="k">Failed</span><span class="v"><?= number_format($statusMap['failed']) ?></span></div>
  </div>
  <form method="get" class="topup-filter" autocomplete="off">
    <div class="grp">
      <label for="fStatus">Status</label>
      <select id="fStatus" name="status">
        <option value="">Semua</option>
        <?php foreach(array_keys($statusMap) as $st): ?>
          <option value="<?= e($st) ?>" <?php if($filter_status===$st) echo 'selected'; ?>><?= str_replace('_',' ',ucfirst($st)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="grp">
      <label for="fSearch">Cari (Wali / Santri)</label>
      <input type="text" id="fSearch" name="q" value="<?= e($q) ?>" placeholder="Nama..." />
    </div>
    <div class="grp actions">
      <button class="btn-action primary">Filter</button>
      <a href="wallet_topups.php" class="btn-action" title="Reset">Reset</a>
    </div>
  </form>
  <div class="panel">
    <div class="table-wrap">
      <table class="table mini-table wallet-topup-table" aria-describedby="topupCaption" style="min-width:880px">
        <caption id="topupCaption" style="position:absolute;left:-9999px;top:-9999px;">Daftar top-up wallet</caption>
        <thead><tr><th>ID</th><th>Wali</th><th>Santri</th><th class="num">Jumlah</th><th>Bukti</th><th>Status</th><th>Dibuat</th><th>Settled</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if($rows){ foreach($rows as $r){ $stClass='pay-status-'.str_replace('_','-',$r['status']); ?>
          <tr class="<?= e($stClass) ?>">
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= e($r['nama_wali']) ?></td>
            <td><?= e($r['nama_santri']) ?></td>
            <td class="num">Rp <?= number_format((float)$r['amount'],0,',','.') ?></td>
            <td>
              <?php if($r['proof_file']): $pf=url('uploads/'.rawurlencode($r['proof_file'])); ?>
                <button type="button" class="btn-action small btn-proof" data-img="<?= e($pf) ?>">Lihat</button>
              <?php else: ?><span class="na">(belum)</span><?php endif; ?>
            </td>
            <td><span class="status-badge <?= e($stClass) ?>"><?= e(str_replace('_',' ',$r['status'])) ?></span></td>
            <td><?= $r['created_at']?date('d M Y H:i',strtotime($r['created_at'])):'-' ?></td>
            <td><?= $r['settled_at']?date('d M Y H:i',strtotime($r['settled_at'])):'-' ?></td>
            <td class="acts">
              <?php if(in_array($r['status'],['awaiting_confirmation','awaiting_proof','initiated'])): ?>
                <form method="post" class="inline" onsubmit="return confirm('Settle top-up #<?= (int)$r['id'] ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
                  <input type="hidden" name="pid" value="<?= (int)$r['id'] ?>" />
                  <input type="hidden" name="aksi" value="settle" />
                  <button class="btn-action small" type="submit">Settle</button>
                </form>
                <form method="post" class="inline" onsubmit="return confirm('Tandai gagal #<?= (int)$r['id'] ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
                  <input type="hidden" name="pid" value="<?= (int)$r['id'] ?>" />
                  <input type="hidden" name="aksi" value="fail" />
                  <button class="btn-action small danger" type="submit">Fail</button>
                </form>
              <?php else: ?><span class="dash">-</span><?php endif; ?>
            </td>
          </tr>
        <?php } } else { ?>
          <tr><td colspan="9" style="text-align:center;color:#777;font-size:13px">Belum ada top-up.</td></tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="proof-modal" id="proofModal" hidden>
  <div class="pm-back" data-close></div>
  <div class="pm-card">
    <button type="button" class="pm-close" data-close>&times;</button>
    <img alt="Bukti top-up" id="pmImg" src="" loading="lazy" />
  </div>
</div>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">(function(){
  const modal=document.getElementById('proofModal'); const img=document.getElementById('pmImg');
  function open(src){ if(!modal) return; img.src=src; modal.hidden=false; document.body.classList.add('modal-open'); }
  function close(){ if(!modal) return; modal.hidden=true; img.src=''; document.body.classList.remove('modal-open'); }
  document.querySelectorAll('.btn-proof').forEach(b=>b.addEventListener('click',()=>open(b.getAttribute('data-img'))));
  modal?.addEventListener('click',e=>{ if(e.target.hasAttribute('data-close')) close(); });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape' && !modal.hidden) close(); });
})();</script>
