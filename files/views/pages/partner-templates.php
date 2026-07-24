<?php declare(strict_types=1);
$labels = [
  'REQUEST_RECEIVED_PARTNER' => 'Demande reçue (partenaire)',
  'REQUEST_RECEIVED_CLIENT' => 'Accusé réception (client)',
  'RESERVATION_CONFIRMED' => 'Réservation confirmée (client)',
  'RESERVATION_CANCELLED' => 'Réservation annulée (client)',
  'REMINDER' => 'Rappel avant arrivée',
];
$plainVariables = \App\View::emailTemplateVariableCatalog();
$resizableVariables = \App\View::emailTemplateImageVariableCatalog();
$isClientFacingTemplate = $selected ? \App\View::isClientFacingTemplateType((string) $selected['type']) : false;
$isAdmin = isset($adminPartnerId);
$baseUrl = $isAdmin ? '/admin/partners/' . (int) $adminPartnerId . '/templates' : '/partner/templates';
?>
<section class="container section-lg">
  <?php if ($isAdmin): ?>
  <nav class="breadcrumb"><a href="/admin/partners">Partenaires</a> › <span>Templates · <?= \App\View::e($adminPartnerName ?? '') ?></span></nav>
  <?php endif; ?>
  <h1><?= $isAdmin ? 'Templates email · ' . \App\View::e($adminPartnerName ?? '') : 'Templates d\'emails' ?></h1>
  <div class="two-panel">
    <div class="card overflow-hidden side-list">
      <?php if ($templates === []): ?>
        <p class="empty-state">Aucun template. Contactez l'administrateur.</p>
      <?php else: foreach ($templates as $template): ?>
        <a href="<?= $baseUrl ?>?id=<?= (int) $template['id'] ?>" class="<?= $selected && (int) $selected['id'] === (int) $template['id'] ? 'active' : '' ?>"><?= \App\View::e($labels[$template['type']] ?? $template['type']) ?> <?= ((string) ($template['language'] ?? 'fr')) === 'en' ? '🇬🇧' : '🇫🇷' ?></a>
      <?php endforeach; endif; ?>
    </div>
    <div class="card card-body">
      <?php if (!$selected): ?>
        <p class="empty-state">Sélectionnez un template à éditer.</p>
      <?php else: ?>
        <h2 class="section-title"><?= \App\View::e($labels[$selected['type']] ?? $selected['type']) ?></h2>
        <p class="<?= $isClientFacingTemplate ? 'recipient-note recipient-note--client' : 'recipient-note recipient-note--partner' ?>">
          <?= $isClientFacingTemplate
            ? '📧 Ce template est envoyé au <strong>client</strong> (le voyageur). N\'y insérez jamais une variable réservée au partenaire (commission, montant à reverser…).'
            : '📧 Ce template est envoyé au <strong>partenaire</strong> (vous), jamais au client.' ?>
        </p>
        <form method="post" action="<?= $baseUrl ?>/<?= (int) $selected['id'] ?>" class="stack-md" data-template-editor>
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
            <iframe class="preview-frame" sandbox="allow-same-origin" data-template-preview srcdoc="<?= \App\View::e($selected['body_html']) ?>"></iframe>
          </details>
          <div class="flex-group">
            <button class="btn-primary" type="submit">Sauvegarder</button>
          </div>

        </form>
        <form method="post" action="<?= $baseUrl ?>/<?= (int) $selected['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr ? Cette action est irréversible.');">
          <button class="btn-secondary" type="submit">Supprimer</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
