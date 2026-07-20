<?php declare(strict_types=1);
$labels = [
  'REQUEST_RECEIVED_PARTNER' => 'Demande reçue (partenaire)',
  'REQUEST_RECEIVED_CLIENT' => 'Accusé réception (client)',
  'RESERVATION_CONFIRMED' => 'Réservation confirmée (client)',
  'RESERVATION_CANCELLED' => 'Réservation annulée (client)',
  'REMINDER' => 'Rappel avant arrivée',
];
$variables = ['{{nom_client}}','{{email_client}}','{{telephone_client}}','{{dates}}','{{date_arrivee}}','{{date_depart}}','{{nuits}}','{{adultes}}','{{enfants}}','{{bebes}}','{{hebergement}}','{{photo_bien}}','{{partenaire}}','{{notes}}','{{message}}','{{tarif_nuits}}','{{tarif_hebergement}}','{{tarif_personnes_supplementaires}}','{{tarif_nettoyage}}','{{tarif_total}}','{{taxe_touristique}}','{{tarif_bloc}}','{{signature_photo}}','{{signature_nom}}','{{logo_partenaire}}','{{email_partenaire}}','{{lien_partenaire}}','{{telephone_partenaire}}'];
$baseUrl = '/admin/templates';
?>
<section class="container section-lg">
  <h1>Templates email</h1>
  <div class="two-panel">
    <div class="card overflow-hidden side-list">
      <div class="card-header">Partenaires</div>
      <?php foreach ($partners as $p): ?>
        <a href="<?= $baseUrl ?>?partner_id=<?= (int) $p['id'] ?>"
           class="<?= $selectedPartnerId === (int) $p['id'] ? 'active' : '' ?>">
          <?= \App\View::e($p['name']) ?>
          <span class="badge-count"><?= (int) $p['template_count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="card card-body stack-md">
      <?php if ($selectedPartnerId === null): ?>
        <p class="empty-state">Sélectionnez un partenaire pour voir ses templates.</p>
      <?php else: ?>
        <nav class="breadcrumb"><a href="<?= $baseUrl ?>">Templates</a> › <span><?= \App\View::e($selectedPartnerName ?? '') ?></span></nav>
        <?php if ($templates === []): ?>
          <p class="empty-state">Aucun template pour ce partenaire.</p>
        <?php else: ?>
          <div class="two-panel">
            <div class="card overflow-hidden side-list">
              <?php foreach ($templates as $tpl): ?>
                <a href="<?= $baseUrl ?>?partner_id=<?= $selectedPartnerId ?>&amp;id=<?= (int) $tpl['id'] ?>"
                   class="<?= $selected && (int) $selected['id'] === (int) $tpl['id'] ? 'active' : '' ?>">
                  <?= \App\View::e($labels[$tpl['type']] ?? $tpl['type']) ?>
                </a>
              <?php endforeach; ?>
            </div>
            <div class="card card-body">
              <?php if (!$selected): ?>
                <p class="empty-state">Sélectionnez un template à éditer.</p>
              <?php else: ?>
                <h2 class="section-title"><?= \App\View::e($labels[$selected['type']] ?? $selected['type']) ?></h2>
                <form method="post" action="<?= $baseUrl ?>/<?= $selectedPartnerId ?>/<?= (int) $selected['id'] ?>" class="stack-md" data-template-editor>
                  <label><span>Objet de l'email</span><input class="input" type="text" name="subject" value="<?= \App\View::e($selected['subject']) ?>"></label>
                  <div>
                    <div class="template-toolbar">
                      <span class="label-inline">Corps de l'email (HTML)</span>
                      <div class="insert-var-dropdown">
                        <button type="button" class="btn-secondary btn-sm" data-insert-dropdown-toggle>📋 Insérer variable ▾</button>
                        <div class="insert-var-menu" hidden>
                          <?php foreach ($variables as $variable): ?>
                            <button type="button" class="insert-var-item" data-insert-variable="<?= \App\View::e($variable) ?>"><?= \App\View::e($variable) ?></button>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                    <textarea class="input codearea" rows="16" name="body_html" data-template-body><?= \App\View::e($selected['body_html']) ?></textarea>
                  </div>
                  <details class="preview-box" open>
                    <summary>Aperçu HTML</summary>
                    <iframe class="preview-frame" sandbox="" data-template-preview srcdoc="<?= \App\View::e($selected['body_html']) ?>"></iframe>
                  </details>
                  <button class="btn-primary" type="submit">Sauvegarder</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
