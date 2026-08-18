<?php declare(strict_types=1);
$rid = (int) $request['id'];
$status = (string) $request['status'];
// Single source of truth for the "< 3 ans" / "3-12 ans" split, computed by
// PageController::reservationPublic() via ReservationsController::
// childBreakdownValues() (which self-heals a database missing the
// migration-018 breakdown columns) — never re-derived here, so the counts
// shown can't drift from what every other part of the app reads.
$childrenUnder = (int) $childrenUnder3;
$children3to12v = (int) $children3to12;
$guests = is_array($request['guests'] ?? null) ? $request['guests'] : [];
$quoteCurrency = (string) ($request['quote_currency'] ?? 'EUR');
$hasQuote = ($request['quote_room_total'] ?? null) !== null;
$nationalitiesSummary = \App\controllers\ReservationsController::guestNationalitiesText($guests);
?>
<section class="container section-lg narrow-wide reservation-public-page">
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
    <p class="muted">Votre demande est modifiable ci-dessous : cliquez sur « Modifier » pour changer le nom, les dates, les voyageurs, la nationalité ou l'hébergement. Le tarif et la disponibilité seront recalculés automatiquement avant l'envoi.</p>
  <?php endif; ?>

  <div class="card card-body stack-md reservation-public-card">
    <div class="section-header">
      <h2 class="section-title">Détails de la demande</h2>
      <?php if ($editable): ?>
        <button type="button" class="btn-secondary" data-reservation-edit-toggle>Modifier</button>
      <?php endif; ?>
    </div>

    <!-- Read-only view: always the default, even while the request is still
         pending (see PageController::reservationPublic()) — editing is only
         ever enabled by explicitly clicking "Modifier" above. -->
    <div data-reservation-view class="reservation-summary">
      <div class="reservation-summary-grid">
        <div class="reservation-summary-item"><span class="muted">Nom</span><strong><?= \App\View::e($request['client_name']) ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Email</span><strong><?= \App\View::e($request['client_email']) ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Hébergement</span><strong><?= \App\View::e($request['property_name'] ?: '—') ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Arrivée</span><strong><?= \App\View::e(\App\controllers\ReservationsController::formatDateShortFr((string) $request['checkin_date'])) ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Départ</span><strong><?= \App\View::e(\App\controllers\ReservationsController::formatDateShortFr((string) $request['checkout_date'])) ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Voyageurs</span><strong><?= (int) $request['adults'] ?> adulte(s), <?= $children3to12v ?> enfant(s), <?= $childrenUnder ?> bébé(s)</strong></div>
        <?php if ($nationalitiesSummary !== ''): ?>
          <div class="reservation-summary-item reservation-summary-wide"><span class="muted">Nationalité(s)</span><strong><?= $nationalitiesSummary /* already HTML-escaped by guestNationalitiesText() */ ?></strong></div>
        <?php endif; ?>
        <?php if (trim((string) ($request['message'] ?? '')) !== ''): ?>
          <div class="reservation-summary-item reservation-summary-wide"><span class="muted">Note du client</span><strong><?= nl2br(\App\View::e((string) $request['message'])) ?></strong></div>
        <?php endif; ?>
      </div>

      <?php if ($hasQuote): ?>
        <div class="quote-box reservation-quote-box">
          <div class="quote-line"><span>Hébergement (<?= (int) ($request['quote_nights'] ?? 0) ?> nuit(s))</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_room_total'] ?? 0), $quoteCurrency)) ?></span></div>
          <?php if ((float) ($request['quote_extra_person_total'] ?? 0) > 0): ?>
            <div class="quote-line"><span>Personne(s) supplémentaire(s)</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_extra_person_total'] ?? 0), $quoteCurrency)) ?></span></div>
          <?php endif; ?>
          <?php if ((float) ($request['quote_cleaning_total'] ?? 0) > 0): ?>
            <div class="quote-line"><span>Frais de ménage</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_cleaning_total'] ?? 0), $quoteCurrency)) ?></span></div>
          <?php endif; ?>
          <?php if ((float) ($request['quote_tourist_tax_total'] ?? 0) > 0): ?>
            <div class="quote-line"><span>Taxe de séjour</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_tourist_tax_total'] ?? 0), $quoteCurrency)) ?></span></div>
          <?php endif; ?>
          <div class="quote-line quote-line-total"><span>Total voyageur</span><strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_total_traveler'] ?? 0), $quoteCurrency)) ?></strong></div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($editable): ?>
      <div data-reservation-edit-form hidden>
        <form method="post" action="/r/<?= \App\View::e($token) ?>/update" class="stack-md" data-reservation-edit-quote-form data-reservation-token="<?= \App\View::e($token) ?>" data-max-guests="0">
          <input type="hidden" name="guests_json" data-guests-json value="">
          <input type="hidden" name="children" value="<?= $childrenUnder + $children3to12v ?>">
          <input type="hidden" name="property_id" value="<?= (int) $request['property_id'] ?>" data-reservation-property-id>
          <div class="form-grid cols-2">
            <label><span>Nom complet</span><input class="input" type="text" name="client_name" value="<?= \App\View::e($request['client_name']) ?>" required></label>
            <label><span>Email</span><input class="input" type="email" name="client_email" value="<?= \App\View::e($request['client_email']) ?>" required></label>
            <label><span>Téléphone</span><input class="input" type="tel" name="client_phone" value="<?= \App\View::e((string) ($request['client_phone'] ?? '')) ?>"></label>
          </div>
          <div class="form-grid cols-2">
            <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin_date" value="<?= \App\View::e($request['checkin_date']) ?>" required data-reservation-quote-field></label>
            <label><span>Date de départ</span><input class="input" type="date" name="checkout_date" value="<?= \App\View::e($request['checkout_date']) ?>" required data-reservation-quote-field></label>
            <label><span>Adultes</span><input class="input" type="number" min="1" max="20" name="adults" value="<?= (int) $request['adults'] ?>" required data-reservation-quote-field></label>
            <label><span>Enfants (3-12 ans)</span><input class="input" type="number" min="0" max="20" name="children_3to12" value="<?= $children3to12v ?>" data-reservation-quote-field></label>
            <label><span>Bébés (- 3 ans)</span><input class="input" type="number" min="0" max="2" name="children_under3" value="<?= $childrenUnder ?>" data-reservation-quote-field></label>
          </div>

          <div class="stack-sm">
            <h3 class="reservation-subheading">Nationalité</h3>
            <?php $initialGuests = $guests; require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
          </div>

          <div class="stack-sm">
            <h3 class="reservation-subheading">Hébergement</h3>
            <div class="reservation-property-current">
              <strong data-reservation-property-name><?= \App\View::e($request['property_name'] ?: '—') ?></strong>
              <button type="button" class="btn-secondary" data-reservation-change-property>Changer d'hébergement</button>
            </div>
          </div>

          <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"><?= \App\View::e((string) ($request['message'] ?? '')) ?></textarea></label>

          <div class="quote-box reservation-quote-box" data-reservation-live-quote>
            <div class="quote-line"><span>Dernier tarif enregistré</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_total_traveler'] ?? 0), $quoteCurrency)) ?></span></div>
            <p class="muted reservation-quote-hint" data-reservation-quote-hint>Modifiez les dates, les voyageurs ou l'hébergement ci-dessus pour recalculer le tarif automatiquement.</p>
          </div>

          <div class="button-row">
            <button class="btn-secondary" type="button" data-reservation-edit-cancel>Annuler la modification</button>
            <button class="btn-primary" type="submit">Renvoyer la demande modifiée</button>
          </div>
        </form>
      </div>
      <form method="post" action="/r/<?= \App\View::e($token) ?>/cancel" onsubmit="return confirm('Annuler définitivement cette demande de réservation ?');">
        <div class="button-row"><button class="btn-secondary danger" type="submit">Annuler la demande</button></div>
      </form>

      <!-- "Changer d'hébergement" modal: lists only properties available
           according to this app's own local reservations (not Lodgify's
           live calendar) for the dates/party size currently entered above —
           see ReservationsController::publicAvailableProperties(). -->
      <div class="simple-modal-overlay" data-reservation-property-modal hidden>
        <div class="simple-modal-dialog" role="dialog" aria-modal="true" aria-label="Changer d'hébergement">
          <div class="simple-modal-header">
            <h3>Changer d'hébergement</h3>
            <button type="button" class="btn-icon-plain" data-reservation-property-modal-close aria-label="Fermer">✕</button>
          </div>
          <div class="reservation-modal-summary" data-reservation-modal-summary></div>
          <div class="reservation-modal-list" data-reservation-modal-list>
            <p class="muted">Chargement des biens disponibles…</p>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
