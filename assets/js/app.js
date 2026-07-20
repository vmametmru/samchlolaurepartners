// Each init function is isolated in its own try/catch: on any given page only
// a handful of these apply (most simply no-op via querySelectorAll on absent
// data attributes), but if one throws (e.g. an unexpected DOM shape on some
// device/browser) it must not abort the rest of this handler, otherwise
// later calls — crucially initBookingCalendarSelection() and
// initMultiPropertyCart(), which wire up the date-selection click handlers —
// would silently never run, leaving calendar cells looking interactive
// (hover/tap CSS still applies) but unresponsive to clicks/taps.
function runInit(fn) {
  try {
    fn();
  } catch (error) {
    if (window.console && console.error) console.error(`[app.js] ${fn.name} failed:`, error);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  [
    initGallery,
    initShareButton,
    initPropertyTabs,
    initMaps,
    initApiForms,
    initNationalities,
    initTemplateEditor,
    initColorSync,
    initDateRanges,
    initBookingCalendarSelection,
    initPhoneInputs,
    initGuestSteppers,
    initCalendarGuestPricing,
    initBookingModal,
    initBookingAccordion,
    initBookingQuote,
    initCalendarBoard,
    initCalendarFilterLoading,
    initCalendarFilterSubmitState,
    initCalendarNameColumnToggle,
    initCalendarGuestSlider,
    initHelpDialogs,
    initMultiPropertyCart,
    initPartnerCodeFromHash,
    initMobileNavbar,
    initHeroVideoLoading,
    initHeroMobileSearchToggle,
    initHeroSearchCollapse,
  ].forEach(runInit);
});

// Typing "https://.../#code" into the address bar when a document at that
// same path is already loaded (e.g. the visitor was just on "/") is treated
// by browsers as a same-document fragment navigation: no reload happens, so
// 'DOMContentLoaded' never fires again and initPartnerCodeFromHash() (wired
// above) would never run for the new hash, leaving the visitor stuck on the
// "Bienvenue" gate page. Re-run it on 'hashchange' too so both a fresh
// full-page load AND an in-page hash change are handled.
window.addEventListener('hashchange', () => runInit(initPartnerCodeFromHash));

function initGallery() {
  document.querySelectorAll('[data-gallery]').forEach((gallery) => {
    const main = gallery.querySelector('[data-gallery-main]');
    const thumbs = gallery.querySelectorAll('[data-gallery-thumb]');
    thumbs.forEach((thumb) => {
      thumb.addEventListener('click', () => {
        thumbs.forEach((item) => item.classList.remove('active'));
        thumb.classList.add('active');
        if (main) main.src = thumb.dataset.src || '';
      });
    });

    const track = gallery.querySelector('[data-gallery-track]');
    if (!track) return;
    let scrollDirection = 0;
    let scrollFrame = null;
    const edgeZoneRatio = 0.2;

    const step = () => {
      if (scrollDirection !== 0) {
        track.scrollLeft += scrollDirection * 6;
        scrollFrame = window.requestAnimationFrame(step);
      } else {
        scrollFrame = null;
      }
    };

    const startScrolling = (direction) => {
      if (scrollDirection === direction) return;
      scrollDirection = direction;
      if (!scrollFrame) scrollFrame = window.requestAnimationFrame(step);
    };

    const stopScrolling = () => {
      scrollDirection = 0;
    };

    track.addEventListener('mousemove', (event) => {
      const rect = track.getBoundingClientRect();
      const relativeX = (event.clientX - rect.left) / rect.width;
      if (relativeX <= edgeZoneRatio) {
        startScrolling(-1);
      } else if (relativeX >= 1 - edgeZoneRatio) {
        startScrolling(1);
      } else {
        stopScrolling();
      }
    });
    track.addEventListener('mouseleave', stopScrolling);
  });
}

function initCalendarBoard() {
  // This "hover the edge of the board to auto-scroll" behaviour relies on
  // continuous mousemove events plus a mouseleave to stop it. Touch devices
  // (iPhone/iPad) only ever fire a single synthetic mousemove at the tap
  // location before the click, with no matching mouseleave: if that tap
  // happened to land within the edge zone, scrollDirection was set once and
  // never reset, so the requestAnimationFrame loop kept scrolling the board
  // to the right forever and the visitor could never scroll back. Restrict
  // this feature to devices that actually have a real hover-capable pointer
  // (mouse/trackpad).
  if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
  document.querySelectorAll('[data-calendar-board]').forEach((board) => {
    let scrollDirection = 0;
    let scrollFrame = null;
    const edgeZoneRatio = 0.12;
    const speed = 10;

    const step = () => {
      if (scrollDirection !== 0) {
        board.scrollLeft += scrollDirection * speed;
        scrollFrame = window.requestAnimationFrame(step);
      } else {
        scrollFrame = null;
      }
    };

    const startScrolling = (direction) => {
      if (scrollDirection === direction) return;
      scrollDirection = direction;
      if (!scrollFrame) scrollFrame = window.requestAnimationFrame(step);
    };

    const stopScrolling = () => {
      scrollDirection = 0;
    };

    board.addEventListener('mousemove', (event) => {
      const rect = board.getBoundingClientRect();
      const relativeX = (event.clientX - rect.left) / rect.width;
      if (relativeX <= edgeZoneRatio) {
        startScrolling(-1);
      } else if (relativeX >= 1 - edgeZoneRatio) {
        startScrolling(1);
      } else {
        stopScrolling();
      }
    });
    board.addEventListener('mouseleave', stopScrolling);
  });
}

function initCalendarFilterLoading() {
  const form = document.querySelector('.calendar-filter');
  const loading = document.querySelector('[data-calendar-loading]');
  if (!form || !loading) return;
  form.addEventListener('submit', () => {
    loading.hidden = false;
  });
}

/**
 * The /calendrier filter form only makes sense once the visitor has given
 * either a date range or a number of guests: disable "Afficher les
 * disponibilités" while both the date pickers are empty and the guest count
 * is 0, and re-enable it as soon as either condition is satisfied.
 */
function initCalendarFilterSubmitState() {
  const form = document.querySelector('[data-calendar-filter-form]');
  const submitBtn = form ? form.querySelector('[data-calendar-filter-submit]') : null;
  if (!form || !submitBtn) return;

  const dateFrom = form.querySelector('input[name="date_from"]');
  const dateTo = form.querySelector('input[name="date_to"]');
  const guestInputs = Array.from(
    form.querySelectorAll('[data-guest-slide-input], [data-calendar-guest-input]')
  );

  function totalGuests() {
    return guestInputs.reduce((sum, input) => sum + (parseInt(input.value || '0', 10) || 0), 0);
  }

  function updateState() {
    const datesFilled = Boolean(dateFrom && dateFrom.value) && Boolean(dateTo && dateTo.value);
    submitBtn.disabled = !datesFilled && totalGuests() === 0;
  }

  [dateFrom, dateTo].forEach((input) => {
    if (input) input.addEventListener('input', updateState);
  });
  guestInputs.forEach((input) => input.addEventListener('input', updateState));

  updateState();
}

/**
 * Wires the "Aide" (help) buttons to open their associated <dialog> as a
 * modal, and lets the dialog's own "×" close form submit (method="dialog")
 * close it again. Without this, clicking the button did nothing since
 * <dialog> elements require showModal() to be called from JavaScript.
 */
function initHelpDialogs() {
  document.querySelectorAll('[data-help-trigger]').forEach((trigger) => {
    const name = trigger.dataset.helpTrigger;
    const dialog = document.querySelector(`[data-help-dialog="${name}"]`);
    if (!dialog || typeof dialog.showModal !== 'function') return;
    trigger.addEventListener('click', () => {
      dialog.showModal();
    });
  });
}

/**
 * Horizontally-sliding guest count fields (Adulte(s) / Enfant(s) 3-12 ans /
 * Bébé(s) -3 ans) on the /calendrier filter form: only one field is expanded
 * (input visible) at a time, the others collapse to a small icon + count
 * button. Clicking a collapsed icon expands its field and collapses the
 * previously active one, keeping every value regardless of which field is
 * currently shown.
 */
function initCalendarGuestSlider() {
  document.querySelectorAll('[data-guest-slide-group]').forEach((group) => {
    const items = Array.from(group.querySelectorAll('[data-guest-slide-item]'));
    if (!items.length) return;

    function setActive(target) {
      items.forEach((item) => {
        const isTarget = item === target;
        item.classList.toggle('active', isTarget);
        const input = item.querySelector('[data-guest-slide-input]');
        const count = item.querySelector('[data-guest-slide-count]');
        if (input && count) count.textContent = input.value || '0';
      });
      const input = target.querySelector('[data-guest-slide-input]');
      if (input) input.focus();
    }

    items.forEach((item) => {
      const summary = item.querySelector('[data-guest-slide-summary]');
      const input = item.querySelector('[data-guest-slide-input]');
      const count = item.querySelector('[data-guest-slide-count]');
      summary?.addEventListener('click', () => setActive(item));
      input?.addEventListener('input', () => {
        if (count) count.textContent = input.value || '0';
      });
    });
  });
}

/**
 * The /calendrier board's property-name column is hidden by default (see
 * the "cal-name-hidden" class rendered server-side in calendar.php) to
 * leave more width for the date columns, which matters most on narrow
 * mobile screens in portrait mode. This wires the checkbox that lets a
 * visitor reveal it, remembering their choice in localStorage (same
 * behaviour on desktop and mobile) so it doesn't reset on every page load.
 */
function initCalendarNameColumnToggle() {
  const board = document.querySelector('[data-calendar-board]');
  const toggle = document.querySelector('[data-calendar-name-toggle]');
  if (!board || !toggle) return;

  const storageKey = 'calendarNameColumnVisible';
  let stored = null;
  try {
    stored = window.localStorage.getItem(storageKey);
  } catch (error) {
    stored = null;
  }
  const visible = stored === '1';
  toggle.checked = visible;
  board.classList.toggle('cal-name-hidden', !visible);

  toggle.addEventListener('change', () => {
    board.classList.toggle('cal-name-hidden', !toggle.checked);
    try {
      window.localStorage.setItem(storageKey, toggle.checked ? '1' : '0');
    } catch (error) {
      // Ignore storage errors (e.g. private browsing): the choice simply
      // won't persist across page loads, which is a harmless degradation.
    }
  });
}

function initShareButton() {
  document.querySelectorAll('[data-share-btn]').forEach((btn) => {
    const toast = btn.closest('.gallery-share')?.querySelector('[data-share-toast]');
    let hideTimeout = null;
    btn.addEventListener('click', async () => {
      const url = window.location.href;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(url);
        } else {
          const textarea = document.createElement('textarea');
          textarea.value = url;
          textarea.style.position = 'fixed';
          textarea.style.opacity = '0';
          document.body.appendChild(textarea);
          textarea.select();
          document.execCommand('copy');
          document.body.removeChild(textarea);
        }
      } catch (error) {
        return;
      }
      if (!toast) return;
      toast.classList.add('visible');
      if (hideTimeout) window.clearTimeout(hideTimeout);
      hideTimeout = window.setTimeout(() => {
        toast.classList.remove('visible');
      }, 3000);
    });
  });
}

