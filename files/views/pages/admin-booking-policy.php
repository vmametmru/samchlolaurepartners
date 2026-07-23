<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1>Politique de réservation</h1>
  <div class="card card-body stack-md">
    <p class="muted">Ce texte s'affiche sous le calendrier sur chaque page hébergement et est disponible dans les templates d'emails via la variable <code>{{politique_reservation}}</code>.</p>
    <form method="post" action="/admin/politique-reservation" class="stack-md">
      <label><span>Texte de la politique de réservation</span><textarea class="input" name="policy_text" rows="12"><?= \App\View::e($policyText) ?></textarea></label>
      <button class="btn-primary" type="submit">Sauvegarder</button>
    </form>
  </div>
</section>
