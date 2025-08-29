<?php
// Entry point to start Google OAuth 2.0 flow
require_once __DIR__ . '/../src/includes/init.php';
require_once BASE_PATH . '/src/includes/config.php'; // if exists

if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET) || GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
    http_response_code(400);
    echo 'Google OAuth belum dikonfigurasi.';
    exit;
}

if (defined('GOOGLE_REDIRECT_URI')) {
  $tmp = constant('GOOGLE_REDIRECT_URI');
  $redirect = $tmp ?: (APP_ORIGIN . BASE_URL . 'google_callback.php');
} else {
  $redirect = APP_ORIGIN . BASE_URL . 'google_callback.php';
}

// Generate state token untuk CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['oauth2state'] = $state;

$params = [
  'response_type' => 'code',
  'client_id' => GOOGLE_CLIENT_ID,
  'redirect_uri' => $redirect,
  'scope' => 'openid email profile',
  'state' => $state,
  'access_type' => 'offline',
  'include_granted_scopes' => 'true',
  'prompt' => 'select_account'
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $authUrl);
exit;