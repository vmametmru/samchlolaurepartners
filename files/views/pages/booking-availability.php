<?php declare(strict_types=1);
$propertyName = $property ? \App\View::localized($property, 'name') : ('Bien #' . $propertyId);
$mainImage = $property['images'][0]['url'] ?? null;
$arrivalLabel = \App\controllers\ReservationsController::formatDateFr($arrival);
$departureLabel = \App\controllers\ReservationsController::formatDateFr($departure);
$travelers = $adults + $children;
?>
<section class="container section-lg narrow">
  <div class="card card-body stack-md center">
    <h1><?= \App\View::e($propertyName) ?></h1>
    <?php if ($mainImage): ?>
      <img src="<?= \App\View::e($mainImage) ?>" alt="<?= \App\View::e($propertyName) ?>" style="max-width:100%;border-radius:1rem;">
    <?php endif; ?>

    <?php if (!$validRange): ?>
      <div class="alert alert-error">Dates invalides. Merci de repartir de la fiche du bien pour choisir vos dates.</div>
    <?php elseif ($available): ?>
      <div class="alert alert-success">✅ Ces dates sont disponibles pour ce bien.</div>
      <p>Du <strong><?= \App\View::e($arrivalLabel) ?></strong> au <strong><?= \App\View::e($departureLabel) ?></strong>
        &middot; <?= (int) $travelers ?> voyageur(s)</p>
      <a class="btn-primary" href="<?= \App\View::e($checkoutUrl) ?>" target="_blank" rel="noopener">Réserver</a>
    <?php else: ?>
      <div class="alert alert-error">❌ Ces dates ne sont malheureusement plus disponibles pour ce bien.</div>
      <p>Du <?= \App\View::e($arrivalLabel) ?> au <?= \App\View::e($departureLabel) ?> &middot; <?= (int) $travelers ?> voyageur(s)</p>
    <?php endif; ?>

    <?php if ($propertyId > 0): ?>
      <a class="btn-secondary" href="/properties/<?= (int) $propertyId ?>">Voir le bien / choisir d'autres dates</a>
    <?php endif; ?>
  </div>
</section>
