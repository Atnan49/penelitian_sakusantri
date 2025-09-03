// Progressive Enhancement UI Script (keeps legacy inline JS tetap jalan)
(function(){
  const body = document.body;
  const menu = document.getElementById('mainMenu');
  // Insert toggle button if sidebar present
  if(menu && body.classList.contains('has-sidebar')){
    let toggle = document.querySelector('.nav-toggle');
    if(!toggle){
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'nav-toggle';
      toggle.setAttribute('aria-expanded','false');
      toggle.setAttribute('aria-controls','mainMenu');
      toggle.innerHTML = '<span class="bar"></span><span class="sr-only">Toggle menu</span>';
      const header = document.querySelector('.site-header .header-inner') || document.body;
      header.insertBefore(toggle, header.firstChild);
    }
    const backdrop = document.createElement('div');
    backdrop.className='menu-backdrop';
    document.body.appendChild(backdrop);
    function closeMenu(){body.classList.remove('menu-open');toggle.setAttribute('aria-expanded','false');}
    function openMenu(){body.classList.add('menu-open');toggle.setAttribute('aria-expanded','true');}
    toggle.addEventListener('click',()=>{body.classList.contains('menu-open')?closeMenu():openMenu();});
    backdrop.addEventListener('click',closeMenu);
    // Close on esc
    window.addEventListener('keydown',e=>{if(e.key==='Escape'){closeMenu();}});
  }
  // Focus ring only when keyboard navigation
  function handleFirstTab(e){ if(e.key==='Tab'){ document.documentElement.classList.add('user-tab'); window.removeEventListener('keydown',handleFirstTab); window.addEventListener('mousedown',handleMouse);} }
  function handleMouse(){ document.documentElement.classList.remove('user-tab'); window.removeEventListener('mousedown',handleMouse); window.addEventListener('keydown',handleFirstTab); }
  window.addEventListener('keydown',handleFirstTab);

  // Sidebar scroll state (shadow indicator)
  if(menu){
    function updateMenuShadow(){
      if(menu.scrollTop > 4){ menu.classList.add('scrolled'); }
      else { menu.classList.remove('scrolled'); }
    }
    menu.addEventListener('scroll', updateMenuShadow, { passive:true });
    updateMenuShadow();
  }
  // Close menu on route link click (mobile)
  if(menu){
    menu.addEventListener('click', e=>{
      const a = e.target.closest('a.btn-menu');
      if(a && body.classList.contains('menu-open')){
        body.classList.remove('menu-open');
      }
    });
  }

  // Theme toggle (persist preference in localStorage)
  const toggle = document.getElementById('themeToggle');
  const PREF_KEY = 'app_theme_pref_v1';
  function applyTheme(pref){
    if(pref==='dark'){ body.classList.add('theme-dark'); }
    else { body.classList.remove('theme-dark'); }
  }
  // Load stored preference or system
  try{
    const stored = localStorage.getItem(PREF_KEY);
    if(stored){ applyTheme(stored); }
    else if(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches){ applyTheme('dark'); }
  }catch(e){/* ignore */}
  if(toggle){
    toggle.addEventListener('click',()=>{
      const dark = body.classList.toggle('theme-dark');
      try{ localStorage.setItem(PREF_KEY, dark ? 'dark' : 'light'); }catch(e){}
    });
  }
  // Keep sticky header background accurate if theme changes
  const observer = new MutationObserver(()=>{
    // Force repaint by toggling a data attribute
    document.documentElement.setAttribute('data-theme-ts', Date.now());
  });
  observer.observe(body,{attributes:true,attributeFilter:['class']});

  // Delegasi konfirmasi untuk elemen dengan data-confirm
  document.addEventListener('click', function(e){
    const el = e.target.closest('[data-confirm]');
    if(!el) return;
    const msg = el.getAttribute('data-confirm') || 'Lanjutkan tindakan ini?';
    if(!window.confirm(msg)){
      e.preventDefault();
      e.stopPropagation();
    }
  });

  // Delegasi navigasi untuk elemen dengan data-href
  document.addEventListener('click', function(e){
    const nav = e.target.closest('[data-href]');
    if(!nav) return;
    const url = nav.getAttribute('data-href');
    if(url){ window.location.href = url; }
  });

  // Tombol kembali generic
  document.addEventListener('click', function(e){
    const back = e.target.closest('[data-action="back"]');
    if(!back) return;
    e.preventDefault();
    history.back();
  });

  // Avatar / image fallback: tandai gambar gagal dan sembunyikan bila pakai .js-img-hide-on-error
  document.addEventListener('error', function(e){
    const img = e.target;
    if(!(img instanceof HTMLImageElement)) return;
    if(img.classList.contains('js-hide-on-error')){
      const wrap = img.closest('.avatar-sm, .pb-avatar, .admin-avatar');
      if(wrap){ wrap.classList.add('no-img'); }
      img.remove();
    }
  }, true); // use capture agar menangkap event error image

  // Clear NISN search (kasir) tanpa inline JS
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.js-clear-nisn');
    if(!btn) return;
    const form = btn.closest('form');
    if(!form) return;
    const inp = form.querySelector('[name=nisn]');
    if(inp){ inp.value=''; }
    form.submit();
  });

  // Buat_tagihan script load fallback detection (ganti inline onerror)
  const tagihanMarker = document.querySelector('[data-require-script="buat_tagihan"]');
  if(tagihanMarker){
    const script = document.querySelector('script[src$="buat_tagihan.js"]');
    if(script){
      script.addEventListener('error', ()=>{
        const msg = document.getElementById('jsErrorMsg');
        if(msg){ msg.style.display='block'; }
      });
    }
  }

  // Generic tab switching (replaces removed inline scripts; works with buttons .tab-btn[data-tab])
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.tab-btn');
    if(!btn) return;
    const tabKey = btn.getAttribute('data-tab');
    if(!tabKey) return;
  // Debug marker (can be removed later)
  if(window.console){ console.debug('[ui.js] Switch tab ->', tabKey); }
    // Limit scope to same tab group if multiple groups exist
    const wrap = btn.closest('.tabs-wrap');
    const btns = wrap ? wrap.querySelectorAll('.tab-btn') : document.querySelectorAll('.tab-btn');
    btns.forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    // Show/hide contents (#tab-<key>)
    const panels = document.querySelectorAll('.tab-content');
    panels.forEach(p=>{
      const match = p.id === 'tab-'+tabKey;
      if(match){ p.classList.add('active'); }
      else { p.classList.remove('active'); }
    });
  });
})();
