<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('admin');

// --- Server-side filters & pagination ---
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 40; $offset=($page-1)*$perPage;

// Handle POST actions (add, reset password, delete)
if($_SERVER['REQUEST_METHOD']==='POST'){
    $token = $_POST['csrf_token'] ?? '';
    if(!verify_csrf_token($token)){
        $pesan_error='Token tidak valid.';
    } else {
        $aksi = $_POST['aksi'] ?? '';
        if($aksi==='hapus_user'){
            $uid=(int)($_POST['user_id']??0);
            if($uid>0){
                $cek = mysqli_prepare($conn,"SELECT id,nama_wali,nama_santri FROM users WHERE id=? AND role='wali_santri' LIMIT 1");
                if($cek){ mysqli_stmt_bind_param($cek,'i',$uid); mysqli_stmt_execute($cek); $res=mysqli_stmt_get_result($cek); $row=$res?mysqli_fetch_assoc($res):null; }
                if(!empty($row)){
                    $del = mysqli_prepare($conn,'DELETE FROM users WHERE id=? LIMIT 1');
                    if($del){ mysqli_stmt_bind_param($del,'i',$uid); mysqli_stmt_execute($del); if(mysqli_affected_rows($conn)>0){ $pesan='Pengguna dihapus.'; if(function_exists('audit_log')) audit_log($conn,(int)($_SESSION['user_id']??0),'delete_user','users',$uid,['nama_wali'=>$row['nama_wali']]); } else $pesan_error='Gagal hapus.'; }
                } else { $pesan_error='Pengguna tidak ditemukan.'; }
            }
        } elseif($aksi==='reset_password'){
            $uid=(int)($_POST['user_id']??0); $np=$_POST['new_password']??''; $cp=$_POST['confirm_password']??'';
            if($uid>0 && $np!=='' && $np===$cp && strlen($np)>=8){
                $cek=mysqli_prepare($conn,"SELECT id FROM users WHERE id=? AND role='wali_santri' LIMIT 1");
                if($cek){ mysqli_stmt_bind_param($cek,'i',$uid); mysqli_stmt_execute($cek); $r=mysqli_stmt_get_result($cek); if($r && mysqli_fetch_assoc($r)){ $hash=password_hash($np,PASSWORD_DEFAULT); $upd=mysqli_prepare($conn,'UPDATE users SET password=? WHERE id=?'); if($upd){ mysqli_stmt_bind_param($upd,'si',$hash,$uid); if(mysqli_stmt_execute($upd)) $pesan='Password direset.'; else $pesan_error='Gagal reset.'; } } }
            } else { $pesan_error='Data reset tidak valid.'; }
    } elseif($aksi==='tambah_user'){
        } elseif($aksi==='update_avatar'){
            $uid=(int)($_POST['user_id']??0);
            $colAvatar=false; if($chkA=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'avatar'")){ if(mysqli_fetch_assoc($chkA)) $colAvatar=true; }
            if(!$colAvatar){ $pesan_error='Kolom avatar belum dimigrasi.'; }
            elseif($uid<=0){ $pesan_error='User tidak valid.'; }
            elseif(!isset($_FILES['avatar_new']) || $_FILES['avatar_new']['error']!==UPLOAD_ERR_OK){ $pesan_error='File tidak diterima.'; }
            else {
                $tmp=$_FILES['avatar_new']['tmp_name']; $size=(int)$_FILES['avatar_new']['size']; $type=@mime_content_type($tmp);
                if(!in_array($type,['image/png','image/jpeg','image/webp'])) $pesan_error='Tipe gambar tidak didukung.';
                elseif($size<=0 || $size>10*1024*1024) $pesan_error='Ukuran >10MB.';
                else {
                    $dinfo=null; if($stInfo=mysqli_prepare($conn,'SELECT nama_santri,nisn FROM users WHERE id=? AND role="wali_santri" LIMIT 1')){ mysqli_stmt_bind_param($stInfo,'i',$uid); mysqli_stmt_execute($stInfo); $rsI=mysqli_stmt_get_result($stInfo); $dinfo=$rsI?mysqli_fetch_assoc($rsI):null; }
                    $nmSantri = $dinfo['nama_santri'] ?? 'avatar'; $nisnCur = $dinfo['nisn'] ?? '';
                    $ext = $type==='image/png' ? 'png' : ($type==='image/webp'?'webp':'jpg');
                    $raw = strtolower($nmSantri);
                    if(function_exists('iconv')){ $t = @iconv('UTF-8','ASCII//TRANSLIT',$raw); if($t!==false) $raw=$t; }
                    $slug = preg_replace('/[^a-z0-9]+/','-',$raw); $slug=trim($slug,'-'); if($slug==='') $slug='avatar';
                    $nisnSlug = preg_replace('/[^0-9]/','',$nisnCur);
                    $base = $slug.'_'.$nisnSlug;
                    $dir = __DIR__.'/../assets/uploads/'; if(!is_dir($dir)) @mkdir($dir,0755,true);
                    $candidate = $base.'.'.$ext; $iDup=1;
                    while(file_exists($dir.$candidate) && $iDup<50){ $candidate=$base.'-'.$iDup.'.'.$ext; $iDup++; }
                    if(@move_uploaded_file($tmp,$dir.$candidate)){
                        $upd=mysqli_prepare($conn,'UPDATE users SET avatar=? WHERE id=? AND role="wali_santri"');
                        if($upd){ mysqli_stmt_bind_param($upd,'si',$candidate,$uid); if(mysqli_stmt_execute($upd) && mysqli_affected_rows($conn)>=0){ $pesan='Avatar diperbarui.'; } else { $pesan_error='Gagal update avatar.'; } }
                    } else { $pesan_error='Gagal simpan file.'; }
                }
            }
            $nama_wali=trim($_POST['nama_wali']??''); $nama_santri=trim($_POST['nama_santri']??''); $nisn=trim($_POST['nisn']??''); $pw=$_POST['password']??''; $avatarFile=null;
            if($nama_wali===''||$nama_santri===''||$nisn===''||$pw===''){ $pesan_error='Semua field wajib diisi.'; }
            elseif(strlen($pw)<8){ $pesan_error='Password minimal 8 karakter.'; }
            else {
                // Handle optional avatar upload
                if(isset($_FILES['avatar']) && $_FILES['avatar']['error']===UPLOAD_ERR_OK){
                    $tmp=$_FILES['avatar']['tmp_name']; $size=(int)$_FILES['avatar']['size']; $type=mime_content_type($tmp);
                    // Batas ukuran 10MB, tipe diperbolehkan
                    if($size>0 && $size<=10*1024*1024 && in_array($type,['image/png','image/jpeg','image/webp'])){
                        $ext = $type==='image/png' ? 'png' : ($type==='image/webp'?'webp':'jpg');
                        // Pola nama: nama_santri_nisn.ext (disanitasi)
                        $raw = strtolower($nama_santri);
                        if(function_exists('iconv')){ $t=@iconv('UTF-8','ASCII//TRANSLIT',$raw); if($t!==false) $raw=$t; }
                        $slug = preg_replace('/[^a-z0-9]+/','-',$raw); $slug=trim($slug,'-'); if($slug==='') $slug='avatar';
                        $nisnSlug = preg_replace('/[^0-9]/','',$nisn);
                        $base = $slug.'_'.$nisnSlug;
                        $dir = __DIR__.'/../assets/uploads/'; if(!is_dir($dir)) @mkdir($dir,0755,true);
                        $candidate = $base.'.'.$ext; $iDup=1;
                        while(file_exists($dir.$candidate) && $iDup<50){ $candidate=$base.'-'.$iDup.'.'.$ext; $iDup++; }
                        if(@move_uploaded_file($tmp,$dir.$candidate)) $avatarFile=$candidate;
                    }
                }
                $hash=password_hash($pw,PASSWORD_DEFAULT);
                if($avatarFile){
                    $ins=mysqli_prepare($conn,"INSERT INTO users (nama_wali,nama_santri,nisn,password,role,avatar) VALUES (?,?,?,?, 'wali_santri',?)");
                    if($ins){
                        mysqli_stmt_bind_param($ins,'sssss',$nama_wali,$nama_santri,$nisn,$hash,$avatarFile);
                        if(mysqli_stmt_execute($ins)){
                            $pesan='Pengguna baru ditambahkan.'; add_notification($conn,mysqli_insert_id($conn),'user_created','Akun baru dibuat.');
                        } else {
                            $errNo = mysqli_errno($conn);
                            if($errNo==1062) $pesan_error='NISN sudah terdaftar.'; else { $pesan_error='Gagal tambah pengguna.'; error_log('Tambah user avatar fail errno='.$errNo.' state='.mysqli_sqlstate($conn)); }
                        }
                    } else { $pesan_error='Gagal menyiapkan insert.'; }
                } else {
                    $ins=mysqli_prepare($conn,"INSERT INTO users (nama_wali,nama_santri,nisn,password,role) VALUES (?,?,?,?, 'wali_santri')");
                    if($ins){
                        mysqli_stmt_bind_param($ins,'ssss',$nama_wali,$nama_santri,$nisn,$hash);
                        if(mysqli_stmt_execute($ins)){
                            $pesan='Pengguna baru ditambahkan.'; add_notification($conn,mysqli_insert_id($conn),'user_created','Akun baru dibuat.');
                        } else {
                            $errNo = mysqli_errno($conn);
                            if($errNo==1062) $pesan_error='NISN sudah terdaftar.'; else { $pesan_error='Gagal tambah pengguna.'; error_log('Tambah user fail errno='.$errNo.' state='.mysqli_sqlstate($conn)); }
                        }
                    } else { $pesan_error='Gagal menyiapkan insert.'; }
                }
            }
        }
    }
}

// Build WHERE for listing
$conds=["role='wali_santri'"];
if($q!==''){
    $safe='%'.mysqli_real_escape_string($conn,$q).'%';
    $conds[]="(nama_wali LIKE '$safe' OR nama_santri LIKE '$safe' OR nisn LIKE '$safe')";
}
$where=implode(' AND ',$conds);
$totalFiltered=0; if($rsC=mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE $where")){ if($rw=mysqli_fetch_assoc($rsC)) $totalFiltered=(int)$rw['c']; }
$totalAll=0; if($rsA=mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE role='wali_santri'")){ if($ra=mysqli_fetch_assoc($rsA)) $totalAll=(int)$ra['c']; }
$avgSaldo=0; if($rsS=mysqli_query($conn,"SELECT AVG(saldo) a FROM users WHERE role='wali_santri'")){ if($rS=mysqli_fetch_assoc($rsS)) $avgSaldo=(float)$rS['a']; }

// Cek apakah kolom avatar sudah ada (agar halaman tidak error sebelum migrasi dijalankan)
$hasAvatar=false; if($chk=mysqli_query($conn,"SHOW COLUMNS FROM users LIKE 'avatar'")){ if(mysqli_fetch_assoc($chk)) $hasAvatar=true; }
$selectAvatar = $hasAvatar? 'avatar,' : '';
$users=[]; $sql="SELECT id,nama_wali,nama_santri,nisn,saldo,$selectAvatar (SELECT COUNT(*) FROM transaksi t WHERE t.user_id=users.id AND t.jenis_transaksi='spp' AND t.status='menunggu_pembayaran') spp_due FROM users WHERE $where ORDER BY spp_due DESC, id DESC LIMIT $offset,$perPage"; $res=mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)){ if(!$hasAvatar){ $r['avatar']=''; } $users[]=$r; }
$totalPages=max(1, (int)ceil($totalFiltered/$perPage));

require_once __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-shell kelola-user-page enhanced">
    <div class="content-header">
        <h1>Kelola Pengguna</h1>
        <div class="quick-actions-inline">
            <button class="qa-btn" type="button" id="btnOpenAdd">+Tambah</button>
        </div>
    </div>
    <div class="inv-chips user-chips compact" aria-label="Ringkasan pengguna">
        <div class="inv-chip info"><span class="k">Total</span><span class="v"><?= number_format($totalAll) ?></span></div>
        <div class="inv-chip ok"><span class="k">Ditampilkan</span><span class="v"><?= number_format($totalFiltered) ?></span></div>
        <div class="inv-chip"><span class="k">Rata2 Saldo</span><span class="v">Rp <?= number_format($avgSaldo,0,',','.') ?></span></div>
    </div>
    <?php if(isset($pesan)): ?><div class="alert success" role="alert"><?= e($pesan) ?></div><?php endif; ?>
    <?php if(isset($pesan_error)): ?><div class="alert error" role="alert"><?= e($pesan_error) ?></div><?php endif; ?>
    <form method="get" class="user-filter simple-search" autocomplete="off" id="searchForm">
        <div class="search-pill">
            <span class="icon" aria-hidden="true">🔍</span>
            <input type="text" id="fQ" name="q" value="<?= e($q) ?>" placeholder="Cari nama / wali / NISN" autocomplete="off" />
            <button type="button" class="clear" id="btnClearSearch" aria-label="Bersihkan" <?= $q===''?'hidden':'' ?>>&times;</button>
        </div>
        <?php if($q!==''): ?><a class="reset-link" href="kelola_user.php">Reset</a><?php endif; ?>
        <noscript><button class="btn-action primary">Cari</button></noscript>
    </form>
    <div class="table-scroll-wrap">
        <table class="pengguna-table ku-table refined">
            <thead><tr><th>#</th><th>Santri</th><th>NISN</th><th>Saldo</th><th>SPP</th><th>Wali</th><th>Aksi</th></tr></thead>
            <tbody>
                <?php $i=$offset+1; if(!empty($users)): foreach($users as $u): ?>
                <tr>
                    <td data-th="#" class="row-num"><?= $i++ ?></td>
                    <td data-th="Santri" class="col-santri with-avatar">
                        <?php 
                            $av = trim($u['avatar'] ?? '');
                            $initial = mb_strtoupper(mb_substr($u['nama_santri']??'',0,1,'UTF-8'),'UTF-8');
                            $avUrl = $av!=='' ? url('assets/uploads/'.rawurlencode($av)) : '';
                        ?>
                        <div class="avatar-sm<?= $avUrl===''?' no-img':'' ?>">
                            <?php if($avUrl!==''): ?>
                                <img src="<?= e($avUrl) ?>" alt="Avatar <?= e($u['nama_santri']) ?>" loading="lazy" onerror="this.closest('.avatar-sm').classList.add('no-img'); this.remove();" />
                            <?php endif; ?>
                            <?php if($avUrl===''): ?><span class="av-initial" aria-hidden="true"><?= e($initial) ?></span><?php endif; ?>
                        </div>
                        <div class="sn-block">
                            <a class="row-link" href="<?= url('admin/pengguna-detail?id='.(int)$u['id']); ?>" title="Detail pengguna"><?= e($u['nama_santri']) ?></a>
                            <div class="nisn-mobile"><code><?= e($u['nisn']) ?></code></div>
                        </div>
                    </td>
                    <td data-th="NISN" class="col-nisn"><code><?= e($u['nisn']) ?></code></td>
                    <td data-th="Saldo" class="col-saldo text-end"><span class="chip saldo <?= (float)$u['saldo']<=0?'zero':'' ?>">Rp <?= number_format($u['saldo'],0,',','.') ?></span></td>
                    <td data-th="SPP" class="col-spp text-center"><?php if((int)$u['spp_due']>0): ?><span class="chip due-mini" title="Tagihan menunggu"><?= (int)$u['spp_due'] ?></span><?php else: ?><span class="chip ok" style="font-size:10px">Lunas</span><?php endif; ?></td>
                    <td data-th="Wali" class="col-wali"><span class="wali-name-short" title="<?= e($u['nama_wali']) ?>"><?= e($u['nama_wali']) ?></span></td>
                    <td data-th="Aksi" class="col-aksi">
                        <div class="action-row">
                            <button type="button" class="mini-btn reset-toggle" data-id="<?= (int)$u['id'] ?>">Reset PW</button>
                            <?php if($hasAvatar): ?>
                            <button type="button" class="mini-btn avatar-toggle" data-id="<?= (int)$u['id'] ?>">Ganti Avatar</button>
                            <?php endif; ?>
                            <form method="POST" action="" onsubmit="return confirm('Hapus pengguna & transaksi terkait?');" style="display:inline">
                                <input type="hidden" name="aksi" value="hapus_user" />
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                                <button type="submit" class="mini-btn danger">Hapus</button>
                            </form>
                        </div>
                        <div class="reset-inline-wrap" id="resetPanel<?= (int)$u['id'] ?>" hidden>
                        <?php if($hasAvatar): ?>
                        <div class="avatar-inline-wrap" id="avatarPanel<?= (int)$u['id'] ?>" hidden>
                            <form class="avatar-inline" method="POST" action="" enctype="multipart/form-data" onsubmit="return confirm('Ganti avatar?');">
                                <input type="hidden" name="aksi" value="update_avatar" />
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                                <input type="file" name="avatar_new" accept="image/png,image/jpeg,image/webp" required />
                                <button class="mini-btn" type="submit">Upload</button>
                            </form>
                        </div>
                        <?php endif; ?>
                            <form class="reset-inline" method="POST" action="" onsubmit="return confirm('Reset password?');">
                                <input type="hidden" name="aksi" value="reset_password" />
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
                                <input type="password" name="new_password" minlength="8" placeholder="Password Baru" />
                                <input type="password" name="confirm_password" minlength="8" placeholder="Konfirmasi" />
                                <button class="mini-btn" type="submit">Simpan</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="7" style="text-align:center;padding:18px 8px;color:#64748b;font-size:13px">Tidak ada data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages>1): ?>
        <nav class="pager" aria-label="Pagination">
            <?php $params=$_GET; unset($params['p']); $build=function($p) use ($params){ return '?'.http_build_query(array_merge($params,['p'=>$p])); }; ?>
            <a class="pg-btn" href="<?= $build(max(1,$page-1)) ?>" aria-label="Prev" <?= $page==1?'aria-disabled="true"':'' ?>>&laquo;</a>
            <?php $win=3; $start=max(1,$page-$win); $end=min($totalPages,$page+$win); if($start>1) echo '<span class="pg-ellipsis">…</span>'; for($p=$start;$p<=$end;$p++){ echo '<a class="pg-btn '.($p==$page?'active':'').'" href="'.$build($p).'">'.$p.'</a>'; } if($end<$totalPages) echo '<span class="pg-ellipsis">…</span>'; ?>
            <a class="pg-btn" href="<?= $build(min($totalPages,$page+1)) ?>" aria-label="Next" <?= $page==$totalPages?'aria-disabled="true"':'' ?>>&raquo;</a>
        </nav>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="overlay-modal" id="addUserModal" hidden>
    <div class="om-backdrop" data-close></div>
    <div class="om-card" role="dialog" aria-modal="true" aria-labelledby="addUserTitle">
        <button class="om-close" type="button" data-close>&times;</button>
        <h2 id="addUserTitle">Tambah Pengguna</h2>
    <form method="POST" action="" class="form-vertical" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="aksi" value="tambah_user" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>" />
            <label>Nama Wali<input type="text" name="nama_wali" required /></label>
            <label>Nama Santri<input type="text" name="nama_santri" required /></label>
            <label>NISN<input type="text" name="nisn" required /></label>
            <label>Password Awal<input type="password" name="password" minlength="8" required /></label>
            <label>Avatar (opsional)
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/jpg,image/webp" />
                <small style="font-size:11px;color:#64748b;font-weight:600">PNG/JPG/WebP &lt; 10MB</small>
            </label>
            <div class="om-actions"><button class="btn-action primary" type="submit">Simpan</button><button class="btn-action" type="button" data-close>Batal</button></div>
        </form>
    </div>
