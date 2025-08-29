<?php
require_once __DIR__.'/../../src/includes/init.php';
require_once __DIR__.'/../includes/session_check.php';
require_role('admin');
require_once BASE_PATH.'/src/includes/payments.php';
$pesan=$err=null;
// Preview endpoint
if(isset($_GET['preview']) && $_GET['preview']=='1'){
  header('Content-Type: application/json; charset=utf-8');
  $y=preg_replace('/[^0-9]/','', $_GET['year']??''); $m=preg_replace('/[^0-9]/','', $_GET['month']??'');
  $resp=['ok'=>false];
  if(strlen($y)===4 && (int)$m>=1 && (int)$m<=12){
    $period=$y.str_pad($m,2,'0',STR_PAD_LEFT); $totalWali=0; $existing=0;
    if($rs=mysqli_query($conn,"SELECT COUNT(id) c FROM users WHERE role='wali_santri'")){ $totalWali=(int)(mysqli_fetch_assoc($rs)['c']??0); }
    if($st=mysqli_prepare($conn,'SELECT COUNT(DISTINCT user_id) c FROM invoice WHERE type="spp" AND period=?')){ mysqli_stmt_bind_param($st,'s',$period); mysqli_stmt_execute($st); $r=mysqli_stmt_get_result($st); if($r && ($rw=mysqli_fetch_assoc($r))) $existing=(int)$rw['c']; }
    $resp=['ok'=>true,'period'=>$period,'total_wali'=>$totalWali,'sudah_ada'=>$existing,'akan_dibuat'=>max(0,$totalWali-$existing)];
  }
  echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
// Handle submit (structured fields)
$rawYear=trim($_POST['year']??''); $rawMonth=trim($_POST['month']??'');
$period=''; if($rawYear && $rawMonth){ $period=preg_replace('/[^0-9]/','',$rawYear).str_pad(preg_replace('/[^0-9]/','',$rawMonth),2,'0',STR_PAD_LEFT); }
$amount = normalize_amount($_POST['amount'] ?? 0);
$due_date = trim($_POST['due_date'] ?? '');
if($_SERVER['REQUEST_METHOD']==='POST'){
  $tok=$_POST['csrf_token']??''; if(!verify_csrf_token($tok)){ $err='Token tidak valid'; }
  elseif(!preg_match('/^[0-9]{6}$/',$period)){ $err='Pilih Tahun & Bulan'; }
  elseif($amount<=0){ $err='Nominal harus > 0'; }
  else {
    if($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due_date)) $due_date='';
    $res=invoice_generate_spp_bulk($conn,$period,$amount,$due_date?:null);
    $pesan='Generate SPP '.$period.' selesai. Dibuat: '.$res['created'].', Skip: '.$res['skipped'];
  }
}
require_once __DIR__.'/../../src/includes/header.php';
?>
<div class="page-shell generate-spp-page">
  <div class="content-header">
    <h1>Generate SPP</h1>
    <div class="quick-actions-inline">
      <a class="qa-btn" href="invoice.php" title="Lihat Invoice">Lihat Invoice</a>
    </div>
  </div>
  <?php if($pesan): ?><div class="alert success" role="alert"><?= e($pesan) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
  <?php $recentPeriods=[]; $rsP=mysqli_query($conn,"SELECT period, COUNT(*) c, SUM(amount) total FROM invoice WHERE type='spp' GROUP BY period ORDER BY period DESC LIMIT 6"); while($rsP && $r=mysqli_fetch_assoc($rsP)) $recentPeriods[]=$r; $nowY=(int)date('Y'); $yStart=max(2020,$nowY-1); $yEnd=$nowY+3; ?>
  <div class="inv-chips spp-preview-chips" aria-label="Preview generate">
    <div class="inv-chip info"><span class="k">Periode</span><span class="v" id="chipPeriod">-</span></div>
    <div class="inv-chip"><span class="k">Total Wali</span><span class="v" id="chipTotal">-</span></div>
    <div class="inv-chip warn"><span class="k">Sudah Ada</span><span class="v" id="chipExisting">-</span></div>
    <div class="inv-chip ok"><span class="k">Akan Dibuat</span><span class="v" id="chipToCreate">-</span></div>
    <button class="btn-action small" type="button" id="btnFocusForm">Atur</button>
  </div>
  <div class="panel generate-panel">
    <div class="gen-layout">
      <div class="gen-left">
        <form method="POST" id="genSPPForm" class="gen-form" autocomplete="off">
          <div class="row">
            <label>Periode</label>
            <div class="period-dual">
              <div class="ywrap"><select name="year" id="yearSel" required><?php for($y=$yStart;$y<=$yEnd;$y++):?><option value="<?= $y ?>" <?php if(substr($period?:date('Ym'),0,4)==$y) echo 'selected';?>><?= $y ?></option><?php endfor;?></select></div>
              <div class="mwrap"><select name="month" id="monthSel" required><?php $curM=substr($period?:date('Ym'),4,2); for($m=1;$m<=12;$m++): $mm=str_pad($m,2,'0',STR_PAD_LEFT);?><option value="<?= $mm ?>" <?php if($mm==$curM) echo 'selected';?>><?= $mm ?></option><?php endfor;?></select></div>
            </div>
          </div>
          <div class="row">
            <label>Nominal</label>
            <div class="amount-wrap">
              <input type="text" id="amountDisplay" placeholder="Rp 0" inputmode="numeric" autocomplete="off" value="<?= $amount? 'Rp '.number_format($amount,0,',','.') : '' ?>">
              <input type="hidden" name="amount" id="amountRaw" value="<?= (float)$amount ?>">
              <div class="quick-amt"><?php $__amts=[100000,150000,175000,200000,250000]; foreach($__amts as $qa){ ?><button type="button" data-v="<?= $qa ?>">Rp <?= number_format($qa,0,',','.') ?></button><?php } ?></div>
            </div>
          </div>
          <div class="row">
            <label>Jatuh Tempo</label>
            <div class="due-wrap">
              <input type="date" name="due_date" id="dueDateInput" value="<?= e($due_date) ?>">
              <div class="due-actions"><label class="auto"><input type="checkbox" id="autoEnd" checked> Akhir bulan otomatis</label><button type="button" class="btn-action xsmall btn-end-month">Akhir Bulan</button><button type="button" class="btn-action xsmall btn-plus7">+7 Hari</button></div>
            </div>
          </div>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="actions-row"><button type="submit" class="btn-action primary" id="btnGenerate" disabled>Generate</button></div>
          <div class="small muted">Wali yang sudah punya tagihan periode ini tidak dibuat ulang.</div>
        </form>
      </div>
      <div class="gen-right">
        <h3 style="margin:0 0 12px;font-size:16px;font-weight:700">Periode Terakhir</h3>
        <ul class="recent-periods"><?php if($recentPeriods){ foreach($recentPeriods as $rp){ ?><li><span class="p"><?= e($rp['period']) ?></span><span class="c"><?= (int)$rp['c'] ?> inv</span><span class="amt">Rp <?= number_format((float)$rp['total'],0,',','.') ?></span></li><?php } } else { ?><li class="empty">Belum ada.</li><?php } ?></ul>
        <div class="tip">Gunakan nominal konsisten untuk mempermudah monitoring.</div>
      </div>
    </div>
  </div>
  <div class="panel recent-invoices">
    <div class="panel-header"><h2>Invoice SPP Terbaru</h2></div>
    <?php $recent=[]; $rs=mysqli_query($conn,"SELECT id,user_id,type,period,amount,status,created_at FROM invoice WHERE type='spp' ORDER BY id DESC LIMIT 30"); while($rs && $r=mysqli_fetch_assoc($rs)) $recent[]=$r; ?>
    <div class="table-wrap"><table class="table mini-table" style="min-width:780px"><thead><tr><th>ID</th><th>Periode</th><th>Jumlah</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody><?php if($recent){ foreach($recent as $r){ ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['period']??'-') ?></td><td>Rp <?= number_format((float)$r['amount'],0,',','.') ?></td><td><span class="status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td><td><?= $r['created_at']?date('d M Y H:i',strtotime($r['created_at'])):'-' ?></td><td><a class="btn-detail" href="invoice_detail.php?id=<?= (int)$r['id'] ?>">Detail</a></td></tr><?php } } else { ?><tr><td colspan="6" style="text-align:center;color:#777;font-size:13px">Belum ada invoice SPP.</td></tr><?php } ?></tbody></table></div>
  </div>
</div>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">(function(){
  const year=document.getElementById('yearSel'); const month=document.getElementById('monthSel');
  const dAmt=document.getElementById('amountDisplay'); const rAmt=document.getElementById('amountRaw');
  const quick=document.querySelectorAll('.quick-amt button[data-v]');
  const due=document.getElementById('dueDateInput'); const autoEnd=document.getElementById('autoEnd');
  const pvPeriod=document.getElementById('pvPeriod'); const pvTotalWali=document.getElementById('pvTotalWali'); const pvSudah=document.getElementById('pvSudah'); const pvBuat=document.getElementById('pvBuat'); const pvStatus=document.getElementById('pvStatus'); const btnGen=document.getElementById('btnGenerate');
  // Chips
  const chipPeriod=document.getElementById('chipPeriod'); const chipTotal=document.getElementById('chipTotal'); const chipExisting=document.getElementById('chipExisting'); const chipToCreate=document.getElementById('chipToCreate');
  document.getElementById('btnFocusForm')?.addEventListener('click',()=>{ year?.focus(); });
  function onlyDigits(s){return (s||'').replace(/[^0-9]/g,'');}
  function fmt(n){return 'Rp '+(Number(n)||0).toLocaleString('id-ID');}
  function syncAmount(){const v=parseInt(onlyDigits(dAmt.value),10)||0; rAmt.value=v; dAmt.value=v?fmt(v):''; btnGen.disabled = v<=0 || (pvBuat.textContent==='-'||parseInt(pvBuat.textContent,10)<=0); }
  function setEndMonth(){ const y=parseInt(year.value,10); const m=parseInt(month.value,10); if(!y||!m) return; const last=new Date(y,m,0).getDate(); due.value=`${y}-${String(m).padStart(2,'0')}-${String(last).padStart(2,'0')}`; }
  let lastReq=0; let t=null;
  function preview(){ const y=year.value; const m=month.value; if(!y||!m){ pvStatus.textContent='Periode belum lengkap'; return;} const started=++lastReq; pvStatus.textContent='Memuat...'; btnGen.disabled=true;
    fetch(`generate_spp.php?preview=1&year=${encodeURIComponent(y)}&month=${encodeURIComponent(m)}`,{headers:{'Accept':'application/json'}})
      .then(r=>r.json()).then(j=>{ if(started!==lastReq) return; if(!j.ok){ pvStatus && (pvStatus.textContent='Preview gagal'); return; }
        if(pvPeriod) pvPeriod.textContent=j.period; if(pvTotalWali) pvTotalWali.textContent=j.total_wali; if(pvSudah) pvSudah.textContent=j.sudah_ada; if(pvBuat) pvBuat.textContent=j.akan_dibuat; if(pvStatus) pvStatus.textContent=j.akan_dibuat>0?'Siap generate':'Semua sudah ada';
        // update chips with pulse animation
        const upd=(el,val)=>{ if(!el) return; if(el.textContent!==String(val)){ el.textContent=val; el.classList.remove('pulse'); void el.offsetWidth; el.classList.add('pulse'); } };
        upd(chipPeriod,j.period); upd(chipTotal,j.total_wali); upd(chipExisting,j.sudah_ada); upd(chipToCreate,j.akan_dibuat);
        chipToCreate?.classList.toggle('zero', j.akan_dibuat<=0);
        syncAmount(); })
      .catch(()=>{ if(started!==lastReq) return; pvStatus.textContent='Gagal ambil preview'; });
  }
  function schedule(){ if(t) clearTimeout(t); t=setTimeout(preview,250); }
  year.addEventListener('change',()=>{ if(autoEnd.checked) setEndMonth(); schedule(); });
  month.addEventListener('change',()=>{ if(autoEnd.checked) setEndMonth(); schedule(); });
  dAmt.addEventListener('input',syncAmount); dAmt.addEventListener('blur',syncAmount); syncAmount();
  quick.forEach(b=>b.addEventListener('click',()=>{ rAmt.value=b.getAttribute('data-v'); dAmt.value=fmt(b.getAttribute('data-v')); syncAmount(); }));
  document.querySelector('.btn-end-month')?.addEventListener('click',()=>{ setEndMonth(); autoEnd.checked=false; });
  document.querySelector('.btn-plus7')?.addEventListener('click',()=>{ const base = due.value? new Date(due.value): new Date(); base.setDate(base.getDate()+7); due.value=base.toISOString().slice(0,10); autoEnd.checked=false; });
  autoEnd.addEventListener('change',()=>{ if(autoEnd.checked) setEndMonth(); }); if(autoEnd.checked) setEndMonth(); schedule();
})();</script>
<?php require_once __DIR__.'/../../src/includes/footer.php'; ?>
