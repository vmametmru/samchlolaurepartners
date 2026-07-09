<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1>Versions &amp; Déploiements</h1>
  <div class="card card-body stack-md">
    <h2 class="section-title">Déployer une nouvelle version</h2>
    <form method="post" action="/admin/versions/deploy" class="form-grid cols-3">
      <label><span>Version</span><input class="input" type="text" name="version" placeholder="v1.2.3" required></label>
      <label class="col-span-2"><span>Notes</span><input class="input" type="text" name="notes" placeholder="Notes (optionnel)"></label>
      <button class="btn-primary" type="submit">🚀 Déployer</button>
    </form>
  </div>
  <div class="card overflow-hidden">
    <div class="card-header">Historique des versions</div>
    <table class="table"><thead><tr><th>Version</th><th>Déployé par</th><th>Date</th><th>Notes</th><th></th></tr></thead><tbody>
      <?php foreach ($versions as $version): ?>
        <tr class="<?= !empty($version['rolled_back_at']) ? 'row-muted' : '' ?>"><td><code><?= \App\View::e($version['version']) ?></code></td><td><?= \App\View::e($version['deployed_by']) ?></td><td><?= \App\View::e((string) $version['deployed_at']) ?></td><td><?= \App\View::e($version['notes'] ?? '—') ?></td><td><?php if (empty($version['rolled_back_at'])): ?><form method="post" action="/admin/versions/rollback"><input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>"><button class="link-warning" type="submit">Rollback</button></form><?php else: ?>Rolled back<?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="card overflow-hidden">
    <div class="card-header">Migrations BDD appliquées</div>
    <table class="table"><thead><tr><th>Fichier</th><th>Appliqué le</th></tr></thead><tbody><?php foreach ($migrations as $migration): ?><tr><td><code><?= \App\View::e($migration['filename']) ?></code></td><td><?= \App\View::e((string) $migration['applied_at']) ?></td></tr><?php endforeach; ?></tbody></table>
  </div>
</section>
