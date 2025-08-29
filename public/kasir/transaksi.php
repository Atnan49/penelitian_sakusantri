<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin'); // sementara admin sebagai kasir

// Deteksi kolom avatar agar aman sebelum migrasi
$hasAvatar=false; if($chk=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'avatar'")){ if(mysqli_fetch_assoc($chk)) $hasAvatar=true; }
$avatarSelect = $hasAvatar ? ',avatar' : '';

// Ambil NISN dari GET untuk pencarian sederhana
$nisn = trim($_GET['nisn'] ?? '');
$pengguna = null;
if($nisn !== ''){
  if($stmt = mysqli_prepare($conn,'SELECT id,nama_wali,nama_santri,nisn,saldo'.$avatarSelect.' FROM users WHERE nisn=? AND role="wali_santri" LIMIT 1')){
    mysqli_stmt_bind_param($stmt,'s',$nisn); mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt); $pengguna = $res?mysqli_fetch_assoc($res):null;
    if(!$pengguna) $err = 'Akun dengan NISN itu tidak ditemukan'; else if(!$hasAvatar) $pengguna['avatar']='';
  }
}

// Proses pembelian (debit)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['aksi']??'')==='beli'){
  $token = $_POST['csrf_token'] ?? '';
  if(!verify_csrf_token($token)){
    $err = 'Token tidak valid';
  } else {
    $uid = (int)($_POST['user_id']??0);
    $nominal = (int)($_POST['nominal']??0);
    $catatan = trim($_POST['catatan'] ?? 'Belanja koperasi');
    if($uid<=0 || $nominal<=0){ $err='Data tidak lengkap'; }
    else {
      @mysqli_begin_transaction($conn);
      $lock = mysqli_prepare($conn,'SELECT saldo FROM users WHERE id=? FOR UPDATE');
      if($lock){ mysqli_stmt_bind_param($lock,'i',$uid); mysqli_stmt_execute($lock); $rs= mysqli_stmt_get_result($lock); $row=$rs?mysqli_fetch_assoc($rs):null; }
      if(empty($row)){ @mysqli_rollback($conn); $err='Pengguna tidak ditemukan'; }
      elseif((int)$row['saldo'] < $nominal){ @mysqli_rollback($conn); $err='Saldo tidak cukup'; }
      else {
        $upd = mysqli_prepare($conn,'UPDATE users SET saldo = saldo - ? WHERE id=?');
        if($upd){ $nF = (float)$nominal; mysqli_stmt_bind_param($upd,'di',$nF,$uid); mysqli_stmt_execute($upd); }
        $ins = mysqli_prepare($conn,'INSERT INTO wallet_ledger (user_id,direction,amount,ref_type,ref_id,note) VALUES (? ,"debit", ?, "purchase", NULL, ?)');
        if($ins){ $nF=(float)$nominal; mysqli_stmt_bind_param($ins,'ids',$uid,$nF,$catatan); mysqli_stmt_execute($ins); }
        add_notification($conn,$uid,'purchase','Belanja koperasi Rp '.number_format($nominal,0,',','.'));
        @mysqli_commit($conn);
        $msg='Transaksi diproses';
  // refresh data pengguna (saldo terbaru)
  if($stmt = mysqli_prepare($conn,'SELECT id,nama_wali,nama_santri,nisn,saldo'.$avatarSelect.' FROM users WHERE id=? LIMIT 1')){ mysqli_stmt_bind_param($stmt,'i',$uid); mysqli_stmt_execute($stmt); $r=mysqli_stmt_get_result($stmt); $pengguna=$r?mysqli_fetch_assoc($r):$pengguna; if($pengguna && !$hasAvatar) $pengguna['avatar']=''; }
        $nisn = $pengguna['nisn'] ?? $nisn; // kalau kolom nisn tidak di select sebelumnya aman di GET
      }
    }
  }
}

// Transaksi terbaru
$recent=[]; if($conn){
  if($rsR=mysqli_query($conn,"SELECT l.id,l.amount,l.created_at,u.nama_santri FROM wallet_ledger l JOIN users u ON l.user_id=u.id WHERE l.ref_type='purchase' ORDER BY l.id DESC LIMIT 8")){
    while($r=mysqli_fetch_assoc($rsR)) $recent[]=$r;
  }
}

