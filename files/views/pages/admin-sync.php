<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Synchronisation Lodgify</h1>
  <p class="subtitle">Force la mise à jour du cache des propriétés depuis l'API Lodgify.</p>
  <div class="card card-body center stack-md">
    <div class="emoji-xl">🔄</div>
    <?php if (!empty($lastSyncLabel)): ?>
      <p class="text-muted"><?= \App\View::e($lastSyncLabel) ?></p>
    <?php else: ?>
      <p class="text-muted">Aucune synchronisation n'a encore été effectuée.</p>
    <?php endif; ?>
    <p>Cette action efface le cache local et recharge toutes les propriétés (fiche, photos, description) depuis Lodgify. C'est la seule façon de rafraîchir ces données : elles ne sont plus synchronisées automatiquement.</p>
    <form method="post" action="/admin/sync"><button class="btn-primary" type="submit">Synchroniser maintenant</button></form>
  </div>
</section>