function initPropertyTabs() {
  document.querySelectorAll('[data-tabs]').forEach((tabs) => {
    const buttons = tabs.querySelectorAll('[data-tab-btn]');
    const section = tabs.closest('[data-gallery]') || tabs.parentElement;
    const panelsContainer = section ? section.querySelector('[data-tab-panels]') : null;
    if (!panelsContainer) return;
    const panels = panelsContainer.querySelectorAll('[data-tab-panel]');
    const formPanels = section.querySelectorAll('[data-form-panel]');
    const detailGrid = section.querySelector('.detail-grid');
    const activate = (target) => {
      buttons.forEach((item) => item.classList.toggle('active', item.dataset.tabBtn === target));
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.tabPanel !== target;
      });
      formPanels.forEach((panel) => {
        panel.hidden = panel.dataset.formPanel !== target;
      });
      if (detailGrid) detailGrid.classList.toggle('detail-grid-single', target !== 'rates-availability');
    };
    buttons.forEach((button) => {
      button.addEventListener('click', () => activate(button.dataset.tabBtn));
    });
    const hashTarget = window.location.hash ? window.location.hash.slice(1) : '';
    if (hashTarget && tabs.querySelector(`[data-tab-btn="${hashTarget}"]`)) {
      activate(hashTarget);
    } else {
      activate('description');
    }

    section.querySelectorAll('[data-reserve-btn]').forEach((button) => {
      button.addEventListener('click', () => {
        const target = button.dataset.reserveTab || 'rates-availability';
        activate(target);
        const targetTabBtn = tabs.querySelector(`[data-tab-btn="${target}"]`);
        if (targetTabBtn) targetTabBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const calendarWidget = section.querySelector('[data-calendar-widget]');
        if (calendarWidget) calendarWidget.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  });
}

/**
 * Wires the "Tarifs & Disponibilités" calendar to the booking form's hidden
 * checkin/checkout fields.
 *
 * Selection rules:
 * - 1st click sets the arrival date. It is only accepted if the date starts
 *   a run of at least "min stay" consecutive available nights (a date with
 *   fewer available nights ahead of it is not clickable/reservable).
 * - 2nd click sets the departure date, as long as every night between the
 *   arrival and that date is available. A departure date does not need to be
 *   "available" itself (it's just the day the guest leaves), so a date on
 *   which another guest is arriving can still be picked as someone else's
 *   departure date, and vice versa.
 * - If the 2nd click lands on a date that is before/equal to the arrival, or
 *   with an unavailable night in between, that click becomes the new arrival
 *   date and the widget waits for a new departure click.
 */
function initBookingCalendarSelection() {
  document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const propertyId = form.dataset.propertyId;
    const checkinInput = form.querySelector('[data-booking-checkin]');
    const checkoutInput = form.querySelector('[data-booking-checkout]');
    const summary = form.querySelector('[data-booking-dates-summary]');
    const calendarWidget = propertyId
      ? document.querySelector(`[data-calendar-widget][data-property-id="${propertyId}"]`)
      : null;
    if (!calendarWidget || !checkinInput || !checkoutInput) return;

    let checkin = null;
    let checkout = null;

    function formatFr(dateStr) {
      const [y, m, d] = dateStr.split('-');
      return `${d}/${m}/${y}`;
    }

    function nightsBetween(startStr, endStr) {
      const start = new Date(`${startStr}T00:00:00`);
      const end = new Date(`${endStr}T00:00:00`);
      return Math.round((end - start) / 86400000);
    }

    function addDaysStr(dateStr, days) {
      // Use UTC arithmetic so the result is independent of the visitor's
      // timezone: building the date in local time and reading it back with
      // toISOString() shifts it by a day in timezones ahead of UTC (e.g.
      // Mauritius, UTC+4), which broke availability checks (turnover day
      // wrongly greyed out) and caused an infinite loop when selecting the
      // departure date.
      const [y, m, d] = dateStr.split('-').map(Number);
      const date = new Date(Date.UTC(y, m - 1, d));
      date.setUTCDate(date.getUTCDate() + days);
      return date.toISOString().slice(0, 10);
    }

    // Map<date, { available: boolean, minStay: number }> built once from the
    // server-rendered cells (the calendar is not re-rendered client-side).
    const nightInfo = new Map();
    calendarWidget.querySelectorAll('[data-calendar-date]').forEach((cell) => {
      const date = cell.dataset.calendarDate;
      if (!date) return;
      nightInfo.set(date, {
        available: cell.dataset.calendarAvailable === '1',
        minStay: Math.max(1, parseInt(cell.dataset.calendarMinstay || '1', 10) || 1),
      });
    });

    function isNightAvailable(date) {
      const info = nightInfo.get(date);
      return Boolean(info && info.available);
    }

    // Property minimum-stay (in nights) that applies to a stay starting on
    // this arrival date. Defaults to 1 when the date is unknown.
    function minStayFor(date) {
      const info = nightInfo.get(date);
      return info ? info.minStay : 1;
    }

    // Whether a stay can start on this date. Any available (green) day is a
    // valid arrival date, including the turnover/arrival days of existing
    // reservations: the guest simply picks a departure date afterwards. The
    // minimum-stay is not enforced here (it would grey out otherwise bookable
    // arrival days); it is checked when the departure date is clicked instead.
    function canStartStayAt(date) {
      return isNightAvailable(date);
    }

    // Whether every night from startDate (inclusive) to endDate (exclusive)
    // is available, i.e. a valid stay with no gap in between.
    function isRangeFullyAvailable(startDate, endDate) {
      let cursor = startDate;
      while (cursor < endDate) {
        if (!isNightAvailable(cursor)) return false;
        cursor = addDaysStr(cursor, 1);
      }
      return true;
    }

    // Every available day stays green and clickable as an arrival date; the
    // minimum-stay is enforced only when the departure date is chosen, so no
    // bookable arrival day is greyed out.

    function update() {
      checkinInput.value = checkin || '';
      checkoutInput.value = checkout || '';
      if (summary) {
        if (checkin && checkout) {
          summary.innerHTML = `<p>Arrivée : ${formatFr(checkin)}</p><p>Départ : ${formatFr(checkout)}</p><p>Nuits : ${nightsBetween(checkin, checkout)}</p>`;
        } else if (checkin) {
          const minStay = minStayFor(checkin);
          const minHint = minStay > 1 ? ` (séjour minimum : ${minStay} nuits)` : '';
          summary.innerHTML = `<p>Arrivée : ${formatFr(checkin)}</p><p class="muted">Cliquez sur une autre date du calendrier pour le départ${minHint}.</p>`;
        } else {
          summary.innerHTML = '<p class="muted">Sélectionnez vos dates dans le calendrier (Tarifs &amp; Disponibilités) : 1er clic = arrivée, 2e clic = départ.</p>';
        }
      }
      calendarWidget.querySelectorAll('[data-calendar-date]').forEach((cell) => {
        const date = cell.dataset.calendarDate;
        cell.classList.toggle('selected', date === checkin || date === checkout);
        cell.classList.toggle('in-range', Boolean(checkin && checkout && date > checkin && date < checkout));
      });
      form.dispatchEvent(new CustomEvent('booking-dates-changed'));
    }

    calendarWidget.addEventListener('click', (event) => {
      const cell = event.target.closest('[data-calendar-date]');
      if (!cell) return;
      const date = cell.dataset.calendarDate;
      if (!date) return;

      if (!checkin || checkout) {
        // Fresh selection (arrival click).
        if (!canStartStayAt(date)) return;
        checkin = date;
        checkout = null;
      } else if (date === checkin) {
        // Clicking the arrival date again clears the selection instead of
        // silently re-picking the same date as a new arrival.
        checkin = null;
        checkout = null;
      } else if (date <= checkin || !isRangeFullyAvailable(checkin, date)) {
        // Invalid departure (before/same as arrival, or a booking in between):
        // this click becomes the new arrival date instead.
        checkin = canStartStayAt(date) ? date : null;
        checkout = null;
      } else if (nightsBetween(checkin, date) < minStayFor(checkin)) {
        // Valid, fully-free range but shorter than the property's minimum
        // stay: keep the arrival selected and wait for a later departure
        // rather than accepting a too-short booking (nights, not days: e.g.
        // 10→17 July is 7 nights). Ignore this click.
        return;
      } else {
        checkout = date;
      }
      update();
    });

    form.addEventListener('reset', () => {
      checkin = null;
      checkout = null;
      update();
    });

    form.addEventListener('booking-clear-dates', () => {
      checkin = null;
      checkout = null;
      update();
    });
  });
}

/**
 * Keeps the nightly price shown in the property-detail "Tarifs &
 * Disponibilités" calendar in sync with the cleaning fee for the currently
 * selected number of guests (adults + children), the same way the
 * /calendrier board folds the cleaning fee into the displayed price.
 * data-calendar-rate on each cell holds the base nightly rate (no cleaning
 * fee); data-cleaning-fee-per-person on the widget holds the per-person,
 * per-night cleaning fee configured for the active partner.
 */
function initCalendarGuestPricing() {
  document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const propertyId = form.dataset.propertyId;
    const calendarWidget = propertyId
      ? document.querySelector(`[data-calendar-widget][data-property-id="${propertyId}"]`)
      : null;
    if (!calendarWidget) return;

    const cleaningFeePerPerson = Number(calendarWidget.dataset.cleaningFeePerPerson || 0);
    const guestInputs = Array.from(form.querySelectorAll('[data-guest-stepper] input'));
    if (!guestInputs.length) return;

    function totalGuests() {
      return guestInputs.reduce((sum, input) => sum + Number(input.value || 0), 0);
    }

    function updatePrices() {
      const cleaningFeePerNight = cleaningFeePerPerson * totalGuests();
      calendarWidget.querySelectorAll('[data-calendar-rate]').forEach((cell) => {
        const baseRate = Number(cell.dataset.calendarRate || 0);
        const priceEl = cell.querySelector('.calendar-price');
        if (!priceEl) return;
        const total = Math.round((baseRate + cleaningFeePerNight) * 100) / 100;
        const currency = cell.dataset.calendarCurrency || '';
        priceEl.textContent = `${total.toLocaleString('fr-FR', { maximumFractionDigits: 0 })} ${currency}`.trim();
      });
    }

    guestInputs.forEach((input) => input.addEventListener('input', updatePrices));
    form.addEventListener('reset', () => setTimeout(updatePrices, 0));
  });
}

/**
 * Controls the slide-in booking modal on the property-detail "Tarifs &
 * Disponibilités" tab: the modal appears (slides from the right with a 20%
 * background overlay) as soon as both arrival and departure dates are set,
 * and hides when:
 *  - the "Masquer" button inside the modal is clicked (allows the visitor to
 *    go back to the calendar to change dates; the modal re-opens on the next
 *    complete date selection),
 *  - "Effacer les dates sélectionnées" is clicked (also clears the calendar
 *    selection but keeps Nombre de Voyageurs / Détails des Voyageurs data).
 */
function initBookingModal() {
  document.querySelectorAll('[data-booking-modal-overlay]').forEach((overlay) => {
    const form = overlay.querySelector('[data-booking-form]');
    if (!form) return;

    const propertyId = form.dataset.propertyId;
    const calendarWidget = propertyId
      ? document.querySelector(`[data-calendar-widget][data-property-id="${propertyId}"]`)
      : null;
    const ratesPanel = calendarWidget
      ? calendarWidget.closest('[data-tab-panel="rates-availability"]')
      : null;
    const clearBtn = ratesPanel ? ratesPanel.querySelector('[data-clear-dates-btn]') : null;
    const hideBtn = overlay.querySelector('[data-booking-modal-hide]');

    let isOpen = false;

    function openModal() {
      if (isOpen) return;
      isOpen = true;
      overlay.style.removeProperty('display');
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          overlay.classList.add('booking-modal-open');
        });
      });
    }

    function closeModal() {
      if (!isOpen) return;
      isOpen = false;
      overlay.classList.remove('booking-modal-open');
      overlay.addEventListener('transitionend', () => {
        if (!isOpen) overlay.style.display = 'none';
      }, { once: true });
    }

    function updateFromDates() {
      const checkin = form.querySelector('[data-booking-checkin]')?.value || '';
      const checkout = form.querySelector('[data-booking-checkout]')?.value || '';
      if (checkin && checkout) {
        openModal();
      } else {
        closeModal();
      }
      if (clearBtn) clearBtn.hidden = !(checkin || checkout);
    }

    form.addEventListener('booking-dates-changed', updateFromDates);

    if (hideBtn) {
      hideBtn.addEventListener('click', closeModal);
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        form.dispatchEvent(new CustomEvent('booking-clear-dates'));
      });
    }
  });
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[char]));
}

function initMaps() {
  if (typeof window.L === 'undefined') return;
  document.querySelectorAll('.map-board').forEach((board) => {
    let points = [];
    try {
      points = JSON.parse(board.dataset.points || '[]');
    } catch (error) {
      if (window.console && console.error) console.error('[app.js] initMaps failed to parse points:', error);
    }
    const center = points.length ? [points[0].lat, points[0].lng] : [-20.0186, 57.5807];
    const map = window.L.map(board, { scrollWheelZoom: false }).setView(center, points.length ? 13 : 11);
    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19,
    }).addTo(map);

    const markers = [];
    points.forEach((point) => {
      const marker = window.L.marker([point.lat, point.lng]).addTo(map);
      const name = escapeHtml(point.name) + (point.estimated ? ' (position approximative)' : '');
      const url = escapeHtml(point.url);
      const imageHtml = point.image
        ? `<img class="map-popup-image" src="${escapeHtml(point.image)}" alt="${escapeHtml(point.name)}">`
        : '';
      const popupHtml = `
        <div class="map-popup">
          ${imageHtml}
          <h4 class="map-popup-title">${name}</h4>
          <div class="map-popup-meta"><span>${point.bedrooms || 0} ch.</span><span>·</span><span>${point.maxGuests || 0} pers. max</span></div>
          <a class="btn-primary map-popup-link" href="${url}">Voir la fiche</a>
        </div>
      `;
      marker.bindPopup(popupHtml, { minWidth: 220 });
      markers.push(marker);
    });

    if (markers.length > 1) {
      map.fitBounds(window.L.featureGroup(markers).getBounds().pad(0.2));
    }
  });
}

