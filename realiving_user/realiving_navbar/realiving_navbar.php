<?php
//realiving_sidebar.php — Collapsible sidebar navigation
//Same transparent(blurred) → white-on-scroll behavior as the original top navbar,
//just applied to a vertical sidebar instead of a horizontal bar.
//Usage: include this file right after <body> opens.
//IMPORTANT: give your main content wrapper the class "main-content" so it shifts
//correctly when the sidebar expands/collapses. See notes at the bottom of this file.
?>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />

<style>
  :root{
    --sb-accent:#2f1200;
    --sb-w-expanded:280px;
    --sb-w-collapsed:84px;
  }

  #sidebar{
    font-family:'Montserrat', sans-serif;
    position:fixed;
    top:0;
    left:0;
    height:100vh;
    width:var(--sb-w-expanded);
    background:transparent;
    isolation:isolate;
    /* Clean solid box edge — walang fade/dissolve, tuwid na border na lang
       ang gilid, katulad ng normal na sidebar/panel */
    border-right:1px solid rgba(255,255,255,0.12);
    display:flex;
    flex-direction:column;
    z-index:50;
    transition:width .3s ease, background-color .3s ease, border-color .3s ease, box-shadow .3s ease, backdrop-filter .3s ease, mask-image .3s ease;
    overflow:hidden;
  }
  #sidebar.collapsed{ width:var(--sb-w-collapsed); }

  /* Sharp cutout of the hero image showing through the sidebar —
     synced via JS to whichever hero slide is currently active.
     background-attachment:fixed + same background-size/position as the
     hero itself means the rail (collapsed or expanded) always lines up
     with the exact same patch of the photo behind it — no visible seam. */
  #sidebar::before{
    content:'';
    position:absolute;
    top:-40px;
    left:-40px;
    right:-40px;
    bottom:-40px;
    background-image:var(--hero-bg-image, none);
    background-size:cover;
    background-position:center;
    background-attachment:fixed;
    filter:blur(10px) saturate(120%) brightness(0.95);
    z-index:-2;
    pointer-events:none;
    transition:opacity .4s ease;
  }

  /* separate darkening layer ON TOP of the blurred image (not mixed into
     the same background-image stack) so text stays readable without
     washing the photo out to flat gray.
     position:absolute + inset:0 = contained sa loob mismo ng #sidebar box
     (dahil overflow:hidden + isolation:isolate na nasa #sidebar), hindi na
     tumatagos pababa sa buong page/viewport height. */
  #sidebar::after{
    content:'';
    position:absolute;
    inset:0;
    background:rgba(20,10,0,0.18);
    z-index:-1;
    pointer-events:none;
    transition:opacity .4s ease;
  }

  #sidebar.scrolled::before,
  #sidebar.scrolled::after{
    opacity:0;
  }

  /* ══ SCROLLED (solid white) STATE ══ */
  #sidebar.scrolled{
  background:#ffffff;
  backdrop-filter:none;
  -webkit-backdrop-filter:none;
  border-right:1px solid rgba(0,0,0,0.08);
  box-shadow:2px 0 16px rgba(0,0,0,0.05);
  transition:width .3s ease, background-color .3s ease, border-color .3s ease, box-shadow .15s ease .15s, backdrop-filter .3s ease;
}

  /* header / logo */
  .sb-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:26px 20px;
    border-bottom:1px solid rgba(255,255,255,0.15);
    flex-shrink:0;
    transition:border-color .3s ease;
  }
  #sidebar.scrolled .sb-header{ border-bottom-color:rgba(0,0,0,0.08); }

  .sb-brand{
    display:flex;
    align-items:center;
    gap:14px;
    overflow:hidden;
    text-decoration:none;
    min-width:0;
  }

  /* circular mark with the actual logo image inside — only shown when collapsed */
  .sb-logo-mark{
    display:flex;
    flex-shrink:0;
    height:64px; width:64px;
    border-radius:50%;
    background:#fff;
    overflow:hidden;
    align-items:center;
    justify-content:center;
    padding:10px;
    box-sizing:border-box;
    border:2px solid rgba(255,255,255,0.5);
    transition:all .3s ease;
  }
  #sidebar.scrolled .sb-logo-mark{ border-color:rgba(0,0,0,0.1); }

  .sb-mark-img{ width:100%; height:100%; object-fit:contain; }

  /* expanded = show the real logo only, no circle mark */
  #sidebar:not(.collapsed) .sb-logo-mark{ display:none; }

  .sb-logo-text{ display:flex; align-items:center; overflow:hidden; min-width:0; }
  .sb-logo-full{ height:56px; width:auto; max-width:210px; object-fit:contain; }

  /* collapsed: keep the avatar mark, hide the wordmark, stack the collapse button below */
  #sidebar.collapsed .sb-logo-text{ display:none; }
  #sidebar.collapsed .sb-header{
    flex-direction:column;
    justify-content:center;
    gap:14px;
    padding:24px 0;
  }
  #sidebar.collapsed .sb-brand{ justify-content:center; }

  .sb-collapse-btn{
    background:transparent;
    border:1px solid rgba(255,255,255,0.3);
    color:#fff;
    width:32px; height:32px;
    border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    transition:all .2s ease, transform .3s ease;
    flex-shrink:0;
  }
  .sb-collapse-btn:hover{ background:rgba(255,255,255,0.15); }
  #sidebar.scrolled .sb-collapse-btn{ border-color:rgba(0,0,0,0.2); color:var(--sb-accent); }
  #sidebar.scrolled .sb-collapse-btn:hover{ background:rgba(0,0,0,0.05); }
  /* keep visible when collapsed — this IS the expand trigger, just flip the arrow */
  #sidebar.collapsed .sb-collapse-btn i{ transform:rotate(180deg); }

  /* section label */
  .sb-label{
    padding:18px 24px 8px;
    font-size:11px;
    letter-spacing:1.5px;
    text-transform:uppercase;
    color:rgba(255,255,255,0.65);
    flex-shrink:0;
    white-space:nowrap;
    transition:color .3s ease;
  }
  #sidebar.scrolled .sb-label{ color:rgba(0,0,0,0.45); }
  #sidebar.collapsed .sb-label{ text-align:center; padding:18px 0 8px; }

  /* nav */
  .sb-nav{
    flex:1 1 auto;
    overflow-y:auto;
    overflow-x:hidden;
    padding-bottom:12px;
  }
  .sb-nav::-webkit-scrollbar{ width:4px; }
  .sb-nav::-webkit-scrollbar-thumb{ background:rgba(0,0,0,0.15); border-radius:4px; }

  .sb-link{
    position:relative;
    display:flex;
    align-items:center;
    gap:16px;
    padding:13px 24px;
    color:#fff;
    text-decoration:none;
    font-size:12px;
    font-weight:500;
    text-transform:uppercase;
    letter-spacing:1px;
    white-space:nowrap;
    transition:background .2s ease, color .2s ease;
  }
  .sb-link i{ font-size:19px; width:22px; text-align:center; flex-shrink:0; color:rgba(255,255,255,0.85); transition:color .2s ease; }
  .sb-link:hover{ background:rgba(255,255,255,0.1); }

  #sidebar.scrolled .sb-link{ color:#2b2b2b; }
  #sidebar.scrolled .sb-link i{ color:#8a8a8a; }
  #sidebar.scrolled .sb-link:hover{ background:rgba(47,18,0,0.06); }
  #sidebar.scrolled .sb-link:hover i{ color:var(--sb-accent); }

  .sb-link.active{ color:var(--sb-accent); background:rgba(255,255,255,0.85); }
  .sb-link.active i{ color:var(--sb-accent); }
  #sidebar.scrolled .sb-link.active{ background:rgba(47,18,0,0.08); }
  .sb-link.active::before{
    content:'';
    position:absolute;
    left:0; top:0; bottom:0;
    width:3px;
    background:var(--sb-accent);
  }

  .sb-link .sb-text{ transition:opacity .2s ease; }
  #sidebar.collapsed .sb-link{ justify-content:center; padding:13px 0; gap:0; }
  #sidebar.collapsed .sb-link .sb-text{ display:none; }

  .sb-divider{ height:1px; background:rgba(255,255,255,0.2); margin:8px 20px; flex-shrink:0; transition:background-color .3s ease; }
  #sidebar.scrolled .sb-divider{ background:rgba(0,0,0,0.08); }
  #sidebar.collapsed .sb-divider{ margin:8px 16px; }

  /* footer / book now */
  .sb-footer{
    padding:18px 20px 22px;
    border-top:1px solid rgba(255,255,255,0.15);
    flex-shrink:0;
    transition:border-color .3s ease;
  }
  #sidebar.scrolled .sb-footer{ border-top-color:rgba(0,0,0,0.08); }

  .sb-book-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    width:100%;
    padding:12px 14px;
    border:2px solid #fff;
    background:transparent;
    color:#fff;
    font-size:11px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:1.5px;
    text-decoration:none;
    transition:all .25s ease;
    white-space:nowrap;
    overflow:hidden;
  }
  .sb-book-btn:hover{ background:#fff; color:var(--sb-accent); }
  #sidebar.scrolled .sb-book-btn{ border-color:var(--sb-accent); color:var(--sb-accent); }
  #sidebar.scrolled .sb-book-btn:hover{ background:var(--sb-accent); color:#fff; }
  #sidebar.collapsed .sb-book-btn{ padding:12px 0; }
  #sidebar.collapsed .sb-book-btn .sb-text{ display:none; }

  /* tooltip on collapsed hover */
  #sidebar.collapsed .sb-link{ position:relative; }
  #sidebar.collapsed .sb-link:hover::after{
    content:attr(data-label);
    position:absolute;
    left:calc(100% + 12px);
    top:50%;
    transform:translateY(-50%);
    background:#2b2b2b;
    color:#fff;
    padding:6px 12px;
    border-radius:6px;
    font-size:12px;
    white-space:nowrap;
    box-shadow:0 4px 12px rgba(0,0,0,.25);
    pointer-events:none;
    z-index:60;
  }

  /* main content offset — base/fallback values, JS below sets the precise
     inline margin-left AND width so the page area actually shrinks/grows
     (true "push" effect) instead of just shifting right while keeping the
     same total width. Always matches the sidebar's real width and the
     current viewport size (desktop vs mobile), recalculates on resize. */
  .main-content{
    margin-left:var(--sb-w-expanded);
    width:calc(100% - var(--sb-w-expanded));
    transition:margin-left .3s ease, width .3s ease, transform .3s ease;
    box-sizing:border-box;
  }
  .main-content.sb-collapsed-offset{
    margin-left:var(--sb-w-collapsed);
    width:calc(100% - var(--sb-w-collapsed));
  }

  /* mobile */
  @media (max-width: 1024px){
    /* Sidebar is now a PERMANENT RAIL on mobile too — same collapse/expand
       push mechanic as desktop, just resized to fit small screens. No more
       off-canvas drawer, no dark overlay, no separate hamburger button —
       the same collapse-arrow button in the header does the job. */
    :root{
      --sb-w-expanded:240px;
      --sb-w-collapsed:64px;
    }

    #sidebar{ left:0; }
    #sidebar.collapsed{ width:var(--sb-w-collapsed); }

    /* re-enable the collapse/expand button (desktop hides nothing here,
       this override only existed for the old drawer pattern) */
    #sidebar .sb-collapse-btn{ display:flex !important; }

    /* slightly smaller logo mark so it doesn't crowd the narrower rail */
    .sb-logo-mark{ height:48px; width:48px; padding:8px; }
  }
