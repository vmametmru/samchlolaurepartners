<?php declare(strict_types=1);
$labels = [
  'REQUEST_RECEIVED_PARTNER' => 'Demande reçue (partenaire)',
  'REQUEST_RECEIVED_CLIENT' => 'Accusé réception (client)',
  'RESERVATION_CONFIRMED' => 'Réservation confirmée (client)',
  'RESERVATION_CANCELLED' => 'Réservation annulée (client)',
  'REMINDER' => 'Rappel avant arrivée',
];
$variables = ['{{nom_client}}','{{email_client}}','{{telephone_client}}','{{dates}}','{{date_arrivee}}','{{date_depart}}','{{adultes}}','{{enfants}}','{{hebergement}}','{{photo_bien}}','{{partenaire}}','{{notes}}','{{message}}'];
?>
<section class="container section-lg">
  <h1>Templates d'emails</h1>
  <div class="two-panel">
    <div class="card overflow-hidden side-list">
      <?php if ($templates === []): ?>
        <p class="empty-state">Aucun template. Contactez l'administrateur.</p>
      <?php else: foreach ($templates as $template): ?>
        <a href="/partner/templates?id=<?= (int) $template['id'] ?>" class="<?= $selected && (int) $selected['id'] === (int) $template['id'] ? 'active' : '' ?>"><?= \App\View::e($labels[$template['type']] ?? $template['type']) ?></a>
      <?php endforeach; endif; ?>
    </div>
    <div class="card card-body">
      <?php if (!$selected): ?>
        <p class="empty-state">Sélectionnez un template à éditer.</p>
      <?php else: ?>
        <h2 class="section-title"><?= \App\View::e($labels[$selected['type']] ?? $selected['type']) ?></h2>
        <form method="post" action="/partner/templates/<?= (int) $selected['id'] ?>" class="stack-md" data-template-editor>
          <label><span>Objet de l'email</span><input class="input" type="text" name="subject" value="<?= \App\View::e($selected['subject']) ?>"></label>
          <div>
            <span class="label-inline">Variables disponibles</span>
            <div class="chips"><?php foreach ($variables as $variable): ?><button type="button" class="chip" data-insert-variable="<?= \App\View::e($variable) ?>"><?= \App\View::e($variable) ?></button><?php endforeach; ?></div>
          </div>
          <label><span>Corps de l'email (HTML)</span><textarea class="input codearea" rows="12" name="body_html" data-template-body><?= \App\View::e($selected['body_html']) ?></textarea></label>
          <details class="preview-box" open>
            <summary>Aperçu HTML</summary>
            <iframe class="preview-frame" sandbox="" data-template-preview srcdoc="<?= \App\View::e($selected['body_html']) ?>"></iframe>
          </details>
          <button class="btn-primary" type="submit">Sauvegarder</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