function showTransientFormPopup(form, message, state = 'success') {
  const popupId = form.dataset.feedbackPopupId || '';
  if (!popupId) return;
  const popup = document.getElementById(popupId);
  if (!popup) return;

  const box = popup.querySelector('[data-form-status-popup-box]');
  const messageEl = popup.querySelector('[data-form-status-popup-message]');
  if (messageEl) messageEl.textContent = message;
  if (box) {
    box.classList.toggle('success', state === 'success');
    box.classList.toggle('error', state === 'error');
  }

  if (popup._hideTimer) window.clearTimeout(popup._hideTimer);
  if (popup._hideTransitionTimer) window.clearTimeout(popup._hideTransitionTimer);

  popup.hidden = false;
  requestAnimationFrame(() => {
    popup.classList.add('visible');
  });

  popup._hideTimer = window.setTimeout(() => {
    popup.classList.remove('visible');
    popup._hideTransitionTimer = window.setTimeout(() => {
      if (!popup.classList.contains('visible')) popup.hidden = true;
    }, 200);
  }, 3000);
}

function initApiForms() {
  document.querySelectorAll('[data-api-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const feedback = form.querySelector('[data-form-feedback]');
      if (feedback) {
        feedback.textContent = '';
        feedback.classList.remove('success');
      }
      if (form.hasAttribute('data-booking-form')) {
        const checkin = form.querySelector('[data-booking-checkin]')?.value;
        const checkout = form.querySelector('[data-booking-checkout]')?.value;
        if (!checkin || !checkout) {
          if (feedback) feedback.textContent = "Veuillez sélectionner vos dates d'arrivée et de départ dans le calendrier.";
          return;
        }
        const maxGuests = Number(form.dataset.maxGuests || 0);
        if (maxGuests > 0) {
          // Babies (children_under3) don't count toward the property's capacity.
          const total = ['adults', 'children_3to12']
            .reduce((sum, name) => sum + Number(form.querySelector(`[name="${name}"]`)?.value || 0), 0);
          if (total > maxGuests) {
            if (feedback) feedback.textContent = `Ce logement peut accueillir au maximum ${maxGuests} personne(s). Veuillez réduire le nombre de voyageurs.`;
            return;
          }
        }
      }
      const multiCartItemsField = form.querySelector('[data-multi-cart-items]');
      if (multiCartItemsField && !multiCartItemsField.value) {
        if (feedback) feedback.textContent = 'Veuillez sélectionner au moins un bien et des dates avant de continuer.';
        return;
      }
      const data = buildFormPayload(form);
      try {
        const response = await fetch(form.action, {
          method: form.method || 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(data),
          credentials: 'same-origin'
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Erreur');
        form.reset();
        const quoteBox = form.querySelector('[data-quote-box]');
        if (quoteBox) quoteBox.hidden = true;
        if (feedback) {
          feedback.textContent = form.dataset.successMessage || payload.message || 'Succès';
          feedback.classList.add('success');
        }
        showTransientFormPopup(form, form.dataset.successMessage || payload.message || 'Succès', 'success');
      } catch (error) {
        if (feedback) feedback.textContent = error.message || 'Une erreur est survenue.';
        showTransientFormPopup(form, error.message || 'Une erreur est survenue.', 'error');
      }
    });
  });
}

function buildFormPayload(form) {
  const data = {};
  const formData = new FormData(form);
  formData.forEach((value, key) => {
    if (key.endsWith('[]')) return;
    data[key] = value;
  });
  ['adults', 'children'].forEach((key) => {
    if (data[key] !== undefined) data[key] = Number(data[key] || 0);
  });
  data.guests = collectGuests(form);
  return data;
}

/**
 * Renders each "adults"/"children_*" number field as a row (label on the
 * left, always-visible -/value/+ controls on the right) and enforces the
 * property's maximum occupancy (data-max-guests on the booking form) across
 * the combined adults + children count: the "+" buttons are disabled and a
 * warning note is shown once the capacity is reached, and any attempt to go
 * over it (typing a value directly, or clicking "+") is clamped back down.
 */
function initGuestSteppers() {
  document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const rows = Array.from(form.querySelectorAll('[data-guest-stepper]'));
    const inputs = rows.map((row) => row.querySelector('input')).filter(Boolean);
    if (!inputs.length) return;
    const maxGuests = Number(form.dataset.maxGuests || 0) || Infinity;
    const note = form.querySelector('[data-guest-capacity-note]');

    // Babies (children_under3) do not count toward the property's max capacity.
    function countedGuests() {
      return inputs
        .filter((input) => input.name !== 'children_under3')
        .reduce((sum, item) => sum + Number(item.value || 0), 0);
    }

    function updateCapacityState() {
      const total = countedGuests();
      const atCapacity = isFinite(maxGuests) && total >= maxGuests;
      rows.forEach((row) => {
        const input = row.querySelector('input');
        // The babies row is never blocked by the overall capacity limit.
        if (input && input.name === 'children_under3') return;
        const incBtn = row.querySelector('[data-step="1"]');
        if (incBtn) incBtn.disabled = atCapacity;
      });
      if (note) {
        note.hidden = !isFinite(maxGuests) || total <= maxGuests;
        if (!note.hidden) {
          note.textContent = `Ce logement peut accueillir au maximum ${maxGuests} personne(s) (adultes + enfants de 3 ans et plus).`;
        }
      }
    }

    rows.forEach((row) => {
      const input = row.querySelector('input');
      if (!input) return;
      const min = Number(input.min || 0);
      const fieldMax = Number(input.max || 99);

      function setValue(newValue) {
        let clamped = Math.min(fieldMax, Math.max(min, Number.isFinite(newValue) ? newValue : min));
        // Babies don't count toward max_guests, so only clamp non-baby fields.
        if (isFinite(maxGuests) && input.name !== 'children_under3') {
          const othersCountedTotal = countedGuests() - Number(input.value || 0);
          clamped = Math.min(clamped, Math.max(min, maxGuests - othersCountedTotal));
        }
        input.value = String(clamped);
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }

      row.querySelectorAll('[data-step]').forEach((btn) => {
        btn.addEventListener('click', () => setValue(Number(input.value || 0) + Number(btn.dataset.step)));
      });
      input.addEventListener('input', updateCapacityState);
      input.addEventListener('change', () => setValue(Number(input.value || 0)));
    });

    form.addEventListener('reset', () => setTimeout(updateCapacityState, 0));
    updateCapacityState();
  });
}

/**
 * Accordion behaviour between the booking form's "Nombre de Voyageur(s)"
 * block and the "détails des voyageurs" block: opening one collapses the
 * other. The dates block above is always visible (not part of the
 * accordion). The summary block (3rd, submit) visibility is handled
 * separately by initBookingQuote() once the required fields are filled in.
 * When the booking form uses the slide-in modal layout (no [data-block-toggle]
 * buttons), this function is a no-op so it does not hide any block bodies.
 */
function initBookingAccordion() {
  document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const blocks = Array.from(form.querySelectorAll('[data-booking-block]')).filter(
      (block) => block.dataset.bookingBlock !== 'summary' && block.querySelector('[data-block-toggle]')
    );
    if (blocks.length < 2) return;

    function setOpen(block, open) {
      const body = block.querySelector('[data-block-body]');
      const header = block.querySelector('[data-block-toggle]');
      if (body) body.hidden = !open;
      if (header) header.classList.toggle('open', open);
    }

    blocks.forEach((block) => {
      const header = block.querySelector('[data-block-toggle]');
      if (!header) return;
      header.addEventListener('click', () => {
        blocks.forEach((other) => setOpen(other, other === block));
      });
    });

    setOpen(blocks[0], true);
    for (let i = 1; i < blocks.length; i += 1) setOpen(blocks[i], false);
    form.addEventListener('reset', () => {
      setOpen(blocks[0], true);
      for (let i = 1; i < blocks.length; i += 1) setOpen(blocks[i], false);
    });
  });
}

function initNationalities() {
  document.querySelectorAll('[data-nationalities]').forEach((wrap) => {
    const list = wrap.querySelector('[data-nationality-list]');
    const template = wrap.querySelector('[data-nationality-template]');
    const uniformBox = wrap.querySelector('[data-nationality-single]');
    const sameCheckbox = wrap.querySelector('[data-same-nationality]');
    const uniformSelect = wrap.querySelector('[data-uniform-nationality]');
    const form = wrap.closest('form');
    const adultsInput = form.querySelector('[name="adults"]');
    const childrenInput = form.querySelector('[name="children"]');
    const childrenUnder3Input = form.querySelector('[name="children_under3"]');
    const children3to12Input = form.querySelector('[name="children_3to12"]');
    // Some forms (property detail booking) split children into two age
    // groups (< 3 ans / 3-12 ans) while others (contact form) only have a
    // single "children" field. Both are supported here.
    const hasSplitChildren = Boolean(childrenUnder3Input || children3to12Input);

    function render() {
      const adults = Number(adultsInput?.value || 0);
      const under3 = hasSplitChildren ? Number(childrenUnder3Input?.value || 0) : 0;
      const from3to12 = hasSplitChildren ? Number(children3to12Input?.value || 0) : Number(childrenInput?.value || 0);
      const children = under3 + from3to12;
      if (hasSplitChildren && childrenInput) childrenInput.value = String(children);
      const total = adults + children;
      list.innerHTML = '';
      const same = sameCheckbox?.checked;
      if (uniformBox) uniformBox.classList.toggle('hidden', !same);
      if (same) return;
      for (let i = 0; i < total; i += 1) {
        const node = template.content.firstElementChild.cloneNode(true);
        let label;
        let type;
        if (i < adults) {
          label = `Adulte ${i + 1} — Nationalité`;
          type = 'adult';
        } else if (hasSplitChildren && i < adults + under3) {
          label = `Enfant (< 3 ans) ${i - adults + 1} — Nationalité`;
          type = 'child_under3';
        } else if (hasSplitChildren) {
          label = `Enfant (3-12 ans) ${i - adults - under3 + 1} — Nationalité`;
          type = 'child_3to12';
        } else {
          label = `Enfant ${i - adults + 1} — Nationalité`;
          type = 'child';
        }
        node.querySelector('span').textContent = label;
        const select = node.querySelector('[data-nationality-select]');
        select.dataset.type = type;
        list.appendChild(node);
      }
    }

    [adultsInput, childrenInput, childrenUnder3Input, children3to12Input, sameCheckbox].forEach((input) => {
      input?.addEventListener('change', render);
      input?.addEventListener('input', render);
    });
    uniformSelect?.addEventListener('change', () => {});
    render();
  });
}

function collectGuests(form) {
  const wrap = form.querySelector('[data-nationalities]');
  if (!wrap) return [];
  const sameCheckbox = wrap.querySelector('[data-same-nationality]');
  const adults = Number(form.querySelector('[name="adults"]')?.value || 0);
  const children = Number(form.querySelector('[name="children"]')?.value || 0);
  if (sameCheckbox?.checked) {
    const nationality = wrap.querySelector('[data-uniform-nationality]')?.value || '';
    return [
      ...Array.from({ length: adults }, () => ({ type: 'adult', nationality })),
      ...Array.from({ length: children }, () => ({ type: 'child', nationality }))
    ];
  }
  return Array.from(wrap.querySelectorAll('[data-nationality-select]')).map((select) => ({
    type: select.dataset.type || 'adult',
    nationality: select.value || ''
  }));
}