</style>

<!-- ═══════════════════════════════
     SIDEBAR
═══════════════════════════════ -->
<aside id="sidebar">

  <!-- LOGO + COLLAPSE TOGGLE -->
  <div class="sb-header">
    <a href="#" class="sb-brand">
      <span class="sb-logo-mark">
        <img id="sbMarkWhite" src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving" class="sb-mark-img">
        <img id="sbMarkDark" src="<?= CLIENT_ASSET ?>/images/logo/logoblack.png" alt="Realiving" class="sb-mark-img" style="display:none;">
      </span>
      <span class="sb-logo-text">
        <img id="sbLogoWhite" src="<?= CLIENT_ASSET ?>/images/logo/logowhite.png" alt="Realiving Logo" class="sb-logo-full">
        <img id="sbLogoDark" src="<?= CLIENT_ASSET ?>/images/logo/logoblack.png" alt="Realiving Logo" class="sb-logo-full" style="display:none;">
      </span>
    </a>
    <button id="sbCollapseBtn" class="sb-collapse-btn">
      <i class="ri-arrow-left-s-line"></i>
    </button>
  </div>

  <div class="sb-label">Menu</div>

  <!-- NAV LINKS -->
  <nav class="sb-nav">
    <a href="#" class="sb-link active" data-label="Home">
      <i class="ri-home-5-line"></i>
      <span class="sb-text">Home</span>
    </a>
    <a href="#" class="sb-link" data-label="Projects">
      <i class="ri-building-4-line"></i>
      <span class="sb-text">Projects</span>
    </a>
    <a href="#" class="sb-link" data-label="Concepts">
      <i class="ri-lightbulb-flash-line"></i>
      <span class="sb-text">Concepts</span>
    </a>
    <a href="#" class="sb-link" data-label="About">
      <i class="ri-information-line"></i>
      <span class="sb-text">About</span>
    </a>
    <a href="#" class="sb-link" data-label="Services">
      <i class="ri-tools-line"></i>
      <span class="sb-text">Services</span>
    </a>
    <a href="#" class="sb-link" data-label="What's New">
      <i class="ri-newspaper-line"></i>
      <span class="sb-text">What's New</span>
    </a>

    <div class="sb-divider"></div>

    <a href="#" class="sb-link" data-label="Contact">
      <i class="ri-mail-line"></i>
      <span class="sb-text">Contact</span>
    </a>
  </nav>

  <!-- BOOK NOW -->
  <div class="sb-footer">
    <a href="#" id="bookBtn" class="sb-book-btn">
      <i class="ri-calendar-check-line"></i>
      <span class="sb-text">Book Now</span>
    </a>
  </div>

