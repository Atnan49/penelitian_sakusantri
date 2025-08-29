<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';

$msg=$err=null; $period = $_GET['period'] ?? date('Ym');
$filter_status = $_GET['status'] ?? '';
$do = $_POST['do'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verify_csrf_token($_POST['csrf_token'] ?? '')){ $err='Token tidak valid'; }
  else if($do==='gen_spp'){
    $p = preg_replace('/[^0-9]/','', $_POST['period'] ?? '');
    if(strlen($p)!==6){ $err='Format periode salah'; }
    else {
  $amount = normalize_amount($_POST['amount'] ?? 0);
      if($amount<=0) $err='Nominal harus > 0';
      else {
        $due = $_POST['due_date'] ?? '';
        if($due && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) $due='';
        $res = invoice_generate_spp_bulk($conn,$p,$amount,$due?:null);
        $msg = 'Generate SPP '.$p.': dibuat '.$res['created'].', skip '.$res['skipped'];
        $period = $p;
      }
    }
  }
}

// Ambil daftar invoice
$where = '1=1';
$params=[]; $types='';
if($period){ $where.=' AND period=?'; $params[]=$period; $types.='s'; }
if($filter_status){ $where.=' AND status=?'; $params[]=$filter_status; $types.='s'; }
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100; $offset = ($page-1)*$perPage; if($offset>5000) $offset=5000; // hard cap
$sql = "SELECT i.*, u.nama_santri FROM invoice i JOIN users u ON i.user_id=u.id WHERE $where ORDER BY i.id DESC LIMIT $perPage OFFSET $offset";
$total = 0; $countSql = "SELECT COUNT(*) c FROM invoice i WHERE $where"; if($cstmt = mysqli_prepare($conn,$countSql)){ if($params){ mysqli_stmt_bind_param($cstmt,$types,...$params); } mysqli_stmt_execute($cstmt); $cr = mysqli_stmt_get_result($cstmt); if($cr && ($crow=mysqli_fetch_assoc($cr))) $total=(int)$crow['c']; }
$totalPages = max(1, (int)ceil($total / $perPage)); if($page>$totalPages) $page=$totalPages;
$rows=[];
if($stmt = mysqli_prepare($conn,$sql)){
  if($params){ mysqli_stmt_bind_param($stmt,$types,...$params); }
  mysqli_stmt_execute($stmt); $r = mysqli_stmt_get_result($stmt); while($r && $row=mysqli_fetch_assoc($r)){ $rows[]=$row; }
}