function initTemplateEditor() {
  const mediaTemplates = {
    photo1: { src: '{{photo1_url}}', alt: '{{hebergement}}', label: 'photo1', defaultSize: 320, shape: 'rect' },
    photo2: { src: '{{photo2_url}}', alt: '{{hebergement}}', label: 'photo2', defaultSize: 320, shape: 'rect' },
    photo3: { src: '{{photo3_url}}', alt: '{{hebergement}}', label: 'photo3', defaultSize: 320, shape: 'rect' },
    logo_partenaire: { src: '{{logo_partenaire_url}}', alt: '{{partenaire}}', label: 'logo', defaultSize: 80, shape: 'rect' },
    signature_photo: { src: '{{signature_photo_url}}', alt: '{{signature_nom}}', label: 'photo profil', defaultSize: 64, shape: 'circle' },
    // photo_bien is generated server-side as a full <img> tag (no separate
    // "_url" variable exists for it), so it can only be shown as a preview
    // placeholder — it isn't click-editable like the variables above.
    photo_bien: { src: '{{photo_bien}}', alt: '{{hebergement}}', label: 'photo du bien', defaultSize: 320, shape: 'rect', editable: false }
  };

  // Sample values used only to populate the HTML preview so every plain-text
  // variable shows realistic temporary data instead of the raw {{token}}.
  const sampleTextValues = {
    nom_client: 'Jean Dupont',
    email_client: 'jean.dupont@example.com',
    telephone_client: '+230 5712 3456',
    dates: 'Du 12 juil. 2026 au 19 juil. 2026',
    date_arrivee: '12 juillet 2026',
    date_depart: '19 juillet 2026',
    nuits: '7',
    adultes: '2',
    enfants: '1',
    bebes: '0',
    hebergement: 'Villa Bleu Océan',
    partenaire: 'Grand Baie Escapes',
    notes: 'Merci de prévoir un lit bébé supplémentaire.',
    message: 'Nous avons hâte de vous accueillir !',
    tarif_nuits: '7',
    tarif_hebergement: '1 200,00 €',
    tarif_personnes_supplementaires: '80,00 €',
    tarif_nettoyage: '60,00 €',
    tarif_total: '1 340,00 €',
    taxe_touristique: '14,00 €',
    signature_nom: 'Marie Lemoine',
    email_partenaire: 'contact@grandbaie-escapes.com',
    lien_partenaire: 'https://exemple-partenaire.grand-baie-maurice.com/espace',
    telephone_partenaire: '+230 5698 7412'
  };

  // These tokens are rendered as real <img> elements (or a dedicated block,
  // for tarif_bloc) earlier in decoratePreviewHtml/substituteVariablesInPreview,
  // so the generic text-variable substitution must ignore them.
  const nonTextVariableNames = new Set([
    'photo1', 'photo2', 'photo3', 'logo_partenaire', 'signature_photo', 'photo_bien', 'tarif_bloc'
  ]);

  function buildSampleTarifBlocHtml() {
    return '<div data-template-var="tarif_bloc" contenteditable="false" style="padding:12px 24px 16px;border:1px dashed #93c5fd;border-radius:8px;" title="Bloc généré automatiquement (aperçu avec données temporaires)">'
      + '<p style="margin:0 0 10px;font-weight:bold;font-size:14px;color:#111827;">Résumé Tarifaire :</p>'
      + '<table style="width:100%;border-collapse:collapse;font-size:14px;"><tbody>'
      + '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;color:#374151;">Tarif</td><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">1 200,00 €</td></tr>'
      + '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;color:#374151;">Personne(s) supplémentaire(s)</td><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">80,00 €</td></tr>'
      + '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;color:#374151;">Nettoyage</td><td style="padding:6px 0;border-bottom:1px solid #e5e7eb;text-align:right;color:#374151;">60,00 €</td></tr>'
      + '<tr><td style="padding:8px 0;font-weight:bold;color:#111827;">Total</td><td style="padding:8px 0;font-weight:bold;text-align:right;color:#111827;">1 340,00 €</td></tr>'
      + '</tbody></table>'
      + '<div style="margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:12px 14px;"><table style="width:100%;border-collapse:collapse;"><tbody><tr>'
      + '<td style="width:28px;vertical-align:top;font-size:18px;padding-right:8px;">⚠️</td>'
      + '<td style="font-size:13px;color:#92400e;vertical-align:top;"><strong>Attention</strong><br>Taxe touristique de 14,00 Euros à régler à l\u2019arrivée<br>(Non comprise dans le total)</td>'
      + '</tr></tbody></table></div>'
      + '</div>';
  }

  function placeholderDataUrl(label, width, shape = 'rect') {
    const safeWidth = Math.max(24, Math.min(1200, width || 320));
    const safeHeight = shape === 'circle' ? safeWidth : Math.max(80, Math.round(safeWidth * 0.56));
    const radius = shape === 'circle' ? Math.round(safeWidth / 2) : 16;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${safeWidth}" height="${safeHeight}" viewBox="0 0 ${safeWidth} ${safeHeight}"><rect width="${safeWidth}" height="${safeHeight}" rx="${radius}" fill="#f3f4f6" stroke="#d1d5db"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6b7280" font-family="Arial,Helvetica,sans-serif" font-size="${Math.max(14, Math.round(safeWidth / 12))}">${label}</text></svg>`;
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
  }

  function mediaTemplateBySource(source) {
    return Object.values(mediaTemplates).find((item) => item.src === source) || null;
  }

  function previewImageSource(source, width, shape = 'rect') {
    const template = mediaTemplateBySource(source);
    if (template) return placeholderDataUrl(template.label, width, shape || template.shape);
    return source;
  }

  function sanitizeTemplateImageSource(source) {
    const value = String(source || '').trim();
    if (!value) return '';
    if (mediaTemplateBySource(value)) return value;
    if (/^\/images\/[a-zA-Z0-9/_\-.]+$/.test(value)) return value;
    if (/^https?:\/\/[^\s"'<>]+$/i.test(value)) return value;
    return '';
  }

  function escapeHtmlText(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escapeHtmlAttribute(value) {
    return escapeHtmlText(value)
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function serializeTemplateNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      return escapeHtmlText(node.textContent || '');
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      return '';
    }

    const element = node;

    // The floating format toolbar and style tags are internal editor UI —
    // never persist them into the saved template HTML.
    if (element.hasAttribute('data-template-toolbar') || element.id === 'template-editor-hint-style') {
      return '';
    }

    // Atomic preview-only placeholders (plain-text variable chips, the
    // tarif_bloc quote block, and non-editable image variables like
    // photo_bien) always serialize back to their original {{variable}}
    // token, regardless of the sample content currently displayed.
    if (element.hasAttribute('data-template-var')) {
      return `{{${element.getAttribute('data-template-var')}}}`;
    }

    const tagName = element.tagName.toLowerCase();
    const isVoidElement = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'].includes(tagName);
    const attributes = [];

    if (tagName === 'img') {
      const safeSource = sanitizeTemplateImageSource(element.getAttribute('data-template-original-src') || element.getAttribute('src') || '');
      if (!safeSource) return '';
      attributes.push(`src="${escapeHtmlAttribute(safeSource)}"`);
    }

    [...element.attributes].forEach((attribute) => {
      if (attribute.name === 'src' || attribute.name.startsWith('data-template-')) return;
      attributes.push(`${attribute.name}="${escapeHtmlAttribute(attribute.value)}"`);
    });

    const openingTag = `<${tagName}${attributes.length ? ` ${attributes.join(' ')}` : ''}>`;
    if (isVoidElement) {
      return openingTag;
    }

    return `${openingTag}${[...element.childNodes].map((childNode) => serializeTemplateNode(childNode)).join('')}</${tagName}>`;
  }

  function normalizeImageWidth(raw, fallback) {
    const width = parseInt(String(raw || fallback || 320), 10);
    if (!Number.isFinite(width) || width <= 0) return fallback || 320;
    return Math.max(24, Math.min(1200, width));
  }

  function detectImagePosition(img) {
    const marginLeft = img.style.marginLeft || '';
    const marginRight = img.style.marginRight || '';
    if (marginLeft === 'auto' && marginRight === 'auto') return 'center';
    if (marginLeft === 'auto') return 'right';
    return 'left';
  }

  function applyImageLayout(img, width, position, shape = 'rect') {
    img.setAttribute('width', String(width));
    img.style.width = `${width}px`;
    img.style.maxWidth = '100%';
    img.style.display = 'block';
    img.style.height = shape === 'circle' ? `${width}px` : 'auto';
    img.style.marginTop = '0';
    img.style.marginBottom = '0';
    img.style.marginLeft = position === 'center' || position === 'right' ? 'auto' : '0';
    img.style.marginRight = position === 'center' ? 'auto' : (position === 'left' ? 'auto' : '0');
    if (shape === 'circle') {
      img.setAttribute('height', String(width));
      img.style.borderRadius = '50%';
      img.style.objectFit = 'cover';
    }
  }

  function buildImageSnippet(variableName, width) {
    const template = mediaTemplates[variableName];
    if (!template) return `{{${variableName}:${width}}}`;
    const resolvedWidth = normalizeImageWidth(width, template.defaultSize);
    const style = template.shape === 'circle'
      ? `display:block;width:${resolvedWidth}px;max-width:100%;height:${resolvedWidth}px;margin:0 auto;border-radius:50%;object-fit:cover;`
      : `display:block;width:${resolvedWidth}px;max-width:100%;height:auto;margin:0 auto;`;
    const heightAttr = template.shape === 'circle' ? ` height="${resolvedWidth}"` : '';
    return `<img src="${template.src}" alt="${template.alt}" width="${resolvedWidth}"${heightAttr} style="${style}">`;
  }

  function variableChipHtml(name) {
    const sampleValue = Object.prototype.hasOwnProperty.call(sampleTextValues, name) ? sampleTextValues[name] : `« ${name} »`;
    return `<span data-template-var="${name}" contenteditable="false" style="background:#fce7f3;color:#9d174d;border-radius:4px;padding:0 3px;" title="Variable {{${name}}} — donnée temporaire pour l’aperçu">${escapeHtmlText(sampleValue)}</span>`;
  }

  function decoratePreviewHtml(html) {
    let output = html;

    output = output.replace(
      /<img\b([^>]*?)\ssrc=(['"])\{\{(photo[123]_url|logo_partenaire_url|signature_photo_url)\}\}\2([^>]*)>/gi,
      (match, before, quote, tokenName, after) => {
        const source = `{{${tokenName}}}`;
        const template = mediaTemplateBySource(source);
        const widthMatch = match.match(/\bwidth=(['"])?(\d{1,4})\1?/i);
        const width = normalizeImageWidth(widthMatch ? widthMatch[2] : '', template?.defaultSize || 320);
        const shape = template?.shape || 'rect';
        return `<img${before} src="${previewImageSource(source, width, shape)}" data-template-original-src="${source}" data-template-editable="1" data-template-shape="${shape}"${after}>`;
      }
    );

    output = output.replace(/\{\{(photo[123]|logo_partenaire|signature_photo|photo_bien)(?::(\d{1,4}))?\}\}/g, (match, variableName, widthRaw) => {
      const template = mediaTemplates[variableName];
      if (!template) return match;
      const width = normalizeImageWidth(widthRaw, template.defaultSize);
      const heightAttr = template.shape === 'circle' ? ` height="${width}"` : '';
      const style = template.shape === 'circle'
        ? `display:block;width:${width}px;max-width:100%;height:${width}px;margin:0 auto;border-radius:50%;object-fit:cover;`
        : `display:block;width:${width}px;max-width:100%;height:auto;margin:0 auto;`;
      const editableAttrs = template.editable === false
        ? ` data-template-var="${variableName}"`
        : ` data-template-original-src="${template.src}" data-template-editable="1"`;
      return `<img src="${previewImageSource(template.src, width, template.shape)}" alt="${template.alt}" width="${width}"${heightAttr} style="${style}"${editableAttrs} data-template-shape="${template.shape}">`;
    });

    return output;
  }

  function substituteVariablesInPreview(doc) {
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, null);
    const textNodes = [];
    let node;
    while ((node = walker.nextNode())) {
      if (/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/.test(node.nodeValue || '')) {
        textNodes.push(node);
      }
    }

    textNodes.forEach((textNode) => {
      const parent = textNode.parentNode;
      if (!parent || !parent.tagName) return;
      const parentTag = parent.tagName.toLowerCase();
      if (parentTag === 'script' || parentTag === 'style') return;
      if (parent.closest && parent.closest('[data-template-var]')) return;

      const text = textNode.nodeValue || '';
      const regex = /\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g;
      let lastIndex = 0;
      let match;
      let matched = false;
      const fragment = doc.createDocumentFragment();

      while ((match = regex.exec(text)) !== null) {
        const name = match[1].trim();
        // Image tokens are already converted to real <img> elements earlier
        // (in decoratePreviewHtml); leave any leftover occurrence untouched.
        if (nonTextVariableNames.has(name) && name !== 'tarif_bloc') continue;
        matched = true;
        if (match.index > lastIndex) {
          fragment.appendChild(doc.createTextNode(text.slice(lastIndex, match.index)));
        }

        if (name === 'tarif_bloc') {
          const wrapper = doc.createElement('div');
          wrapper.innerHTML = buildSampleTarifBlocHtml();
          fragment.appendChild(wrapper.firstElementChild);
        } else {
          const wrapper = doc.createElement('span');
          wrapper.innerHTML = variableChipHtml(name);
          fragment.appendChild(wrapper.firstElementChild);
        }
        lastIndex = match.index + match[0].length;
      }

      if (!matched) return;
      if (lastIndex < text.length) {
        fragment.appendChild(doc.createTextNode(text.slice(lastIndex)));
      }
      parent.replaceChild(fragment, textNode);
    });
  }

  document.querySelectorAll('[data-template-editor]').forEach((form) => {
    const textarea = form.querySelector('[data-template-body]');
    const preview = form.querySelector('[data-template-preview]');
    let activeEditableEl = null;
    let lastKnownRange = null;

    function renderPreview() {
      if (!textarea || !preview) return;
      preview.srcdoc = decoratePreviewHtml(textarea.value);
    }

    function insertHtmlIntoActiveEditable(html) {
      const doc = preview?.contentDocument;
      if (!doc || !activeEditableEl) return false;
      const selection = doc.getSelection();
      if (lastKnownRange) {
        selection.removeAllRanges();
        selection.addRange(lastKnownRange);
      }
      if (!selection.rangeCount || !activeEditableEl.contains(selection.getRangeAt(0).commonAncestorContainer)) {
        const range = doc.createRange();
        range.selectNodeContents(activeEditableEl);
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
      }
      doc.execCommand('insertHTML', false, html);
      lastKnownRange = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
      syncTextareaFromPreview();
      return true;
    }

    function insertAtCursor(text) {
      if (activeEditableEl && insertHtmlIntoActiveEditable(text)) return;
      if (!textarea) return;
      const start = textarea.selectionStart ?? textarea.value.length;
      const end = textarea.selectionEnd ?? start;
      textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
      const pos = start + text.length;
      textarea.setSelectionRange(pos, pos);
      textarea.focus();
      renderPreview();
    }

    function syncTextareaFromPreview() {
      if (!textarea || !preview?.contentDocument?.body) return;
      const bodyClone = preview.contentDocument.body.cloneNode(true);
      textarea.value = [...bodyClone.childNodes].map((childNode) => serializeTemplateNode(childNode)).join('');
    }


    function editPreviewImage(img) {
      const shape = img.getAttribute('data-template-shape') || 'rect';
      const currentSource = img.getAttribute('data-template-original-src') || img.getAttribute('src') || '';
      const source = window.prompt('Source de l’image (URL ou variable {{photo1_url}}, {{logo_partenaire_url}}, ...)', currentSource);
      if (source === null || source.trim() === '') return;
      const safeSource = sanitizeTemplateImageSource(source);
      if (!safeSource) return;

      const currentWidth = normalizeImageWidth(img.getAttribute('width') || img.style.width, 320);
      const widthAnswer = window.prompt('Largeur de l’image en pixels', String(currentWidth));
      if (widthAnswer === null) return;
      const width = normalizeImageWidth(widthAnswer, currentWidth);

      const currentPosition = detectImagePosition(img);
      const positionAnswer = window.prompt('Position de l’image: left, center ou right', currentPosition);
      if (positionAnswer === null) return;
      const position = ['left', 'center', 'right'].includes(positionAnswer.trim().toLowerCase()) ? positionAnswer.trim().toLowerCase() : currentPosition;

      img.setAttribute('data-template-original-src', safeSource);
      img.setAttribute('src', previewImageSource(safeSource, width, shape));
      applyImageLayout(img, width, position, shape);
      syncTextareaFromPreview();
      renderPreview();
    }

    const NEVER_EDITABLE_TAGS = new Set([
      'IMG', 'SCRIPT', 'STYLE', 'BR', 'HR', 'INPUT', 'TEXTAREA', 'SELECT', 'IFRAME', 'NOSCRIPT',
      'TABLE', 'TBODY', 'THEAD', 'TFOOT', 'TR', 'UL', 'OL', 'FORM', 'HTML', 'HEAD', 'BODY'
    ]);

    function isEditableTextContainer(el) {
      if (!el || !el.tagName) return false;
      // Variable chips / computed blocks (tarif_bloc, photo_bien, ...) stay
      // atomic — their content must never be typed into directly.
      if (el.hasAttribute('data-template-var')) return false;
      if (el.closest && el.closest('[data-template-var]')) return false;
      if (NEVER_EDITABLE_TAGS.has(el.tagName.toUpperCase())) return false;
      // Skip layout containers: only the innermost element holding the
      // actual text (a table cell, paragraph, link, span, heading, ...)
      // should become directly editable.
      if (el.querySelector('table, tr, td, th, tbody, thead, tfoot, ul, ol, form')) return false;
      return (el.textContent || '').trim() !== '';
    }

    function finishTextEdit(el) {
      el.removeAttribute('contenteditable');
      el.style.outline = '';
      if (activeEditableEl === el) {
        activeEditableEl = null;
        lastKnownRange = null;
      }
      hideFormatToolbar();
      syncTextareaFromPreview();
      renderPreview();
    }

    let formatToolbar = null;
    let toolbarInteracting = false;

    function hideFormatToolbar() {
      if (formatToolbar) formatToolbar.style.display = 'none';
    }

    function ensureFormatToolbar(doc) {
      let toolbar = doc.getElementById('template-format-toolbar');
      if (toolbar) return toolbar;
      toolbar = doc.createElement('div');
      toolbar.id = 'template-format-toolbar';
      toolbar.setAttribute('data-template-toolbar', '1');
      toolbar.setAttribute('contenteditable', 'false');
      toolbar.style.cssText = 'display:none;position:fixed;z-index:99999;background:#1f2937;color:#fff;border-radius:6px;padding:4px;box-shadow:0 4px 14px rgba(0,0,0,.25);gap:4px;align-items:center;font:13px/1.4 system-ui,sans-serif;';
      toolbar.style.display = 'none';
      toolbar.innerHTML = `
        <button type="button" data-cmd="bold" title="Gras" style="font-weight:bold;background:none;border:none;color:#fff;padding:4px 8px;cursor:pointer;border-radius:4px;">G</button>
        <button type="button" data-cmd="italic" title="Italique" style="font-style:italic;background:none;border:none;color:#fff;padding:4px 8px;cursor:pointer;border-radius:4px;">I</button>
        <button type="button" data-cmd="underline" title="Souligné" style="text-decoration:underline;background:none;border:none;color:#fff;padding:4px 8px;cursor:pointer;border-radius:4px;">S</button>
        <select data-cmd="fontName" title="Police" style="background:#374151;color:#fff;border:none;border-radius:4px;padding:3px 4px;">
          <option value="">Police…</option>
          <option value="Arial, Helvetica, sans-serif">Arial</option>
          <option value="Georgia, serif">Georgia</option>
          <option value="'Times New Roman', serif">Times New Roman</option>
          <option value="Verdana, sans-serif">Verdana</option>
          <option value="'Courier New', monospace">Courier New</option>
          <option value="'Trebuchet MS', sans-serif">Trebuchet MS</option>
        </select>`;
      toolbar.addEventListener('mousedown', (event) => {
        if (event.target.tagName === 'SELECT') {
          toolbarInteracting = true;
          return;
        }
        event.preventDefault();
      });
      toolbar.querySelectorAll('button[data-cmd]').forEach((button) => {
        button.addEventListener('click', () => {
          doc.execCommand(button.dataset.cmd, false, null);
          syncTextareaFromPreview();
        });
      });
      const fontSelect = toolbar.querySelector('select[data-cmd]');
      fontSelect?.addEventListener('change', () => {
        const value = fontSelect.value;
        fontSelect.value = '';
        if (!value || !activeEditableEl) {
          toolbarInteracting = false;
          return;
        }
        const selection = doc.getSelection();
        if (lastKnownRange) {
          selection.removeAllRanges();
          selection.addRange(lastKnownRange);
        }
        doc.execCommand('styleWithCSS', false, true);
        doc.execCommand('fontName', false, value);
        doc.execCommand('styleWithCSS', false, false);
        activeEditableEl.focus();
        toolbarInteracting = false;
        syncTextareaFromPreview();
      });
      fontSelect?.addEventListener('blur', () => {
        setTimeout(() => {
          if (activeEditableEl && doc.activeElement !== activeEditableEl && doc.activeElement !== fontSelect) {
            toolbarInteracting = false;
            finishTextEdit(activeEditableEl);
          }
        }, 0);
      });
      doc.body.appendChild(toolbar);
      formatToolbar = toolbar;

      doc.addEventListener('selectionchange', () => {
        if (!activeEditableEl) {
          hideFormatToolbar();
          return;
        }
        const selection = doc.getSelection();
        if (!selection || !selection.rangeCount || selection.isCollapsed) {
          hideFormatToolbar();
          return;
        }
        const range = selection.getRangeAt(0);
        if (!activeEditableEl.contains(range.commonAncestorContainer)) {
          hideFormatToolbar();
          return;
        }
        lastKnownRange = range.cloneRange();
        const rect = range.getBoundingClientRect();
        if (!rect || (rect.width === 0 && rect.height === 0)) {
          hideFormatToolbar();
          return;
        }
        toolbar.style.display = 'flex';
        const toolbarRect = toolbar.getBoundingClientRect();
        let top = rect.top - toolbarRect.height - 8;
        if (top < 4) top = rect.bottom + 8;
        let left = rect.left + rect.width / 2 - toolbarRect.width / 2;
        left = Math.max(4, Math.min(left, doc.defaultView.innerWidth - toolbarRect.width - 4));
        toolbar.style.top = `${top}px`;
        toolbar.style.left = `${left}px`;
      });

      return toolbar;
    }

    let variableModal = null;
    let pendingVariableRange = null;
    let modalInteracting = false;

    function closeVariableModal() {
      if (variableModal) variableModal.style.display = 'none';
      pendingVariableRange = null;
      modalInteracting = false;
      if (activeEditableEl) activeEditableEl.focus();
    }

    function ensureVariableModal() {
      if (variableModal) return variableModal;
      const overlay = document.createElement('div');
      overlay.className = 'template-var-modal-overlay';
      overlay.innerHTML = `
        <div class="template-var-modal" role="dialog" aria-modal="true" aria-label="Insérer une variable">
          <div class="template-var-modal-header">
            <span>Choisir une variable à insérer</span>
            <button type="button" class="template-var-modal-close" aria-label="Fermer">✕</button>
          </div>
          <div class="template-var-modal-body"></div>
        </div>`;
      document.body.appendChild(overlay);
      overlay.addEventListener('mousedown', (event) => {
        if (event.target === overlay) closeVariableModal();
      });
      overlay.querySelector('.template-var-modal-close').addEventListener('click', closeVariableModal);
      variableModal = overlay;
      return overlay;
    }

    function chooseVariableFromModal(rawToken, resizable, defaultSize) {
      const range = pendingVariableRange;
      closeVariableModal();
      if (!range || !activeEditableEl) return;
      if (resizable) {
        const answer = window.prompt('Largeur de l’image en pixels', defaultSize || '320');
        if (answer === null) return;
        const width = parseInt(answer, 10);
        if (!Number.isFinite(width) || width <= 0) return;
        lastKnownRange = range;
        insertAtCursor(buildImageSnippet(rawToken, width));
        return;
      }
      const nameMatch = (rawToken || '').match(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/);
      if (!nameMatch) return;
      lastKnownRange = range;
      insertAtCursor(variableChipHtml(nameMatch[1]));
    }

    function openVariableModalForInsertion(range) {
      const modal = ensureVariableModal();
      pendingVariableRange = range;
      modalInteracting = true;
      const body = modal.querySelector('.template-var-modal-body');
      body.innerHTML = '';
      const sourceMenu = form.querySelector('.insert-var-menu');
      if (sourceMenu) {
        [...sourceMenu.children].forEach((node) => {
          if (node.tagName === 'BUTTON') {
            const clone = node.cloneNode(true);
            clone.addEventListener('click', () => {
              chooseVariableFromModal(clone.dataset.insertVariable || '', clone.dataset.variableResizable === '1', clone.dataset.variableDefaultSize || '320');
            });
            body.appendChild(clone);
          } else {
            body.appendChild(node.cloneNode(true));
          }
        });
      }
      modal.style.display = 'flex';
    }

    function editPreviewText(el) {
      el.setAttribute('contenteditable', 'true');
      el.style.outline = '2px solid #ec4899';
      el.focus();
      activeEditableEl = el;
      lastKnownRange = null;
      ensureFormatToolbar(preview.contentDocument);
      const range = document.createRange();
      range.selectNodeContents(el);
      const selection = preview.contentWindow?.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);

      // Typing "{}" (e.g. replacing a selection with the shorthand) opens a
      // modal to pick a variable to insert in place of the two characters.
      const onInput = () => {
        const doc = preview.contentDocument;
        const sel = preview.contentWindow?.getSelection();
        if (!doc || !sel || !sel.rangeCount) return;
        const caretRange = sel.getRangeAt(0);
        if (!caretRange.collapsed) return;
        const node = caretRange.startContainer;
        if (node.nodeType !== Node.TEXT_NODE) return;
        const offset = caretRange.startOffset;
        const textBefore = node.textContent.slice(0, offset);
        if (!textBefore.endsWith('{}')) return;
        node.textContent = textBefore.slice(0, -2) + node.textContent.slice(offset);
        const insertRange = doc.createRange();
        insertRange.setStart(node, offset - 2);
        insertRange.collapse(true);
        sel.removeAllRanges();
        sel.addRange(insertRange);
        openVariableModalForInsertion(insertRange.cloneRange());
      };

      const onBlur = () => {
        if (toolbarInteracting || modalInteracting) return;
        el.removeEventListener('blur', onBlur);
        el.removeEventListener('keydown', onKeydown);
        el.removeEventListener('input', onInput);
        finishTextEdit(el);
      };
      const onKeydown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          el.blur();
        } else if (event.key === 'Escape') {
          event.preventDefault();
          el.blur();
        }
      };
      el.addEventListener('blur', onBlur);
      el.addEventListener('keydown', onKeydown);
      el.addEventListener('input', onInput);
    }

    function wireEditablePreview() {
      const doc = preview.contentDocument;
      if (!doc) return;

      substituteVariablesInPreview(doc);

      if (!doc.getElementById('template-editor-hint-style')) {
        const style = doc.createElement('style');
        style.id = 'template-editor-hint-style';
        style.textContent = '[data-template-hoverable]{cursor:pointer}[data-template-hoverable]:hover{outline:1px dashed #ec4899;outline-offset:1px}[data-template-var]{cursor:default}';
        doc.head?.appendChild(style);
      }

      doc.querySelectorAll('img').forEach((img) => {
        // Atomic image variables (e.g. photo_bien) are server-computed and
        // shown for preview only — they aren't click-editable.
        if (img.hasAttribute('data-template-var')) return;
        img.setAttribute('data-template-hoverable', '1');
        img.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          editPreviewImage(img);
        });
      });

      doc.querySelectorAll('body *').forEach((el) => {
        if (!isEditableTextContainer(el)) return;
        el.setAttribute('data-template-hoverable', '1');
        el.addEventListener('click', (event) => {
          if (el.isContentEditable) return;
          event.preventDefault();
          event.stopPropagation();
          editPreviewText(el);
        });
      });
    }

    preview?.addEventListener('load', wireEditablePreview);

    form.querySelectorAll('[data-insert-variable]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.dataset.variableResizable === '1') {
          const variableName = button.dataset.insertVariable || '';
          const defaultSize = button.dataset.variableDefaultSize || '320';
          const answer = window.prompt('Largeur de l’image en pixels', defaultSize);
          if (answer === null) return;
          const width = parseInt(answer, 10);
          if (!Number.isFinite(width) || width <= 0) return;
          insertAtCursor(buildImageSnippet(variableName, width));
          return;
        }
        const rawToken = button.dataset.insertVariable || '';
        if (activeEditableEl) {
          const nameMatch = rawToken.match(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/);
          if (nameMatch) {
            insertAtCursor(variableChipHtml(nameMatch[1]));
            return;
          }
        }
        insertAtCursor(rawToken);
      });
    });

    form.querySelectorAll('[data-insert-html]').forEach((button) => {
      button.addEventListener('click', () => {
        insertAtCursor(button.dataset.insertHtml || '');
      });
    });

    // Dropdown toggle
    const dropdownToggle = form.querySelector('[data-insert-dropdown-toggle]');
    const dropdownMenu = form.querySelector('.insert-var-menu');
    if (dropdownToggle && dropdownMenu) {
      dropdownToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdownMenu.hidden = !dropdownMenu.hidden;
      });
      document.addEventListener('click', () => { dropdownMenu.hidden = true; });
      dropdownMenu.addEventListener('click', (e) => { e.stopPropagation(); });
    }

    textarea?.addEventListener('input', () => {
      renderPreview();
    });

    renderPreview();
  });
}

