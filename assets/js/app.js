document.addEventListener('DOMContentLoaded', () => {
  initGallery();
  initPropertyTabs();
  initMaps();
  initApiForms();
  initNationalities();
  initTemplateEditor();
  initColorSync();
  initDateRanges();
  initBookingCalendarSelection();
  initPhoneInputs();
  initGuestSteppers();
  initBookingAccordion();
  initBookingQuote();
  initCalendarBoard();
  initMultiPropertyCart();
});

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
  });
}

function initMaps() {
  document.querySelectorAll('.map-board').forEach((board) => {
    const minLat = parseFloat(board.dataset.minLat || '-20.6');
    const maxLat = parseFloat(board.dataset.maxLat || '-19.9');
    const minLng = parseFloat(board.dataset.minLng || '57.1');
    const maxLng = parseFloat(board.dataset.maxLng || '57.9');
    board.querySelectorAll('.map-marker').forEach((marker) => {
      const lat = parseFloat(marker.dataset.lat || '0');
      const lng = parseFloat(marker.dataset.lng || '0');
      const top = maxLat === minLat ? 50 : ((maxLat - lat) / (maxLat - minLat)) * 100;
      const left = maxLng === minLng ? 50 : ((lng - minLng) / (maxLng - minLng)) * 100;
      marker.style.top = `${Math.max(8, Math.min(92, top))}%`;
      marker.style.left = `${Math.max(8, Math.min(92, left))}%`;
    });
  });
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
          const total = ['adults', 'children_under5', 'children_5to12']
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
      } catch (error) {
        if (feedback) feedback.textContent = error.message || 'Une erreur est survenue.';
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

    function totalGuests() {
      return inputs.reduce((sum, item) => sum + Number(item.value || 0), 0);
    }

    function updateCapacityState() {
      const total = totalGuests();
      const atCapacity = isFinite(maxGuests) && total >= maxGuests;
      rows.forEach((row) => {
        const incBtn = row.querySelector('[data-step="1"]');
        if (incBtn) incBtn.disabled = atCapacity;
      });
      if (note) {
        note.hidden = !isFinite(maxGuests) || total <= maxGuests;
        if (!note.hidden) {
          note.textContent = `Ce logement peut accueillir au maximum ${maxGuests} personne(s) (adultes + enfants).`;
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
        if (isFinite(maxGuests)) {
          const othersTotal = totalGuests() - Number(input.value || 0);
          clamped = Math.min(clamped, Math.max(min, maxGuests - othersTotal));
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
 */
function initBookingAccordion() {
  document.querySelectorAll('[data-booking-form]').forEach((form) => {
    const blocks = Array.from(form.querySelectorAll('[data-booking-block]')).filter(
      (block) => block.dataset.bookingBlock !== 'summary'
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
    const childrenUnder5Input = form.querySelector('[name="children_under5"]');
    const children5to12Input = form.querySelector('[name="children_5to12"]');
    // Some forms (property detail booking) split children into two age
    // groups (< 5 ans / 5-12 ans) while others (contact form) only have a
    // single "children" field. Both are supported here.
    const hasSplitChildren = Boolean(childrenUnder5Input || children5to12Input);

    function render() {
      const adults = Number(adultsInput?.value || 0);
      const under5 = hasSplitChildren ? Number(childrenUnder5Input?.value || 0) : 0;
      const from5to12 = hasSplitChildren ? Number(children5to12Input?.value || 0) : Number(childrenInput?.value || 0);
      const children = under5 + from5to12;
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
        } else if (hasSplitChildren && i < adults + under5) {
          label = `Enfant (< 5 ans) ${i - adults + 1} — Nationalité`;
          type = 'child_under5';
        } else if (hasSplitChildren) {
          label = `Enfant (5-12 ans) ${i - adults - under5 + 1} — Nationalité`;
          type = 'child_5to12';
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

    [adultsInput, childrenInput, childrenUnder5Input, children5to12Input, sameCheckbox].forEach((input) => {
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
  document.querySelectorAll('[data-template-editor]').forEach((form) => {
    const textarea = form.querySelector('[data-template-body]');
    const preview = form.querySelector('[data-template-preview]');
    form.querySelectorAll('[data-insert-variable]').forEach((button) => {
      button.addEventListener('click', () => {
        textarea.value += button.dataset.insertVariable || '';
        if (preview) preview.srcdoc = textarea.value;
      });
    });
    textarea?.addEventListener('input', () => {
      if (preview) preview.srcdoc = textarea.value;
    });
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
    const checkin = wrap.querySelector('input[name="checkin"], input[name="checkin_date"]');
    const checkout = wrap.querySelector('input[name="checkout"], input[name="checkout_date"]');
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
      form.querySelector('[data-quote-nights]').textContent = quote.nights;
      // The cleaning fee is folded into the room line instead of shown
      // separately, matching the amount already used to compute the total.
      const roomWithCleaning = Number(quote.room_total) + Number(quote.cleaning_total);
      form.querySelector('[data-quote-room]').textContent = formatMoney(roomWithCleaning);
      form.querySelector('[data-quote-total]').textContent = formatMoney(quote.total_without_tax);
      const recap = form.querySelector('[data-quote-recap]');
      if (recap) {
        const adults = Number(form.querySelector('[name="adults"]')?.value || 0);
        const under5 = Number(form.querySelector('[name="children_under5"]')?.value || 0);
        const from5to12 = Number(form.querySelector('[name="children_5to12"]')?.value || 0);
        const parts = [`${adults} Adulte(s)`];
        if (under5 > 0) parts.push(`${under5} Enfant(s) -5 ans`);
        if (from5to12 > 0) parts.push(`${from5to12} Enfant(s) 5-12 ans`);
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
        const taxRate = form.querySelector('[data-quote-tax-rate]');
        if (taxRate) {
          taxRate.textContent = Number(quote.tourist_tax_rate || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
      }
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
 * is insufficient for the party size entered above the table are rendered
 * disabled server-side and are simply not wired here.
 */
function initMultiPropertyCart() {
  const board = document.querySelector('[data-multi-calendar-board]');
  const cartRoot = document.querySelector('[data-multi-cart]');
  if (!board || !cartRoot) return;

  const listEl = cartRoot.querySelector('[data-multi-cart-list]');
  const feedbackEl = cartRoot.querySelector('[data-multi-cart-feedback]');
  const checkoutForm = cartRoot.querySelector('[data-multi-cart-form]');
  const itemsInput = checkoutForm ? checkoutForm.querySelector('[data-multi-cart-items]') : null;
  const summaryEl = cartRoot.querySelector('[data-multi-cart-summary]');
  const summaryCountEl = cartRoot.querySelector('[data-multi-cart-summary-count]');
  const summaryNightsEl = cartRoot.querySelector('[data-multi-cart-summary-nights]');
  const summaryCapacityEl = cartRoot.querySelector('[data-multi-cart-summary-capacity]');
  const summaryTotalEl = cartRoot.querySelector('[data-multi-cart-summary-total]');
  if (!listEl || !checkoutForm || !itemsInput) return;

  const cart = [];
  const rowUpdaters = [];

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

  function renderCart() {
    listEl.innerHTML = '';
    if (cart.length === 0) {
      cartRoot.hidden = true;
      checkoutForm.hidden = true;
      if (summaryEl) summaryEl.hidden = true;
      itemsInput.value = '';
      return;
    }
    cartRoot.hidden = false;
    checkoutForm.hidden = false;
    if (summaryEl) summaryEl.hidden = false;

    let totalNights = 0;
    let totalAmount = 0;
    let minCapacity = null;

    cart.forEach((item, index) => {
      const nights = nightsBetween(item.checkin, item.checkout);
      totalNights += nights;
      totalAmount += item.roomTotal;
      if (minCapacity === null || item.maxGuests < minCapacity) minCapacity = item.maxGuests;

      const li = document.createElement('li');
      li.className = 'multi-cart-item';

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

    if (summaryCountEl) summaryCountEl.textContent = String(cart.length);
    if (summaryNightsEl) summaryNightsEl.textContent = String(totalNights);
    if (summaryCapacityEl) summaryCapacityEl.textContent = String(minCapacity ?? 0);
    if (summaryTotalEl) summaryTotalEl.textContent = formatEuros(totalAmount);

    itemsInput.value = JSON.stringify(cart.map((item) => ({
      property_id: item.propertyId,
      property_name: item.propertyName,
      checkin_date: item.checkin,
      checkout_date: item.checkout,
    })));
    if (feedbackEl) feedbackEl.textContent = '';
  }

  board.querySelectorAll('[data-property-row]').forEach((row) => {
    if (row.dataset.capacityOk !== '1') return;
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

    row.addEventListener('click', (event) => {
      const cell = event.target.closest('[data-calendar-date]');
      if (!cell) return;
      const date = cell.dataset.calendarDate;
      if (!date) return;

      if (!checkin || checkout) {
        if (!isNightAvailable(date)) return;
        checkin = date;
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
}
