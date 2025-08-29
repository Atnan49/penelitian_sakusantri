<?php
require_once __DIR__ . '/../src/includes/init.php';
require_once __DIR__ . '/includes/session_check.php';

// Role check: admin can view all; wali can view only own files
$userId = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

$fn = basename($_GET['f'] ?? '');
if ($fn === '') { http_response_code(400); echo 'Bad request'; exit; }

// Basic extension allowlist to avoid serving unexpected files
$ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
$allowed_ext = ['jpg','jpeg','png','webp','gif'];
if (!in_array($ext, $allowed_ext, true)) {
  http_response_code(400);
  echo 'Tipe file tidak didukung';
  exit;
}

// Validate file ownership for wali
$allowed = false;
if ($role === 'admin') {
  $allowed = true;
} elseif ($role === 'wali_santri') {
  $stmt = mysqli_prepare($conn, 'SELECT 1 FROM transaksi WHERE user_id = ? AND bukti_pembayaran = ? LIMIT 1');
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'is', $userId, $fn);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $allowed = ($res && mysqli_fetch_row($res));
  }
}

if (!$allowed) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

// Try multiple known upload locations for backward compatibility
$candidates = [
  APP_ROOT . '/public/uploads/' . $fn,
  APP_ROOT . '/public/assets/uploads/' . $fn,
  APP_ROOT . '/uploads/' . $fn,
];
$path = null;
foreach ($candidates as $p) {
  if (is_file($p)) { $path = $p; break; }
}
if ($path === null) { http_response_code(404); echo 'Not found'; exit; }

// Send file with proper headers (inline)
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
  $f = @finfo_open(FILEINFO_MIME_TYPE);
  if ($f) { $det = @finfo_file($f, $path); if ($det) { $mime = $det; } @finfo_close($f); }
}
// Force image/* for known image types when detection fails or is generic
if (strpos($mime, 'image/') !== 0) {
  $map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
  if (isset($map[$ext])) { $mime = $map[$ext]; }
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode($fn) . '"');
readfile($path);
exit;