function initColorSync() {
  document.querySelectorAll('[data-sync-color]').forEach((textInput) => {
    const colorInput = textInput.parentElement?.querySelector('input[type="color"]');
    colorInput?.addEventListener('input', () => { textInput.value = colorInput.value; });
    textInput.addEventListener('input', () => {
      if (/^#[0-9a-fA-F]{6}$/.test(textInput.value) && colorInput) colorInput.value = textInput.value;
    });
  });
}

/**
 * Wires an "Arrivée"/"Départ" date pair so they behave like a single range
 * picker: choosing the arrival date automatically opens the departure date
 * picker and prevents picking a departure date on or before the arrival.
 */
function initDateRanges() {
  document.querySelectorAll('[data-date-range]').forEach((wrap) => {
    const checkin = wrap.querySelector('input[name="checkin"], input[name="checkin_date"], input[name="date_from"]');
    const checkout = wrap.querySelector('input[name="checkout"], input[name="checkout_date"], input[name="date_to"]');
    if (!checkin || !checkout) return;

    function addDays(dateStr, days) {
      // UTC arithmetic to stay timezone-independent (see addDaysStr in
      // initBookingCalendarSelection): local-time + toISOString() shifts the
      // result by a day in timezones ahead of UTC.
      const [y, m, d] = dateStr.split('-').map(Number);
      const date = new Date(Date.UTC(y, m - 1, d));
      date.setUTCDate(date.getUTCDate() + days);
      return date.toISOString().slice(0, 10);
    }

    function syncCheckoutMin() {
      if (!checkin.value) return;
      const minCheckout = addDays(checkin.value, 1);
      checkout.min = minCheckout;
      if (checkout.value && checkout.value <= checkin.value) {
        checkout.value = minCheckout;
      }
    }

    checkin.addEventListener('change', () => {
      syncCheckoutMin();
      if (typeof checkout.showPicker === 'function') {
        try { checkout.showPicker(); } catch (e) { checkout.focus(); }
      } else {
        checkout.focus();
      }
    });

    checkout.addEventListener('change', () => {
      const adults = wrap.querySelector('input[name="adults"]');
      if (adults) adults.focus();
    });

    syncCheckoutMin();
  });
}

/**
 * Combines the dial code select + local number input of [data-phone-input]
 * into a single hidden "client_phone" field (e.g. "+230 5xxx xxxx"),
 * submitted with the booking/contact form.
 */
function initPhoneInputs() {
  document.querySelectorAll('[data-phone-input]').forEach((wrap) => {
    const dialCode = wrap.querySelector('[data-phone-dial-code]');
    const number = wrap.querySelector('[data-phone-number]');
    const combined = wrap.querySelector('[data-phone-combined]');
    if (!dialCode || !number || !combined) return;
    function normalizedCode() {
      const raw = dialCode.value.trim();
      if (!raw) return '';
      return raw.startsWith('+') ? raw : `+${raw.replace(/\D/g, '')}`;
    }
    function update() {
      const code = normalizedCode();
      const value = number.value.trim();
      combined.value = code && value ? `${code} ${value}` : '';
      combined.dispatchEvent(new Event('change', { bubbles: true }));
    }
    dialCode.addEventListener('change', update);
    dialCode.addEventListener('input', update);
    number.addEventListener('input', update);
    wrap.closest('form')?.addEventListener('reset', () => setTimeout(update, 0));
    update();
  });
}

/**
 * Fetches a live price estimate (room total, cleaning fee folded into the
 * room line, tourist tax shown as a separate note) once the booking form
 * has enough information (no page reload, no visible loading indicator
 * while the request is in flight). Also reveals the summary block (Bloc 3)
 * only once the required fields are filled in.
 */
function initBookingQuote() {
  document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const box = form.querySelector('[data-quote-box]');
    const result = form.querySelector('[data-quote-result]');
    const summaryBlock = form.querySelector('[data-booking-block="summary"]');
    if (!box || !result) return;

    let requestId = 0;
    let debounceTimer = null;

    function isReady() {
      const checkin = form.querySelector('[data-booking-checkin]')?.value;
      const checkout = form.querySelector('[data-booking-checkout]')?.value;
      const adults = Number(form.querySelector('[name="adults"]')?.value || 0);
      const clientName = form.querySelector('[name="client_name"]')?.value.trim();
      const clientEmail = form.querySelector('[name="client_email"]')?.value.trim();
      const clientPhone = form.querySelector('[name="client_phone"]')?.value.trim();
      return Boolean(checkin && checkout && adults >= 1 && clientName && clientEmail && clientPhone);
    }

    function updateSummaryVisibility() {
      if (summaryBlock) summaryBlock.hidden = !isReady();
    }

    async function fetchQuote() {
      updateSummaryVisibility();
      if (!isReady()) {
        box.hidden = true;
        return;
      }
      const currentRequest = ++requestId;
      try {
        const payload = buildFormPayload(form);
        const response = await fetch('/api/reservations/quote', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(payload),
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (currentRequest !== requestId) return;
        if (!response.ok) throw new Error(data.message || 'Erreur');
        box.hidden = false;
        renderQuote(data.data, form.dataset.currency || data.data.currency || 'EUR');
      } catch (error) {
        if (currentRequest !== requestId) return;
        box.hidden = true;
      }
    }

    function renderQuote(quote, currency) {
      const formatMoney = (amount) => `${Number(amount).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
      const setQuoteField = (name, value) => {
        const field = form.querySelector(`[name="${name}"]`);
        if (field) field.value = String(value ?? '');
      };
      form.querySelector('[data-quote-nights]').textContent = quote.nights;
      form.querySelector('[data-quote-room]').textContent = formatMoney(quote.room_total);
      const extraLine = form.querySelector('[data-quote-extra-line]');
      const extraEl = form.querySelector('[data-quote-extra]');
      const extraApplies = Number(quote.extra_person_total) > 0;
      if (extraLine) extraLine.hidden = !extraApplies;
      if (extraEl) extraEl.textContent = formatMoney(quote.extra_person_total || 0);
      const cleaningEl = form.querySelector('[data-quote-cleaning]');
      if (cleaningEl) cleaningEl.textContent = formatMoney(quote.cleaning_total);
      form.querySelector('[data-quote-total]').textContent = formatMoney(quote.total_without_tax);
      const recap = form.querySelector('[data-quote-recap]');
      if (recap) {
        const adults = Number(form.querySelector('[name="adults"]')?.value || 0);
        const under3 = Number(form.querySelector('[name="children_under3"]')?.value || 0);
        const from3to12 = Number(form.querySelector('[name="children_3to12"]')?.value || 0);
        const parts = [`${adults} Adulte(s)`];
        if (under3 > 0) parts.push(`${under3} Enfant(s) -3 ans`);
        if (from3to12 > 0) parts.push(`${from3to12} Enfant(s) 3-12 ans`);
        recap.textContent = parts.join(' · ');
      }
      const taxLine = form.querySelector('[data-quote-tax-line]');
      const taxApplies = Number(quote.tourist_tax_total) > 0;
      taxLine.hidden = !taxApplies;
      if (taxApplies) {
        const taxAmount = form.querySelector('[data-quote-tax-amount]');
        if (taxAmount) {
          taxAmount.textContent = Number(quote.tourist_tax_total).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
      }
      setQuoteField('quote_currency', currency);
      setQuoteField('quote_nights', Number(quote.nights || 0));
      setQuoteField('quote_room_total', Number(quote.room_total || 0));
      setQuoteField('quote_extra_person_total', Number(quote.extra_person_total || 0));
      setQuoteField('quote_cleaning_total', Number(quote.cleaning_total || 0));
      setQuoteField('quote_total_without_tax', Number(quote.total_without_tax || 0));
      setQuoteField('quote_tourist_tax_total', Number(quote.tourist_tax_total || 0));
      result.hidden = false;
    }

    function scheduleQuote() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(fetchQuote, 400);
    }

    form.addEventListener('booking-dates-changed', scheduleQuote);
    form.addEventListener('input', () => { updateSummaryVisibility(); scheduleQuote(); });
    form.addEventListener('change', () => { updateSummaryVisibility(); scheduleQuote(); });
    form.addEventListener('reset', () => { box.hidden = true; updateSummaryVisibility(); });
    updateSummaryVisibility();
  });
}

/**
 * Lets a visitor reserve several properties in a few clicks from the
 * "Calendrier" board (/calendrier): clicking an available date on a property
 * row sets its arrival date, and a second click on the same row sets the
 * departure date (reusing the same arrival/departure rules as the property
 * detail calendar). Once a valid range is picked, that property/date-range
 * pair is added to a selection "cart"; the visitor can then click on another
 * property row (same dates or different dates) to add it too, building a
 * multi-property booking in a single request. Rows whose maximum occupancy
 * is insufficient for the party size entered above the table stay fully
 * selectable here (only the sum of every distinct selected property's
 * capacity is checked, cumulatively, both client-side and server-side), so
 * several under-capacity properties can be combined to reach the party size.
 */
function initMultiPropertyCart() {
  const board = document.querySelector('[data-multi-calendar-board]');
  const cartRoot = document.querySelector('[data-multi-cart]');
  if (!board || !cartRoot) return;

  const listEl = cartRoot.querySelector('[data-multi-cart-list]');
  const gapHintEl = cartRoot.querySelector('[data-multi-cart-gap-hint]');
  const babyNoteEl = cartRoot.querySelector('[data-multi-cart-baby-note]');
  const feedbackEl = cartRoot.querySelector('[data-multi-cart-feedback]');
  const checkoutForm = cartRoot.querySelector('[data-multi-cart-form]');
  const itemsInput = checkoutForm ? checkoutForm.querySelector('[data-multi-cart-items]') : null;
  const summaryEl = cartRoot.querySelector('[data-multi-cart-summary]');
  const summaryLineEl = cartRoot.querySelector('[data-multi-cart-summary-line]');
  const capacityTableEl = cartRoot.querySelector('[data-multi-cart-capacity-table]');
  const capacityHintEl = cartRoot.querySelector('[data-multi-cart-capacity-hint]');
  const summaryTotalEl = cartRoot.querySelector('[data-multi-cart-summary-total]');
  const clearBtn = cartRoot.querySelector('[data-multi-cart-clear]');
  const viewBtn = document.querySelector('[data-multi-cart-view-btn]');
  const submitBtn = checkoutForm ? checkoutForm.querySelector('[type="submit"]') : null;
  if (!listEl || !checkoutForm || !itemsInput) return;

  if (viewBtn) {
    viewBtn.addEventListener('click', () => {
      cartRoot.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // The requested party size must stay live: a visitor can change the guest
  // count fields above the table after already clicking "Afficher les
  // disponibilités" (without submitting again), and "Votre sélection" has to
  // reflect that immediately — both the displayed target and the capacity
  // warning — instead of staying frozen at the value from the last page
  // load (board.dataset.totalGuests).
  const filterForm = document.querySelector('[data-calendar-filter-form]');
  const guestInputs = filterForm
    ? Array.from(filterForm.querySelectorAll('[data-guest-slide-input], [data-calendar-guest-input]'))
    : [];

  // Only adults and children 3-12 count toward property capacity; babies don't.
  function getRequestedGuests() {
    if (guestInputs.length) {
      return guestInputs
        .filter((input) => input.name !== 'children_under3')
        .reduce((sum, input) => sum + (parseInt(input.value || '0', 10) || 0), 0);
    }
    return parseInt(board.dataset.totalGuests || '0', 10) || 0;
  }

  // Returns the number of babies (children under 3) in the current search.
  function getBabies() {
    const babiesInput = filterForm ? filterForm.querySelector('input[name="children_under3"]') : null;
    if (babiesInput) return parseInt(babiesInput.value || '0', 10) || 0;
    return parseInt(board.dataset.babies || '0', 10) || 0;
  }

  let requestedGuests = getRequestedGuests();

  const cart = [];
  const rowUpdaters = [];
  const rowResetters = [];

  function refreshAllRowHighlights() {
    rowUpdaters.forEach((updateRowSelection) => updateRowSelection());
  }

  function formatFr(dateStr) {
    const [y, m, d] = dateStr.split('-');
    return `${d}/${m}/${y}`;
  }

  function nightsBetween(startStr, endStr) {
    const start = new Date(`${startStr}T00:00:00`);
    const end = new Date(`${endStr}T00:00:00`);
    return Math.round((end - start) / 86400000);
  }

  function addDaysStr(dateStr, days) {
    const [y, m, d] = dateStr.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  }

  function formatEuros(amount) {
    return amount.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  const MONTHS_FR = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
  function formatFrLong(dateStr) {
    const [y, m, d] = dateStr.split('-').map(Number);
    return `${d} ${MONTHS_FR[m - 1]} ${y}`;
  }

  // If the visitor switches property mid-cart (e.g. clicks a departure date
  // on property A, then an arrival date on property B that isn't the same
  // day as A's departure), the night(s) between the two selections are left
  // unbooked — a silent gap the visitor likely didn't intend. Detect it here
  // and surface a warning in "Votre sélection" without altering any existing
  // selection behaviour.
  function computeGapWarning() {
    if (cart.length < 2) return '';
    const sorted = cart.slice().sort((a, b) => (a.checkin < b.checkin ? -1 : a.checkin > b.checkin ? 1 : 0));
    const gaps = [];
    for (let i = 0; i < sorted.length - 1; i++) {
      const current = sorted[i];
      const next = sorted[i + 1];
      if (next.checkin > current.checkout) {
        gaps.push(`la nuit du ${formatFr(current.checkout)} n'est pas réservée entre « ${current.propertyName} » (départ le ${formatFr(current.checkout)}) et « ${next.propertyName} » (arrivée le ${formatFr(next.checkin)})`);
      }
    }
    if (!gaps.length) return '';
    return `Attention : ${gaps.join(' ; ')}. Vérifiez vos dates pour éviter un trou dans votre séjour.`;
  }

  // Builds a night-by-night capacity breakdown across the whole selection
  // span (earliest arrival to latest departure): for every night, each cart
  // item contributes its property's max capacity when that night falls in
  // its own [checkin, checkout) range, 0 otherwise. This is what decides
  // both the day-by-day "Ok / Not Ok" table and each mini-block's color.
  function computeDailyCapacity() {
    if (cart.length === 0) return [];
    let minDate = cart[0].checkin;
    let maxDate = cart[0].checkout;
    const babies = getBabies();
    cart.forEach((item) => {
      if (item.checkin < minDate) minDate = item.checkin;
      if (item.checkout > maxDate) maxDate = item.checkout;
    });
    const days = [];
    let cursor = minDate;
    while (cursor < maxDate) {
      const terms = cart.map((item) => (cursor >= item.checkin && cursor < item.checkout ? item.maxGuests : 0));
      const total = terms.reduce((sum, n) => sum + n, 0);
      const propertiesOnDay = terms.filter((t) => t > 0).length;
      const adultOk = requestedGuests <= 0 || total >= requestedGuests;
      const babyCapacityOnDay = propertiesOnDay * 2;
      const babyOk = babies <= 0 || babies <= babyCapacityOnDay;
      const ok = adultOk && babyOk;
      days.push({ date: cursor, terms, total, ok, adultOk, babyOk, babyCapacityOnDay });
      cursor = addDaysStr(cursor, 1);
    }
    return days;
  }

  function isItemOk(item, dailyCapacity) {
    return dailyCapacity.every((day) => (day.date < item.checkin || day.date >= item.checkout ? true : day.ok));
  }

  function renderCart() {
    listEl.innerHTML = '';
    if (cart.length === 0) {
      cartRoot.hidden = true;
      if (viewBtn) viewBtn.hidden = true;
      checkoutForm.hidden = true;
      if (summaryEl) summaryEl.hidden = true;
      if (gapHintEl) gapHintEl.textContent = '';
      if (babyNoteEl) { babyNoteEl.textContent = ''; babyNoteEl.hidden = true; }
      if (capacityTableEl) capacityTableEl.innerHTML = '';
      itemsInput.value = '';
      return;
    }
    cartRoot.hidden = false;
    if (viewBtn) viewBtn.hidden = false;
    checkoutForm.hidden = false;
    if (summaryEl) summaryEl.hidden = false;

    const dailyCapacity = computeDailyCapacity();

    let totalNights = 0;
    let totalAmount = 0;
    const distinctPropertyIds = new Set();
    const nightsPerItem = new Set();

    cart.forEach((item, index) => {
      const nights = nightsBetween(item.checkin, item.checkout);
      totalNights += nights;
      totalAmount += item.roomTotal;
      distinctPropertyIds.add(item.propertyId);
      nightsPerItem.add(nights);

      const itemOk = isItemOk(item, dailyCapacity);

      const li = document.createElement('li');
      li.className = `multi-cart-item ${itemOk ? 'cap-ok' : 'cap-warn'}`;

      const thumb = document.createElement('img');
      thumb.className = 'multi-cart-item-thumb';
      thumb.src = item.propertyPhoto;
      thumb.alt = item.propertyName;
      li.appendChild(thumb);

      const info = document.createElement('span');
      info.className = 'multi-cart-item-info';
      const nameEl = document.createElement('span');
      nameEl.className = 'multi-cart-item-name';
      nameEl.textContent = item.propertyName;
      const datesEl = document.createElement('span');
      datesEl.className = 'multi-cart-item-dates';
      datesEl.textContent = `${formatFr(item.checkin)} -> ${formatFr(item.checkout)}`;
      info.appendChild(nameEl);
      info.appendChild(datesEl);
      li.appendChild(info);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'multi-cart-remove';
      removeBtn.setAttribute('aria-label', `Retirer ${item.propertyName}`);
      removeBtn.textContent = '×';
      removeBtn.addEventListener('click', () => {
        cart.splice(index, 1);
        renderCart();
        refreshAllRowHighlights();
      });
      li.appendChild(removeBtn);

      listEl.appendChild(li);
    });

    const overallOk = dailyCapacity.every((day) => day.ok);
    const propertyCount = distinctPropertyIds.size;
    const babies = getBabies();

    if (summaryLineEl) {
      if (nightsPerItem.size <= 1) {
        const nightsPerSelection = nightsPerItem.size === 1 ? [...nightsPerItem][0] : 0;
        summaryLineEl.textContent = `${propertyCount} bien(s) sélectionné(s) x ${nightsPerSelection} nuit(s) sélectionnée(s) = ${totalNights} nuit(s) sélectionnée(s)`;
      } else {
        summaryLineEl.textContent = `${propertyCount} bien(s) sélectionné(s) — ${totalNights} nuit(s) sélectionnée(s) au total`;
      }
    }
    if (summaryTotalEl) summaryTotalEl.textContent = formatEuros(totalAmount);
    if (capacityHintEl) {
      if (!overallOk) {
        if (babies > 0) {
          capacityHintEl.textContent = `Capacité insuffisante pour ${requestedGuests} Personnes >3ans + ${babies} bébé${babies > 1 ? 's' : ''} sur une ou plusieurs dates : sélectionnez un ou plusieurs biens supplémentaires.`;
        } else {
          capacityHintEl.textContent = `Capacité insuffisante pour ${requestedGuests} Personnes >3ans sur une ou plusieurs dates : sélectionnez un ou plusieurs biens supplémentaires.`;
        }
      } else {
        capacityHintEl.textContent = '';
      }
    }
    if (babyNoteEl) {
      babyNoteEl.textContent = '';
      babyNoteEl.hidden = true;
    }
    if (submitBtn) submitBtn.disabled = !overallOk;
    if (capacityTableEl) {
      capacityTableEl.innerHTML = '';
      dailyCapacity.forEach((day) => {
        const li = document.createElement('li');
        li.className = `multi-cart-capacity-row ${day.ok ? 'cap-ok' : 'cap-warn'}`;

        const icon = document.createElement('span');
        icon.className = 'multi-cart-capacity-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = day.ok ? '✔' : '⚠';
        li.appendChild(icon);

        const text = document.createElement('span');
        text.appendChild(document.createTextNode(`${formatFrLong(day.date)} : `));

        const adultsStatus = document.createElement('span');
        adultsStatus.className = `multi-cart-capacity-status ${day.adultOk ? 'cap-ok' : 'cap-warn'}`;
        adultsStatus.textContent = `Personnes >3ans : ${requestedGuests} (${day.adultOk ? 'Vert' : 'Rouge'})`;
        text.appendChild(adultsStatus);

        if (babies > 0) {
          text.appendChild(document.createTextNode(' | '));
          const babyStatus = document.createElement('span');
          babyStatus.className = `multi-cart-capacity-status ${day.babyOk ? 'cap-ok' : 'cap-warn'}`;
          babyStatus.textContent = `Bébé(s) : ${babies}/${day.babyCapacityOnDay} (${day.babyOk ? 'Vert' : 'Rouge'})`;
          text.appendChild(babyStatus);
        }

        if (!day.ok) {
          text.appendChild(document.createTextNode(' | '));
          const solution = document.createElement('span');
          solution.className = 'multi-cart-capacity-status cap-warn';
          solution.textContent = `Solution : ${!day.adultOk ? 'Rajouter un ou plusieurs biens' : 'Rajouter un bien'}`;
          text.appendChild(solution);
        }
        li.appendChild(text);

        capacityTableEl.appendChild(li);
      });
    }
    if (gapHintEl) gapHintEl.textContent = computeGapWarning();

    itemsInput.value = JSON.stringify(cart.map((item) => ({
      property_id: item.propertyId,
      property_name: item.propertyName,
      checkin_date: item.checkin,
      checkout_date: item.checkout,
    })));
    if (feedbackEl) feedbackEl.textContent = '';
  }

  board.querySelectorAll('[data-property-row]').forEach((row) => {
    // Every row stays selectable, even if its own capacity is below the
    // requested party size: several properties can be combined to reach the
    // desired total capacity (checked cumulatively above and on the server).
    const propertyId = row.dataset.propertyId || '';
    const propertyName = row.dataset.propertyName || '';
    const propertyPhoto = row.dataset.propertyPhoto || '';
    const maxGuests = parseInt(row.dataset.maxGuests || '0', 10) || 0;

    const nightInfo = new Map();
    row.querySelectorAll('[data-calendar-date]').forEach((cell) => {
      const date = cell.dataset.calendarDate;
      if (!date) return;
      nightInfo.set(date, {
        available: cell.dataset.calendarAvailable === '1',
        minStay: Math.max(1, parseInt(cell.dataset.calendarMinstay || '1', 10) || 1),
        price: parseFloat(cell.dataset.calendarPrice || '0') || 0,
      });
    });

    function isNightAvailable(date) {
      const info = nightInfo.get(date);
      return Boolean(info && info.available);
    }

    function minStayFor(date) {
      const info = nightInfo.get(date);
      return info ? info.minStay : 1;
    }

    function isRangeFullyAvailable(startDate, endDate) {
      let cursor = startDate;
      while (cursor < endDate) {
        if (!isNightAvailable(cursor)) return false;
        cursor = addDaysStr(cursor, 1);
      }
      return true;
    }

    function roomTotalFor(startDate, endDate) {
      let cursor = startDate;
      let total = 0;
      while (cursor < endDate) {
        const info = nightInfo.get(cursor);
        total += info ? info.price : 0;
        cursor = addDaysStr(cursor, 1);
      }
      return total;
    }

    let checkin = null;
    let checkout = null;

    // Once a range has been added to the cart, it must keep showing as
    // selected (red) on the board calendar, exactly like the property detail
    // calendar keeps the chosen dates highlighted: cart items are not reset
    // to the free-to-select state.
    function updateRowSelection() {
      row.querySelectorAll('[data-calendar-date]').forEach((cell) => {
        const date = cell.dataset.calendarDate;
        let selected = date === checkin || date === checkout;
        let inRange = Boolean(checkin && checkout && date > checkin && date < checkout);
        cart.forEach((item) => {
          if (item.propertyId !== propertyId) return;
          if (date === item.checkin || date === item.checkout) {
            selected = true;
          } else if (date > item.checkin && date < item.checkout) {
            inRange = true;
          }
        });
        cell.classList.toggle('selected', selected);
        cell.classList.toggle('in-range', inRange);
      });
    }
    rowUpdaters.push(updateRowSelection);
    rowResetters.push(() => {
      checkin = null;
      checkout = null;
    });

    row.addEventListener('click', (event) => {
      const cell = event.target.closest('[data-calendar-date]');
      if (!cell) return;
      const date = cell.dataset.calendarDate;
      if (!date) return;

      if (!checkin || checkout) {
        if (!isNightAvailable(date)) return;
        checkin = date;
        checkout = null;
      } else if (date === checkin) {
        // Clicking the arrival date again clears the selection instead of
        // silently re-picking the same date as a new arrival.
        checkin = null;
        checkout = null;
      } else if (date <= checkin || !isRangeFullyAvailable(checkin, date)) {
        checkin = isNightAvailable(date) ? date : null;
        checkout = null;
      } else if (nightsBetween(checkin, date) < minStayFor(checkin)) {
        return;
      } else {
        checkout = date;
      }
      updateRowSelection();

      if (checkin && checkout) {
        // Replace any existing selection(s) for this same property whose
        // dates overlap the newly picked range — the visitor is re-picking
        // those nights. Existing selections for this property that don't
        // overlap the new range are left untouched (only the "Votre
        // sélection" gap warning applies between separate, non-overlapping
        // ranges of the same property).
        for (let i = cart.length - 1; i >= 0; i--) {
          const item = cart[i];
          if (item.propertyId === propertyId && item.checkin < checkout && item.checkout > checkin) {
            cart.splice(i, 1);
          }
        }
        cart.push({
          propertyId,
          propertyName,
          propertyPhoto,
          maxGuests,
          checkin,
          checkout,
          roomTotal: roomTotalFor(checkin, checkout),
        });
        checkin = null;
        checkout = null;
        updateRowSelection();
        renderCart();
      }
    });
  });

  checkoutForm.addEventListener('reset', () => {
    cart.length = 0;
    renderCart();
  });

  // "Effacer les sélections" fully resets the visitor's choices: the cart
  // itself, plus any in-progress arrival/departure pick on every property
  // row so the calendar board goes back to its free-to-select state.
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      cart.length = 0;
      rowResetters.forEach((reset) => reset());
      renderCart();
      refreshAllRowHighlights();
    });
  }

  // Keep the requested party size (and its capacity warning) live if the
  // visitor tweaks the guest fields after already loading availabilities,
  // instead of only reflecting whatever was submitted last.
  guestInputs.forEach((input) => {
    input.addEventListener('input', () => {
      requestedGuests = getRequestedGuests();
      renderCart();
      // Keep the checkout form's hidden guest-count fields (sent with the
      // reservation request) in sync with whatever the visitor last set,
      // not just what was submitted when the page loaded.
      const adultsInput = checkoutForm.querySelector('input[name="adults"]');
      const under3Input = checkoutForm.querySelector('input[name="children_under3"]');
      const to12Input = checkoutForm.querySelector('input[name="children_3to12"]');
      const liveAdults = filterForm ? filterForm.querySelector('input[name="adults"]') : null;
      const liveUnder3 = filterForm ? filterForm.querySelector('input[name="children_under3"]') : null;
      const liveTo12 = filterForm ? filterForm.querySelector('input[name="children_3to12"]') : null;
      if (adultsInput && liveAdults) adultsInput.value = liveAdults.value;
      if (under3Input && liveUnder3) under3Input.value = liveUnder3.value;
      if (to12Input && liveTo12) to12Input.value = liveTo12.value;
    });
  });
}

