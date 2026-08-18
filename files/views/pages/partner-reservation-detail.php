<?php declare(strict_types=1);
$rid = (int) $reservation['id'];
$status = (string) $reservation['status'];
$editable = $status === 'pending';
$childrenUnder = (int) ($childrenUnder3 ?? 0);
$children3to12v = (int) ($children3to12 ?? 0);
$guests = is_array($reservation['guests'] ?? null) ? $reservation['guests'] : [];
$nationalitiesSummary = \App\controllers\ReservationsController::guestNationalitiesText($guests);
$property = $property ?? null;
$propertyPhotoUrl = $property['images'][0]['url'] ?? '';
$propertyDescription = $property ? trim(\App\View::localized($property, 'description')) : '';
?>
<section class="container section-lg narrow-wide">
  <a class="text-link" href="/partner/reservations">← Retour</a>
  <div class="section-header">
    <h1>Demande #<?= $rid ?></h1>
    <span class="badge badge-<?= \App\View::e($status) ?>"><?= \App\View::e(\App\View::badgeLabel($status)) ?></span>
  </div>
  <?php if (!empty($reservation['public_url'])): ?>
    <div class="card card-body stack-sm">
      <h2 class="section-title">Partager le lien</h2>
      <p class="muted">Envoyez ce lien au client pour qu'il ouvre une copie en ligne de sa demande, la modifie (dates, voyageurs, nationalité, bien) si besoin, et la renvoie.</p>
      <div class="button-row">
        <a class="btn-secondary" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text=<?= urlencode('Voici le lien de votre demande de réservation : ' . $reservation['public_url']) ?>">💬 Partager sur WhatsApp</a>
        <button type="button" class="btn-secondary" data-copy-link="<?= \App\View::e($reservation['public_url']) ?>">🔗 Copier le lien</button>
      </div>
    </div>
  <?php endif; ?>
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
    <div class="section-header">
      <h2 class="section-title">Détails du séjour</h2>
      <?php if ($editable): ?>
        <button type="button" class="btn-secondary" data-reservation-edit-toggle>Modifier</button>
      <?php endif; ?>
    </div>

    <!-- Selected property's photo/gallery — same "Voir galerie photo" modal
         and "Changer d'hébergement" picker as the client's own public link
         (files/views/pages/reservation-public.php), reusing the exact same
         assets/js/app.js initReservationPublic*() functions via generic
         data-reservation-base-url attributes instead of a public token. -->
    <div class="reservation-property-photo-block" data-reservation-property-photo-block>
      <?php if ($propertyPhotoUrl !== ''): ?>
        <img class="reservation-property-photo" data-reservation-property-photo src="<?= \App\View::e($propertyPhotoUrl) ?>" alt="<?= \App\View::e($reservation['property_name'] ?: '') ?>">
      <?php endif; ?>
      <div class="button-row">
        <button type="button" class="btn-secondary" data-reservation-view-gallery data-reservation-gallery-property-id="<?= (int) $reservation['property_id'] ?>">Voir galerie photo</button>
      </div>
    </div>

    <div data-reservation-view class="reservation-summary">
      <div class="reservation-summary-grid">
        <div class="reservation-summary-item"><span class="muted">Hébergement</span><strong><?= \App\View::e($reservation['property_name'] ?: '—') ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Arrivée</span><strong><?= \App\View::e(\App\controllers\ReservationsController::formatDateShortFr((string) $reservation['checkin_date'])) ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Départ</span><strong><?= \App\View::e(\App\controllers\ReservationsController::formatDateShortFr((string) $reservation['checkout_date'])) ?></strong></div>
        <div class="reservation-summary-item"><span class="muted">Voyageurs</span><strong><?= (int) $reservation['adults'] ?> adulte(s), <?= $children3to12v ?> enfant(s), <?= $childrenUnder ?> bébé(s)</strong></div>
        <?php if ($nationalitiesSummary !== ''): ?>
          <div class="reservation-summary-item reservation-summary-wide"><span class="muted">Nationalité(s)</span><strong><?= $nationalitiesSummary /* already HTML-escaped by guestNationalitiesText() */ ?></strong></div>
        <?php endif; ?>
        <?php if (trim((string) ($reservation['message'] ?? '')) !== ''): ?>
          <div class="reservation-summary-item reservation-summary-wide"><span class="muted">Message</span><strong><?= nl2br(\App\View::e((string) $reservation['message'])) ?></strong></div>
        <?php endif; ?>
      </div>
      <?php if ((int) ($reservation['property_id'] ?? 0) > 0): ?>
        <?php
          $propertyLinkParams = [
            'arrival' => (string) $reservation['checkin_date'],
            'departure' => (string) $reservation['checkout_date'],
            'adults' => (int) $reservation['adults'],
            'children' => $children3to12v ?: (int) ($reservation['children'] ?? 0),
          ];
          $propertyLinkUrl = '/properties/' . (int) $reservation['property_id'] . '?' . http_build_query($propertyLinkParams);
        ?>
        <div class="button-row">
          <a class="btn-secondary" href="<?= \App\View::e($propertyLinkUrl) ?>" target="_blank" rel="noopener">
            🏠 Voir le bien avec ces dates
          </a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($editable): ?>
      <div data-reservation-edit-form hidden>
        <form method="post" action="/partner/reservations/<?= $rid ?>/update" class="stack-md" data-reservation-edit-quote-form data-reservation-base-url="/partner/reservations/<?= $rid ?>" data-max-guests="0">
          <input type="hidden" name="guests_json" data-guests-json value="">
          <input type="hidden" name="children" value="<?= $childrenUnder + $children3to12v ?>">
          <input type="hidden" name="property_id" value="<?= (int) $reservation['property_id'] ?>" data-reservation-property-id>
          <div class="form-grid cols-2">
            <label><span>Nom complet</span><input class="input" type="text" name="client_name" value="<?= \App\View::e($reservation['client_name']) ?>" required></label>
            <label><span>Email</span><input class="input" type="email" name="client_email" value="<?= \App\View::e($reservation['client_email']) ?>" required></label>
            <label><span>Téléphone</span><input class="input" type="tel" name="client_phone" value="<?= \App\View::e((string) ($reservation['client_phone'] ?? '')) ?>"></label>
          </div>
          <div class="form-grid cols-2">
            <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin_date" value="<?= \App\View::e($reservation['checkin_date']) ?>" required data-reservation-quote-field></label>
            <label><span>Date de départ</span><input class="input" type="date" name="checkout_date" value="<?= \App\View::e($reservation['checkout_date']) ?>" required data-reservation-quote-field></label>
          </div>
          <div class="form-grid cols-3">
            <label><span>Adultes</span><input class="input" type="number" min="1" max="20" name="adults" value="<?= (int) $reservation['adults'] ?>" required data-reservation-quote-field></label>
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
              <strong data-reservation-property-name><?= \App\View::e($reservation['property_name'] ?: '—') ?></strong>
              <div class="button-row">
                <a class="btn-secondary" target="_blank" rel="noopener" data-reservation-view-property-link href="/properties/<?= (int) $reservation['property_id'] ?>#rates-availability">Voir le bien</a>
                <button type="button" class="btn-secondary" data-reservation-change-property>Changer d'hébergement</button>
              </div>
            </div>
          </div>

          <label><span>Message (optionnel)</span><textarea class="input" rows="3" name="message"><?= \App\View::e((string) ($reservation['message'] ?? '')) ?></textarea></label>

          <div class="quote-box reservation-quote-box" data-reservation-live-quote>
            <div class="quote-line"><span>Dernier tarif enregistré</span><span><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr((float) ($reservation['quote_total_traveler'] ?? 0), (string) ($reservation['quote_currency'] ?? 'EUR'))) ?></span></div>
            <p class="muted reservation-quote-hint" data-reservation-quote-hint>Modifiez les dates, les voyageurs ou l'hébergement ci-dessus pour recalculer le tarif automatiquement.</p>
          </div>

          <div class="button-row">
            <button class="btn-secondary" type="button" data-reservation-edit-cancel>Annuler la modification</button>
            <label class="inline-check"><input type="checkbox" name="skip_client_notification" value="1"> Ne pas notifier le client par email</label>
            <button class="btn-primary" type="submit">Enregistrer les modifications</button>
          </div>
        </form>
      </div>

      <!-- "Changer d'hébergement" modal — see reservation-public.php for
           the shared markup/behaviour (initReservationPublicPropertyPicker()
           in assets/js/app.js), scoped here to this partner's own tenant via
           PageController::partnerReservationAvailableProperties(). -->
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

    <!-- "Voir galerie photo" modal — kept outside the "$editable" block so
         it's still usable while confirmed/cancelled, matching
         reservation-public.php. -->
    <div class="simple-modal-overlay" data-reservation-gallery-modal data-reservation-base-url="/partner/reservations/<?= $rid ?>" hidden>
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
        <?php
          // Shows 0,00 EUR (never hidden) when the property isn't
          // registered for VAT, so partners always see the row and know
          // it isn't simply missing.
          $quoteVatTotal = \App\controllers\ReservationsController::vatTotalFromStoredQuote(
            (float) ($reservation['quote_room_total'] ?? 0),
            (float) ($reservation['quote_extra_person_total'] ?? 0),
            (float) ($reservation['quote_vat_rate'] ?? 0)
          );
        ?>
        <div><span class="muted">TVA totale :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr($quoteVatTotal, $quoteCurrency)) ?></strong></div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($status !== 'pending'): ?>
    <div class="card card-body stack-md">
      <h2 class="section-title">Statut</h2>
      <div class="form-grid cols-2 compact-grid">
        <?php if (!empty($reservation['confirmed_at'])): ?><div><span class="muted">Confirmée le :</span> <?= \App\View::e($reservation['confirmed_at']) ?></div><?php endif; ?>
        <?php if (!empty($reservation['cancelled_at'])): ?><div><span class="muted">Annulée le :</span> <?= \App\View::e($reservation['cancelled_at']) ?></div><?php endif; ?>
      </div>
      <?php if (!empty($reservation['notes'])): ?><div><span class="muted">Notes internes :</span><p class="message-box"><?= nl2br(\App\View::e($reservation['notes'])) ?></p></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="card card-body stack-md">
    <h2 class="section-title">Action</h2>
    <?php if ($status === 'pending'): ?>
      <p class="muted">Veuillez d'abord réserver manuellement sur mauritius-booking.com, puis confirmer ici pour notifier le client.</p>
      <form method="post" action="/partner/reservations/<?= $rid ?>/confirm" class="stack-md">
        <label><span>Notes internes (optionnel)</span><textarea class="input" name="notes" rows="3"><?= \App\View::e($reservation['notes'] ?? '') ?></textarea></label>
        <div class="button-row">
          <button class="btn-primary" type="submit">▶️ Confirmer la réservation</button>
        </div>
      </form>
      <form method="post" action="/partner/reservations/<?= $rid ?>/cancel" onsubmit="return confirm('Annuler cette réservation ?');">
        <div class="button-row"><button class="btn-secondary danger" type="submit">❌ Annuler</button></div>
      </form>
    <?php elseif ($status === 'confirmed'): ?>
      <div class="button-row">
        <form method="post" action="/partner/reservations/<?= $rid ?>/reopen">
          <button class="btn-secondary" type="submit">⏸️ Repasser en attente</button>
        </form>
        <form method="post" action="/partner/reservations/<?= $rid ?>/cancel" onsubmit="return confirm('Annuler cette réservation ?');">
          <button class="btn-secondary danger" type="submit">❌ Annuler</button>
        </form>
      </div>
    <?php else: ?>
      <div class="button-row">
        <form method="post" action="/partner/reservations/<?= $rid ?>/confirm">
          <button class="btn-primary" type="submit">▶️ Confirmer la réservation</button>
        </form>
        <form method="post" action="/partner/reservations/<?= $rid ?>/reopen">
          <button class="btn-secondary" type="submit">⏸️ Repasser en attente</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</section>
