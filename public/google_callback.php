<?php
require_once __DIR__ . '/../src/includes/init.php';
@include_once BASE_PATH . '/src/includes/config.php';

// Pastikan konstanta terdefinisi sebelum digunakan
if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET') || empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET) || GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
    header('Location: ' . url('login?pesan=gagal'));
    exit;
}

// Validasi state untuk CSRF
$state = $_GET['state'] ?? '';
if (!isset($_SESSION['oauth2state']) || !hash_equals($_SESSION['oauth2state'], $state)) {
    unset($_SESSION['oauth2state']);
    header('Location: ' . url('login?pesan=gagal'));
    exit;
}
unset($_SESSION['oauth2state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    header('Location: ' . url('login?pesan=gagal'));
    exit;
}

$redirect = defined('GOOGLE_REDIRECT_URI') && constant('GOOGLE_REDIRECT_URI') ? constant('GOOGLE_REDIRECT_URI') : (APP_ORIGIN . BASE_URL . 'google_callback.php');

// Tukar authorization code dengan access token
$tokenResp = oauth_post('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => $redirect,
    'grant_type' => 'authorization_code'
]);

if (!$tokenResp || empty($tokenResp['id_token'])) {
    header('Location: ' . url('login?pesan=gagal'));
    exit;
}

// Decode id_token (JWT) sederhana (tanpa verifikasi tanda tangan penuh di sini)
$parts = explode('.', $tokenResp['id_token']);
if (count($parts) !== 3) {
    header('Location: ' . url('login?pesan=gagal'));
    exit;
}
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
$email = $payload['email'] ?? null;
$name  = $payload['name'] ?? ($payload['given_name'] ?? 'Pengguna');

if (!$email) {
    header('Location: ' . url('login?pesan=gagal'));
    exit;
}

// Map email -> users.nisn jika belum ada kolom email (gunakan email sebagai nisn unik pseudo)
$nisn = $email; // karena tabel belum punya kolom email.

// Cari user
$stmt = mysqli_prepare($conn, 'SELECT id, nama_wali, role FROM users WHERE nisn = ? LIMIT 1');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $nisn);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res && ($u = mysqli_fetch_assoc($res))) {
        login_and_redirect($u['id'], $u['nama_wali'], $u['role']);
    }
}

// Jika belum ada, buat akun wali_santri baru (password random)
$randomPass = bin2hex(random_bytes(8));
$hash = password_hash($randomPass, PASSWORD_DEFAULT);
$namaWali = mb_substr($name, 0, 100);
$dummyNamaSantri = '-';
$role = 'wali_santri';
$insert = mysqli_prepare($conn, 'INSERT INTO users (nama_wali, nama_santri, nisn, password, role) VALUES (?,?,?,?,?)');
if ($insert) {
    mysqli_stmt_bind_param($insert, 'sssss', $namaWali, $dummyNamaSantri, $nisn, $hash, $role);
    if (mysqli_stmt_execute($insert)) {
        $newId = mysqli_insert_id($conn);
        login_and_redirect($newId, $namaWali, $role);
    }
}

header('Location: ' . url('login?pesan=gagal'));
exit;

// --- Helper ---
function oauth_post(string $url, array $data): ?array {
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];
    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $json = json_decode($resp, true);
    return is_array($json) ? $json : null;
}

function login_and_redirect(int $id, string $nama, string $role): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['nama_wali'] = $nama;
    $_SESSION['role'] = $role;
    $_SESSION['last_activity'] = time();
    header('Location: ' . url($role === 'admin' ? 'admin/' : 'wali/'));
    exit;
}