</aside>

<script>
(function(){
  const sidebar      = document.getElementById('sidebar');
  const collapseBtn  = document.getElementById('sbCollapseBtn');
  let   mainContent  = document.querySelector('.main-content'); // pwedeng null pa dito
  const logoWhite    = document.getElementById('sbLogoWhite');
  const logoDark     = document.getElementById('sbLogoDark');
  const markWhite    = document.getElementById('sbMarkWhite');
  const markDark     = document.getElementById('sbMarkDark');

  const SCROLL_THRESHOLD = 60;    // px scrolled before switching to solid white
  const MOBILE_BREAKPOINT = 1024; // must match the CSS media query above

  const rootStyles = getComputedStyle(document.documentElement);

  function updateScrollState(){
    const scrolled = window.scrollY > SCROLL_THRESHOLD;
    sidebar.classList.toggle('scrolled', scrolled);
    logoWhite.style.display = scrolled ? 'block' : 'none';
    logoDark.style.display  = scrolled ? 'none' : 'block';
    markWhite.style.display = scrolled ? 'block' : 'none';
    markDark.style.display  = scrolled ? 'none' : 'block';
  }
  updateScrollState();
  window.addEventListener('scroll', updateScrollState, { passive: true });

  /* ─────────────────────────────────────────────
     DYNAMIC MAIN-CONTENT OFFSET
     Same push mechanic on desktop AND mobile now: the sidebar is always
     a visible rail (collapsed or expanded), never an off-canvas drawer.
     We compute the sidebar's actual current width and push that as an
     inline margin-left + width on .main-content, so the page is always
     properly "pushed" and never hidden underneath the sidebar — whatever
     the screen size. Recalculates on resize so it never gets out of sync.
  ───────────────────────────────────────────── */
  function applyContentOffset(){
    // muling hanapin kung hindi pa na-cache — kailangan ito dahil ang
    // .main-content ay lumalabas SA HTML PAGKATAPOS ng sidebar include,
    // kaya wala pa ito sa DOM sa unang pagkakataong tumakbo ang script na ito
    if (!mainContent) mainContent = document.querySelector('.main-content');
    if (!mainContent) return;

    const isCollapsed = sidebar.classList.contains('collapsed');

    // UNIVERSAL na ngayon (desktop at mobile pareho): ang .main-content
    // offset ay laging base sa COLLAPSED width lang, kahit naka-expand
    // pa ang sidebar visually. Dahil dito, ang expanded sidebar ay hindi
    // na nag-p-push/nagpapaliit ng content — sa halip, ito ay naka-
    // overlay/blend lang sa ibabaw (blur bleed-through sa hero o kahit
    // anong laman) dahil fixed-positioned na naman talaga siya (z-index:50).
    // Push (magbabago ng width/margin ng .main-content) ay mangyayari lang
    // kapag COLLAPSED/minimized ang sidebar — maliit lang naman ang epekto
    // non dahil maliit din ang collapsed width (84px desktop / 64px mobile).
    const sbWidth = rootStyles.getPropertyValue('--sb-w-collapsed').trim();

    mainContent.style.marginLeft = sbWidth;
    mainContent.style.width = `calc(100% - ${sbWidth})`;
    mainContent.classList.add('sb-collapsed-offset');
  }

  // restore collapsed preference — if the person never toggled it before
  // and they're on a small screen, default to collapsed to save space
  const savedState = localStorage.getItem('sb_collapsed');
  if (savedState === '1') {
    sidebar.classList.add('collapsed');
  } else if (savedState === null && window.innerWidth <= MOBILE_BREAKPOINT) {
    sidebar.classList.add('collapsed');
  }

  applyContentOffset();

  collapseBtn.addEventListener('click', function(){
    sidebar.classList.toggle('collapsed');
    const isCollapsed = sidebar.classList.contains('collapsed');
    localStorage.setItem('sb_collapsed', isCollapsed ? '1' : '0');
    applyContentOffset();
  });

  // recalculate on resize (debounced) so it always matches the current
  // page/viewport size, e.g. rotating a tablet or resizing a browser window
  let resizeTimer;
  window.addEventListener('resize', function(){
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(applyContentOffset, 120);
  });

  // active link highlight on click
  document.querySelectorAll('.sb-link').forEach(function(link){
    link.addEventListener('click', function(){
      document.querySelectorAll('.sb-link').forEach(l => l.classList.remove('active'));
      this.classList.add('active');
    });
  });

  // safety net: kapag natapos nang ma-parse ang buong page (kasama na yung
  // .main-content na dumadating pagkatapos ng sidebar sa HTML), i-verify
  // ulit natin at itama kung kinakailangan
  document.addEventListener('DOMContentLoaded', applyContentOffset);
})();
</script>

