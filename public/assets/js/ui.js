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
})();