require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell kasir-page minimal">
  <div class="content-header">
    <h1>Kasir Koperasi</h1>
    <div class="quick-actions-inline"></div>
  </div>
  <?php if(!empty($msg)): ?><div class="alert success" role="alert"><?= e($msg) ?></div><?php endif; ?>
  <?php if(!empty($err)): ?><div class="alert error" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="get" class="kasir-search-pill" autocomplete="off">
    <div class="pill">
      <span class="icon" aria-hidden="true">🔍</span>
      <input type="text" name="nisn" value="<?= e($nisn) ?>" placeholder="Masukkan / scan NISN" autofocus />
      <?php if($nisn!==''): ?><button type="button" class="clear" aria-label="Bersihkan" onclick="this.closest('form').querySelector('[name=nisn]').value=''; this.closest('form').submit();">&times;</button><?php endif; ?>
    </div>
    <noscript><button class="btn-action primary">Cari</button></noscript>
  </form>

  <?php if($pengguna): ?>
  <div class="kasir-card pengguna-block">
    <div class="pb-top">
      <div class="pb-ident">
        <?php 
          $av = trim($pengguna['avatar'] ?? '');
          $initial = mb_strtoupper(mb_substr($pengguna['nama_santri']??'',0,1,'UTF-8'),'UTF-8');
          $avUrl = $av!=='' ? url('assets/uploads/'.rawurlencode($av)) : '';
        ?>
        <div class="pb-avatar avatar-sm<?= $avUrl===''?' no-img':'' ?>">
          <?php if($avUrl!==''): ?>
            <img src="<?= e($avUrl) ?>" alt="Avatar <?= e($pengguna['nama_santri']) ?>" loading="lazy" onerror="this.closest('.avatar-sm').classList.add('no-img'); this.remove();" />
          <?php endif; ?>
          <?php if($avUrl===''): ?><span class="av-initial" aria-hidden="true"><?= e($initial) ?></span><?php endif; ?>
        </div>
        <div class="nm-santri"><?= e($pengguna['nama_santri']) ?></div>
        <div class="nm-wali">Wali: <span><?= e($pengguna['nama_wali']) ?></span></div>
      </div>
      <div class="pb-saldo"><span class="label">Saldo</span><span class="val">Rp <?= number_format((float)$pengguna['saldo'],0,',','.') ?></span></div>
    </div>
    <form method="post" class="form-beli compact">
      <input type="hidden" name="aksi" value="beli" />
      <input type="hidden" name="user_id" value="<?= (int)$pengguna['id'] ?>" />
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" />
      <div class="fld">
        <label>Nominal</label>
        <input type="text" id="nominalDisplay" placeholder="Rp 0" inputmode="numeric" autocomplete="off" required />
        <input type="hidden" name="nominal" id="nominal" />
      </div>
      <div class="quick-amounts" aria-label="Jumlah cepat">
        <?php foreach([5000,10000,20000,50000,100000] as $q): ?>
          <button type="button" class="qa-chip" data-val="<?= $q ?>"><?= number_format($q/1000,0) ?>k</button>
        <?php endforeach; ?>
      </div>
      <div class="fld">
        <label>Catatan</label>
        <input type="text" name="catatan" value="Belanja koperasi" />
      </div>
      <div class="actions"><button type="submit" class="btn-action primary">Proses</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="kasir-recent">
    <h2 class="section-title">Transaksi Terakhir</h2>
    <div class="recent-list simple">
      <?php if(!$recent): ?>
        <div class="empty">Belum ada transaksi.</div>
      <?php else: foreach($recent as $r): ?>
        <div class="rc-row"><div class="rc-main"><span class="rc-name"><?= e($r['nama_santri']) ?></span><span class="rc-time"><?= date('d M H:i',strtotime($r['created_at'])) ?></span></div><div class="rc-amt">- Rp <?= number_format($r['amount'],0,',','.') ?></div></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">(function(){
 const hidden=document.getElementById('nominal');
 const disp=document.getElementById('nominalDisplay');
 function digits(s){return (s||'').replace(/[^0-9]/g,'');}
 function fmt(n){ try{return 'Rp '+Number(n).toLocaleString('id-ID');}catch(e){return 'Rp '+n;}}
 function sync(v){ if(hidden) hidden.value=String(v||0); if(disp) disp.value=fmt(v||0);} 
 if(disp){ const on=()=>{const v=parseInt(digits(disp.value)||'0',10); sync(v);}; disp.addEventListener('input',on); disp.addEventListener('blur',on); on(); }
 document.querySelectorAll('.qa-chip').forEach(ch=>ch.addEventListener('click',()=>{const v=parseInt(ch.getAttribute('data-val'),10)||0; sync(v);}));
})();</script>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>
