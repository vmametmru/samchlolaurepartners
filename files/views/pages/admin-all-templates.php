<?php declare(strict_types=1);
$labels = [];
foreach (($templateCatalog ?? []) as $type => $definition) {
  $labels[$type] = $definition['label'] ?? $type;
}
$plainVariables = ['{{nom_client}}','{{email_client}}','{{telephone_client}}','{{dates}}','{{date_arrivee}}','{{date_depart}}','{{nuits}}','{{adultes}}','{{enfants}}','{{bebes}}','{{nationalites}}','{{multi_biens_note}}','{{hebergement}}','{{partenaire}}','{{notes}}','{{message}}','{{tarif_nuits}}','{{tarif_hebergement}}','{{tarif_personnes_supplementaires}}','{{tarif_nettoyage}}','{{tarif_total}}','{{taxe_touristique}}','{{tarif_bloc}}','{{tarif_normal}}','{{commission_partenaire}}','{{personnes_additionnelles}}','{{nettoyage}}','{{total_voyageur}}','{{signature_nom}}','{{email_partenaire}}','{{lien_partenaire}}','{{telephone_partenaire}}','{{politique_reservation}}'];
$resizableVariables = [
  ['name' => 'photo_bien', 'label' => '{{photo_bien}}', 'default' => 320],
  ['name' => 'photo1', 'label' => '{{photo1}}', 'default' => 320],
  ['name' => 'photo2', 'label' => '{{photo2}}', 'default' => 320],
  ['name' => 'photo3', 'label' => '{{photo3}}', 'default' => 320],
  ['name' => 'logo_partenaire', 'label' => '{{logo_partenaire}}', 'default' => 80],
  ['name' => 'signature_photo', 'label' => '{{signature_photo}}', 'default' => 64],
];
$baseUrl = '/admin/templates';
$selectedLanguage = $selectedLanguage ?? 'fr';
?>
<section class="container section-lg">
  <h1>Templates email</h1>
  <div class="two-panel">
    <div class="card overflow-hidden side-list partner-accordion-list">
      <div class="card-header">Partenaires</div>
      <?php foreach ($partners as $p): ?>
        <?php $isSelectedPartner = $selectedPartnerId === (int) $p['id']; ?>
        <details class="partner-accordion" <?= $isSelectedPartner ? 'open' : '' ?>>
          <summary class="partner-accordion-summary <?= $isSelectedPartner ? 'active' : '' ?>">
            <a href="<?= $baseUrl ?>?partner_id=<?= (int) $p['id'] ?>&amp;language=<?= \App\View::e($selectedLanguage) ?>"><?= \App\View::e($p['name']) ?></a>
            <span class="badge-count"><?= (int) $p['template_count'] ?></span>
          </summary>
          <?php if ($isSelectedPartner): ?>
            <div class="partner-template-list">
              <?php if (($templates ?? []) === []): ?>
                <p class="empty-state" style="padding:.5rem 1rem;">Aucun template pour ce partenaire.</p>
              <?php else: ?>
                <?php foreach ($templates as $tpl): ?>
                  <a href="<?= $baseUrl ?>?partner_id=<?= $selectedPartnerId ?>&amp;language=<?= \App\View::e($selectedLanguage) ?>&amp;id=<?= (int) $tpl['id'] ?>"
                     class="partner-template-link <?= $selected && (int) $selected['id'] === (int) $tpl['id'] ? 'active' : '' ?>">
                    <?= \App\View::e($labels[$tpl['type']] ?? $tpl['type']) ?>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </details>
      <?php endforeach; ?>
    </div>
    <div class="card card-body stack-md">
      <?php if ($selectedPartnerId === null): ?>
        <p class="empty-state">Sélectionnez un partenaire pour voir ses templates.</p>
      <?php else: ?>
        <nav class="breadcrumb"><a href="<?= $baseUrl ?>">Templates</a> › <span><?= \App\View::e($selectedPartnerName ?? '') ?></span></nav>

        <div class="tabs" role="tablist" style="display:flex;gap:.5rem;margin:-.25rem 0 .5rem;">
          <a href="<?= $baseUrl ?>?partner_id=<?= (int) $selectedPartnerId ?>&amp;language=fr" class="btn-sm <?= $selectedLanguage === 'fr' ? 'btn-primary' : 'btn-secondary' ?>">🇫🇷 Français</a>
          <a href="<?= $baseUrl ?>?partner_id=<?= (int) $selectedPartnerId ?>&amp;language=en" class="btn-sm <?= $selectedLanguage === 'en' ? 'btn-primary' : 'btn-secondary' ?>">🇬🇧 English</a>
        </div>


        <div class="form-grid cols-3">
          <div class="card card-body stack-md">
            <h2 class="section-title">Nouveaux templates</h2>
            <?php if (($creatableTemplates ?? []) === []): ?>
              <p class="empty-state">Les 5 templates existent déjà pour ce partenaire.</p>
            <?php else: ?>
              <form method="post" action="<?= $baseUrl ?>/create" class="stack-md">
                <input type="hidden" name="partner_id" value="<?= (int) $selectedPartnerId ?>">
                <input type="hidden" name="language" value="<?= \App\View::e($selectedLanguage) ?>">
                <label>
                  <span>Type de template</span>
                  <select class="input" name="type" required>
                    <?php foreach ($creatableTemplates as $type => $definition): ?>
                      <option value="<?= \App\View::e((string) $type) ?>"><?= \App\View::e($definition['label'] ?? (string) $type) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button class="btn-primary" type="submit">➕ Créer le template</button>
              </form>
            <?php endif; ?>
          </div>

          <div class="card card-body stack-md">
            <h2 class="section-title">Importer depuis un autre partenaire</h2>
            <?php if (($importTemplates ?? []) === []): ?>
              <p class="empty-state">Aucun template disponible à importer.</p>
            <?php else: ?>
              <form method="post" action="<?= $baseUrl ?>/import" class="stack-md">
                <input type="hidden" name="partner_id" value="<?= (int) $selectedPartnerId ?>">
                <label>
                  <span>Template source</span>
                  <select class="input" name="source_template_id" required>
                    <?php foreach ($importTemplates as $template): ?>
                      <option value="<?= (int) $template['id'] ?>"><?= \App\View::e($template['partner_name']) ?> — <?= \App\View::e($labels[$template['type']] ?? $template['type']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button class="btn-secondary" type="submit">📥 Importer</button>
              </form>
            <?php endif; ?>
          </div>

          <div class="card card-body stack-md">
            <h2 class="section-title">Importer un ZIP Canva.com</h2>
            <form method="post" action="<?= $baseUrl ?>/import-zip" enctype="multipart/form-data" class="stack-md" data-zip-import-form>
              <input type="hidden" name="partner_id" value="<?= (int) $selectedPartnerId ?>">
              <?php if ($selected): ?><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><?php endif; ?>
              <label>
                <span>Fichier ZIP (HTML + images)</span>
                <input class="input" type="file" name="template_zip" accept=".zip,application/zip,application/x-zip-compressed" required data-zip-file-input>
              </label>
              <label>
                <span>Que faut-il importer ?</span>
                <select class="input" name="import_mode" data-zip-import-mode>
                  <option value="all" <?= !$selected ? 'disabled' : '' ?> <?= $selected ? 'selected' : '' ?>>Tout (HTML + images)</option>
                  <option value="images_only" <?= !$selected ? 'selected' : '' ?>>Juste les images</option>
                  <option value="html_only" <?= !$selected ? 'disabled' : '' ?>>Tout sauf les images (HTML seul)</option>
                </select>
              </label>
              <button class="btn-secondary" type="submit">📦 Importer le ZIP</button>
              <?php if (!$selected): ?>
                <p class="text-muted" style="margin:0;">Sélectionnez un template pour activer les options « Tout » et « Tout sauf les images ».</p>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <details class="card card-body accordion">
          <summary class="section-title">Mini galerie graphique</summary>
          <div class="stack-md" style="margin-top:.75rem;">
            <p class="text-muted">Ajoutez ici les éléments graphiques du template (coins, icônes, séparateurs, badges...). Les photos d’hébergement restent dans les variables.</p>
            <form method="post" action="<?= $baseUrl ?>/assets/upload" enctype="multipart/form-data" class="form-grid cols-3">
              <input type="hidden" name="partner_id" value="<?= (int) $selectedPartnerId ?>">
              <label class="col-span-2">
                <span>Image</span>
                <input class="input" type="file" name="asset" accept=".jpg,.jpeg,.png,.gif,.webp" required>
              </label>
              <button class="btn-secondary" type="submit">🖼️ Ajouter à la galerie</button>
            </form>
            <?php if (($galleryAssets ?? []) === []): ?>
              <p class="empty-state">Aucun élément graphique enregistré pour ce partenaire.</p>
            <?php else: ?>
              <form method="post" action="<?= $baseUrl ?>/assets/delete" class="stack-md" data-gallery-bulk-delete-form>
                <input type="hidden" name="partner_id" value="<?= (int) $selectedPartnerId ?>">
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                  <label style="display:flex;align-items:center;gap:.4rem;font-weight:normal;">
                    <input type="checkbox" data-gallery-select-all>
                    <span>Tout sélectionner</span>
                  </label>
                  <button type="submit" class="link-warning" data-gallery-delete-selected disabled>🗑️ Supprimer la sélection (<span data-gallery-selected-count>0</span>)</button>
                </div>
                <div class="form-grid cols-3">
                  <?php foreach ($galleryAssets as $asset): ?>
                    <?php $snippet = '<img src=&quot;' . \App\View::e($asset['url']) . '&quot; alt=&quot;&quot; width=&quot;120&quot; style=&quot;display:block;width:120px;max-width:100%;height:auto;&quot;>'; ?>
                    <div class="card card-body stack-sm">
                      <label style="display:flex;align-items:center;gap:.4rem;font-weight:normal;">
                        <input type="checkbox" name="asset_urls[]" value="<?= \App\View::e($asset['url']) ?>" data-gallery-select-item>
                        <span>Sélectionner</span>
                      </label>
                      <img src="<?= \App\View::e($asset['url']) ?>" alt="<?= \App\View::e($asset['name']) ?>" style="display:block;max-width:100%;height:auto;border-radius:.5rem;border:1px solid #e5e7eb;">
                      <code><?= \App\View::e($asset['name']) ?></code>
                      <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <button type="button" class="btn-secondary btn-sm" data-insert-html="<?= $snippet ?>">Insérer</button>
                        <button type="button" class="link-warning" data-gallery-delete-single="<?= \App\View::e($asset['url']) ?>">Supprimer</button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </details>

        <?php if (($templates ?? []) === []): ?>
          <p class="empty-state">Aucun template pour ce partenaire.</p>
        <?php elseif (!$selected): ?>
          <p class="empty-state">Sélectionnez un template à éditer.</p>
        <?php else: ?>
          <div class="card card-body">
            <h2 class="section-title"><?= \App\View::e($labels[$selected['type']] ?? $selected['type']) ?></h2>
            <form method="post" action="<?= $baseUrl ?>/<?= $selectedPartnerId ?>/<?= (int) $selected['id'] ?>" class="stack-md" data-template-editor data-gallery-assets="<?= \App\View::e(json_encode($galleryAssets ?? [])) ?>">
              <label><span>Objet de l'email</span><input class="input" type="text" name="subject" value="<?= \App\View::e($selected['subject']) ?>"></label>
              <details class="code-box">
                <summary>Corps de l'email (HTML)</summary>
                <div class="template-toolbar">
                  <div class="insert-var-dropdown">
                    <button type="button" class="btn-secondary btn-sm" data-insert-dropdown-toggle>📋 Insérer variable ▾</button>
                    <div class="insert-var-menu" hidden>
                      <?php foreach ($plainVariables as $variable): ?>
                        <button type="button" class="insert-var-item" data-insert-variable="<?= \App\View::e($variable) ?>"><?= \App\View::e($variable) ?></button>
                      <?php endforeach; ?>
                      <div style="padding:.4rem .9rem;font-size:.8rem;color:#6b7280;border-top:1px solid #e5e7eb;">Variables image avec taille</div>
                      <?php foreach ($resizableVariables as $variable): ?>
                        <button
                          type="button"
                          class="insert-var-item"
                          data-insert-variable="<?= \App\View::e($variable['name']) ?>"
                          data-variable-resizable="1"
                          data-variable-default-size="<?= (int) $variable['default'] ?>"
                        ><?= \App\View::e($variable['label']) ?> · taille</button>
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
            <form method="post" action="<?= $baseUrl ?>/<?= $selectedPartnerId ?>/<?= (int) $selected['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr ? Cette action est irréversible.');">
              <button class="btn-secondary" type="submit">Supprimer</button>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