// Status distribution (ignore individual status filter so admin bisa lihat komposisi)
$distCounts = ['pending'=>0,'partial'=>0,'paid'=>0,'overdue'=>0,'canceled'=>0];
$outstandingTotal = 0; $baseWhere = '1=1'; $baseParams=[]; $baseTypes='';
if($period){ $baseWhere.=' AND period=?'; $baseParams[]=$period; $baseTypes.='s'; }
$sqlDist = "SELECT status, COUNT(*) c, SUM(amount-paid_amount) os FROM invoice WHERE $baseWhere GROUP BY status";
if($dstmt = mysqli_prepare($conn,$sqlDist)){
  if($baseParams){ mysqli_stmt_bind_param($dstmt,$baseTypes,...$baseParams); }
  mysqli_stmt_execute($dstmt); $dr = mysqli_stmt_get_result($dstmt);
  while($dr && $drow = mysqli_fetch_assoc($dr)){
    $st = $drow['status']; if(isset($distCounts[$st])){ $distCounts[$st] = (int)$drow['c']; }
    if(in_array($st,['pending','partial','overdue'])){ $outstandingTotal += (float)($drow['os'] ?? 0); }
  }
}
$totalAll = array_sum($distCounts) ?: 1;
require_once BASE_PATH.'/src/includes/header.php';
?>
<div class="page-shell invoice-page">
  <div class="content-header">
    <h1>Invoice SPP</h1>
  <div class="quick-actions-inline">
      <a class="qa-btn" href="invoice_overdue_run.php" title="Tandai Overdue">Run Overdue</a>
      <a class="qa-btn" href="generate_spp.php" title="Halaman Generate SPP">Generate SPP</a>
    </div>
  </div>
  <?php if($msg): ?><div class="alert success" role="alert"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>

  <div class="inv-chips" aria-label="Ringkasan status periode">
    <div class="inv-chip warn" title="Belum Dibayar (Pending + Partial)"><span class="k">Belum</span><span class="v"><?= number_format($distCounts['pending'] + $distCounts['partial']); ?></span></div>
    <div class="inv-chip danger" title="Overdue"><span class="k">Overdue</span><span class="v"><?= number_format($distCounts['overdue']); ?></span></div>
    <div class="inv-chip ok" title="Lunas"><span class="k">Lunas</span><span class="v"><?= number_format($distCounts['paid']); ?></span></div>
    <div class="inv-chip mute" title="Batal"><span class="k">Batal</span><span class="v"><?= number_format($distCounts['canceled']); ?></span></div>
    <div class="inv-chip info" title="Outstanding"><span class="k">Outstanding</span><span class="v">Rp <?= number_format($outstandingTotal,0,',','.'); ?></span></div>
    <button class="btn-action small btn-generate-open" type="button">Generate SPP</button>
  </div>

  <!-- Modal Generate SPP -->
  <div class="ds-modal gen-modal" id="genModal" hidden>
    <div class="modal-card small">
      <div class="modal-head"><h3>Generate SPP</h3><button type="button" class="close-gen" aria-label="Tutup">×</button></div>
      <form method="post" class="gen-form-simple" id="genSPPForm" autocomplete="off">
        <?php 
          $curYear = substr($period,0,4); $curMonth = substr($period,4,2); 
          $nowYear = (int)date('Y');
          $yearStart = max(2020,$nowYear-1); $yearEnd = $nowYear+3; 
          if((int)$curYear < $yearStart){ $yearStart = (int)$curYear; }
          if((int)$curYear > $yearEnd){ $yearEnd = (int)$curYear; }
        ?>
        <div class="row">
          <label>Periode</label>
          <div class="period-dual">
            <div class="ywrap">
              <select id="yearInput" required aria-label="Tahun">
                <?php for($y=$yearStart;$y<=$yearEnd;$y++): ?>
                  <option value="<?= $y ?>" <?php if((int)$curYear===$y) echo 'selected'; ?>><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="mwrap">
              <select id="monthInput" aria-label="Bulan" required>
                <?php for($m=1;$m<=12;$m++): $mm=str_pad((string)$m,2,'0',STR_PAD_LEFT); ?>
                  <option value="<?= $mm ?>" <?php if($mm===$curMonth) echo 'selected'; ?>><?= $mm ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
          <input type="hidden" id="periodInput" name="period" value="<?= e($period) ?>">
        </div>
        <div class="row">
          <label for="amountInput">Nominal</label>
          <input type="number" id="amountInput" name="amount" value="150000" step="1000" min="1000" required>
          <div class="small muted" id="amountPreview">Rp 150.000</div>
        </div>
        <div class="row">
          <label for="dueDateInput">Jatuh Tempo</label>
          <input type="date" id="dueDateInput" name="due_date" value="">
          <div class="due-helpers">
            <button type="button" class="btn-action small end-month">Akhir Bulan</button>
            <button type="button" class="btn-action small plus7">+7 Hari</button>
            <label class="auto"><input type="checkbox" id="autoDueEnd" checked> Auto akhir bulan</label>
          </div>
        </div>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="gen_spp">
        <div class="modal-actions"><button class="btn-action primary">Generate</button></div>
      </form>
    </div>
  </div>

  <div class="panel section invoice-list">
    <div class="panel-header"><h2>Daftar Invoice</h2></div>
    <?php 
      $fYear = substr($period,0,4); $fMonth = substr($period,4,2); 
      $nowYear = (int)date('Y');
      $fyStart = max(2020,$nowYear-1); $fyEnd = $nowYear+3;
      if((int)$fYear < $fyStart){ $fyStart = (int)$fYear; }
      if((int)$fYear > $fyEnd){ $fyEnd = (int)$fYear; }
    ?>
    <form method="get" class="inv-filter" autocomplete="off" id="filterInvoiceForm">
      <div class="grp period">
        <label>Periode</label>
        <div class="period-dual small">
          <select id="fYear" aria-label="Tahun">
            <option value="">--</option>
            <?php for($y=$fyStart;$y<=$fyEnd;$y++): ?>
              <option value="<?= $y ?>" <?php if((int)$fYear===$y) echo 'selected'; ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
          <select id="fMonth" aria-label="Bulan">
            <option value="">--</option>
            <?php for($m=1;$m<=12;$m++): $mm=str_pad((string)$m,2,'0',STR_PAD_LEFT); ?>
              <option value="<?= $mm ?>" <?php if($mm===$fMonth) echo 'selected'; ?>><?= $mm ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <input type="hidden" id="fPeriodHidden" name="period" value="<?= e($period) ?>">
      </div>
      <div class="grp status">
        <label for="fStatus">Status</label>
        <select id="fStatus" name="status">
          <option value="">Semua</option>
          <?php foreach(['pending','partial','paid','overdue','canceled'] as $st): ?>
            <option value="<?= $st ?>" <?php if($filter_status===$st) echo 'selected'; ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grp actions">
        <button class="btn-action outline">Filter</button>
        <a href="?period=<?= urlencode($period) ?>" class="btn-action" title="Reset">Reset</a>
      </div>
    </form>
    <div class="table-wrap">
  <table class="table table-compact invoice-table slim" style="min-width:780px" aria-describedby="invCaption">
        <caption id="invCaption" style="position:absolute;left:-9999px;top:-9999px;">Daftar invoice SPP</caption>
        <thead><tr>
          <th>ID</th>
          <th>Santri</th>
          <th>Periode</th>
          <th class="num">Nominal</th>
            <th class="num">Dibayar</th>
          <th>Status</th>
          <th>Jatuh Tempo</th>
          <th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" style="text-align:center;font-size:13px;color:#777">Belum ada invoice.</td></tr>
        <?php else: foreach($rows as $inv): ?>
          <?php $amt=(float)$inv['amount']; $paid=(float)$inv['paid_amount']; $ratio=$amt>0?min(1,$paid/$amt):0; $pct=round($ratio*100,1); ?>
          <tr class="st-<?= e(str_replace('_','-',$inv['status'])) ?>">
            <td>#<?= (int)$inv['id'] ?></td>
            <td><?= e($inv['nama_santri']) ?></td>
            <td><?= e($inv['period']) ?></td>
            <td class="num">Rp <?= number_format($amt,0,',','.') ?></td>
            <td class="num">Rp <?= number_format($paid,0,',','.') ?><?php if($paid>0 && $paid<$amt) echo ' <span class="pct">('.$pct.'%)</span>'; ?></td>
            <td><span class="status-<?= e(str_replace('_','-',$inv['status'])) ?>"><?= e(ucfirst($inv['status'])) ?></span></td>
            <td><?= $inv['due_date'] ?></td>
            <td><a class="btn-detail" href="invoice_detail.php?id=<?= (int)$inv['id'] ?>">Detail</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($totalPages>1): ?>
      <nav class="page-nav" aria-label="Navigasi halaman">
        <span class="current">Hal <?= $page ?>/<?= $totalPages ?></span>
        <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" aria-label="Halaman sebelumnya">&larr;</a><?php endif; ?>
        <?php if($page<$totalPages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" aria-label="Halaman berikutnya">&rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
(function(){
  const genModal=document.getElementById('genModal');
  const openBtn=document.querySelector('.btn-generate-open');
  const closeBtn=genModal? genModal.querySelector('.close-gen'):null;
  if(openBtn&&genModal){openBtn.addEventListener('click',()=>{genModal.hidden=false; setTimeout(()=>document.getElementById('yearInput')?.focus(),40);});}
  if(closeBtn){closeBtn.addEventListener('click',()=>genModal.hidden=true);} 
  genModal?.addEventListener('click',e=>{if(e.target===genModal) genModal.hidden=true;});
  document.addEventListener('keydown',e=>{if(e.key==='Escape' && !genModal.hidden) genModal.hidden=true;});
  // Generate form logic
  const genForm=document.getElementById('genSPPForm'); if(genForm){
    const yearInput=document.getElementById('yearInput');
    const monthInput=document.getElementById('monthInput');
    const hiddenPeriod=document.getElementById('periodInput');
    const amountInput=document.getElementById('amountInput');
    const amountPreview=document.getElementById('amountPreview');
    const dueInput=document.getElementById('dueDateInput');
    const autoDue=document.getElementById('autoDueEnd');
    function syncPeriod(){ const y=yearInput.value; const m=monthInput.value; if(y&&m){ hiddenPeriod.value = y+m; } if(autoDue.checked) setAutoDue(); }
    function setAutoDue(){ const y=parseInt(yearInput.value,10); const m=parseInt(monthInput.value,10); if(!y||!m) return; const ld=new Date(y,m,0).getDate(); dueInput.value=`${y}-${String(m).padStart(2,'0')}-${String(ld).padStart(2,'0')}`; }
    yearInput.addEventListener('change',syncPeriod); monthInput.addEventListener('change',syncPeriod);
    amountInput.addEventListener('input',()=>{ const v=parseInt(amountInput.value||'0',10)||0; amountPreview.textContent='Rp '+v.toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.'); });
    genForm.querySelector('.end-month').addEventListener('click',()=>{ setAutoDue(); autoDue.checked=false; });
    genForm.querySelector('.plus7').addEventListener('click',()=>{ const base= dueInput.value? new Date(dueInput.value): new Date(); base.setDate(base.getDate()+7); dueInput.value=base.toISOString().slice(0,10); autoDue.checked=false; });
    autoDue.addEventListener('change',()=>{ if(autoDue.checked) setAutoDue(); });
    syncPeriod(); if(autoDue.checked) setAutoDue();
  }
  // Filter period logic
  const filterForm=document.getElementById('filterInvoiceForm'); if(filterForm){
    const fy=document.getElementById('fYear'); const fm=document.getElementById('fMonth'); const hidden=document.getElementById('fPeriodHidden');
  function rebuild(){ const y=(fy.value||'').trim(); const m=(fm.value||'').trim(); if(y && m){ hidden.value=y+ m; } else { hidden.value=''; } }
  fy?.addEventListener('change',rebuild); fm?.addEventListener('change',rebuild);
    rebuild();
  }
})();
</script>
