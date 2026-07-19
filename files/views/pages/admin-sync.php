<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Synchronisation Lodgify</h1>
  <p class="subtitle">Force la mise à jour du cache des propriétés depuis l'API Lodgify.</p>
  <div class="card card-body center stack-md" data-lodgify-sync>
    <div class="emoji-xl">🔄</div>
    <p class="text-muted" data-sync-last-sync>
      <?php if (!empty($lastSyncLabel)): ?>
        <?= \App\View::e($lastSyncLabel) ?>
      <?php else: ?>
        Aucune synchronisation n'a encore été effectuée.
      <?php endif; ?>
    </p>
    <p>La synchronisation des textes (nom, description, capacité, équipements…) est séparée de la synchronisation des photos. Les disponibilités et tarifs sont traités à chaque requête et n'ont pas besoin d'être resynchronisés ici.</p>
    <div class="row" style="justify-content:center;gap:.75rem;flex-wrap:wrap">
      <button class="btn-primary" type="button" data-sync-start="texts">Synchroniser les textes</button>
      <button class="btn-secondary" type="button" data-sync-start="photos">Synchroniser les photos</button>
    </div>
    <div data-sync-progress hidden style="width:100%">
      <p data-sync-status class="text-muted">Préparation…</p>
      <progress data-sync-bar value="0" max="1" style="width:100%"></progress>
    </div>
    <div data-sync-result></div>
    <noscript><form method="post" action="/admin/sync"><button class="btn-secondary" type="submit">Synchroniser les photos (sans JavaScript)</button></form></noscript>
  </div>
</section>
<script>
(function () {
  const root = document.querySelector('[data-lodgify-sync]');
  if (!root) return;
  const startButtons = Array.from(root.querySelectorAll('[data-sync-start]'));
  const progressBox = root.querySelector('[data-sync-progress]');
  const statusEl = root.querySelector('[data-sync-status]');
  const barEl = root.querySelector('[data-sync-bar]');
  const resultEl = root.querySelector('[data-sync-result]');
  const lastSyncEl = root.querySelector('[data-sync-last-sync]');

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value == null ? '' : value);
    return div.innerHTML;
  }

  async function postJson(url) {
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(payload.message || 'Erreur de synchronisation');
    }
    return payload.data;
  }

  async function runSync(mode) {
    startButtons.forEach((button) => { button.disabled = true; });
    resultEl.innerHTML = '';
    progressBox.hidden = false;
    statusEl.textContent = mode === 'photos'
      ? 'Récupération de la liste des biens (photos)…'
      : 'Récupération de la liste des biens (textes)…';
    barEl.value = 0;
    barEl.max = 1;

    const photoErrors = [];
    let refreshed = 0;
    let properties = [];

    try {
      properties = await postJson('/admin/sync/start?mode=' + encodeURIComponent(mode));
    } catch (error) {
      progressBox.hidden = true;
      const failureHint = mode === 'photos'
        ? ' (aucun bien n\u2019a pu être récupéré, aucun dossier images/listings/ n\u2019a donc été créé).'
        : '.';
      resultEl.innerHTML = '<div class="alert alert-error">Synchronisation Lodgify échouée : ' + escapeHtml(error.message) + failureHint + '</div>';
      startButtons.forEach((button) => { button.disabled = false; });
      return;
    }

    if (!Array.isArray(properties) || properties.length === 0) {
      progressBox.hidden = true;
      resultEl.innerHTML = mode === 'photos'
        ? '<div class="alert alert-error">Synchronisation Lodgify terminée, mais Lodgify n\u2019a retourné aucun bien : aucun dossier images/listings/ n\u2019a donc été créé.</div>'
        : '<div class="alert alert-error">Synchronisation des textes terminée, mais Lodgify n\u2019a retourné aucun bien.</div>';
      startButtons.forEach((button) => { button.disabled = false; });
      return;
    }

    barEl.max = properties.length;

    for (let i = 0; i < properties.length; i++) {
      const property = properties[i];
      statusEl.textContent = 'Bien ' + (i + 1) + '/' + properties.length + ' : ' + property.name + '…';
      try {
        const result = await postJson('/admin/sync/property/' + property.id + '?mode=' + encodeURIComponent(mode));
        if (result && result.ok) refreshed++;
        if (result && Array.isArray(result.photo_errors)) {
          photoErrors.push(...result.photo_errors);
        }
      } catch (error) {
        photoErrors.push('Bien #' + property.id + ' (' + property.name + '): ' + error.message);
      }
      barEl.value = i + 1;
    }

    statusEl.textContent = 'Finalisation…';
    try {
      const finishData = await postJson('/admin/sync/finish');
      if (lastSyncEl && finishData && finishData.last_sync_label) {
        lastSyncEl.textContent = finishData.last_sync_label;
      }
    } catch (error) {
      photoErrors.push('Finalisation : ' + error.message);
    }

    progressBox.hidden = true;
    startButtons.forEach((button) => { button.disabled = false; });

    if (photoErrors.length === 0) {
      const actionLabel = mode === 'photos' ? 'des photos' : 'des textes';
      resultEl.innerHTML = '<div class="alert alert-success">Synchronisation Lodgify ' + actionLabel + ' terminée (' + refreshed + ' bien(s) rafraîchi(s)).</div>';
      return;
    }
    const preview = photoErrors.slice(0, 3).map(escapeHtml).join(' | ');
    let message = mode === 'photos'
      ? 'Synchronisation terminée avec ' + photoErrors.length + ' erreur(s) de mise en cache des photos : ' + preview
      : 'Synchronisation des textes terminée avec ' + photoErrors.length + ' erreur(s) : ' + preview;
    if (photoErrors.length > 3) {
      message += ' \u2026 (voir le journal des erreurs pour le détail complet)';
    }
    resultEl.innerHTML = '<div class="alert alert-error">' + message + '</div>';
  }

  startButtons.forEach((button) => {
    button.addEventListener('click', () => {
      runSync(button.getAttribute('data-sync-start') || 'photos');
    });
  });
})();
</script>
