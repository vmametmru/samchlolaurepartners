<?php declare(strict_types=1);
$rid = (int) $request['id'];
$status = (string) $request['status'];
$childrenUnder = $request['children_under3'] ?? $request['children_under5'] ?? 0;
$children3to12 = $request['children_3to12'] ?? $request['children_5to12'] ?? 0;
$guests = is_array($request['guests'] ?? null) ? $request['guests'] : [];
$existingAdultNationality = '';
$existingChildNationality = '';
foreach ($guests as $guest) {
  $type = (string) ($guest['type'] ?? 'adult');
  $nationality = (string) ($guest['nationality'] ?? '');
  if ($type === 'adult' && $existingAdultNationality === '') {
    $existingAdultNationality = $nationality;
  } elseif ($type !== 'adult' && $existingChildNationality === '') {
    $existingChildNationality = $nationality;
  }
}
$nationalities = ['Mauricienne', 'Française', 'Britannique', 'Allemande', 'Italienne', 'Espagnole', 'Belge', 'Suisse', 'Américaine', 'Australienne', 'Autre'];
?>
<section class="container section-lg narrow-wide">
  <div class="section-header">
    <h1>Ma demande de réservation #<?= $rid ?></h1>
    <span class="badge badge-<?= \App\View::e($status) ?>"><?= \App\View::e(\App\View::badgeLabel($status)) ?></span>
  </div>

  <?php if ($status === 'confirmed'): ?>
    <div class="alert alert-success">
      Votre demande est <strong>confirmée</strong>. Elle ne peut plus être modifiée ou annulée en ligne : contactez directement l'agence pour tout changement.
    </div>
  <?php elseif ($status === 'cancelled'): ?>
    <div class="alert alert-error">
      Cette demande a été <strong>annulée</strong>. Contactez l'agence si vous souhaitez la réactiver.
    </div>
  <?php else: ?>
    <p class="muted">Vous pouvez modifier votre demande ci-dessous (nom, dates, voyageurs, nationalité, hébergement) : le tarif et la disponibilité seront recalculés automatiquement avant l'envoi.</p>
  <?php endif; ?>

  <div class="card card-body stack-md">
    <h2 class="section-title">Détails de la demande</h2>
    <?php if (!$editable): ?>
      <div class="form-grid cols-2 compact-grid">
        <div><span class="muted">Nom :</span> <strong><?= \App\View::e($request['client_name']) ?></strong></div>
        <div><span class="muted">Email :</span> <?= \App\View::e($request['client_email']) ?></div>
        <div><span class="muted">Hébergement :</span> <strong><?= \App\View::e($request['property_name'] ?: '—') ?></strong></div>
        <div><span class="muted">Arrivée :</span> <?= \App\View::e($request['checkin_date']) ?></div>
        <div><span class="muted">Départ :</span> <?= \App\View::e($request['checkout_date']) ?></div>
        <div><span class="muted">Voyageurs :</span> <?= (int) $request['adults'] ?> adulte(s), <?= (int) $children3to12 ?> enfant(s), <?= (int) $childrenUnder ?> bébé(s)</div>
      </div>
      <?php if (($request['quote_room_total'] ?? null) !== null): ?>
        <?php $quoteCurrency = (string) ($request['quote_currency'] ?? 'EUR'); ?>
        <div class="form-grid cols-2 compact-grid">
          <div><span class="muted">Total voyageur :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_total_traveler'] ?? 0), $quoteCurrency)) ?></strong></div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" action="/r/<?= \App\View::e($token) ?>/update" class="stack-md">
        <div class="form-grid cols-2">
          <label><span>Nom complet</span><input class="input" type="text" name="client_name" value="<?= \App\View::e($request['client_name']) ?>" required></label>
          <label><span>Email</span><input class="input" type="email" name="client_email" value="<?= \App\View::e($request['client_email']) ?>" required></label>
          <label><span>Téléphone</span><input class="input" type="tel" name="client_phone" value="<?= \App\View::e((string) ($request['client_phone'] ?? '')) ?>"></label>
          <label>
            <span>Hébergement</span>
            <select class="input" name="property_id" required>
              <?php foreach ($properties as $property): ?>
                <option value="<?= (int) $property['id'] ?>"<?= (int) $property['id'] === (int) $request['property_id'] ? ' selected' : '' ?>><?= \App\View::e((string) $property['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin_date" value="<?= \App\View::e($request['checkin_date']) ?>" required></label>
          <label><span>Date de départ</span><input class="input" type="date" name="checkout_date" value="<?= \App\View::e($request['checkout_date']) ?>" required></label>
          <label><span>Adultes</span><input class="input" type="number" min="1" max="20" name="adults" value="<?= (int) $request['adults'] ?>" required></label>
          <label><span>Enfants (3-12 ans)</span><input class="input" type="number" min="0" max="20" name="children_3to12" value="<?= (int) $children3to12 ?>"></label>
          <label><span>Bébés (- 3 ans)</span><input class="input" type="number" min="0" max="2" name="children_under3" value="<?= (int) $childrenUnder ?>"></label>
          <label>
            <span>Nationalité (adultes)</span>
            <select class="input" name="adult_nationality">
              <option value="">Sélectionner...</option>
              <?php foreach ($nationalities as $nationality): ?><option value="<?= \App\View::e($nationality) ?>"<?= $nationality === $existingAdultNationality ? ' selected' : '' ?>><?= \App\View::e($nationality) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Nationalité (enfants, si différente)</span>
            <select class="input" name="child_nationality">
              <option value="">Même que les adultes</option>
              <?php foreach ($nationalities as $nationality): ?><option value="<?= \App\View::e($nationality) ?>"<?= $nationality === $existingChildNationality && $existingChildNationality !== $existingAdultNationality ? ' selected' : '' ?>><?= \App\View::e($nationality) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"><?= \App\View::e((string) ($request['message'] ?? '')) ?></textarea></label>
        <?php if (($request['quote_room_total'] ?? null) !== null): ?>
          <?php $quoteCurrency = (string) ($request['quote_currency'] ?? 'EUR'); ?>
          <div class="quote-box">
            <div class="quote-line"><span>Tarif actuel (avant modification)</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_total_traveler'] ?? 0), $quoteCurrency)) ?></span></div>
          </div>
          <p class="muted">Le tarif ci-dessus correspond à votre dernière demande envoyée ; il sera recalculé automatiquement d'après les disponibilités et tarifs actuels lorsque vous renverrez votre demande modifiée.</p>
        <?php endif; ?>
        <div class="button-row">
          <button class="btn-primary" type="submit">Renvoyer la demande modifiée</button>
        </div>
      </form>
      <form method="post" action="/r/<?= \App\View::e($token) ?>/cancel" onsubmit="return confirm('Annuler définitivement cette demande de réservation ?');">
        <div class="button-row"><button class="btn-secondary danger" type="submit">Annuler la demande</button></div>
      </form>
    <?php endif; ?>
  </div>
</section>
