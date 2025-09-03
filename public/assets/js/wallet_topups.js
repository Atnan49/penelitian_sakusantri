// JS modal bukti top-up (admin wallet_topups)
(function(){
  const modal=document.getElementById('proofModal');
  const img=document.getElementById('pmImg');
  const fnSpan=document.getElementById('pmFn');
  const openLink=document.getElementById('pmOpen');
  const toolbar=document.getElementById('pmToolbar');
  if(!modal||!img){ return; }
  function setSrc(src,alt){
    if(!src){ console.warn('[bukti] src kosong'); return; }
    img.onerror=function(){
      console.warn('[bukti] gagal load',src,'coba alt');
      if(alt && img.getAttribute('data-alt-tried')!=='1'){
        img.setAttribute('data-alt-tried','1');
        img.src=alt;
        if(openLink) openLink.href=alt;
      }
    };
    img.src=src;
    if(openLink) openLink.href=src;
  }
  function open(btn){
    const src=btn.getAttribute('data-img');
    const alt=btn.getAttribute('data-alt');
    const fn=btn.getAttribute('data-fn');
    modal.hidden=false; document.body.classList.add('modal-open');
    img.removeAttribute('data-alt-tried');
    setSrc(src,alt);
    img.alt='Bukti top-up '+(fn||'');
    if(fnSpan){ fnSpan.textContent=fn||''; }
    if(toolbar){ toolbar.hidden = !fn; }
  }
  function close(){ modal.hidden=true; img.src=''; document.body.classList.remove('modal-open'); img.classList.remove('zoom'); }
  document.addEventListener('click',e=>{
    const btn=e.target.closest('.btn-proof');
    if(btn){ e.preventDefault(); open(btn); }
    if(e.target.hasAttribute('data-close')){ close(); }
  });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape' && !modal.hidden) close(); });
  // Toggle zoom on image click
  img.addEventListener('click',()=>{ img.classList.toggle('zoom'); });
})();
