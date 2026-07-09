<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <a class="text-link" href="/partner/reservations">← Retour</a>
  <div class="section-header">
    <h1>Demande #<?= (int) $reservation['id'] ?></h1>
    <span class="badge badge-<?= \App\View::e($reservation['status']) ?>"><?= \App\View::e(\App\View::badgeLabel((string) $reservation['status'])) ?></span>
  </div>
  <div class="card card-body stack-md">
    <h2 class="section-title">Informations client</h2>
    <div class="form-grid cols-2 compact-grid">
      <div><span class="muted">Nom :</span> <strong><?= \App\View::e($reservation['client_name']) ?></strong></div>
      <div><span class="muted">Email :</span> <a class="text-link" href="mailto:<?= \App\View::e($reservation['client_email']) ?>"><?= \App\View::e($reservation['client_email']) ?></a></div>
      <?php if (!empty($reservation['client_phone'])): ?><div><span class="muted">Tél :</span> <?= \App\View::e($reservation['client_phone']) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="card card-body stack-md">
    <h2 class="section-title">Détails du séjour</h2>
    <div class="form-grid cols-2 compact-grid">
      <div><span class="muted">Hébergement :</span> <strong><?= \App\View::e($reservation['property_name'] ?: '—') ?></strong></div>
      <div><span class="muted">Arrivée :</span> <strong><?= \App\View::e($reservation['checkin_date']) ?></strong></div>
      <div><span class="muted">Départ :</span> <strong><?= \App\View::e($reservation['checkout_date']) ?></strong></div>
      <div><span class="muted">Voyageurs :</span> <?= (int) $reservation['adults'] ?> adulte(s), <?= (int) $reservation['children'] ?> enfant(s)</div>
    </div>
    <?php if (!empty($reservation['message'])): ?><div><span class="muted">Message :</span><p class="message-box"><?= nl2br(\App\View::e($reservation['message'])) ?></p></div><?php endif; ?>
  </div>
  <?php if ($reservation['status'] === 'pending'): ?>
    <div class="card card-body stack-md">
      <h2 class="section-title">Action</h2>
      <p class="muted">Veuillez d'abord réserver manuellement sur mauritius-booking.com, puis confirmer ici pour notifier le client.</p>
      <form method="post" action="/partner/reservations/<?= (int) $reservation['id'] ?>/confirm" class="stack-md">
        <label><span>Notes internes (optionnel)</span><textarea class="input" name="notes" rows="3"><?= \App\View::e($reservation['notes'] ?? '') ?></textarea></label>
        <div class="button-row"><button class="btn-primary" type="submit">✓ Confirmer la réservation</button></form>
        <form method="post" action="/partner/reservations/<?= (int) $reservation['id'] ?>/cancel" onsubmit="return confirm('Annuler cette réservation ?');"><button class="btn-secondary danger" type="submit">✕ Annuler</button></form></div>
    </div>
  <?php endif; ?>
</section>