</div>

<script nonce="<?= htmlspecialchars($GLOBALS['SCRIPT_NONCE'] ?? '',ENT_QUOTES,'UTF-8'); ?>">
// Modal logic
const addBtn=document.getElementById('btnOpenAdd');
const modal=document.getElementById('addUserModal');
addBtn?.addEventListener('click',()=>{ modal.removeAttribute('hidden'); document.body.classList.add('modal-open'); modal.querySelector('input[name=nama_wali]').focus(); });
modal?.addEventListener('click',e=>{ if(e.target.hasAttribute('data-close')|| e.target.classList.contains('om-close')){ modal.setAttribute('hidden',''); document.body.classList.remove('modal-open'); }});
document.addEventListener('keydown',e=>{ if(e.key==='Escape' && !modal.hasAttribute('hidden')){ modal.setAttribute('hidden',''); document.body.classList.remove('modal-open'); }});
// Toggle reset password inline panels
document.querySelectorAll('.reset-toggle').forEach(btn=>{ btn.addEventListener('click',()=>{ const id=btn.getAttribute('data-id'); const panel=document.getElementById('resetPanel'+id); if(!panel)return; const vis=!panel.hasAttribute('hidden'); document.querySelectorAll('.reset-inline-wrap').forEach(p=>{ p.setAttribute('hidden',''); p.querySelectorAll('input[name=new_password],input[name=confirm_password]').forEach(i=>i.removeAttribute('required')); }); if(!vis){ panel.removeAttribute('hidden'); panel.querySelectorAll('input[name=new_password],input[name=confirm_password]').forEach(i=>i.setAttribute('required','required')); panel.querySelector('input[name=new_password]')?.focus(); } }); });
// Toggle avatar update panels
document.querySelectorAll('.avatar-toggle').forEach(btn=>{ btn.addEventListener('click',()=>{ const id=btn.getAttribute('data-id'); const panel=document.getElementById('avatarPanel'+id); if(!panel)return; const vis=!panel.hasAttribute('hidden'); document.querySelectorAll('.avatar-inline-wrap').forEach(p=>p.setAttribute('hidden','')); if(!vis){ panel.removeAttribute('hidden'); panel.querySelector('input[type=file]')?.focus(); } }); });
// Simple elegant search (debounce + clear + minimal highlight)
const searchInput=document.getElementById('fQ');
const searchForm=document.getElementById('searchForm');
const clearBtn=document.getElementById('btnClearSearch');
let sbTO=null; const DEBOUNCE=380;
function schedule(){ if(!searchInput) return; if(sbTO) clearTimeout(sbTO); sbTO=setTimeout(()=>{ if(searchForm) searchForm.submit(); },DEBOUNCE); }
searchInput?.addEventListener('input',()=>{ if(clearBtn){ if(searchInput.value==='') clearBtn.setAttribute('hidden',''); else clearBtn.removeAttribute('hidden'); } schedule(); });
clearBtn?.addEventListener('click',()=>{ searchInput.value=''; clearBtn.setAttribute('hidden',''); searchInput.focus(); schedule(); });
(function(){ const q="<?= e($q) ?>".trim(); if(!q) return; const reg=new RegExp('('+q.replace(/[.*+?^${}()|[\\]\\\\]/g,'\\$&')+')','gi'); document.querySelectorAll('.kelola-user-page .col-santri .row-link, .kelola-user-page .col-santri .wali-sub span').forEach(el=>{ const txt=el.textContent; if(reg.test(txt)){ el.innerHTML=txt.replace(reg,'<mark>$1</mark>'); } }); })();
</script>
<?php require_once __DIR__ . '/../../src/includes/footer.php'; ?>


