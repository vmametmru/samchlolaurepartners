<?php declare(strict_types=1); ?>
<section class="container section-lg">
  <div class="section-header"><h1>Gestion des partenaires</h1><a class="btn-primary" href="/admin/partners/new">+ Nouveau partenaire</a></div>
  <div class="card overflow-hidden">
    <table class="table">
      <thead><tr><th>Partenaire</th><th>Sous-domaine</th><th>Email</th><th>Marge %</th><th>Actif</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($partners as $partnerRow): ?>
        <tr>
          <td><?= \App\View::e($partnerRow['name']) ?></td>
          <td><code><?= \App\View::e($partnerRow['subdomain']) ?></code></td>
          <td><?= \App\View::e($partnerRow['email']) ?></td>
          <td><?= \App\View::e((string) $partnerRow['markup_percent']) ?>%</td>
          <td><?= !empty($partnerRow['active']) ? '✅' : '❌' ?></td>
          <td class="actions"><a class="text-link" href="/admin/partners/<?= (int) $partnerRow['id'] ?>/edit">Éditer</a>
            <form method="post" action="/admin/partners/<?= (int) $partnerRow['id'] ?>/delete" onsubmit="return confirm('Désactiver ce partenaire ?');"><button class="link-danger" type="submit">Supprimer</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