// Allows deep-linking straight to a partner's site with e.g.
// https://www.grand-baie-maurice.com/#scl (or "#/scl", "#scl/calendrier"):
// on the "/" gate page (files/views/pages/enter-code.php), if the URL
// carries a non-empty hash we treat it exactly as if the visitor had typed
// that code into the "Code partenaire" form and clicked "Ouvrir le site" —
// auto-filling the input (and the target sub-page, if any) and submitting
// the real form so the server sets the partner_code cookie and redirects
// there (see PageController::submitPartnerCode()).
// On the homepage, once a search has been performed (server-rendered with
// results below), collapse the tall fullscreen hero down to a slim search
// bar so the results/map appear higher on the page — the background video
// keeps playing (it's position:fixed, sized independently of .hero-video).
// The section starts in its tall state and the 'hero-video--compact' class
// is added a frame later so the CSS transition actually animates the
// collapse instead of snapping straight to the compact state on load.
function initHeroSearchCollapse() {
  const hero = document.querySelector('.hero-video[data-searched="1"]');
  if (!hero) return;
  requestAnimationFrame(() => {
    requestAnimationFrame(() => hero.classList.add('hero-video--compact'));
  });
}

function initMobileNavbar() {
  const nav = document.querySelector('.navbar');
  const toggle = nav ? nav.querySelector('[data-mobile-nav-toggle]') : null;
  const links = nav ? nav.querySelector('[data-mobile-nav-links]') : null;
  const backdrop = nav ? nav.querySelector('[data-mobile-nav-backdrop]') : null;
  if (!toggle || !links || !backdrop) return;

  const media = window.matchMedia ? window.matchMedia('(max-width: 760px)') : null;
  const isOpen = () => document.body.classList.contains('mobile-nav-open');
  const setOpen = (open) => {
    document.body.classList.toggle('mobile-nav-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  const close = () => setOpen(false);

  toggle.addEventListener('click', () => setOpen(!isOpen()));
  backdrop.addEventListener('click', close);
  links.querySelectorAll('a').forEach((anchor) => anchor.addEventListener('click', close));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
  });

  const closeOnDesktop = () => {
    if (media && !media.matches) close();
  };
  if (media) {
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', closeOnDesktop);
    } else if (typeof media.addListener === 'function') {
      media.addListener(closeOnDesktop);
    }
  }
  closeOnDesktop();
}

