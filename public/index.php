<?php
require_once __DIR__ . "/../src/includes/init.php";
if (!empty($_SESSION["role"])) {
  header("Location: " . ($_SESSION["role"] === "admin" ? url("admin/") : url("wali/")));
  exit;
}
require_once __DIR__ . "/../src/includes/header.php";
?>

<main class="login-container">
  <!-- Brand Header (Desktop) -->
  <div class="brand-header">
    <div class="brand-logo">
  <img src="<?php echo url('assets/img/logo.png'); ?>" alt="SakuSantri" class="logo-icon js-hide-on-error">
      <span class="brand-text">SakuSantri</span>
    </div>
  </div>

  <!-- Mobile Header Circle -->
  <div class="mobile-header">
    <div class="mobile-logo">
  <img src="<?php echo url('assets/img/logo.png'); ?>" alt="SakuSantri" class="logo-mobile js-hide-on-error">
      <span class="brand-mobile">SakuSantri</span>
    </div>
  </div>

  <!-- Main Content -->
  <div class="login-main">
    <!-- Left Side (Desktop) -->
    <div class="login-left">
      <div class="illustration">
        <img src="<?php echo url('assets/img/hero.png'); ?>" alt="Ilustrasi Santri" class="hero-img" loading="lazy" decoding="async">
      </div>
      <p class="tagline">SakuSantri hadir sebagai solusi digital untuk mendampingi kehidupan santri yang tertib, teratur, dan produktif di era modern.</p>
    </div>

    <!-- Right Side (Login Card) -->
    <div class="login-right">
      <!-- LOGIN Badge (Mobile) -->
      <div class="login-badge">LOGIN</div>
      
      <!-- Login Card -->
      <div class="login-card">
        <!-- LOGIN Cap (Desktop) -->
        <div class="login-cap">
          <span>LOGIN</span>
        </div>
        
        <!-- Login Form Panel -->
        <div class="login-panel">
          <?php
            if(isset($_GET['pesan'])){
              if($_GET['pesan'] === 'gagal'){
                echo '<div class="alert error">Login gagal! NISN atau password salah.</div>';
              } elseif($_GET['pesan'] === 'sesi_berakhir') {
                echo '<div class="alert info">Sesi berakhir. Silakan login kembali.</div>';
              } elseif($_GET['pesan'] === 'logout') {
                echo '<div class="alert info">Berhasil logout.</div>';
              }
            }
          ?>
          <form action="<?php echo url('login'); ?>" method="POST" class="login-form" novalidate>
            <div class="form-field icon-holder">
              <label for="nisn">
                NISN
              </label>
              <span class="material-symbols-outlined form-icon" aria-hidden="true">mail</span>
              <input type="text" id="nisn" name="nisn" required autocomplete="username" inputmode="numeric" pattern="[0-9]{5,}" title="Masukkan NISN (minimal 5 digit angka)">
            </div>
            <div class="form-field icon-holder">
              <label for="password">Password</label>
              <span class="material-symbols-outlined form-icon" aria-hidden="true">key</span>
              <div class="password-field">
                <input type="password" id="password" name="password" required autocomplete="current-password" minlength="8" aria-describedby="pwHelp">
                <button type="button" class="password-toggle" aria-label="Tampilkan password" aria-pressed="false">
                  <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                </button>
              </div>
              <small id="pwHelp" class="sr-only">Minimal 8 karakter.</small>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            
            <!-- MASUK Button -->
            <button type="submit" class="btn-masuk">MASUK</button>
          </form>
          
          <!-- Google login removed per request -->
        </div>
      </div>
    </div>
  </div>

  <!-- Decorative Circles -->
  <div class="circle-decoration circle-left"></div>
  <div class="circle-decoration circle-right"></div>
</main>
<script src="assets/js/main.js" defer></script>
<?php require_once __DIR__ . "/../src/includes/footer.php"; ?>
