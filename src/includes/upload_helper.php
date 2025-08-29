<?php
// Central helper untuk upload bukti pembayaran / topup
// Returns array [ok=>bool, file=>string|null, error=>string|null]
function handle_payment_proof_upload(string $field, array $allowedExt=['jpg','jpeg','png','pdf'], int $maxBytes=3145728): array {
    if(empty($_FILES[$field]['name'] ?? '')) return ['ok'=>false,'file'=>null,'error'=>'File belum dipilih'];
    $fn = $_FILES[$field]['name']; $tmp = $_FILES[$field]['tmp_name']; $size = (int)($_FILES[$field]['size'] ?? 0);
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    if(!in_array($ext,$allowedExt,true)) return ['ok'=>false,'file'=>null,'error'=>'Tipe file tidak diizinkan'];
    if($size > $maxBytes) return ['ok'=>false,'file'=>null,'error'=>'Ukuran melebihi batas'];
    if(!is_uploaded_file($tmp)) return ['ok'=>false,'file'=>null,'error'=>'Upload tidak valid'];
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false; $mime = $finfo? @finfo_file($finfo,$tmp):''; if($finfo) @finfo_close($finfo);
    $allowedMime = ['image/jpeg','image/png','application/pdf'];
    if($mime && !in_array($mime,$allowedMime,true)) return ['ok'=>false,'file'=>null,'error'=>'MIME tidak valid'];
    if(!function_exists('payments_random_name')){ return ['ok'=>false,'file'=>null,'error'=>'Helper random name tidak ada']; }
    $newName = payments_random_name('payproof',$ext);
    $destDir = BASE_PATH.'/public/uploads/payment_proof'; if(!is_dir($destDir)) @mkdir($destDir,0775,true);
    $dest = $destDir.'/'.$newName;
    if(!@move_uploaded_file($tmp,$dest)) return ['ok'=>false,'file'=>null,'error'=>'Gagal simpan file'];
    return ['ok'=>true,'file'=>$newName,'error'=>null];
}
?>