<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Synchronisation Lodgify</h1>
  <p class="subtitle">Force la mise à jour du cache des propriétés depuis l'API Lodgify.</p>
  <div class="card card-body center stack-md">
    <div class="emoji-xl">🔄</div>
    <p>Cette action efface le cache local et recharge toutes les propriétés depuis Lodgify.</p>
    <form method="post" action="/admin/sync"><button class="btn-primary" type="submit">Synchroniser maintenant</button></form>
  </div>
</section>
