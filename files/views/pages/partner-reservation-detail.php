<?php declare(strict_types=1);
$rid = (int) $reservation['id'];
$status = (string) $reservation['status'];
$editable = $status === 'pending';
$hasQuote = ($reservation['quote_room_total'] ?? null) !== null;
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
        <div class="button-row">
          <button type="button" class="btn-secondary" data-reservation-edit-toggle>Modifier</button>
          <button type="button" class="btn-secondary" data-reservation-edit-toggle-lock>Modifier (Sans toucher aux Prix)</button>
        </div>
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
          <input type="hidden" name="lock_price" value="0" data-reservation-lock-price-field>
          <p class="muted" data-reservation-lock-price-notice hidden>Mode « Sans toucher aux Prix » : seuls le nom, le téléphone, l'email, le nombre de personnes et la nationalité peuvent être modifiés. Les dates, l'hébergement et le prix restent inchangés.</p>
          <div class="form-grid cols-2">
            <label><span>Nom complet</span><input class="input" type="text" name="client_name" value="<?= \App\View::e($reservation['client_name']) ?>" required></label>
            <label data-client-email-field><span>Email</span><input class="input" type="email" name="client_email" value="<?= \App\View::e($reservation['client_email']) ?>" required></label>
          </div>
          <!-- Same partner-only "Pas de Email"/"Pas de Téléphone" escape
               hatches as the booking forms (property-detail.php /
               calendar.php, see initNoClientContactToggles() in app.js):
               ticking one drops the field's mandatory flag, hides it and
               clears it. Both can be ticked at once (client with neither
               email nor phone); ReservationsController::applyRequestEdit()
               re-checks server-side that only a partner/admin may do so. -->
          <?php
            // A request created with "Pas de Email"/"Pas de Téléphone" has no
            // stored email/phone: pre-tick the matching box so the edit form
            // doesn't demand a value the partner never had.
            $storedEmail = trim((string) ($reservation['client_email'] ?? ''));
            $storedPhone = trim((string) ($reservation['client_phone'] ?? ''));
          ?>
          <label class="inline-check"><input type="checkbox" name="no_client_email" value="1" data-no-client-email-toggle<?= $storedEmail === '' ? ' checked' : '' ?>> Pas de Email</label>
          <?php $phoneValue = $storedPhone; require BASE_PATH . '/files/views/partials/phone-input.php'; ?>
          <label class="inline-check"><input type="checkbox" name="no_client_phone" value="1" data-no-client-phone-toggle<?= $storedPhone === '' ? ' checked' : '' ?>> Pas de Téléphone</label>
          <div class="form-grid cols-2">
            <label><span>Date d'arrivée</span><input class="input" type="date" name="checkin_date" value="<?= \App\View::e($reservation['checkin_date']) ?>" required readonly data-reservation-quote-field data-reservation-dates-checkin></label>
            <label><span>Date de départ</span><input class="input" type="date" name="checkout_date" value="<?= \App\View::e($reservation['checkout_date']) ?>" required readonly data-reservation-quote-field data-reservation-dates-checkout></label>
          </div>
          <div class="button-row">
            <button type="button" class="btn-secondary" data-reservation-change-dates data-reservation-price-locked-field>Modifier les Dates</button>
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
                <button type="button" class="btn-secondary" data-reservation-change-property data-reservation-price-locked-field>Changer d'hébergement</button>
                <?php if ($hasQuote): ?>
                  <!-- Once a devis exists, the same button on the client's
                       own public link (reservation-public.php) is hidden by
                       default (migration 047, client_can_change_property);
                       this tick lets the partner re-enable it for this
                       request without opening the full "Modifier" form —
                       submits on change, no separate save button needed.
                       This checkbox lives inside the main "Modifier" <form>
                       above (visually, under "Hébergement"), so it can't be
                       wrapped in its own nested <form>: HTML forbids nested
                       forms, and browsers silently close the *outer* form
                       early when they hit one, leaving "Enregistrer les
                       modifications" outside any form and unable to submit
                       at all. Instead it's tied to the standalone
                       #client-property-change-form-<?= $rid ?> form rendered
                       after the main form closes (see below) via the
                       `form` attribute, which associates a field with a
                       form anywhere in the document without needing to be
                       its descendant. -->
                  <label class="inline-check">
                    <input type="checkbox" name="allow" value="1" form="client-property-change-form-<?= $rid ?>" onchange="this.form.submit()" <?= !empty($reservation['client_can_change_property']) ? 'checked' : '' ?>>
                    Autoriser le client à changer d'hébergement
                  </label>
                <?php endif; ?>
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
        <?php if ($hasQuote): ?>
          <!-- Standalone form for the "Autoriser le client à changer
               d'hébergement" checkbox rendered above, inside the main
               "Modifier" form — see the comment next to that checkbox for
               why it can't be a nested <form> instead. -->
          <form method="post" action="/partner/reservations/<?= $rid ?>/client-property-change" id="client-property-change-form-<?= $rid ?>" class="inline-check-form"></form>
        <?php endif; ?>
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
          <p class="muted reservation-modal-price-note">Les tarifs affichés correspondent au tarif de la chambre et des personnes supplémentaires uniquement (hors frais de ménage et taxe de séjour).</p>
          <div class="reservation-modal-summary" data-reservation-modal-summary></div>
          <div class="reservation-modal-list" data-reservation-modal-list>
            <p class="muted">Chargement des biens disponibles…</p>
          </div>
        </div>
      </div>

      <!-- "Modifier les Dates" modal — see reservation-public.php for the
           shared markup/behaviour (initReservationDatesModal() in
           assets/js/app.js), scoped here to this partner's own tenant via
           PageController::partnerReservationDatesAvailability(). Excluded in
           "Modifier (Sans toucher aux Prix)" mode: the opening button above
           carries data-reservation-price-locked-field like the other
           price-affecting actions. -->
      <div class="simple-modal-overlay" data-reservation-dates-modal hidden>
        <div class="simple-modal-dialog simple-modal-dialog-wide" role="dialog" aria-modal="true" aria-label="Modifier les dates">
          <div class="simple-modal-header">
            <h3>Modifier les dates</h3>
            <button type="button" class="btn-icon-plain" data-reservation-dates-modal-close aria-label="Fermer">✕</button>
          </div>
          <p class="muted">Les dates actuelles apparaissent en orange. Cliquez sur la date d'arrivée puis sur la date de départ pour sélectionner de nouvelles dates (elles apparaîtront en rouge).</p>
          <div class="button-row">
            <button type="button" class="btn-secondary" data-reservation-dates-prev-month>← Mois précédent</button>
            <button type="button" class="btn-secondary" data-reservation-dates-next-month>Mois suivant →</button>
          </div>
          <div data-reservation-dates-calendar class="reservation-dates-calendar">
            <p class="muted">Chargement des disponibilités…</p>
          </div>
          <div class="reservation-modal-summary" data-reservation-dates-summary></div>
          <div class="button-row">
            <button type="button" class="btn-secondary" data-reservation-dates-cancel>Annuler</button>
            <button type="button" class="btn-primary" data-reservation-dates-validate disabled>Valider les dates</button>
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
          // Hidden entirely (row + value) when the property isn't
          // registered for VAT (or the computed total rounds to 0,00), per
          // the site-wide convention: a TVA amount of 0 is never shown.
          $quoteVatTotal = \App\controllers\ReservationsController::vatTotalFromStoredQuote(
            (float) ($reservation['quote_room_total'] ?? 0),
            (float) ($reservation['quote_extra_person_total'] ?? 0),
            (float) ($reservation['quote_vat_rate'] ?? 0)
          );
        ?>
        <?php if ($quoteVatTotal > 0): ?>
          <div><span class="muted">TVA totale :</span> <strong><?= \App\View::e(\App\controllers\ReservationsController::formatMoneyFr($quoteVatTotal, $quoteCurrency)) ?></strong></div>
        <?php endif; ?>
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
