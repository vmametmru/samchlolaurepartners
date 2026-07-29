<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1>Tâches planifiées (cron)</h1>

  <div class="card card-body stack-md">
    <h2 class="section-title">Emails planifiés (rappels, confirmations…)</h2>
    <p class="muted">
      Ce site déclenche automatiquement les emails de rappel (et autres emails programmés) via un
      script PHP qui doit être exécuté périodiquement par une tâche cron du serveur d'hébergement.
      Cette application n'a pas accès à la crontab du serveur : la tâche doit être ajoutée manuellement,
      une seule fois, dans l'interface cron de votre hébergement (ex. cPanel « Cron Jobs »).
    </p>
    <div class="card card-body" style="background:#f7f7f8">
      <p><strong>Commande à ajouter (toutes les 15 à 60 minutes, au choix) :</strong></p>
      <code><?= \App\View::e('php ' . $schedulerScriptPath) ?></code>
    </div>
    <p>
      <strong>Dernière exécution :</strong>
      <?php if ($lastRunLabel): ?>
        <?= \App\View::e($lastRunLabel) ?> —
        <?= (int) $lastRunChecked ?> réservation(s) vérifiée(s), <?= (int) $lastRunSent ?> email(s) envoyé(s).
      <?php else: ?>
        jamais exécutée pour l'instant.
      <?php endif; ?>
    </p>
    <?php if (($lastRunErrors ?? []) !== []): ?>
      <div class="alert alert-error">
        <strong>Erreurs lors du dernier passage :</strong>
        <ul>
          <?php foreach ($lastRunErrors as $error): ?>
            <li><?= \App\View::e((string) $error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <form method="post" action="/admin/cron/run">
      <button class="btn-primary" type="submit">Exécuter maintenant (test)</button>
    </form>
  </div>

  <div class="card card-body stack-md">
    <h2 class="section-title">Statut Prix Lodgify</h2>
    <p class="muted">
      La colonne « Statut Prix » de la page <a href="/admin/lodgify-properties">Biens Lodgify</a> se
      rafraîchit automatiquement toutes les 30 minutes : il ne s'agit pas d'une tâche cron du serveur,
      mais d'un cache interne qui se met à jour tout seul dès qu'une page du site est consultée. Les
      disponibilités et tarifs affichés aux visiteurs, eux, sont toujours interrogés en direct auprès
      de Lodgify et ne sont jamais mis en cache. Il n'y a donc rien à configurer pour cette partie.
    </p>
  </div>

  <div class="card card-body stack-md">
    <h2 class="section-title">Planifications d'emails par partenaire</h2>
    <p class="muted">Chaque ligne déclenche l'envoi du modèle choisi, N jour(s) avant la date d'arrivée du client, lors du prochain passage de la tâche cron ci-dessus.</p>
    <?php if (($schedules ?? []) !== []): ?>
      <?php foreach ($schedules as $schedule): ?>
        <form id="schedule-edit-<?= (int) $schedule['id'] ?>" method="post" action="/admin/cron/schedules"></form>
        <form id="schedule-delete-<?= (int) $schedule['id'] ?>" method="post" action="/admin/cron/schedules/<?= (int) $schedule['id'] ?>/delete" onsubmit="return confirm('Supprimer cette planification ?');"></form>
      <?php endforeach; ?>
      <table class="table">
        <thead>
          <tr><th>Partenaire</th><th>Jours avant arrivée</th><th>Modèle</th><th>Actif</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($schedules as $schedule): ?>
            <?php $editFormId = 'schedule-edit-' . (int) $schedule['id']; ?>
            <tr>
              <td>
                <input type="hidden" form="<?= $editFormId ?>" name="id" value="<?= (int) $schedule['id'] ?>">
                <input type="hidden" form="<?= $editFormId ?>" name="partner_id" value="<?= (int) $schedule['partner_id'] ?>">
                <?= \App\View::e((string) $schedule['partner_name']) ?>
              </td>
              <td><input class="input" form="<?= $editFormId ?>" type="number" min="0" name="days_before_arrival" value="<?= (int) $schedule['days_before_arrival'] ?>" style="width:5rem"></td>
              <td>
                <select class="input" form="<?= $editFormId ?>" name="template_type">
                  <?php foreach ($templateTypeLabels as $type => $label): ?>
                    <option value="<?= \App\View::e($type) ?>" <?= $schedule['template_type'] === $type ? 'selected' : '' ?>><?= \App\View::e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><label class="inline-check"><input type="checkbox" form="<?= $editFormId ?>" name="active" <?= !empty($schedule['active']) ? 'checked' : '' ?>></label></td>
              <td class="row" style="gap:.5rem">
                <button class="btn-secondary" form="<?= $editFormId ?>" type="submit">Enregistrer</button>
                <button class="action-icon-btn danger" form="schedule-delete-<?= (int) $schedule['id'] ?>" type="submit" title="Supprimer" aria-label="Supprimer"><span aria-hidden="true">🗑️</span></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">Aucune planification pour l'instant.</p>
    <?php endif; ?>

    <h3 class="section-title">Ajouter une planification</h3>
    <form method="post" action="/admin/cron/schedules" class="row" style="gap:.75rem;flex-wrap:wrap;align-items:flex-end">
      <label><span>Partenaire</span>
        <select class="input" name="partner_id" required>
          <option value="">—</option>
          <?php foreach (($partners ?? []) as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= \App\View::e((string) $partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span>Jours avant arrivée</span><input class="input" type="number" min="0" name="days_before_arrival" value="5" style="width:6rem" required></label>
      <label><span>Modèle</span>
        <select class="input" name="template_type" required>
          <?php foreach ($templateTypeLabels as $type => $label): ?>
            <option value="<?= \App\View::e($type) ?>" <?= $type === 'REMINDER' ? 'selected' : '' ?>><?= \App\View::e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="inline-check"><input type="checkbox" name="active" checked> Actif</label>
      <button class="btn-primary" type="submit">Créer</button>
    </form>
  </div>
</section>
