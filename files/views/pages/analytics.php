<?php declare(strict_types=1);
$days = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
$scheduleEnabled = !empty($reportSchedule) && (int) ($reportSchedule['enabled'] ?? 0) === 1;
$scheduleDay = (int) ($reportSchedule['day_of_week'] ?? 1);
$scheduleTime = (string) ($reportSchedule['time_of_day'] ?? '08:00');
$filterAction = $isAdmin ? '/admin/analytics' : '/partner/analytics';
$exportCsvUrl = $filterAction . '/export?' . http_build_query($filters);
$exportPdfUrl = $filterAction . '/pdf?' . http_build_query($filters);
?>
<section class="container section-lg">
  <h1>Tableau d'analyse</h1>

  <!-- Filters -->
  <form class="card card-body analytics-filters" method="get" action="<?= \App\View::e($filterAction) ?>">
    <div class="form-grid cols-3">
      <label><span>Date début</span><input class="input" type="date" name="date_from" value="<?= \App\View::e($filters['date_from']) ?>"></label>
      <label><span>Date fin</span><input class="input" type="date" name="date_to" value="<?= \App\View::e($filters['date_to']) ?>"></label>
      <?php if ($isAdmin): ?>
        <label><span>Partenaire</span>
          <select class="input" name="partner_id">
            <option value="">Tous</option>
            <?php foreach ($partners as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $filters['partner_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= \App\View::e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <label><span>Type visiteur</span>
        <select class="input" name="visitor_type">
          <option value="">Tous</option>
          <option value="client" <?= $filters['visitor_type'] === 'client' ? 'selected' : '' ?>>Client</option>
          <option value="partner" <?= $filters['visitor_type'] === 'partner' ? 'selected' : '' ?>>Partenaire</option>
          <option value="admin" <?= $filters['visitor_type'] === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
      </label>
      <label><span>Pays</span><input class="input" type="text" name="country" value="<?= \App\View::e($filters['country']) ?>" placeholder="Code ou nom"></label>
      <label><span>Page (URL)</span><input class="input" type="text" name="page" value="<?= \App\View::e($filters['page']) ?>" placeholder="/properties, /contact..."></label>
    </div>
    <div class="button-row mt-8">
      <button class="btn-primary" type="submit">Filtrer</button>
      <a class="btn-secondary" href="<?= \App\View::e($filterAction) ?>">Réinitialiser</a>
      <a class="btn-secondary" href="<?= \App\View::e($exportCsvUrl) ?>">Exporter CSV</a>
      <a class="btn-secondary" href="<?= \App\View::e($exportPdfUrl) ?>">Exporter PDF</a>
    </div>
  </form>

  <!-- KPIs -->
  <div class="stats-grid analytics-kpis">
    <div class="card card-body"><p>Visites totales</p><strong><?= (int) $kpis['total_visits'] ?></strong></div>
    <div class="card card-body"><p>Visiteurs uniques</p><strong><?= (int) $kpis['unique_visitors'] ?></strong></div>
    <div class="card card-body"><p>Visites clients</p><strong class="accent-ok"><?= (int) $kpis['client_visits'] ?></strong></div>
    <div class="card card-body"><p>Visites partenaires</p><strong class="accent-warn"><?= (int) $kpis['partner_visits'] ?></strong></div>
    <div class="card card-body"><p>Visites admin</p><strong><?= (int) $kpis['admin_visits'] ?></strong></div>
    <div class="card card-body"><p>Durée moy.</p><strong><?= (int) $kpis['avg_duration'] ?>s</strong></div>
    <div class="card card-body"><p>Pays</p><strong><?= (int) $kpis['countries'] ?></strong></div>
    <div class="card card-body"><p>Pages vues</p><strong><?= (int) $kpis['pages_viewed'] ?></strong></div>
  </div>

  <!-- Charts -->
  <div class="form-grid cols-2 analytics-charts-row">
    <div class="card card-body">
      <h2 class="card-header">Visites par jour</h2>
      <canvas id="chart-visits-date" height="250"></canvas>
    </div>
    <div class="card card-body">
      <h2 class="card-header">Visites par heure</h2>
      <canvas id="chart-visits-hour" height="250"></canvas>
    </div>
  </div>
  <div class="form-grid cols-2 analytics-charts-row">
    <div class="card card-body">
      <h2 class="card-header">Visites par pays</h2>
      <canvas id="chart-visits-country" height="250"></canvas>
    </div>
    <div class="card card-body">
      <h2 class="card-header">Répartition par type</h2>
      <canvas id="chart-visits-type" height="250"></canvas>
    </div>
  </div>

  <!-- Data tables -->
  <div class="card overflow-hidden">
    <div class="card-header">Pages les plus visitées</div>
    <?php if ($visitsByPage === []): ?>
      <p class="empty-state">Aucune donnée.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead><tr><th>Page</th><th>Visites</th><th>Durée moy.</th><th>Clients</th><th>Partenaires</th><th>Admin</th></tr></thead>
          <tbody>
            <?php foreach ($visitsByPage as $row): ?>
              <tr>
                <td title="<?= \App\View::e($row['page_url']) ?>"><?= \App\View::e($row['page_title'] ?: $row['page_url']) ?></td>
                <td><?= (int) $row['visits'] ?></td>
                <td><?= (int) ($row['avg_duration'] ?? 0) ?>s</td>
                <td><?= (int) $row['client_visits'] ?></td>
                <td><?= (int) $row['partner_visits'] ?></td>
                <td><?= (int) $row['admin_visits'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card overflow-hidden mt-16">
    <div class="card-header">Visites par pays</div>
    <?php if ($visitsByCountry === []): ?>
      <p class="empty-state">Aucune donnée.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead><tr><th>Pays</th><th>Visites</th></tr></thead>
          <tbody>
            <?php foreach ($visitsByCountry as $row): ?>
              <tr>
                <td><?= \App\View::e($row['country_name'] ?: $row['country_code']) ?></td>
                <td><?= (int) $row['visits'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card overflow-hidden mt-16">
    <div class="card-header">Dernières visites</div>
    <?php if ($visits === []): ?>
      <p class="empty-state">Aucune donnée.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table" id="analytics-visits-table">
          <thead><tr><th>Date/Heure</th><th>Page</th><th>Type</th><th>Pays</th><th>Durée</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($visits, 0, 50) as $row): ?>
              <tr>
                <td><?= \App\View::e($row['visited_at']) ?></td>
                <td title="<?= \App\View::e($row['page_url']) ?>"><?= \App\View::e($row['page_title'] ?: $row['page_url']) ?></td>
                <td><span class="badge badge-<?= \App\View::e($row['visitor_type']) ?>"><?= \App\View::e(ucfirst($row['visitor_type'])) ?></span></td>
                <td><?= \App\View::e($row['country_name'] ?: ($row['country_code'] ?: '—')) ?></td>
                <td><?= $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] . 's' : '—' ?></td>
                <td><?= \App\View::e($row['ip_address'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Report schedule config -->
  <?php if (!$isAdmin && $reportSchedule !== null || !$isAdmin): ?>
    <div class="card card-body mt-16">
      <h2>Rapport automatique hebdomadaire</h2>
      <p class="text-muted">Recevez un PDF de votre rapport d'analyse chaque semaine par email.</p>
      <form method="post" action="/partner/analytics/report-schedule" class="stack-md">
        <label class="inline-check"><input type="checkbox" name="report_enabled" <?= $scheduleEnabled ? 'checked' : '' ?>> Activer l'envoi automatique</label>
        <div class="form-grid cols-2">
          <label><span>Jour d'envoi</span>
            <select class="input" name="report_day">
              <?php foreach ($days as $i => $dayName): ?>
                <option value="<?= $i ?>" <?= $scheduleDay === $i ? 'selected' : '' ?>><?= \App\View::e($dayName) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span>Heure d'envoi</span><input class="input" type="time" name="report_time" value="<?= \App\View::e($scheduleTime) ?>"></label>
        </div>
        <div class="button-row"><button class="btn-primary" type="submit">Sauvegarder</button></div>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
    <div class="card card-body mt-16">
      <h2>Configurer le rapport automatique pour un partenaire</h2>
      <form method="post" action="/admin/analytics/report-schedule" class="stack-md">
        <label><span>Partenaire</span>
          <select class="input" name="partner_id" required>
            <option value="">Sélectionner...</option>
            <?php foreach ($partners as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= \App\View::e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="inline-check"><input type="checkbox" name="report_enabled"> Activer l'envoi automatique</label>
        <div class="form-grid cols-2">
          <label><span>Jour d'envoi</span>
            <select class="input" name="report_day">
              <?php foreach ($days as $i => $dayName): ?>
                <option value="<?= $i ?>" <?= $i === 1 ? 'selected' : '' ?>><?= \App\View::e($dayName) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span>Heure d'envoi</span><input class="input" type="time" name="report_time" value="08:00"></label>
        </div>
        <div class="button-row"><button class="btn-primary" type="submit">Sauvegarder</button></div>
      </form>
    </div>
  <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  var visitsByDate = <?= json_encode($visitsByDate, JSON_UNESCAPED_UNICODE) ?>;
  var visitsByHour = <?= json_encode($visitsByHour, JSON_UNESCAPED_UNICODE) ?>;
  var visitsByCountry = <?= json_encode(array_slice($visitsByCountry, 0, 10), JSON_UNESCAPED_UNICODE) ?>;
  var kpis = <?= json_encode($kpis, JSON_UNESCAPED_UNICODE) ?>;

  // Visits by date chart
  var ctxDate = document.getElementById('chart-visits-date');
  if (ctxDate && visitsByDate.length > 0) {
    new Chart(ctxDate, {
      type: 'line',
      data: {
        labels: visitsByDate.map(function (r) { return r.visit_date; }),
        datasets: [
          { label: 'Total', data: visitsByDate.map(function (r) { return parseInt(r.total); }), borderColor: '#E61E4D', backgroundColor: 'rgba(230,30,77,0.1)', fill: true, tension: 0.3 },
          { label: 'Clients', data: visitsByDate.map(function (r) { return parseInt(r.clients); }), borderColor: '#22c55e', backgroundColor: 'transparent', tension: 0.3 },
          { label: 'Partenaires', data: visitsByDate.map(function (r) { return parseInt(r.partners); }), borderColor: '#f59e0b', backgroundColor: 'transparent', tension: 0.3 },
          { label: 'Admin', data: visitsByDate.map(function (r) { return parseInt(r.admins); }), borderColor: '#6366f1', backgroundColor: 'transparent', tension: 0.3 }
        ]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
    });
  }

  // Visits by hour chart
  var ctxHour = document.getElementById('chart-visits-hour');
  if (ctxHour && visitsByHour.length > 0) {
    var hourLabels = [];
    var hourData = [];
    for (var h = 0; h < 24; h++) {
      hourLabels.push(h + 'h');
      var found = visitsByHour.find(function (r) { return parseInt(r.visit_hour) === h; });
      hourData.push(found ? parseInt(found.visits) : 0);
    }
    new Chart(ctxHour, {
      type: 'bar',
      data: {
        labels: hourLabels,
        datasets: [{ label: 'Visites', data: hourData, backgroundColor: 'rgba(230,30,77,0.6)', borderColor: '#E61E4D', borderWidth: 1 }]
      },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
  }

  // Visits by country chart
  var ctxCountry = document.getElementById('chart-visits-country');
  if (ctxCountry && visitsByCountry.length > 0) {
    var colors = ['#E61E4D','#22c55e','#3b82f6','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#06b6d4','#a855f7'];
    new Chart(ctxCountry, {
      type: 'doughnut',
      data: {
        labels: visitsByCountry.map(function (r) { return r.country_name || r.country_code; }),
        datasets: [{ data: visitsByCountry.map(function (r) { return parseInt(r.visits); }), backgroundColor: colors }]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
  }

  // Visitor type chart
  var ctxType = document.getElementById('chart-visits-type');
  if (ctxType) {
    new Chart(ctxType, {
      type: 'pie',
      data: {
        labels: ['Clients', 'Partenaires', 'Admin'],
        datasets: [{ data: [parseInt(kpis.client_visits) || 0, parseInt(kpis.partner_visits) || 0, parseInt(kpis.admin_visits) || 0], backgroundColor: ['#22c55e', '#f59e0b', '#6366f1'] }]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
  }
})();
</script>
