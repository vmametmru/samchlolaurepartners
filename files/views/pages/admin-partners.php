<?php declare(strict_types=1);
/** @var array<int, array> $properties */
/** @var array<int, array<string, string>> $visibilityByPartner */
/** @var array<int, array<int, array>> $usersByPartner */
$properties = $properties ?? [];
$visibilityByPartner = $visibilityByPartner ?? [];
$usersByPartner = $usersByPartner ?? [];
?>
<section class="container section-lg">
  <div class="section-header"><h1>Gestion des partenaires</h1><a class="btn-primary" href="/admin/partners/new">+ Nouveau partenaire</a></div>
  <div class="card overflow-hidden">
    <table class="table">
      <thead><tr><th>Partenaire</th><th>Code Partenaire</th><th>Email</th><th>Marge %</th><th>Nettoyage</th><th>Taxe Touristique</th><th>Actif</th><th>Biens</th><th>Utilisateurs</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($partners as $partnerRow): ?>
        <tr>
          <td style="display:flex;align-items:center;gap:.5rem;"><?php if (!empty($partnerRow['logo_url'])): ?><img class="partner-logo-thumb" src="<?= \App\View::e($partnerRow['logo_url']) ?>" alt=""><?php endif; ?><?= \App\View::e($partnerRow['name']) ?></td>
          <td><code><?= \App\View::e($partnerRow['subdomain']) ?></code></td>
          <td><?= \App\View::e($partnerRow['email']) ?></td>
          <td><?= \App\View::e((string) $partnerRow['markup_percent']) ?>%</td>
          <td><?= \App\View::e((string) ($partnerRow['cleaning_fee_per_person_per_night'] ?? 0)) ?></td>
          <td><?= \App\View::e((string) ($partnerRow['tourist_tax_per_person_per_night'] ?? 0)) ?></td>
          <td><?= !empty($partnerRow['active']) ? '✅' : '❌' ?></td>
          <td><button type="button" class="text-link" data-help-trigger="assoc-<?= (int) $partnerRow['id'] ?>">Associer des biens</button></td>
          <td><button type="button" class="text-link" data-help-trigger="users-<?= (int) $partnerRow['id'] ?>">Utilisateurs (<?= count($usersByPartner[(int) $partnerRow['id']] ?? []) ?>)</button></td>
          <td class="actions"><a class="text-link" href="/admin/partners/<?= (int) $partnerRow['id'] ?>/edit">Éditer</a>
            <form method="post" action="/admin/partners/<?= (int) $partnerRow['id'] ?>/delete" onsubmit="return confirm('Supprimer définitivement ce partenaire ? Cette action est irréversible.');"><button class="link-danger" type="submit">Supprimer</button></form></td>
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
      <h3>Ajouter un utilisateur</h3>
      <form class="stack-md" method="post" action="/admin/partners/<?= $partnerId ?>/users">
        <label><span>Prénom</span><input class="input" type="text" name="first_name"></label>
        <label><span>Nom</span><input class="input" type="text" name="last_name"></label>
        <label><span>Email</span><input class="input" type="email" name="email" required></label>
        <label><span>Mot de passe</span><input class="input" type="password" name="password" minlength="8" required></label>
        <div class="button-row"><button class="btn-primary" type="submit">Ajouter</button></div>
      </form>
    </dialog>
  <?php endforeach; ?>
</section>