<?php
/*
  PAANO I-INTEGRATE SA MAIN LAYOUT:

  1. I-include ang file na ito agad pagkatapos ng <body> tag:
       <?php include 'realiving_sidebar.php'; ?>

  2. I-wrap ang existing na page content mo sa isang div na may class="main-content"
     para automatic siyang mag-shift kapag ni-collapse o ni-expand ang sidebar:

       <div class="main-content">
         ... existing hero, sections, footer, etc ...
       </div>

  3. SCROLL_THRESHOLD (line na "const SCROLL_THRESHOLD = 60;" sa script) ang
     nagdedecide kung kailan mag-switch from blurred-transparent papuntang
     solid white. I-adjust mo yan (hal. 300 or 400) kung gusto mong mag-stay
     blurred habang naka-cover pa yung hero image.

  4. BAGONG BEHAVIOR — BLUR SA HERO (hindi na plain overlap):
     Habang naka-expand ang sidebar at nasa taas pa (di pa scrolled), yung
     background niya ngayon ay may `backdrop-filter: blur(18px) saturate(160%)`
     kaya kung ano man ang laman ng hero (image o video) sa likod ng sidebar,
     mag-b-blur/frosted-glass effect ito sa halip na basta matabunan lang.
     Pwede mong i-adjust yung blur strength sa `#sidebar` rule (yung
     `backdrop-filter:blur(18px)...` — palakihin/pababain lang yung 18px).
     NOTE: backdrop-filter ay hindi suportado sa mas lumang browsers
     (mostly ok na sa current Chrome/Edge/Safari/Firefox versions).

  5. BAGONG BEHAVIOR — TALAGANG NAG-SHSHRINK NA YUNG WIDTH (hindi lang shift):
     Sa halip na umasa lang sa static CSS class, may JS function na ngayon
     (`applyContentOffset()`) na kumukuha ng ACTUAL width ng sidebar
     (expanded o collapsed) at ng current viewport size, tapos dynamic na
     nilalagay bilang inline `margin-left` AT `width` sa `.main-content`
     (width: calc(100% - sidebar-width)). Ibig sabihin, hindi lang basta
     na-p-push/na-shift papuntang kanan ang content — LUMIILIT din talaga
     ang actual width ng page area kapag naka-expand, at lumalawak ulit
     kapag naka-collapse. Kasama na rin ang pag-recalculate kapag nag-resize
     yung window (hal. pag-rotate ng tablet, o pag-resize ng browser), kaya
     kahit ano pang laki ng page/screen, tama at updated palagi.

  6. Wala pang account section dito gaya ng sabi mo — pwede na lang nating
     idagdag yun sa header area once ready ka na sa design/plan niyan.

  7. BAGO — PAREHONG DESKTOP AT MOBILE, IISANG COLLAPSE/EXPAND RAIL NA LANG:
     Tinanggal na ang dating hamburger + off-canvas drawer + dark overlay sa
     mobile. Ngayon, kahit sa mobile, PERMANENTE nang nakikita ang sidebar
     bilang isang "rail" — kapareho ng desktop collapsed/expanded behavior
     (yung nasa 2nd screenshot mo), i-push lang ang page, hindi na ito
     tumatakip. Sa unang bisita sa isang maliit na screen (1024px pababa),
     nagsisimula itong naka-COLLAPSE (makitid, ~64px, icons lang) para
     makatipid ng espasyo — ito rin ang gumagawa ng "compressed" na page
     na sinasabi mo. Pag pinindot ang collapse/expand arrow button sa
     header (parehong button na ginagamit sa desktop, wala nang hiwalay na
     hamburger), lalawak ang sidebar (~240px sa mobile, mas maliit kaysa sa
     280px ng desktop para bumagay sa screen), at doon lalong mag-c-c-compress
     pa ang `.main-content` — parehong margin-left at width niya na-a-adjust,
     kaya laging tama ang laki ng page kahit anong estado ng sidebar. Naka-
     save pa rin ang preference sa localStorage (`sb_collapsed`), kaya
     mananatili ang huling ginamit mong setting sa susunod na pagbisita.

  8. Kung gusto mong palakihin/paliitin pa yung mobile rail widths, i-adjust
     mo lang ang `--sb-w-expanded` (240px) at `--sb-w-collapsed` (64px) sa
     loob ng `@media (max-width: 1024px)` block — hindi na kailangang
     galawin ang JS, awtomatiko itong susunod dahil kinukuha ng script ang
     mismong CSS variable value sa oras ng pag-toggle o pag-resize.

  8. Logo behavior: pag EXPANDED, plain wordmark lang ("RealLiving" image) ang
     lalabas, walang bilog. Pag COLLAPSED, magpapalit ito sa bilog na "mark"
     na naglalaman ng parehong logo image mo, na-fit lang para kasya sa bilog
     (object-fit: contain, may konting padding para di ma-crop yung text).

  9. Kung meron kang hiwalay na square/icon-only version ng logo (hal.
     logo-icon.png, walang "RealLiving" text, icon/symbol lang), mas maganda
     yun gamitin sa bilog kesa sa buong wordmark — palitan mo na lang yung
     src ng #sbMarkWhite at #sbMarkDark sa may icon version, at pwede mong
     baguhin ang .sb-mark-img mula object-fit:contain papuntang object-fit:cover
     para mas puno yung bilog.

  11. Tinanggal na yung hiwalay na hamburger button dahil hindi na kailangan
      — yung parehong collapse/expand arrow button sa header (`#sbCollapseBtn`)
      ang gumagana na ngayon kahit sa mobile.
*/