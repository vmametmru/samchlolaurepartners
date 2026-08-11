<?php declare(strict_types=1);
/** @var int $propertyId */
/** @var string $propertyName */
/** @var array<int, array{filename: string, url: string, index: int}> $photos */
/** @var string $basePath */
$photos = $photos ?? [];
$basePath = $basePath ?? '/partner/gallery';
?>
<section class="container section-lg">
  <div class="section-header">
    <h1><?= \App\View::e($propertyName) ?></h1>
    <a class="btn-secondary" href="<?= \App\View::e($basePath) ?>">&larr; Retour à la galerie</a>
  </div>
  <?php if ($photos === []): ?>
    <p class="empty-state">Aucune photo disponible pour ce bien.</p>
  <?php else: ?>
    <form method="post" action="<?= \App\View::e($basePath . '/' . $propertyId . '/zip') ?>" data-photo-gallery-form>
      <div class="gallery-toolbar">
        <label class="gallery-select-all"><input type="checkbox" data-photo-gallery-select-all> Tout sélectionner</label>
        <button type="submit" class="btn-primary">Télécharger la sélection (zip)</button>
      </div>
      <div class="gallery-grid">
        <?php foreach ($photos as $photo): ?>
          <div class="gallery-item">
            <label class="gallery-item-checkbox">
              <input type="checkbox" name="files[]" value="<?= \App\View::e($photo['filename']) ?>" data-photo-gallery-checkbox>
            </label>
            <img class="gallery-item-thumb" src="<?= \App\View::e($photo['url']) ?>" alt="<?= \App\View::e($propertyName) ?> - photo <?= (int) $photo['index'] ?>">
            <a class="gallery-item-download" href="<?= \App\View::e($photo['url']) ?>" download title="Télécharger cette photo" aria-label="Télécharger cette photo">⬇️</a>
          </div>
        <?php endforeach; ?>
      </div>
    </form>
  <?php endif; ?>
</section>