function initHeroVideoLoading() {
  const hero = document.querySelector('.hero-video');
  const video = hero ? hero.querySelector('[data-hero-video]') : null;
  if (!hero || !video) return;

  const markReady = () => hero.classList.add('video-ready');
  ['loadeddata', 'canplay', 'playing'].forEach((eventName) => {
    video.addEventListener(eventName, markReady, { once: true });
  });
  let playbackScheduled = false;
  const tryPlay = () => {
    if (playbackScheduled) return;
    playbackScheduled = true;
    window.setTimeout(() => {
      const maybePromise = video.play();
      if (maybePromise && typeof maybePromise.catch === 'function') {
        maybePromise.catch(() => {
          playbackScheduled = false;
        });
      }
    }, 600);
  };
  if (video.readyState >= 3) {
    markReady();
    tryPlay();
  } else {
    video.addEventListener('canplay', tryPlay, { once: true });
    video.addEventListener('canplaythrough', tryPlay, { once: true });
    if (video.preload !== 'auto') video.preload = 'auto';
    try { video.load(); } catch (e) {}
  }
}

function initHeroMobileSearchToggle() {
  const hero = document.querySelector('.hero-video');
  const toggle = hero ? hero.querySelector('[data-hero-search-toggle]') : null;
  const form = hero ? hero.querySelector('[data-hero-search-form]') : null;
  if (!hero || !toggle || !form) return;

  const media = window.matchMedia ? window.matchMedia('(max-width: 760px)') : null;
  const setOpen = (open) => {
    hero.classList.toggle('hero-search-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  const syncToViewport = () => {
    if (!media || !media.matches) setOpen(true);
    else setOpen(false);
  };

  toggle.addEventListener('click', () => {
    const open = !hero.classList.contains('hero-search-open');
    setOpen(open);
    if (open) {
      const firstField = form.querySelector('input, select, textarea, button');
      if (firstField) firstField.focus();
    }
  });

  if (media) {
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', syncToViewport);
    } else if (typeof media.addListener === 'function') {
      media.addListener(syncToViewport);
    }
  }
  syncToViewport();
}

function initPartnerCodeFromHash() {
  const form = document.querySelector('form[action="/partner-code"]');
  if (!form) return;
  const raw = decodeURIComponent(window.location.hash.replace(/^#\/?/, '')).trim();
  if (!raw) return;
  const [code, page] = raw.split('/', 2);
  if (!code) return;
  const input = form.querySelector('input[name="code"]');
  if (!input) return;
  input.value = code;
  const nextInput = form.querySelector('input[name="next"]');
  if (nextInput && page) nextInput.value = '/' + page;
  form.submit();
}
