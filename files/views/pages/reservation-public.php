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
// Once a devis exists, the "Changer d'hébergement" button is hidden by
// default (migration 047, client_can_change_property) unless the partner
// re-enabled it — single source of truth reused for both the button itself
// and the live-quote hint text below it.
$canChangeProperty = !$hasQuote || !empty($request['client_can_change_property']);
$nationalitiesSummary = \App\controllers\ReservationsController::guestNationalitiesText($guests);
$property = $property ?? null;
$propertyPhotoUrl = $property['images'][0]['url'] ?? '';
$propertyDescription = $property ? trim(\App\View::localized($property, 'description')) : '';
$needsClientEmail = !empty($needsClientEmail);
?>
<section class="container section-lg narrow-wide reservation-public-page">
  <div class="section-header">
    <h1>Ma demande de réservation #<?= $rid ?></h1>
    <span class="badge badge-<?= \App\View::e($status) ?>"><?= \App\View::e(\App\View::badgeLabel($status)) ?></span>
  </div>

  <?php if ($needsClientEmail): ?>
    <!-- Blocking email-entry gate (see ReservationsController::
         publicRequestNeedsClientEmail()): the request's client_email is
         either blank or still the partner's own address, so the client
         must supply their own real email before seeing/editing anything
         else on this page — the partner's mailbox must never end up as
         "the client's email" on a reservation request. -->
    <div class="card card-body stack-md reservation-public-card">
      <h2 class="section-title">Votre adresse email</h2>
      <p class="muted">Merci de renseigner votre adresse email pour accéder au détail de votre demande de réservation.</p>
      <form method="post" action="/r/<?= \App\View::e($token) ?>/email" class="form-grid cols-2">
        <label><span>Email</span><input class="input" type="email" name="client_email" required autofocus></label>
        <div class="button-row"><button class="btn-primary" type="submit">Continuer</button></div>
      </form>
    </div>
  <?php else: ?>

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

    <!-- Selected property's photo/gallery — always visible, whether or not
         the request is being edited (see the "Voir galerie photo" button,
         kept usable in read-only mode per its own requirement). The
         description itself is shown inside the gallery modal, under the
         photos (see initReservationPublicPhotoGallery() in assets/js/app.js),
         not here. Updated in place by initReservationPublicPropertyPicker()
         in assets/js/app.js whenever a new property is chosen from the
         "Changer d'hébergement" modal. -->
    <div class="reservation-property-photo-block" data-reservation-property-photo-block>
      <?php if ($propertyPhotoUrl !== ''): ?>
        <img class="reservation-property-photo" data-reservation-property-photo src="<?= \App\View::e($propertyPhotoUrl) ?>" alt="<?= \App\View::e($request['property_name'] ?: '') ?>">
      <?php endif; ?>
      <div class="button-row">
        <button type="button" class="btn-secondary" data-reservation-view-gallery data-reservation-gallery-property-id="<?= (int) $request['property_id'] ?>">Voir galerie photo</button>
      </div>
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
        <form method="post" action="/r/<?= \App\View::e($token) ?>/update" class="stack-md" data-reservation-edit-quote-form data-reservation-base-url="/r/<?= \App\View::e($token) ?>" data-max-guests="0">
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
              <div class="button-row">
                <a class="btn-secondary" target="_blank" rel="noopener" data-reservation-view-property-link href="/properties/<?= (int) $request['property_id'] ?>#rates-availability">Voir le bien</a>
                <?php if ($canChangeProperty): ?>
                  <!-- Once a devis exists this button is hidden by default
                       (migration 047, client_can_change_property): the
                       partner must explicitly re-enable it per request via
                       the checkbox on /partner/reservations/{id} before the
                       client can swap properties themselves again. -->
                  <button type="button" class="btn-secondary" data-reservation-change-property>Changer d'hébergement</button>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"><?= \App\View::e((string) ($request['message'] ?? '')) ?></textarea></label>

          <div class="quote-box reservation-quote-box" data-reservation-live-quote>
            <div class="quote-line"><span>Dernier tarif enregistré</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($request['quote_total_traveler'] ?? 0), $quoteCurrency)) ?></span></div>
            <p class="muted reservation-quote-hint" data-reservation-quote-hint><?php if ($canChangeProperty): ?>Modifiez les dates, les voyageurs ou l'hébergement ci-dessus pour recalculer le tarif automatiquement.<?php else: ?>Modifiez les dates, les voyageurs ci-dessus pour recalculer le tarif automatiquement.<?php endif; ?></p>
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

      <!-- "Changer d'hébergement" modal: lists properties available for the
           dates/party size currently entered above, filtered by this app's
           own local reservations only — see
           ReservationsController::publicAvailableProperties(). -->
      <div class="simple-modal-overlay" data-reservation-property-modal hidden>
        <div class="simple-modal-dialog" role="dialog" aria-modal="true" aria-label="Changer d'hébergement">
          <div class="simple-modal-header">
            <h3>Changer d'hébergement</h3>
            <button type="button" class="btn-icon-plain" data-reservation-property-modal-close aria-label="Fermer">✕</button>
          </div>
          <p class="muted reservation-modal-price-note">Les tarifs affichés correspondent au tarif de la chambre et des personnes supplémentaires uniquement (hors frais de ménage et taxe de séjour).</p>
          <div class="reservation-modal-summary" data-reservation-modal-summary></div>
          <div class="reservation-modal-list" data-reservation-modal-list>
            <p class="muted">Chargement des biens disponibles…</p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- "Voir galerie photo" modal: shows every photo Lodgify has for
         whichever property is currently selected (fetched on demand from
         PageController::reservationPublicPropertyPhotos(), so it stays
         correct right after picking a new property above, before any page
         reload). Kept outside the "$editable" block so it's still usable
         in read-only mode. -->
    <div class="simple-modal-overlay" data-reservation-gallery-modal data-reservation-base-url="/r/<?= \App\View::e($token) ?>" hidden>
      <div class="simple-modal-dialog simple-modal-dialog-wide" role="dialog" aria-modal="true" aria-label="Galerie photo">
        <div class="simple-modal-header">
          <h3>Galerie photo</h3>
          <button type="button" class="btn-icon-plain" data-reservation-gallery-modal-close aria-label="Fermer">✕</button>
        </div>
        <div class="gallery-main" data-reservation-gallery-main-wrap>
          <img class="reservation-gallery-main-img" data-reservation-gallery-main src="" alt="">
        </div>
        <div class="gallery-carousel" data-reservation-gallery-carousel>
          <div class="gallery-carousel-track" data-reservation-gallery-thumbs>
            <p class="muted">Chargement des photos…</p>
          </div>
        </div>
        <?php if ($propertyDescription !== ''): ?>
          <div class="reservation-property-description-block" data-reservation-gallery-description-block>
            <p class="muted reservation-property-description" data-reservation-gallery-description><?= \App\View::plainText($propertyDescription, 400) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>
