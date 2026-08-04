<?php
//appointment.php
session_name("Realivinguser");
session_start();
include $includes['connection'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book an Appointment — Realiving Design Center</title>

    <!-- Preconnects: fonts + CDNs resolve DNS/TLS in parallel instead of blocking mid-parse -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&display=swap"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet" />
</head>

<body class="appointment-page no-hero bg-[#faf8f4] text-[#241205]" style="font-family:'Montserrat',sans-serif;">

    <?php include $includes['header']; ?>

    <div class="main-content">

        <!-- ═══════════════════════════════
             MASTHEAD
        ═══════════════════════════════ -->
        <section class="relative bg-[#2f1200] overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center opacity-20"
                style="background-image:url('<?= CLIENT_ASSET ?>/images/background-image.jpg');"></div>
            <div class="absolute inset-0 bg-gradient-to-b from-[#2f1200]/60 via-[#2f1200]/85 to-[#2f1200]"></div>

            <div class="relative max-w-5xl mx-auto px-6 pt-20 pb-14 text-center">
                <div class="inline-flex items-center gap-3 text-[#c4905c] text-[11px] font-semibold tracking-[3px] uppercase mb-5">
                    <span class="w-6 h-px bg-[#c4905c]"></span>
                    Realiving Design Center
                    <span class="w-6 h-px bg-[#c4905c]"></span>
                </div>
                <h1 class="font-['Crimson_Pro'] italic font-semibold text-white text-4xl md:text-6xl leading-tight">
                    Book an Appointment
                </h1>
                <p class="mt-3 text-white/70 text-sm md:text-base">
                    MC Premier — EDSA Balintawak, Quezon City
                </p>
            </div>
        </section>

        <div class="max-w-6xl mx-auto px-4 sm:px-6 -mt-8 relative z-10 pb-20">

            <!-- ═══════════════════════════════
                 STEPPER
            ═══════════════════════════════ -->
            <div class="bg-white shadow-[0_10px_30px_rgba(47,18,0,0.12)] px-5 sm:px-10 py-6 mb-1 max-w-3xl mx-auto lg:max-w-none">
                <div class="flex items-center max-w-md mx-auto lg:max-w-none" id="stepperBar">

                    <div class="flex flex-col items-center gap-2 stepper-node" data-step-node="1">
                        <span class="step-circle w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-['Crimson_Pro'] font-bold text-[13px] sm:text-[14px] transition-all duration-300 bg-[#2f1200] text-white">1</span>
                        <span class="step-label text-[9px] sm:text-[10px] font-bold uppercase tracking-[1px] text-[#2f1200] transition-colors">Date &amp; Time</span>
                    </div>

                    <div class="flex-1 h-[2px] bg-[#e8dfd3] mx-2 sm:mx-3 -mt-4 relative overflow-hidden">
                        <span class="step-line-fill absolute inset-y-0 left-0 w-0 bg-[#c4905c] transition-all duration-500"></span>
                    </div>

                    <div class="flex flex-col items-center gap-2 stepper-node" data-step-node="2">
                        <span class="step-circle w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-['Crimson_Pro'] font-bold text-[13px] sm:text-[14px] transition-all duration-300 bg-[#e8dfd3] text-[#a3907a]">2</span>
                        <span class="step-label text-[9px] sm:text-[10px] font-bold uppercase tracking-[1px] text-[#a3907a] transition-colors">Your Details</span>
                    </div>

                    <div class="flex-1 h-[2px] bg-[#e8dfd3] mx-2 sm:mx-3 -mt-4 relative overflow-hidden">
                        <span class="step-line-fill absolute inset-y-0 left-0 w-0 bg-[#c4905c] transition-all duration-500"></span>
                    </div>

                    <div class="flex flex-col items-center gap-2 stepper-node" data-step-node="3">
                        <span class="step-circle w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-['Crimson_Pro'] font-bold text-[13px] sm:text-[14px] transition-all duration-300 bg-[#e8dfd3] text-[#a3907a]">3</span>
                        <span class="step-label text-[9px] sm:text-[10px] font-bold uppercase tracking-[1px] text-[#a3907a] transition-colors">Confirm</span>
                    </div>

                </div>
            </div>

            <!-- ═══════════════════════════════
                 WIZARD CARD
            ═══════════════════════════════ -->
            <form id="apptForm" novalidate class="bg-white shadow-[0_10px_30px_rgba(47,18,0,0.08)]">

                <!-- ───────── STEP 1 — DATE & TIME ───────── -->
                <div class="step-panel" data-step="1">
                    <div class="px-5 sm:px-10 pt-8 pb-2">
                        <span class="block text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-2">Step 1 of 3</span>
                        <h2 class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-2xl">Pick a date &amp; time</h2>
                    </div>

                    <div class="px-5 sm:px-10 py-6">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <span class="text-[13px] font-bold text-[#2f1200] font-['Crimson_Pro']" id="currentMonth">January 2026</span>
                            <div class="flex items-center border border-[#e8dfd3]">
                                <button type="button" id="prevMonth" aria-label="Previous"
                                    class="w-8 h-8 flex items-center justify-center text-[#6b5c4d] hover:bg-[#2f1200] hover:text-white transition-colors">
                                    <i class="ri-arrow-left-s-line"></i>
                                </button>
                                <button type="button" id="nextMonth" aria-label="Next"
                                    class="w-8 h-8 flex items-center justify-center text-[#6b5c4d] hover:bg-[#2f1200] hover:text-white transition-colors border-l border-[#e8dfd3]">
                                    <i class="ri-arrow-right-s-line"></i>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-7 gap-1" id="calGrid" style="min-height:300px;">
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Sun</div>
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Mon</div>
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Tue</div>
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Wed</div>
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Thu</div>
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Fri</div>
                            <div class="text-center text-[9px] font-bold tracking-[1.5px] uppercase text-[#a3907a] pb-2">Sat</div>
                        </div>

                        <div class="flex items-center gap-4 flex-wrap mt-4 pt-4 border-t border-[#f0ebe4]">
                            <div class="flex items-center gap-1.5 text-[10.5px] text-[#a3907a]"><span class="w-2.5 h-2.5 bg-[#edf4ea] border border-[#2f1200]/20"></span>Available</div>
                            <div class="flex items-center gap-1.5 text-[10.5px] text-[#a3907a]"><span class="w-2.5 h-2.5 bg-[#faf4f4] border border-[#b84040]/20"></span>Fully booked</div>
                            <div class="flex items-center gap-1.5 text-[10.5px] text-[#a3907a]"><span class="w-2.5 h-2.5 bg-[#2f1200]"></span>Selected</div>
                        </div>

                        <!-- Time pills — appear once a date is picked -->
                        <div id="timeSlotWrap" class="mt-7 hidden">
                            <span class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-3">Available times for <span id="pickedDateLabel" class="text-[#c4905c]"></span></span>
                            <div id="timeSlots" class="flex flex-wrap gap-2"></div>
                        </div>

                        <input type="hidden" id="preferredDate" name="preferredDate">
                        <input type="hidden" id="preferredTime" name="preferredTime">
                        <p id="step1Err" class="ferr text-[11px] text-[#b84040] mt-3 hidden"><span class="font-bold">!</span> Please select both a date and a time to continue.</p>
                    </div>

                    <div class="flex justify-end px-5 sm:px-10 py-6 border-t border-[#f0ebe4]">
                        <button type="button" data-next="2"
                            class="btn-continue bg-[#2f1200] text-white font-bold text-[11px] tracking-[2px] uppercase px-9 py-3.5 transition-all duration-200 hover:bg-[#c4905c] hover:-translate-y-0.5 inline-flex items-center gap-2">
                            Continue <i class="ri-arrow-right-line"></i>
                        </button>
                    </div>
                </div>

                <!-- ───────── STEP 2 — DETAILS ───────── -->
                <div class="step-panel hidden" data-step="2">
                    <div class="px-5 sm:px-10 pt-8 pb-2">
                        <span class="block text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-2">Step 2 of 3</span>
                        <h2 class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-2xl">Tell us about you</h2>
                    </div>

                    <div class="px-5 sm:px-10 py-6 flex flex-col gap-5">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div id="fg-firstName" class="fgroup">
                                <label for="firstName" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">First Name</label>
                                <input type="text" id="firstName" name="firstName" placeholder="e.g. Maria"
                                    class="fg-input w-full px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] placeholder:text-[#a3907a] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>
                            <div id="fg-lastName" class="fgroup">
                                <label for="lastName" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">Last Name</label>
                                <input type="text" id="lastName" name="lastName" placeholder="e.g. Santos"
                                    class="fg-input w-full px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] placeholder:text-[#a3907a] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>
                        </div>

                        <div id="fg-email" class="fgroup">
                            <label for="email" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">Email Address</label>
                            <input type="email" id="email" name="email" placeholder="maria@example.com"
                                class="fg-input w-full px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] placeholder:text-[#a3907a] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                        </div>

                        <div id="fg-phone" class="fgroup">
                            <label for="phone" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">Phone Number</label>
                            <div class="flex">
                                <select id="countryCode" name="countryCode"
                                    class="w-[76px] shrink-0 px-2 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] border-r-0 text-[13px] text-[#2f1200] focus:outline-none focus:border-[#2f1200] cursor-pointer appearance-none">
                                    <option value="+1">+1</option>
                                    <option value="+44">+44</option>
                                    <option value="+33">+33</option>
                                    <option value="+49">+49</option>
                                    <option value="+81">+81</option>
                                    <option value="+86">+86</option>
                                    <option value="+91">+91</option>
                                    <option value="+61">+61</option>
                                    <option value="+55">+55</option>
                                    <option value="+63" selected>+63</option>
                                    <option value="+65">+65</option>
                                    <option value="+60">+60</option>
                                    <option value="+66">+66</option>
                                    <option value="+84">+84</option>
                                    <option value="+62">+62</option>
                                </select>
                                <input type="tel" id="phone" name="phone" placeholder="9XX XXX XXXX"
                                    class="fg-input flex-1 px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] placeholder:text-[#a3907a] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                            </div>
                        </div>

                        <div id="fg-serviceType" class="fgroup">
                            <label for="serviceType" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">Service Type</label>
                            <select id="serviceType" name="serviceType"
                                class="fg-input w-full px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors cursor-pointer appearance-none">
                                <option value="">Select a service</option>
                                <option value="consultation">Initial Consultation</option>
                                <option value="follow-up">Follow-up Appointment</option>
                                <option value="review">Document Review</option>
                                <option value="planning">Strategic Planning</option>
                                <option value="others">Others (specify below)</option>
                            </select>
                        </div>

                        <div id="fg-otherService" class="fgroup hidden">
                            <label for="otherService" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">Service Description</label>
                            <input type="text" id="otherService" name="otherService" placeholder="Briefly describe your request…"
                                class="fg-input w-full px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] placeholder:text-[#a3907a] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors">
                        </div>

                        <div class="fgroup">
                            <label for="notes" class="block text-[10px] font-bold tracking-[1.5px] uppercase text-[#2f1200] mb-1.5">
                                Additional Notes <span class="normal-case tracking-normal font-normal text-[#a3907a]">(optional)</span>
                            </label>
                            <textarea id="notes" name="notes" rows="4" placeholder="Any special requirements or context for your visit…"
                                class="w-full px-3.5 py-2.5 bg-[#faf8f4] border border-[#e3d6c5] text-[14px] text-[#2f1200] placeholder:text-[#a3907a] focus:outline-none focus:border-[#2f1200] focus:bg-white focus:ring-2 focus:ring-[#2f1200]/10 transition-colors resize-y min-h-[90px]"></textarea>
                        </div>

                    </div>

                    <div class="flex items-center justify-between px-5 sm:px-10 py-6 border-t border-[#f0ebe4]">
                        <button type="button" data-back="1"
                            class="text-[11px] font-bold tracking-[1.5px] uppercase text-[#6b5c4d] hover:text-[#2f1200] transition-colors inline-flex items-center gap-1.5">
                            <i class="ri-arrow-left-line"></i> Back
                        </button>
                        <button type="button" data-next="3"
                            class="btn-continue bg-[#2f1200] text-white font-bold text-[11px] tracking-[2px] uppercase px-9 py-3.5 transition-all duration-200 hover:bg-[#c4905c] hover:-translate-y-0.5 inline-flex items-center gap-2">
                            Review <i class="ri-arrow-right-line"></i>
                        </button>
                    </div>
                </div>

                <!-- ───────── STEP 3 — CONFIRM ───────── -->
                <div class="step-panel hidden" data-step="3">
                    <div class="px-5 sm:px-10 pt-8 pb-2">
                        <span class="block text-[10px] font-bold tracking-[2px] uppercase text-[#c4905c] mb-2">Step 3 of 3</span>
                        <h2 class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-2xl">Review &amp; confirm</h2>
                    </div>

                    <div class="px-5 sm:px-10 py-6">
                        <!-- Receipt-style summary -->
                        <div class="border border-dashed border-[#d4b896] bg-[#faf8f4]">
                            <div class="px-6 py-5 border-b border-dashed border-[#d4b896] flex items-center justify-between">
                                <span class="font-['Crimson_Pro'] font-semibold text-[#2f1200] text-lg">Appointment Summary</span>
                                <i class="ri-calendar-check-line text-[#c4905c] text-xl"></i>
                            </div>
                            <div class="px-6 py-2" id="summaryRows">
                                <!-- filled by JS -->
                            </div>
                        </div>

                        <p class="text-[11px] text-[#a3907a] leading-relaxed mt-5">
                            By confirming, you agree to our appointment scheduling policy. You'll receive a confirmation once your slot is secured.
                        </p>
                    </div>

                    <div class="flex items-center justify-between px-5 sm:px-10 py-6 border-t border-[#f0ebe4]">
                        <button type="button" data-back="2"
                            class="text-[11px] font-bold tracking-[1.5px] uppercase text-[#6b5c4d] hover:text-[#2f1200] transition-colors inline-flex items-center gap-1.5">
                            <i class="ri-arrow-left-line"></i> Back
                        </button>
                        <button type="submit" id="submitBtn"
                            class="bg-[#2f1200] text-white font-bold text-[11px] tracking-[2px] uppercase px-9 py-3.5 transition-all duration-200 hover:bg-[#c4905c] hover:-translate-y-0.5 disabled:opacity-45 disabled:cursor-not-allowed inline-flex items-center gap-2">
                            <i class="ri-check-line"></i> Confirm Appointment
                        </button>
                    </div>
                </div>

            </form>

            <!-- ═══════════════════════════════
                 SLIM OFFICE-INFO STRIP
            ═══════════════════════════════ -->
            <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-3 mt-8 px-4 text-[12px] text-[#6b5c4d]">
                <span class="flex items-center gap-2"><i class="ri-time-line text-[#c4905c]"></i>Mon–Fri 7AM–5PM · Sat 7AM–12PM</span>
                <span class="flex items-center gap-2"><i class="ri-map-pin-line text-[#c4905c]"></i>MC Premier, EDSA Balintawak</span>
                <span class="flex items-center gap-2"><i class="ri-phone-line text-[#c4905c]"></i>0985 124 5929</span>
            </div>

        </div>

        <?php include $includes['footer']; ?>

    </div>

    

    <script>
        const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        let cMonth = new Date().getMonth();
        let cYear = new Date().getFullYear();
        let pickedDate = null;
        let pickedTime = null;
        let pickedTimeLabel = null;

        const CELL_BASE = 'flex flex-col items-center justify-center min-h-[42px] sm:min-h-[50px] p-1 border transition-all';
        const CELL_STATE = {
            other: 'opacity-20 cursor-default border-transparent',
            past: 'opacity-30 cursor-not-allowed border-transparent',
            avail: 'cursor-pointer bg-[#edf4ea] border-[#2f1200]/10 hover:bg-[#c4905c]/10 hover:border-[#c4905c]/40',
            booked: 'cursor-not-allowed bg-[#faf4f4] border-[#b84040]/10',
            picked: 'cursor-pointer bg-[#2f1200] border-[#2f1200] scale-105 shadow-[0_4px_18px_rgba(47,18,0,0.28)] z-[2]'
        };
        const DAY_COLOR = {
            other: 'text-[#a3907a]', past: 'text-[#a3907a]',
            avail: 'text-[#2a5c44]', booked: 'text-[#b84040]', picked: 'text-white'
        };

        async function getBooked(y, m) {
            try {
                const r = await fetch(`<?= BASE_URL ?>get-booked-dates?year=${y}&month=${m + 1}`);
                return await r.json();
            } catch { return { bookedDates: [], holidayDates: [] }; }
        }

        function mkCday(text, colorClass) {
            return `<span class="font-['Crimson_Pro'] text-[11px] sm:text-[13px] font-semibold leading-none ${colorClass}">${text}</span>`;
        }

        /* ───────────────────────────────────────────────────────────
           CLS FIX: the grid is built once, synchronously, on first
           paint (renderSkeleton). The async fetch that follows only
           re-classes/re-labels EXISTING cells (applyBookedState) — it
           never adds or removes DOM nodes, so the page never grows
           after initial render and nothing below the calendar shifts.
        ─────────────────────────────────────────────────────────── */
        function computeMonthCells(month, year) {
            const firstDow = new Date(year, month, 1).getDay();
            const daysInMon = new Date(year, month + 1, 0).getDate();
            const prevDays = new Date(year, month, 0).getDate();
            const cells = [];
            for (let i = firstDow - 1; i >= 0; i--) cells.push({ day: prevDays - i, type: 'other' });
            for (let d = 1; d <= daysInMon; d++) cells.push({ day: d, type: 'current' });
            const used = cells.length;
            const rem = used % 7 === 0 ? 0 : 7 - (used % 7);
            for (let d = 1; d <= rem; d++) cells.push({ day: d, type: 'other' });
            return cells;
        }

        function renderSkeleton(month, year) {
            const grid = document.getElementById('calGrid');
            document.getElementById('currentMonth').textContent = `${MONTHS[month]} ${year}`;
            grid.querySelectorAll('.cal-cell').forEach(c => c.remove());

            const today = new Date(); today.setHours(0, 0, 0, 0);
            const cells = computeMonthCells(month, year);

            cells.forEach(c => {
                const el = document.createElement('div');
                el.classList.add('cal-cell');

                if (c.type === 'other') {
                    el.className = `cal-cell ${CELL_BASE} ${CELL_STATE.other}`;
                    el.innerHTML = mkCday(c.day, DAY_COLOR.other);
                    grid.appendChild(el);
                    return;
                }

                const ds = `${year}-${String(month + 1).padStart(2, '0')}-${String(c.day).padStart(2, '0')}`;
                const dt = new Date(year, month, c.day);
                const dow = dt.getDay();
                el.dataset.date = ds;
                el.dataset.dow = String(dow);
                el.dataset.day = String(c.day);

                if (dt < today || dow === 0) {
                    el.className = `cal-cell ${CELL_BASE} ${CELL_STATE.past}`;
                    el.innerHTML = mkCday(c.day, DAY_COLOR.past);
                } else if (ds === pickedDate) {
                    el.className = `cal-cell ${CELL_BASE} ${CELL_STATE.picked}`;
                    el.innerHTML = mkCday(c.day, DAY_COLOR.picked);
                    el.addEventListener('click', () => pickDate(ds, dow));
                } else {
                    // Optimistic "available" state — corrected once booking data arrives,
                    // but the cell's size/position never changes, so no shift occurs.
                    el.className = `cal-cell ${CELL_BASE} ${CELL_STATE.avail}`;
                    el.innerHTML = mkCday(c.day, DAY_COLOR.avail);
                    el.addEventListener('click', () => pickDate(ds, dow));
                }
                grid.appendChild(el);
            });
        }

        async function applyBookedState(month, year) {
            const data = await getBooked(year, month);
            const booked = data.bookedDates || [];
            const holidays = data.holidayDates || [];
            const grid = document.getElementById('calGrid');

            grid.querySelectorAll('.cal-cell[data-date]').forEach(el => {
                const ds = el.dataset.date;
                const dow = Number(el.dataset.dow);
                const day = el.dataset.day;
                if (ds === pickedDate) return; // leave the picked cell as-is

                const bk = booked.find(b => b.date === ds);
                const count = bk ? bk.count : 0;
                const holidayInfo = holidays.find(h => h.date === ds);

                if (holidayInfo || count >= 5) {
                    // swap in a fresh node to drop the old "avail" click listener cleanly
                    const clone = el.cloneNode(false);
                    clone.className = `cal-cell ${CELL_BASE} ${CELL_STATE.booked}`;
                    clone.innerHTML = mkCday(day, DAY_COLOR.booked);
                    if (holidayInfo) clone.addEventListener('click', () => showHolidayPopup(holidayInfo.name, ds));
                    el.replaceWith(clone);
                }
                // otherwise the cell is already correctly styled as "avail" from the skeleton pass
            });
        }

        async function drawCal(month, year) {
            renderSkeleton(month, year);
            await applyBookedState(month, year);
        }

        function pickDate(ds, dow) {
            pickedDate = ds;
            pickedTime = null;
            document.getElementById('preferredDate').value = ds;
            document.getElementById('preferredTime').value = '';
            document.getElementById('step1Err').classList.add('hidden');
            drawCal(cMonth, cYear);
            renderTimeSlots(dow, ds);
        }

        function renderTimeSlots(dow, ds) {
            const wrap = document.getElementById('timeSlotWrap');
            const holder = document.getElementById('timeSlots');
            const dateObj = new Date(ds + 'T00:00:00');
            document.getElementById('pickedDateLabel').textContent = dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric' });

            const slots = dow === 6
                ? [['08:00', '8:00 AM'], ['09:00', '9:00 AM'], ['10:00', '10:00 AM'], ['11:00', '11:00 AM']]
                : [['07:00', '7:00 AM'], ['08:00', '8:00 AM'], ['09:00', '9:00 AM'], ['10:00', '10:00 AM'],
                ['11:00', '11:00 AM'], ['13:00', '1:00 PM'], ['14:00', '2:00 PM'],
                ['15:00', '3:00 PM'], ['16:00', '4:00 PM']];

            holder.innerHTML = '';
            slots.forEach(([v, l]) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = l;
                btn.className = 'px-4 py-2 text-[12px] font-semibold border border-[#e3d6c5] text-[#2f1200] hover:border-[#c4905c] transition-colors';
                btn.addEventListener('click', () => {
                    pickedTime = v;
                    pickedTimeLabel = l;
                    document.getElementById('preferredTime').value = v;
                    document.getElementById('step1Err').classList.add('hidden');
                    holder.querySelectorAll('button').forEach(b => {
                        b.className = 'px-4 py-2 text-[12px] font-semibold border border-[#e3d6c5] text-[#2f1200] hover:border-[#c4905c] transition-colors';
                    });
                    btn.className = 'px-4 py-2 text-[12px] font-semibold border border-[#2f1200] bg-[#2f1200] text-white transition-colors';
                });
                holder.appendChild(btn);
            });
            wrap.classList.remove('hidden');
        }

        function showHolidayPopup(name, ds) {
            const existing = document.getElementById('holidayTooltip');
            if (existing) existing.remove();
            const dateObj = new Date(ds + 'T00:00:00');
            const formatted = dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            const popup = document.createElement('div');
            popup.id = 'holidayTooltip';
            popup.className = 'fixed inset-0 bg-[#2f1200]/55 flex items-center justify-center z-[9999] p-5';
            popup.innerHTML = `
                <div class="bg-white max-w-[340px] w-full border-t-[3px] border-[#c4905c]">
                    <div class="px-7 pt-6 flex items-start gap-3.5">
                        <div class="w-[42px] h-[42px] shrink-0 bg-[#faf3e9] flex items-center justify-center text-xl">🎌</div>
                        <div>
                            <div class="font-['Crimson_Pro'] font-semibold text-[16px] text-[#2f1200] mb-1">${name}</div>
                            <div class="text-[12px] text-[#a3907a]">📅 ${formatted}</div>
                            <div class="mt-2.5 text-[12.5px] text-[#6b5c4d] bg-[#faf3e9] border border-[#c4905c]/40 px-3 py-2">
                                This date is not available for booking due to the holiday.
                            </div>
                        </div>
                    </div>
                    <div class="px-7 py-4.5 flex justify-end border-t border-[#e8dfd3] mt-5">
                        <button onclick="document.getElementById('holidayTooltip').remove()"
                            class="px-6 py-2.5 bg-[#2f1200] text-white text-[10px] font-bold tracking-[2px] uppercase hover:bg-[#c4905c] transition-colors">Close</button>
                    </div>
                </div>`;
            popup.addEventListener('click', e => { if (e.target === popup) popup.remove(); });
            document.body.appendChild(popup);
        }

        document.getElementById('prevMonth').addEventListener('click', () => { cMonth--; if (cMonth < 0) { cMonth = 11; cYear--; } drawCal(cMonth, cYear); });
        document.getElementById('nextMonth').addEventListener('click', () => { cMonth++; if (cMonth > 11) { cMonth = 0; cYear++; } drawCal(cMonth, cYear); });

        // ═══════════ STEPPER NAVIGATION ═══════════
        let currentStep = 1;

        function goToStep(n) {
            document.querySelectorAll('.step-panel').forEach(p => p.classList.toggle('hidden', Number(p.dataset.step) !== n));
            document.querySelectorAll('.stepper-node').forEach(node => {
                const idx = Number(node.dataset.stepNode);
                const circle = node.querySelector('.step-circle');
                const label = node.querySelector('.step-label');
                if (idx < n) {
                    circle.className = 'step-circle w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-bold text-[13px] sm:text-[14px] transition-all duration-300 bg-[#c4905c] text-white';
                    circle.innerHTML = '<i class="ri-check-line"></i>';
                    label.className = 'step-label text-[9px] sm:text-[10px] font-bold uppercase tracking-[1px] text-[#2f1200] transition-colors';
                } else if (idx === n) {
                    circle.className = 'step-circle w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-[\'Crimson_Pro\'] font-bold text-[13px] sm:text-[14px] transition-all duration-300 bg-[#2f1200] text-white';
                    circle.textContent = idx;
                    label.className = 'step-label text-[9px] sm:text-[10px] font-bold uppercase tracking-[1px] text-[#2f1200] transition-colors';
                } else {
                    circle.className = 'step-circle w-9 h-9 sm:w-10 sm:h-10 rounded-full flex items-center justify-center font-[\'Crimson_Pro\'] font-bold text-[13px] sm:text-[14px] transition-all duration-300 bg-[#e8dfd3] text-[#a3907a]';
                    circle.textContent = idx;
                    label.className = 'step-label text-[9px] sm:text-[10px] font-bold uppercase tracking-[1px] text-[#a3907a] transition-colors';
                }
            });
            document.querySelectorAll('.step-line-fill').forEach((line, i) => {
                line.style.width = n > (i + 1) ? '100%' : '0%';
            });
            currentStep = n;
            document.querySelector('.max-w-3xl').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        const okEmail = e => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
        const okPhone = p => /^\d{7,15}$/.test(p.replace(/[\s\-()\+]/g, ''));
        const okName = n => /^[a-zA-Z\s\-']{2,50}$/.test(n.trim());

        const ERR_INPUT_CLASSES = ['border-[#b84040]', 'bg-[#fdf2f2]'];
        const OK_INPUT_CLASSES = ['border-[#e3d6c5]'];

        function setErr(id, msg) {
            const g = document.getElementById('fg-' + id);
            if (!g) return;
            const field = g.querySelector('.fg-input');
            if (field) { field.classList.remove(...OK_INPUT_CLASSES); field.classList.add(...ERR_INPUT_CLASSES); }
            let e = g.querySelector('.ferr');
            if (!e) { e = document.createElement('p'); e.className = 'ferr text-[11px] text-[#b84040] mt-1.5 flex items-center gap-1'; g.appendChild(e); }
            e.innerHTML = `<span class="font-bold">!</span> ${msg}`;
        }
        function clearErr(id) {
            const g = document.getElementById('fg-' + id);
            if (!g) return;
            const field = g.querySelector('.fg-input');
            if (field) { field.classList.remove(...ERR_INPUT_CLASSES); field.classList.add(...OK_INPUT_CLASSES); }
            g.querySelector('.ferr')?.remove();
        }
        function clearAllErrs() {
            document.querySelectorAll('.fgroup').forEach(g => {
                const field = g.querySelector('.fg-input');
                if (field) { field.classList.remove(...ERR_INPUT_CLASSES); field.classList.add(...OK_INPUT_CLASSES); }
                g.querySelector('.ferr')?.remove();
            });
        }

        ['firstName', 'lastName'].forEach(id => {
            document.getElementById(id).addEventListener('blur', function () { okName(this.value) ? clearErr(id) : setErr(id, 'Enter a valid name (2–50 letters)'); });
        });
        document.getElementById('email').addEventListener('blur', function () { okEmail(this.value) ? clearErr('email') : setErr('email', 'Enter a valid email address'); });
        document.getElementById('phone').addEventListener('blur', function () { okPhone(this.value) ? clearErr('phone') : setErr('phone', 'Enter a valid phone number (7–15 digits)'); });
        document.getElementById('serviceType').addEventListener('change', function () {
            const show = this.value === 'others';
            document.getElementById('fg-otherService').classList.toggle('hidden', !show);
            if (this.value) clearErr('serviceType');
        });

        function validateStep2() {
            clearAllErrs();
            let fail = false;
            if (!okName(document.getElementById('firstName').value)) { setErr('firstName', 'Enter a valid first name'); fail = true; }
            if (!okName(document.getElementById('lastName').value)) { setErr('lastName', 'Enter a valid last name'); fail = true; }
            if (!okEmail(document.getElementById('email').value)) { setErr('email', 'Enter a valid email address'); fail = true; }
            if (!okPhone(document.getElementById('phone').value)) { setErr('phone', 'Enter a valid phone number'); fail = true; }
            const sv = document.getElementById('serviceType').value;
            if (!sv) { setErr('serviceType', 'Please select a service type'); fail = true; }
            if (sv === 'others' && document.getElementById('otherService').value.trim().length < 3) { setErr('otherService', 'Please describe your service (min 3 characters)'); fail = true; }
            if (fail) document.querySelector('.ferr')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return !fail;
        }

        function buildSummary() {
            const dateObj = new Date(pickedDate + 'T00:00:00');
            const dateStr = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            const svSelect = document.getElementById('serviceType');
            const svLabel = svSelect.options[svSelect.selectedIndex]?.textContent || '—';
            const fullName = `${document.getElementById('firstName').value} ${document.getElementById('lastName').value}`.trim();
            const cc = document.getElementById('countryCode').value;
            const phone = document.getElementById('phone').value;

            const rows = [
                ['Date', dateStr],
                ['Time', pickedTimeLabel || '—'],
                ['Name', fullName || '—'],
                ['Email', document.getElementById('email').value || '—'],
                ['Phone', `${cc} ${phone}` || '—'],
                ['Service', svLabel],
            ];
            const notes = document.getElementById('notes').value.trim();
            if (notes) rows.push(['Notes', notes]);

            document.getElementById('summaryRows').innerHTML = rows.map(([k, v], i) => `
                <div class="flex items-start justify-between gap-4 py-3 ${i < rows.length - 1 ? 'border-b border-dashed border-[#e3d6c5]' : ''}">
                    <span class="text-[10px] font-bold tracking-[1.5px] uppercase text-[#a3907a] shrink-0 pt-0.5">${k}</span>
                    <span class="text-[13px] font-semibold text-[#2f1200] text-right">${v}</span>
                </div>`).join('');
        }

        document.querySelectorAll('[data-next]').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = Number(btn.dataset.next);
                if (currentStep === 1) {
                    if (!pickedDate || !pickedTime) {
                        document.getElementById('step1Err').classList.remove('hidden');
                        document.getElementById('step1Err').scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                }
                if (currentStep === 2) {
                    if (!validateStep2()) return;
                    buildSummary();
                }
                goToStep(target);
            });
        });
        document.querySelectorAll('[data-back]').forEach(btn => {
            btn.addEventListener('click', () => goToStep(Number(btn.dataset.back)));
        });

        document.getElementById('apptForm').addEventListener('submit', async e => {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            btn.disabled = true; btn.innerHTML = 'Submitting…';

            try {
                const res = await fetch('<?= BASE_URL ?>submit-appointment', { method: 'POST', body: new FormData(e.target) });
                const data = await res.json();
                if (data.success) {
                    showModal(true, 'Appointment Confirmed', data.message);
                    e.target.reset();
                    pickedDate = null; pickedTime = null; pickedTimeLabel = null;
                    document.getElementById('timeSlotWrap').classList.add('hidden');
                    document.getElementById('fg-otherService').classList.add('hidden');
                    drawCal(cMonth, cYear);
                    goToStep(1);
                } else {
                    showModal(false, 'Submission Failed', data.message);
                }
            } catch {
                showModal(false, 'Connection Error', 'Unable to submit your appointment. Please try again.');
            } finally {
                btn.disabled = false; btn.innerHTML = '<i class="ri-check-line"></i> Confirm Appointment';
            }
        });

        function showModal(ok, title, msg) {
            const d = document.createElement('div');
            d.className = 'fixed inset-0 bg-[#1c1c1c]/60 flex items-center justify-center z-[9999] p-5';
            d.innerHTML = `
                <div class="bg-white max-w-[420px] w-full border-t-[3px] ${ok ? 'border-[#2f1200]' : 'border-[#b84040]'}">
                    <div class="px-7 sm:px-8 pt-7 flex items-start gap-4">
                        <div class="w-10 h-10 shrink-0 flex items-center justify-center text-lg font-bold ${ok ? 'bg-[#faf3e9] text-[#2f1200]' : 'bg-[#fdf2f2] text-[#b84040]'}">${ok ? '✓' : '✕'}</div>
                        <div>
                            <div class="font-['Crimson_Pro'] font-semibold text-[16px] text-[#2f1200] mb-1">${title}</div>
                            <div class="text-[13px] text-[#6b5c4d] leading-relaxed">${msg}</div>
                        </div>
                    </div>
                    <div class="px-7 sm:px-8 py-5 flex justify-end border-t border-[#e8dfd3] mt-6">
                        <button onclick="this.closest('.fixed').remove()"
                            class="px-7 py-2.5 text-white text-[10px] font-bold tracking-[2px] uppercase transition-colors ${ok ? 'bg-[#2f1200] hover:bg-[#c4905c]' : 'bg-[#b84040] hover:bg-[#8f2e2e]'}">Close</button>
                    </div>
                </div>`;
            document.body.appendChild(d);
        }

        drawCal(cMonth, cYear);
    </script>
</body>

</html>