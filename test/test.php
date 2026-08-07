<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Meet Our Team — Realiving Design Center</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>

<style>
  body { font-family: 'Montserrat', sans-serif; }
  .font-serif-display { font-family: 'Cormorant Garamond', serif; }

  /* ── Swatch-corner shape: a clipped bottom-left corner, like a material
       finish sample — replaces the generic diagonal-cut card look. Same
       shape everywhere so every tier/device reads as one family. ── */
  .swatch-cut {
    clip-path: polygon(0 0, 100% 0, 100% 100%, 14% 100%, 0% 82%);
  }

  /* Subtle wood-grain texture layered over the swatch panels */
  .grain::before {
    content: "";
    position: absolute; inset: 0;
    background-image:
      repeating-linear-gradient(115deg, rgba(255,255,255,0.05) 0px, rgba(255,255,255,0.05) 1px, transparent 1px, transparent 7px),
      repeating-linear-gradient(115deg, rgba(0,0,0,0.12) 0px, rgba(0,0,0,0.12) 1px, transparent 1px, transparent 22px);
    pointer-events: none;
  }

  /* Brass hang-tag: loop + string + nameplate, like a swatch card tag */
  .hang-loop {
    width: 14px; height: 14px; border-radius: 999px;
    border: 2.5px solid #c4905c;
    background: radial-gradient(circle at 35% 30%, #f0d4ac, #9c6a37 75%);
    box-shadow: 0 2px 4px rgba(47,18,0,.35);
  }
  .hang-string {
    width: 1.5px; height: 14px;
    background: linear-gradient(#c4905c, #9c6a37);
    opacity: .8;
  }

  .nameplate {
    background: linear-gradient(180deg, #d9a86c 0%, #b47c43 55%, #9c6a37 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.35), inset 0 -1px 2px rgba(0,0,0,.25), 0 6px 14px rgba(47,18,0,.25);
  }
  .nameplate-screw {
    width: 4px; height: 4px; border-radius: 999px;
    background: radial-gradient(circle at 35% 35%, #fff6, #0006);
  }

  /* Swatch card: fine brass ring + off-white "cardstock" body */
  .swatch-card {
    background: #fffdfa;
    box-shadow: 0 0 0 1px rgba(196,144,92,0.25), 0 4px 20px rgba(47,18,0,0.06);
  }
  .swatch-card:hover {
    box-shadow: 0 0 0 1px rgba(196,144,92,0.5), 0 20px 45px rgba(47,18,0,0.16);
  }

  .blueprint-corner { position: absolute; width: 22px; height: 22px; border: 1.5px solid #c4905c; opacity: .55; }
  .bc-tl { top: 0; left: 0; border-right: none; border-bottom: none; }
  .bc-tr { top: 0; right: 0; border-left: none; border-bottom: none; }
  .bc-bl { bottom: 0; left: 0; border-right: none; border-top: none; }
  .bc-br { bottom: 0; right: 0; border-left: none; border-top: none; }

  .tape-divider {
    height: 1px;
    background-image: repeating-linear-gradient(90deg, #c4905c 0 1px, transparent 1px 10px);
    opacity: .5;
  }

  /* object-top: keeps the same framing (face near the top of the frame)
     whether the photo sits in a wide card or a shorter modal panel */
  .photo-fill { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 20%; }

  .sample-flag {
    position: absolute; top: 8px; right: 8px;
    max-width: calc(100% - 16px);
    background: #c1121f; color: #fff;
    font-family: 'Montserrat', sans-serif; font-size: 8px; font-weight: 700;
    letter-spacing: 1.5px; text-transform: uppercase;
    padding: 4px 9px; border-radius: 999px; z-index: 5;
    box-shadow: 0 2px 6px rgba(0,0,0,.35);
    white-space: nowrap;
  }

  .team-card:focus-visible, .team-chip:focus-visible {
    outline: 2px solid #c4905c;
    outline-offset: 3px;
  }
</style>
</head>

<body class="bg-[#faf8f6]">

<section class="relative py-20 sm:py-24" id="team">
  <div class="max-w-7xl mx-auto px-5 sm:px-8">

    <!-- Section Header -->
    <div class="relative text-center mb-16 sm:mb-20 max-w-xl mx-auto pt-6 pb-4">
      <span class="blueprint-corner bc-tl"></span>
      <span class="blueprint-corner bc-tr"></span>
      <span class="blueprint-corner bc-bl"></span>
      <span class="blueprint-corner bc-br"></span>

      <span class="inline-block font-montserrat text-[10px] font-bold tracking-[3px] uppercase text-[#8a6236] mb-3">
        The Hands Behind Every Piece
      </span>
      <h2 class="text-3xl sm:text-4xl font-bold text-[#2f1200] font-montserrat uppercase tracking-wide mb-4">
        Meet Our Team
      </h2>
      <p class="text-gray-500 text-sm max-w-md mx-auto">
        From first sketch to final install — the people who design, build, and deliver every Realiving space.
        <span class="block mt-2 text-[11px] text-[#8a6236]">Tap a swatch for contact details.</span>
      </p>
      <div class="mx-auto h-0.5 w-16 bg-[#c4905c] opacity-60 rounded-full mt-4"></div>
    </div>

    <!-- ═══════════════════════════
         LEADERSHIP TIER — always 3-across from the smallest breakpoint
         that can fit it, so all three stay the same size on every device.
         (No more "one card randomly full-width" at tablet widths.)
    ═══════════════════════════ -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 sm:gap-6 lg:gap-9 mb-6">

      <div class="team-card group relative swatch-card rounded-2xl overflow-hidden cursor-pointer active:scale-[0.98] hover:-translate-y-2 transition-all duration-300 touch-manipulation"
        role="button" tabindex="0"
        data-name="Full Name" data-role="Operational Manager"
        data-icon="ri-shield-star-line"
        data-photo="https://i.pravatar.cc/600?img=13"
        data-bio="Oversees daily operations end-to-end — keeping every project on schedule, on budget, and on brief."
        data-email="operations@realiving.com" data-phone="+63 900 000 0001">
        <div class="relative h-56 w-full swatch-cut overflow-hidden bg-[#1c0c00]">
          <span class="sample-flag">Sample</span>
          <img src="https://i.pravatar.cc/600?img=13" alt="Full Name — Operational Manager"
            class="photo-fill group-hover:scale-110 transition-transform duration-500">
          <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent"></div>
        </div>
        <div class="relative flex flex-col items-center -mt-[1px]">
          <span class="hang-string"></span>
          <span class="hang-loop"></span>
          <div class="nameplate rounded-md px-5 py-2 flex items-center gap-2 -mt-1">
            <span class="nameplate-screw"></span>
            <span class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#2f1200]">Operational Manager</span>
            <span class="nameplate-screw"></span>
          </div>
        </div>
        <div class="flex flex-col items-center text-center px-6 pt-4 pb-9">
          <h3 class="font-serif-display text-2xl text-[#2f1200] mb-1.5">Full Name</h3>
          <p class="text-gray-500 text-[13px] leading-relaxed max-w-[220px]">
            Oversees daily operations end-to-end — keeping every project on schedule, on budget, and on brief.
          </p>
          <span class="mt-3 inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-[#c4905c]">
            <i class="ri-contacts-book-line"></i> Tap for contact
          </span>
        </div>
      </div>

      <div class="team-card group relative swatch-card rounded-2xl overflow-hidden cursor-pointer active:scale-[0.98] hover:-translate-y-2 transition-all duration-300 touch-manipulation"
        role="button" tabindex="0"
        data-name="Full Name" data-role="Project Manager"
        data-icon="ri-route-line"
        data-bio="Coordinates design, fabrication, and installation timelines so every handoff runs smoothly."
        data-email="projects@realiving.com" data-phone="+63 900 000 0002">
        <div class="relative h-56 w-full swatch-cut grain overflow-hidden bg-gradient-to-br from-[#3a1a04] to-[#1c0c00] flex items-center justify-center">
          <i class="ri-route-line text-6xl text-[#c4905c]/70 group-hover:text-[#c4905c] group-hover:scale-110 transition-all duration-500"></i>
          <div class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent"></div>
        </div>
        <div class="relative flex flex-col items-center -mt-[1px]">
          <span class="hang-string"></span>
          <span class="hang-loop"></span>
          <div class="nameplate rounded-md px-5 py-2 flex items-center gap-2 -mt-1">
            <span class="nameplate-screw"></span>
            <span class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#2f1200]">Project Manager</span>
            <span class="nameplate-screw"></span>
          </div>
        </div>
        <div class="flex flex-col items-center text-center px-6 pt-4 pb-9">
          <h3 class="font-serif-display text-2xl text-[#2f1200] mb-1.5">Full Name</h3>
          <p class="text-gray-500 text-[13px] leading-relaxed max-w-[220px]">
            Coordinates design, fabrication, and installation timelines so every handoff runs smoothly.
          </p>
          <span class="mt-3 inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-[#c4905c]">
            <i class="ri-contacts-book-line"></i> Tap for contact
          </span>
        </div>
      </div>

      <div class="team-card group relative swatch-card rounded-2xl overflow-hidden cursor-pointer active:scale-[0.98] hover:-translate-y-2 transition-all duration-300 touch-manipulation"
        role="button" tabindex="0"
        data-name="Full Name" data-role="Designer Head"
        data-icon="ri-quill-pen-line"
        data-bio="Leads the design team's creative direction, reviewing every concept before it reaches the client."
        data-email="design@realiving.com" data-phone="+63 900 000 0003">
        <div class="relative h-56 w-full swatch-cut grain overflow-hidden bg-gradient-to-br from-[#3a1a04] to-[#1c0c00] flex items-center justify-center">
          <i class="ri-quill-pen-line text-6xl text-[#c4905c]/70 group-hover:text-[#c4905c] group-hover:scale-110 transition-all duration-500"></i>
          <div class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent"></div>
        </div>
        <div class="relative flex flex-col items-center -mt-[1px]">
          <span class="hang-string"></span>
          <span class="hang-loop"></span>
          <div class="nameplate rounded-md px-5 py-2 flex items-center gap-2 -mt-1">
            <span class="nameplate-screw"></span>
            <span class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#2f1200]">Designer Head</span>
            <span class="nameplate-screw"></span>
          </div>
        </div>
        <div class="flex flex-col items-center text-center px-6 pt-4 pb-9">
          <h3 class="font-serif-display text-2xl text-[#2f1200] mb-1.5">Full Name</h3>
          <p class="text-gray-500 text-[13px] leading-relaxed max-w-[220px]">
            Leads the design team's creative direction, reviewing every concept before it reaches the client.
          </p>
          <span class="mt-3 inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-[#c4905c]">
            <i class="ri-contacts-book-line"></i> Tap for contact
          </span>
        </div>
      </div>

    </div>

    <div class="tape-divider max-w-4xl mx-auto my-14 sm:my-16"></div>

    <!-- ═══════════════════════════
         DESIGNERS TIER
    ═══════════════════════════ -->
    <div class="mb-4">
      <div class="flex items-center justify-center gap-3 mb-10">
        <i class="ri-pencil-ruler-2-line text-[#c4905c] text-lg"></i>
        <h3 class="font-montserrat text-sm font-bold uppercase tracking-[2.5px] text-[#2f1200]">Designers</h3>
      </div>

      <div class="flex flex-wrap justify-center gap-5 sm:gap-6" id="designersGrid"></div>
    </div>

    <div class="tape-divider max-w-4xl mx-auto my-14 sm:my-16"></div>

    <!-- ═══════════════════════════
         CUTTING LIST TIER
    ═══════════════════════════ -->
    <div>
      <div class="flex items-center justify-center gap-3 mb-2">
        <i class="ri-cpu-line text-[#c4905c] text-lg"></i>
        <h3 class="font-montserrat text-sm font-bold uppercase tracking-[2.5px] text-[#2f1200]">Cutting List</h3>
      </div>
      <p class="text-center text-gray-500 text-[12px] max-w-sm mx-auto mb-10">
        Translates every design into precise, CNC-ready cut specifications.
      </p>

      <div class="flex flex-wrap justify-center gap-5 sm:gap-6" id="cuttingGrid"></div>
    </div>

  </div>
</section>

<!-- ═══════════════════════════
     CONTACT MODAL
═══════════════════════════ -->
<div id="teamModal" class="hidden fixed inset-0 z-[99999] bg-black/75 backdrop-blur-sm items-center justify-center p-4">
  <div class="relative w-full max-w-sm max-h-[90vh] overflow-y-auto swatch-card rounded-2xl">

    <div id="teamModalPanel" class="relative h-52 sm:h-56 w-full swatch-cut grain overflow-hidden bg-gradient-to-br from-[#3a1a04] to-[#1c0c00] flex items-center justify-center">
      <i id="teamModalIcon" class="ri-user-3-line text-5xl text-[#c4905c]/80"></i>
      <img id="teamModalPhoto" class="photo-fill hidden" alt="">
      <button onclick="closeTeamModal()" title="Close"
        class="absolute top-3.5 left-3.5 z-10 w-10 h-10 rounded-full bg-white text-black flex items-center justify-center hover:bg-[#2f1200] hover:text-white active:scale-90 transition-all">
        <i class="ri-close-line text-sm"></i>
      </button>
    </div>

    <div class="relative flex flex-col items-center -mt-[1px]">
      <span class="hang-string"></span>
      <span class="hang-loop"></span>
      <div class="nameplate rounded-md px-5 py-2 flex items-center gap-2 -mt-1">
        <span class="nameplate-screw"></span>
        <span id="teamModalRole" class="font-montserrat text-[10px] font-bold tracking-[2px] uppercase text-[#2f1200]"></span>
        <span class="nameplate-screw"></span>
      </div>
    </div>

    <div class="px-7 pt-4 pb-7 text-center">
      <h3 id="teamModalName" class="font-serif-display text-2xl text-[#2f1200] mb-2"></h3>
      <p id="teamModalBio" class="text-gray-500 text-[13px] leading-relaxed mb-6"></p>

      <div class="flex flex-col gap-2.5 text-left">
        <a id="teamModalEmail" href="#" class="flex items-center gap-3 bg-[#faf8f6] rounded-lg px-4 py-3 hover:bg-[#f0e8de] transition-colors">
          <i class="ri-mail-line text-[#c4905c]"></i>
          <span class="font-montserrat text-[13px] text-[#2f1200]"></span>
        </a>
        <a id="teamModalPhone" href="#" class="flex items-center gap-3 bg-[#faf8f6] rounded-lg px-4 py-3 hover:bg-[#f0e8de] transition-colors">
          <i class="ri-phone-line text-[#c4905c]"></i>
          <span class="font-montserrat text-[13px] text-[#2f1200]"></span>
        </a>
      </div>
    </div>

  </div>
</div>

<script>
  const designers = [
    { name: 'Full Name', bio: 'Residential interiors specialist.', email: 'designer1@realiving.com', phone: '+63 900 000 0011', photo: 'https://i.pravatar.cc/500?img=32' },
    { name: 'Full Name', bio: 'Focuses on kitchen & storage systems.', email: 'designer2@realiving.com', phone: '+63 900 000 0012' },
    { name: 'Full Name', bio: 'Handles commercial fit-outs.', email: 'designer3@realiving.com', phone: '+63 900 000 0013' },
    { name: 'Full Name', bio: 'Concept & material selection lead.', email: 'designer4@realiving.com', phone: '+63 900 000 0014' },
  ];

  const cuttingList = [
    { name: 'Full Name', bio: 'Panel optimization & CNC programming.', email: 'cutting1@realiving.com', phone: '+63 900 000 0021' },
    { name: 'Full Name', bio: 'Hardware & joinery specification.', email: 'cutting2@realiving.com', phone: '+63 900 000 0022' },
    { name: 'Full Name', bio: 'Material yield & waste planning.', email: 'cutting3@realiving.com', phone: '+63 900 000 0023' },
    { name: 'Full Name', bio: 'Machine setup & tooling.', email: 'cutting4@realiving.com', phone: '+63 900 000 0024' },
  ];

  function buildCard(member, role, icon) {
    const card = document.createElement('div');
    card.className = 'team-card group relative swatch-card rounded-xl overflow-hidden cursor-pointer active:scale-[0.97] hover:-translate-y-1.5 transition-all duration-300 touch-manipulation w-[calc(50%-10px)] sm:w-[calc(33.333%-16px)] lg:w-[calc(25%-18px)]';
    card.setAttribute('role', 'button');
    card.setAttribute('tabindex', '0');
    card.dataset.name = member.name;
    card.dataset.role = role;
    card.dataset.icon = icon;
    card.dataset.bio = member.bio;
    card.dataset.email = member.email;
    card.dataset.phone = member.phone;
    if (member.photo) card.dataset.photo = member.photo;

    const panelInner = member.photo
      ? `<span class="sample-flag" style="font-size:7px; padding:3px 7px;">Sample</span>
         <img src="${member.photo}" alt="${member.name}" class="photo-fill group-hover:scale-110 transition-transform duration-500">
         <div class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent"></div>`
      : `<i class="${icon} text-4xl text-white/60 group-hover:text-white group-hover:scale-110 transition-all duration-500"></i>`;

    card.innerHTML = `
      <div class="relative h-36 sm:h-40 w-full swatch-cut ${member.photo ? '' : 'grain'} overflow-hidden bg-gradient-to-br from-[#6b4326] to-[#2f1200] ${member.photo ? '' : 'flex items-center justify-center'}">
        ${panelInner}
      </div>
      <div class="relative flex flex-col items-center -mt-[1px]">
        <span class="hang-string" style="height:9px"></span>
        <span class="hang-loop" style="width:10px;height:10px;border-width:2px"></span>
        <div class="nameplate rounded px-3 py-1 -mt-0.5">
          <span class="font-montserrat text-[8px] font-bold tracking-[1.5px] uppercase text-[#2f1200]">${role}</span>
        </div>
      </div>
      <div class="flex flex-col items-center text-center px-4 pt-3 pb-6">
        <h4 class="font-montserrat font-semibold text-[13px] text-[#2f1200]">${member.name}</h4>
        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold uppercase tracking-wider text-[#c4905c]">
          <i class="ri-contacts-book-line"></i> Contact
        </span>
      </div>
    `;
    return card;
  }

  document.getElementById('designersGrid').append(...designers.map(d => buildCard(d, 'Designer', 'ri-user-3-line')));
  document.getElementById('cuttingGrid').append(...cuttingList.map(c => buildCard(c, 'Cutting List', 'ri-terminal-box-line')));

  const teamModal = document.getElementById('teamModal');

  function openTeamModal(el) {
    const { name, role, icon, bio, email, phone, photo } = el.dataset;
    const modalPanel = document.getElementById('teamModalPanel');
    const modalIcon = document.getElementById('teamModalIcon');
    const modalPhoto = document.getElementById('teamModalPhoto');

    if (photo) {
      modalPhoto.src = photo;
      modalPhoto.alt = name || '';
      modalPhoto.classList.remove('hidden');
      modalIcon.classList.add('hidden');
      modalPanel.classList.remove('grain');
    } else {
      modalPhoto.classList.add('hidden');
      modalIcon.classList.remove('hidden');
      modalPanel.classList.add('grain');
    }
    modalIcon.className = (photo ? 'hidden ' : '') + (icon || 'ri-user-3-line') + ' text-5xl text-[#c4905c]/80';
    document.getElementById('teamModalRole').textContent = role || '';
    document.getElementById('teamModalName').textContent = name || '';
    document.getElementById('teamModalBio').textContent = bio || '';

    const emailEl = document.getElementById('teamModalEmail');
    emailEl.href = 'mailto:' + (email || '');
    emailEl.querySelector('span').textContent = email || 'Not available';

    const phoneEl = document.getElementById('teamModalPhone');
    phoneEl.href = 'tel:' + (phone || '').replace(/\s+/g, '');
    phoneEl.querySelector('span').textContent = phone || 'Not available';

    teamModal.classList.remove('hidden');
    teamModal.classList.add('flex');
    document.body.style.overflow = 'hidden';
  }

  function closeTeamModal() {
    teamModal.classList.add('hidden');
    teamModal.classList.remove('flex');
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    const card = e.target.closest('.team-card');
    if (card) openTeamModal(card);
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('team-card')) {
      e.preventDefault();
      openTeamModal(e.target);
    }
    if (e.key === 'Escape' && !teamModal.classList.contains('hidden')) closeTeamModal();
  });
  teamModal.addEventListener('click', function (e) {
    if (e.target === teamModal) closeTeamModal();
  });
</script>

</body>
</html>