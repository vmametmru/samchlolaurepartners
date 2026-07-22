<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1>Mise à jour de l'application</h1>

  <div class="card card-body stack-md">
    <h2 class="section-title">Déployer une nouvelle version</h2>
    <p class="text-muted">Uploadez le fichier ZIP généré par GitHub Actions pour mettre à jour l'application. Les répertoires <code>images/</code> et <code>files/storage/</code> ainsi que le fichier <code>.env</code> ne seront pas écrasés.</p>
    <form method="post" action="/admin/mise-a-jour" enctype="multipart/form-data" class="stack-md">
      <label>
        <span>Fichier ZIP de déploiement</span>
        <input class="input" type="file" name="update_zip" accept=".zip" required>
      </label>
      <button class="btn-primary" type="submit">🚀 Mettre à Jour</button>
    </form>
  </div>

  <div class="card card-body stack-md">
    <h2 class="section-title">Restauration</h2>
    <?php if (empty($backups)): ?>
      <p class="empty-state">Aucune sauvegarde disponible. Une sauvegarde automatique est créée avant chaque mise à jour.</p>
    <?php else: ?>
      <p class="text-muted">Dernière sauvegarde disponible : <strong><?= \App\View::e($backups[0]['label']) ?></strong></p>
      <form method="post" action="/admin/mise-a-jour/rollback">
        <button class="btn-secondary" type="submit" onclick="return confirm('Restaurer la version précédente ? Cette action écrasera les fichiers actuels de l\'application.')">↩ Restaurer la version précédente</button>
      </form>
      <?php if (count($backups) > 1): ?>
        <details class="stack-sm" style="margin-top:.5rem">
          <summary class="text-muted" style="cursor:pointer">Toutes les sauvegardes (<?= count($backups) ?>)</summary>
          <ul class="stack-sm" style="margin-top:.5rem">
            <?php foreach ($backups as $b): ?>
              <li><code><?= \App\View::e($b['label']) ?></code></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
