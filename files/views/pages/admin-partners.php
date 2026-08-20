<?php declare(strict_types=1);
/** @var array<int, array> $properties */
/** @var array<int, array<string, string>> $visibilityByPartner */
/** @var array<int, array<int, array>> $usersByPartner */
/** @var array<int, array<int, int>> $linkedIdsByPartner */
/** @var array<int, array> $sessionsOverview */
$properties = $properties ?? [];
$visibilityByPartner = $visibilityByPartner ?? [];
$usersByPartner = $usersByPartner ?? [];
$linkedIdsByPartner = $linkedIdsByPartner ?? [];
$sessionsOverview = $sessionsOverview ?? [];
$globalTouristTax = (float) ($globalTouristTax ?? 0);
$formatFee = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
?>
<section class="container section-lg">
  <div class="section-header"><h1>Qui est connecté</h1></div>
  <div class="card overflow-hidden">
    <table class="table sessions-table">
      <thead><tr><th></th><th>Utilisateur</th><th>Rôle</th><th>Statut</th><th>Dernière activité</th></tr></thead>
      <tbody>
      <?php if ($sessionsOverview === []): ?>
        <tr><td colspan="5" class="muted">Aucun utilisateur pour le moment.</td></tr>
      <?php endif; ?>
      <?php foreach ($sessionsOverview as $sessionUser):
        $sessionUserId = (int) $sessionUser['id'];
        $displayName = trim(($sessionUser['first_name'] ?? '') . ' ' . ($sessionUser['last_name'] ?? '')) ?: (string) $sessionUser['email'];
        $isOnline = !empty($sessionUser['online']);
      ?>
        <tr>
          <td><span class="session-status-dot <?= $isOnline ? 'online' : 'offline' ?>" title="<?= $isOnline ? 'En ligne' : 'Hors ligne' ?>" aria-hidden="true"></span></td>
          <td>
            <button type="button" class="session-user-trigger" data-help-trigger="session-history-<?= $sessionUserId ?>"><?= \App\View::e($displayName) ?></button>
            <div class="muted small"><?= \App\View::e((string) $sessionUser['email']) ?></div>
          </td>
          <td><?= $sessionUser['role'] === 'admin' ? 'Admin' : \App\View::e((string) ($sessionUser['partner_name'] ?? 'Partenaire')) ?></td>
          <td><?= $isOnline ? '<span class="badge badge-confirmed">En ligne</span>' : '<span class="badge badge-cancelled">Hors ligne</span>' ?></td>
          <td><?= $sessionUser['last_seen_at'] !== null ? \App\View::e(date('d/m/Y H:i', (int) strtotime((string) $sessionUser['last_seen_at']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php foreach ($sessionsOverview as $sessionUser):
    $sessionUserId = (int) $sessionUser['id'];
    $displayName = trim(($sessionUser['first_name'] ?? '') . ' ' . ($sessionUser['last_name'] ?? '')) ?: (string) $sessionUser['email'];
  ?>
    <dialog class="help-dialog session-history-dialog" data-help-dialog="session-history-<?= $sessionUserId ?>"
            data-session-history-url="/admin/users/<?= $sessionUserId ?>/sessions">
      <form method="dialog"><button type="submit" class="help-dialog-close" aria-label="Fermer">×</button></form>
      <h2 class="section-title">Historique de connexion · <?= \App\View::e($displayName) ?></h2>
      <div class="session-history-body"><p class="muted">Chargement…</p></div>
    </dialog>
  <?php endforeach; ?>
</section>

<section class="container section-lg">
  <div class="section-header"><h1>Gestion des partenaires</h1><div class="stack-actions"><a class="btn-secondary" href="/admin/gallery">🖼️ Galerie photo</a><a class="btn-primary" href="/admin/partners/new">+ Nouveau partenaire</a></div></div>
  <div class="card overflow-hidden">
    <table class="table partners-table">
      <colgroup>
        <col class="partners-col-name">
        <col class="partners-col-code">
        <col class="partners-col-email">
        <col class="partners-col-markup">
        <col class="partners-col-cleaning">
        <col class="partners-col-tax">
        <col class="partners-col-status">
        <col class="partners-col-properties">
        <col class="partners-col-users">
        <col class="partners-col-links">
        <col class="partners-col-actions">
      </colgroup>
      <thead><tr><th>Partenaire</th><th>Code Partenaire</th><th>Email</th><th>Marge %</th><th>Nettoyage</th><th>Taxe Touristique</th><th>Actif</th><th>Biens</th><th>Utilisateurs</th><th>Liaisons</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($partners as $partnerRow): ?>
        <tr>
          <td><div class="partner-name-cell"><?php if (!empty($partnerRow['logo_url'])): ?><img class="partner-logo-thumb" src="<?= \App\View::e($partnerRow['logo_url']) ?>" alt=""><?php endif; ?><span class="partner-name-label"><?= \App\View::e($partnerRow['name']) ?></span></div></td>
          <td><a class="partner-code-link" href="/#<?= rawurlencode((string) $partnerRow['subdomain']) ?>" title="Ouvrir le site public du partenaire"><?= \App\View::e((string) $partnerRow['subdomain']) ?><span aria-hidden="true">↗</span></a></td>
          <td><?= \App\View::e($partnerRow['email']) ?></td>
          <td><?= \App\View::e(number_format((float) ($partnerRow['markup_percent'] ?? 0), 0)) ?>%</td>
          <td><?= \App\View::e($formatFee((float) ($partnerRow['cleaning_fee_per_person_per_night'] ?? 0))) ?></td>
          <td><?= \App\View::e($formatFee($globalTouristTax)) ?></td>
          <td><?= !empty($partnerRow['active']) ? '<span class="badge badge-confirmed">Actif</span>' : '<span class="badge badge-cancelled">Inactif</span>' ?></td>
          <td><button type="button" class="partner-table-btn partner-table-btn-icon" data-help-trigger="assoc-<?= (int) $partnerRow['id'] ?>" title="Associer les biens" aria-label="Associer les biens"><span aria-hidden="true">🏠</span><span class="sr-only">Associer les biens</span></button></td>
          <td><button type="button" class="partner-table-btn partner-table-btn-icon" data-help-trigger="users-<?= (int) $partnerRow['id'] ?>" title="Utilisateurs (<?= count($usersByPartner[(int) $partnerRow['id']] ?? []) ?>)" aria-label="Utilisateurs (<?= count($usersByPartner[(int) $partnerRow['id']] ?? []) ?>)"><span aria-hidden="true">👤</span><span class="sr-only">Utilisateurs (<?= count($usersByPartner[(int) $partnerRow['id']] ?? []) ?>)</span></button></td>
          <td><button type="button" class="partner-table-btn partner-table-btn-icon" data-help-trigger="links-<?= (int) $partnerRow['id'] ?>" title="Lier à d'autres partenaires (<?= count($linkedIdsByPartner[(int) $partnerRow['id']] ?? []) ?>)" aria-label="Lier à d'autres partenaires (<?= count($linkedIdsByPartner[(int) $partnerRow['id']] ?? []) ?>)"><span aria-hidden="true">🔗</span><span class="sr-only">Lier à d'autres partenaires (<?= count($linkedIdsByPartner[(int) $partnerRow['id']] ?? []) ?>)</span></button></td>
          <td class="actions partner-actions"><a class="action-icon-btn" href="/admin/partners/<?= (int) $partnerRow['id'] ?>/templates" title="Templates email" aria-label="Templates email"><span aria-hidden="true">📧</span></a><a class="action-icon-btn" href="/admin/partners/<?= (int) $partnerRow['id'] ?>/edit" title="Éditer le partenaire" aria-label="Éditer le partenaire"><span aria-hidden="true">✏️</span></a>
            <form method="post" action="/admin/partners/<?= (int) $partnerRow['id'] ?>/delete" onsubmit="return confirm('Supprimer définitivement ce partenaire ? Cette action est irréversible.');"><button class="action-icon-btn danger" type="submit" title="Supprimer le partenaire" aria-label="Supprimer le partenaire"><span aria-hidden="true">🗑️</span></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php foreach ($partners as $partnerRow):
    $partnerId = (int) $partnerRow['id'];
    $partnerVisibility = $visibilityByPartner[$partnerId] ?? [];
  ?>
    <dialog class="help-dialog assoc-dialog" data-help-dialog="assoc-<?= $partnerId ?>">
      <form method="dialog"><button type="submit" class="help-dialog-close" aria-label="Fermer">×</button></form>
      <h2 class="section-title">Associer des biens · <?= \App\View::e($partnerRow['name']) ?></h2>
      <?php if ($properties === []): ?>
        <p class="muted">Aucun bien Lodgify disponible pour le moment.</p>
      <?php else: ?>
        <p class="muted">FULL : le bien s'affiche normalement. PARTIAL : tout s'affiche sauf les tarifs &amp; disponibilités (remplacés par un message). NONE : le bien n'apparaît pas du tout pour ce partenaire.</p>
        <form class="stack-md assoc-form" method="post" action="/admin/partners/<?= $partnerId ?>/properties">
          <div class="assoc-property-list">
            <?php foreach ($properties as $property):
              $propertyId = (int) ($property['id'] ?? 0);
              $currentVisibility = $partnerVisibility[(string) $propertyId] ?? \App\PartnerPropertyVisibility::FULL;
              $photo = $property['images'][0]['url'] ?? 'https://via.placeholder.com/56x40?text=%20';
            ?>
              <div class="assoc-property-row">
                <img class="assoc-property-photo" src="<?= \App\View::e($photo) ?>" alt="<?= \App\View::e((string) ($property['name'] ?? '')) ?>">
                <span class="assoc-property-name"><?= \App\View::e((string) ($property['name'] ?? '')) ?></span>
                <div class="assoc-property-options">
                  <label><input type="radio" name="visibility[<?= $propertyId ?>]" value="full" <?= $currentVisibility === 'full' ? 'checked' : '' ?>> FULL</label>
                  <label><input type="radio" name="visibility[<?= $propertyId ?>]" value="partial" <?= $currentVisibility === 'partial' ? 'checked' : '' ?>> PARTIAL</label>
                  <label><input type="radio" name="visibility[<?= $propertyId ?>]" value="none" <?= $currentVisibility === 'none' ? 'checked' : '' ?>> NONE</label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="button-row"><button class="btn-primary" type="submit">Enregistrer</button></div>
        </form>
      <?php endif; ?>
    </dialog>
  <?php endforeach; ?>

  <?php foreach ($partners as $partnerRow):
    $partnerId = (int) $partnerRow['id'];
    $partnerUsers = $usersByPartner[$partnerId] ?? [];
  ?>
    <dialog class="help-dialog users-dialog" data-help-dialog="users-<?= $partnerId ?>">
      <form method="dialog"><button type="submit" class="help-dialog-close" aria-label="Fermer">×</button></form>
      <h2 class="section-title">Utilisateurs · <?= \App\View::e($partnerRow['name']) ?></h2>
      <p class="muted">Ces utilisateurs pourront se connecter pour gérer uniquement les demandes de ce partenaire.</p>
      <?php if ($partnerUsers === []): ?>
        <p class="muted">Aucun utilisateur pour ce partenaire pour le moment.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Nom</th><th>Email</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($partnerUsers as $partnerUser): ?>
            <tr>
              <td><?= \App\View::e(trim(($partnerUser['first_name'] ?? '') . ' ' . ($partnerUser['last_name'] ?? '')) ?: '—') ?></td>
              <td><?= \App\View::e($partnerUser['email']) ?></td>
              <td class="actions">
                <form method="post" action="/admin/partners/<?= $partnerId ?>/users/<?= (int) $partnerUser['id'] ?>/delete" onsubmit="return confirm('Supprimer cet utilisateur ?');">
                  <button class="link-danger" type="submit">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if ($partnerUsers === []): ?>
        <h3>Ajouter un utilisateur</h3>
        <form class="stack-md" method="post" action="/admin/partners/<?= $partnerId ?>/users">
          <label><span>Prénom</span><input class="input" type="text" name="first_name"></label>
          <label><span>Nom</span><input class="input" type="text" name="last_name"></label>
          <label><span>Email</span><input class="input" type="email" name="email" required></label>
          <label><span>Mot de passe</span><input class="input" type="password" name="password" minlength="8" required></label>
          <div class="button-row"><button class="btn-primary" type="submit">Ajouter</button></div>
        </form>
      <?php else: ?>
        <p class="muted">Un seul compte utilisateur est autorisé par partenaire (nécessaire pour la fonctionnalité de liaison entre partenaires). Supprimez le compte ci-dessus avant d'en créer un autre.</p>
      <?php endif; ?>
    </dialog>
  <?php endforeach; ?>

  <?php foreach ($partners as $partnerRow):
    $partnerId = (int) $partnerRow['id'];
    $otherPartners = array_values(array_filter($partners, static fn (array $p): bool => (int) $p['id'] !== $partnerId));
    $linkedIds = $linkedIdsByPartner[$partnerId] ?? [];
  ?>
    <dialog class="help-dialog links-dialog" data-help-dialog="links-<?= $partnerId ?>">
      <form method="dialog"><button type="submit" class="help-dialog-close" aria-label="Fermer">×</button></form>
      <h2 class="section-title">Lier des partenaires · <?= \App\View::e($partnerRow['name']) ?></h2>
      <p class="muted">Un partenaire lié permet à cet utilisateur de basculer instantanément sur le compte de l'autre partenaire (icône 🔗 dans la barre de navigation), sans se déconnecter/reconnecter.</p>
      <?php if ($otherPartners === []): ?>
        <p class="empty-state">Aucun autre partenaire à lier pour le moment.</p>
      <?php else: ?>
        <form class="stack-md" method="post" action="/admin/partners/<?= $partnerId ?>/links">
          <div class="links-partner-list">
            <?php foreach ($otherPartners as $otherPartner): ?>
              <label class="links-partner-row">
                <input type="checkbox" name="linked_partner_ids[]" value="<?= (int) $otherPartner['id'] ?>" <?= in_array((int) $otherPartner['id'], $linkedIds, true) ? 'checked' : '' ?>>
                <span><?= \App\View::e((string) $otherPartner['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="button-row"><button class="btn-primary" type="submit">Lier</button></div>
        </form>
      <?php endif; ?>
    </dialog>
  <?php endforeach; ?>
</section>
