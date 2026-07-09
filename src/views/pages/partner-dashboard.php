<?php declare(strict_types=1);
$pending = count(array_filter($requests, static fn(array $row): bool => $row['status'] === 'pending'));
$confirmed = count(array_filter($requests, static fn(array $row): bool => $row['status'] === 'confirmed'));
?>
<section class="container section-lg">
  <h1>Dashboard</h1>
  <div class="stats-grid">
    <div class="card card-body"><p>Total demandes</p><strong><?= count($requests) ?></strong></div>
    <div class="card card-body"><p>En attente</p><strong class="accent-warn"><?= $pending ?></strong></div>
    <div class="card card-body"><p>Confirmées</p><strong class="accent-ok"><?= $confirmed ?></strong></div>
  </div>
  <div class="quick-grid">
    <a class="card card-body center" href="/partner/reservations">📋<span>Réservations</span></a>
    <a class="card card-body center" href="/partner/templates">✉️<span>Templates email</span></a>
    <a class="card card-body center" href="/partner/settings">⚙️<span>Paramètres</span></a>
    <a class="card card-body center" href="/properties">🏠<span>Hébergements</span></a>
  </div>
  <div class="card overflow-hidden">
    <div class="card-header">Demandes récentes</div>
    <?php if ($requests === []): ?>
      <p class="empty-state">Aucune demande.</p>
    <?php else: ?>
      <div class="list-links">
        <?php foreach (array_slice($requests, 0, 5) as $request): ?>
          <a href="/partner/reservations/<?= (int) $request['id'] ?>">
            <div><strong><?= \App\View::e($request['client_name']) ?></strong><br><small><?= \App\View::e($request['property_name']) ?> · <?= \App\View::e($request['checkin_date']) ?> → <?= \App\View::e($request['checkout_date']) ?></small></div>
            <span class="badge badge-<?= \App\View::e($request['status']) ?>"><?= \App\View::e(\App\View::badgeLabel((string) $request['status'])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
