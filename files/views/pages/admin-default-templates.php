<?php declare(strict_types=1);
$labels = [];
foreach (($templateCatalog ?? []) as $type => $definition) {
  $labels[$type] = $definition['label'] ?? $type;
}
$plainVariables = \App\View::emailTemplateVariableCatalog();
$resizableVariables = \App\View::emailTemplateImageVariableCatalog();
$isClientFacingTemplate = $selected ? \App\View::isClientFacingTemplateType((string) $selected['type']) : false;
$baseUrl = '/admin/templates/default';
$selectedLanguage = $selectedLanguage ?? 'fr';
?>
<section class="container section-lg">
  <h1>Templates par défaut</h1>
  <p class="text-muted">Ces templates sont utilisés pour l'envoi des emails lorsqu'un partenaire n'a pas encore créé son propre template pour ce type/langue. <a href="/admin/templates">← Retour aux templates par partenaire</a></p>
  <div class="card card-body stack-md">
    <div class="tabs" role="tablist" style="display:flex;gap:.5rem;margin:-.25rem 0 .5rem;">
      <a href="<?= $baseUrl ?>?language=fr" class="btn-sm <?= $selectedLanguage === 'fr' ? 'btn-primary' : 'btn-secondary' ?>">🇫🇷 Français</a>
      <a href="<?= $baseUrl ?>?language=en" class="btn-sm <?= $selectedLanguage === 'en' ? 'btn-primary' : 'btn-secondary' ?>">🇬🇧 English</a>
    </div>

    <div class="form-grid cols-2">
      <div class="card card-body stack-sm">
        <h2 class="section-title">Templates existants</h2>
        <?php if (($templates ?? []) === []): ?>
          <p class="empty-state">Aucun template par défaut pour cette langue.</p>
        <?php else: ?>
          <div class="partner-template-list">
            <?php foreach ($templates as $tpl): ?>
              <a href="<?= $baseUrl ?>?language=<?= \App\View::e($selectedLanguage) ?>&amp;id=<?= (int) $tpl['id'] ?>"
                 class="partner-template-link <?= $selected && (int) $selected['id'] === (int) $tpl['id'] ? 'active' : '' ?>">
                <?= \App\View::e($labels[$tpl['type']] ?? $tpl['type']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card card-body stack-sm">
        <h2 class="section-title">Nouveau template par défaut</h2>
        <?php if (($creatableTemplates ?? []) === []): ?>
          <p class="empty-state">Les 5 templates existent déjà pour cette langue.</p>
        <?php else: ?>
          <form method="post" action="<?= $baseUrl ?>/create" class="stack-md">
            <input type="hidden" name="language" value="<?= \App\View::e($selectedLanguage) ?>">
            <label>
              <span>Type de template</span>
              <select class="input" name="type" required>
                <?php foreach ($creatableTemplates as $type => $definition): ?>
                  <option value="<?= \App\View::e((string) $type) ?>"><?= \App\View::e($definition['label'] ?? (string) $type) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button class="btn-primary" type="submit">➕ Créer le template par défaut</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($selected): ?>
      <div class="card card-body">
        <h2 class="section-title"><?= \App\View::e($labels[$selected['type']] ?? $selected['type']) ?></h2>
        <p class="<?= $isClientFacingTemplate ? 'recipient-note recipient-note--client' : 'recipient-note recipient-note--partner' ?>">
          <?= $isClientFacingTemplate
            ? '📧 Ce template est envoyé au <strong>client</strong> (le voyageur). N\'y insérez jamais une variable réservée au partenaire (commission, montant à reverser…).'
            : '📧 Ce template est envoyé au <strong>partenaire</strong>, jamais au client.' ?>
        </p>
        <form method="post" action="<?= $baseUrl ?>/<?= (int) $selected['id'] ?>" class="stack-md" data-template-editor data-gallery-assets="[]">
          <label><span>Objet de l'email</span><input class="input" type="text" name="subject" value="<?= \App\View::e($selected['subject']) ?>"></label>
          <details class="code-box">
            <summary>Corps de l'email (HTML)</summary>
            <div class="template-toolbar">
              <div class="insert-var-dropdown">
                <button type="button" class="btn-secondary btn-sm" data-insert-dropdown-toggle>📋 Insérer variable ▾</button>
                <div class="insert-var-menu" hidden>
                  <?php foreach ($plainVariables as $variable): ?>
                    <?php $isRestricted = $isClientFacingTemplate && !empty($variable['partnerOnly']); ?>
                    <button
                      type="button"
                      class="insert-var-item<?= $isRestricted ? ' insert-var-item--restricted' : '' ?>"
                      data-insert-variable="<?= \App\View::e('{{' . $variable['key'] . '}}') ?>"
                      <?= !empty($variable['partnerOnly']) ? 'data-variable-partner-only="1"' : '' ?>
                      title="<?= \App\View::e($variable['description']) ?>"
                    >
                      <span class="insert-var-item-name"><?= \App\View::e('{{' . $variable['key'] . '}}') ?><?= !empty($variable['partnerOnly']) ? ' ⚠️' : '' ?></span>
                      <span class="insert-var-item-desc"><?= \App\View::e($variable['description']) ?></span>
                    </button>
                  <?php endforeach; ?>
                  <div style="padding:.4rem .9rem;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;">Variables image avec taille</div>
                  <?php foreach ($resizableVariables as $variable): ?>
                    <button
                      type="button"
                      class="insert-var-item"
                      data-insert-variable="<?= \App\View::e($variable['name']) ?>"
                      data-variable-resizable="1"
                      data-variable-default-size="<?= (int) $variable['default'] ?>"
                      title="<?= \App\View::e($variable['description']) ?>"
                    >
                      <span class="insert-var-item-name">{{<?= \App\View::e($variable['name']) ?>}} · taille</span>
                      <span class="insert-var-item-desc"><?= \App\View::e($variable['description']) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <p class="text-muted" style="margin:.25rem 0 .75rem;">Toutes les variables (texte, photo, image) affichent une donnée temporaire dans l’aperçu. Cliquez sur un texte ou une image pour le modifier directement.</p>
            <textarea class="input codearea" rows="16" name="body_html" data-template-body><?= \App\View::e($selected['body_html']) ?></textarea>
          </details>
          <details class="preview-box" open>
            <summary>Aperçu HTML</summary>
            <p class="text-muted" style="margin:.5rem 0 1rem;">Cliquez sur une image pour modifier sa source, sa taille et sa position, ou sur un texte pour le modifier directement.</p>
            <iframe class="preview-frame" sandbox="allow-same-origin" data-template-preview srcdoc="<?= \App\View::e($selected['body_html']) ?>"></iframe>
          </details>
          <div class="flex-group">
            <button class="btn-primary" type="submit">Sauvegarder</button>
          </div>
        </form>
        <form method="post" action="<?= $baseUrl ?>/<?= (int) $selected['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr ? Cette action est irréversible.');">
          <button class="btn-secondary" type="submit">Supprimer</button>
        </form>
      </div>
    <?php elseif (($templates ?? []) !== []): ?>
      <p class="empty-state">Sélectionnez un template à éditer.</p>
    <?php endif; ?>
  </div>
</section>
