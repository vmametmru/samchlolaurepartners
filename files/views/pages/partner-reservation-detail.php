<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <a class="text-link" href="/partner/reservations">← Retour</a>
  <div class="section-header">
    <h1>Demande #<?= (int) $reservation['id'] ?></h1>
    <span class="badge badge-<?= \App\View::e($reservation['status']) ?>"><?= \App\View::e(\App\View::badgeLabel((string) $reservation['status'])) ?></span>
  </div>
  <?php
    $childrenUnder = $reservation['children_under3'] ?? $reservation['children_under5'] ?? null;
    $children3to12 = $reservation['children_3to12'] ?? $reservation['children_5to12'] ?? null;
    $guests = is_array($reservation['guests'] ?? null) ? $reservation['guests'] : [];
  ?>
  <div class="card card-body stack-md">
    <h2 class="section-title">Informations client</h2>
    <div class="form-grid cols-2 compact-grid">
      <div><span class="muted">Nom :</span> <strong><?= \App\View::e($reservation['client_name']) ?></strong></div>
      <div><span class="muted">Email :</span> <a class="text-link" href="mailto:<?= \App\View::e($reservation['client_email']) ?>"><?= \App\View::e($reservation['client_email']) ?></a></div>
      <?php if (!empty($reservation['client_phone'])): ?><div><span class="muted">Tél :</span> <?= \App\View::e($reservation['client_phone']) ?></div><?php endif; ?>
      <div><span class="muted">Demande reçue le :</span> <?= \App\View::e($reservation['created_at'] ?? '—') ?></div>
    </div>
  </div>
  <div class="card card-body stack-md">
    <h2 class="section-title">Détails du séjour</h2>
    <div class="form-grid cols-2 compact-grid">
      <div><span class="muted">Hébergement :</span> <strong><?= \App\View::e($reservation['property_name'] ?: '—') ?></strong></div>
      <div><span class="muted">Arrivée :</span> <strong><?= \App\View::e($reservation['checkin_date']) ?></strong></div>
      <div><span class="muted">Départ :</span> <strong><?= \App\View::e($reservation['checkout_date']) ?></strong></div>
      <div>
        <span class="muted">Voyageurs :</span>
        <?= (int) $reservation['adults'] ?> adulte(s)<?php if ($children3to12 !== null || $childrenUnder !== null): ?>,
        <?= (int) ($children3to12 ?? 0) ?> enfant(s) (3-12 ans),
        <?= (int) ($childrenUnder ?? 0) ?> bébé(s) (- 3 ans)
        <?php else: ?>, <?= (int) $reservation['children'] ?> enfant(s)<?php endif; ?>
      </div>
    </div>
    <?php if ($guests !== []): ?>
      <div>
        <span class="muted">Nationalités :</span>
        <ul class="stack-sm">
          <?php foreach ($guests as $i => $guest): ?>
            <li>
              <?php $type = (string) ($guest['type'] ?? 'adult'); ?>
              <?= \App\View::e($type === 'adult' ? 'Adulte' : ($type === 'child' ? 'Enfant' : 'Bébé')) ?> #<?= $i + 1 ?> —
              <?= \App\View::e($guest['nationality'] ?? '—') ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if (!empty($reservation['message'])): ?><div><span class="muted">Message :</span><p class="message-box"><?= nl2br(\App\View::e($reservation['message'])) ?></p></div><?php endif; ?>
  </div>
  <?php if (($reservation['quote_room_total'] ?? null) !== null): ?>
    <?php $quoteCurrency = (string) ($reservation['quote_currency'] ?? 'EUR'); ?>
    <div class="card card-body stack-md">
      <h2 class="section-title">Détail du devis</h2>
      <div class="form-grid cols-2 compact-grid">
        <div><span class="muted">Tarif Normal :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) $reservation['quote_room_total'], $quoteCurrency)) ?></strong></div>
        <div><span class="muted">Commissions Partenaire :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($reservation['quote_commission_total'] ?? 0), $quoteCurrency)) ?></strong></div>
        <div><span class="muted">Personnes Additionnels :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($reservation['quote_extra_person_total'] ?? 0), $quoteCurrency)) ?></strong></div>
        <div><span class="muted">Nettoyage :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($reservation['quote_cleaning_total'] ?? 0), $quoteCurrency)) ?></strong></div>
        <?php if ((float) ($reservation['quote_tourist_tax_total'] ?? 0) > 0): ?>
          <div><span class="muted">Taxe touristique :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) $reservation['quote_tourist_tax_total'], $quoteCurrency)) ?></strong></div>
        <?php endif; ?>
        <div><span class="muted">Total Voyageur :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($reservation['quote_total_traveler'] ?? 0), $quoteCurrency)) ?></strong></div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($reservation['status'] !== 'pending'): ?>
    <div class="card card-body stack-md">
      <h2 class="section-title">Statut</h2>
      <div class="form-grid cols-2 compact-grid">
        <?php if (!empty($reservation['confirmed_at'])): ?><div><span class="muted">Confirmée le :</span> <?= \App\View::e($reservation['confirmed_at']) ?></div><?php endif; ?>
        <?php if (!empty($reservation['cancelled_at'])): ?><div><span class="muted">Annulée le :</span> <?= \App\View::e($reservation['cancelled_at']) ?></div><?php endif; ?>
      </div>
      <?php if (!empty($reservation['notes'])): ?><div><span class="muted">Notes internes :</span><p class="message-box"><?= nl2br(\App\View::e($reservation['notes'])) ?></p></div><?php endif; ?>
    </div>
  <?php endif; ?>
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
