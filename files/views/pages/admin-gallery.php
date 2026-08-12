<?php declare(strict_types=1);
/** @var array<int, array{id: int, name: string, count: int, cover: ?string}> $folders */
/** @var string $basePath */
$folders = $folders ?? [];
$basePath = $basePath ?? '/admin/gallery';
?>
<section class="container section-lg">
  <div class="section-header"><h1>Galerie photo</h1></div>
  <?php if ($folders === []): ?>
    <p class="empty-state">Aucun bien disponible.</p>
  <?php else: ?>
    <div class="quick-grid gallery-folder-grid">
      <?php foreach ($folders as $folder): ?>
        <a class="card card-body gallery-folder-card" href="<?= \App\View::e($basePath . '/' . $folder['id']) ?>">
          <?php if ($folder['cover']): ?>
            <img class="gallery-folder-cover" src="<?= \App\View::e($folder['cover']) ?>" alt="">
          <?php else: ?>
            <div class="gallery-folder-cover gallery-folder-cover-empty" aria-hidden="true">📁</div>
          <?php endif; ?>
          <div class="gallery-folder-info">
            <strong><?= \App\View::e($folder['name']) ?></strong>
            <small><?= (int) $folder['count'] ?> photo<?= $folder['count'] > 1 ? 's' : '' ?></small>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
