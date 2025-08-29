<?php /* filepath: c:\xampp\htdocs\saku_santri\src\includes\footer.php */ ?>
<footer class="site-footer" id="appFooter">
  <p>© 2025 HIMATIF. All Rights Reserved. <span style="font-size:11px;opacity:.7">Gunakan tombol tab untuk navigasi cepat</span></p>
</footer>
<?php // Close main opened in header if not already closed by page templates ?>
<?php if(!defined('MAIN_CLOSED')): ?>
</main>
<?php endif; ?>
<script>
(function(){
  // Footer hide on scroll
  var f = document.getElementById('appFooter');
  if(f){
    var lastY = window.scrollY || 0, ticking=false;
    function onScroll(){
      var y = window.scrollY || 0;
      var hide = y > lastY && y - lastY > 6;
      f.classList.toggle('footer-hide', hide);
      lastY = y; ticking=false;
    }
    window.addEventListener('scroll', function(){ if(!ticking){ ticking=true; requestAnimationFrame(onScroll); } }, { passive:true });
  }
  // Mobile sidebar toggle
  var body = document.body;
  if(body.classList.contains('has-sidebar')){
    var menu = document.getElementById('mainMenu');
    if(menu){
      var btn = document.createElement('button');
      btn.className='mobile-nav-toggle';
      btn.type='button';
      btn.addEventListener('click', function(){
        menu.classList.toggle('open');
        btn.classList.toggle('active');
      });
      document.body.appendChild(btn);
      document.addEventListener('click', function(e){
        if(!menu.classList.contains('open')) return;
        if(menu.contains(e.target) || btn.contains(e.target)) return;
        menu.classList.remove('open');
        btn.classList.remove('active');
      });
    }
  }
})();
</script>
<script src="<?php echo url('assets/js/ui.js'); ?>?v=20250829a" defer></script>
</body>
</html>
