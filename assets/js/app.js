document.addEventListener('DOMContentLoaded', () => {
  initGallery();
  initMaps();
  initApiForms();
  initNationalities();
  initTemplateEditor();
  initColorSync();
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

    function render() {
      const adults = Number(adultsInput?.value || 0);
      const children = Number(childrenInput?.value || 0);
      const total = adults + children;
      list.innerHTML = '';
      const same = sameCheckbox?.checked;
      if (uniformBox) uniformBox.classList.toggle('hidden', !same);
      if (same) return;
      for (let i = 0; i < total; i += 1) {
        const node = template.content.firstElementChild.cloneNode(true);
        node.querySelector('span').textContent = `${i < adults ? 'Adulte' : 'Enfant'} ${i < adults ? i + 1 : i - adults + 1} — Nationalité`;
        const select = node.querySelector('[data-nationality-select]');
        select.dataset.type = i < adults ? 'adult' : 'child';
        list.appendChild(node);
      }
    }

    [adultsInput, childrenInput, sameCheckbox].forEach((input) => input?.addEventListener('change', render));
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
