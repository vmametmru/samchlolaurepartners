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
  <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
    <div>
      <h1 style="margin-bottom:0;">Tableau d'analyse</h1>
      <p class="text-muted" style="margin-top:.25rem;">Toutes les heures sont affichées en heure de Maurice (GMT+4).</p>
    </div>
    <?php if ($isAdmin): ?>
      <button type="button" class="btn-secondary" onclick="document.getElementById('analytics-settings-modal').hidden=false;" title="Paramètres" style="display:inline-flex;align-items:center;gap:.4rem;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Paramètres
      </button>
    <?php endif; ?>
  </div>

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
    <?php if ($isAdmin): ?>
      <div class="card card-body"><p>Visites admin</p><strong><?= (int) $kpis['admin_visits'] ?></strong></div>
    <?php endif; ?>
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
    <div class="card card-body" style="display:flex;flex-direction:column;">
      <h2 class="card-header" style="flex-shrink:0;">Visites par pays</h2>
      <div id="map-visits-country" style="flex:1;min-height:250px;"></div>
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
          <thead><tr><th>Page</th><th>Partenaire</th><th>Visites</th><th>Durée moy.</th><th>Clients</th><th>Partenaires</th><?php if ($isAdmin): ?><th>Admin</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($visitsByPage as $row): ?>
              <tr>
                <td title="<?= \App\View::e($row['page_url']) ?>"><?= \App\View::e($row['page_title'] ?: $row['page_url']) ?></td>
                <td><?= \App\View::e($row['partner_name'] ?? '—') ?></td>
                <td><?= (int) $row['visits'] ?></td>
                <td><?= (int) ($row['avg_duration'] ?? 0) ?>s</td>
                <td><?= (int) $row['client_visits'] ?></td>
                <td><?= (int) $row['partner_visits'] ?></td>
                <?php if ($isAdmin): ?><td><?= (int) $row['admin_visits'] ?></td><?php endif; ?>
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
          <thead><tr><th>Date/Heure</th><th>Partenaire</th><th>Page</th><th>Type</th><th>Pays</th><th>Durée</th><th>IP</th><?php if ($isAdmin): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach (array_slice($visits, 0, 50) as $row): ?>
              <tr>
                <td><?= \App\View::e($row['visited_at']) ?></td>
                <td><?= \App\View::e($row['partner_name'] ?? '—') ?></td>
                <td title="<?= \App\View::e($row['page_url']) ?>"><?= \App\View::e($row['page_title'] ?: $row['page_url']) ?></td>
                <td><span class="badge badge-<?= \App\View::e($row['visitor_type']) ?>"><?= \App\View::e(ucfirst($row['visitor_type'])) ?></span></td>
                <td><?= \App\View::e($row['country_name'] ?: ($row['country_code'] ?: '—')) ?></td>
                <td><?= $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] . 's' : '—' ?></td>
                <td><?= \App\View::e($row['ip_address'] ?: '—') ?></td>
                <?php if ($isAdmin): ?>
                  <td class="nowrap">
                    <form method="post" action="/admin/analytics/<?= (int) $row['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Supprimer cette entrée ?');">
                      <button type="submit" class="btn-sm btn-danger" title="Supprimer">✕</button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Report schedule config -->
  <?php if (!$isAdmin): ?>
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
    <div class="simple-modal-overlay" id="analytics-settings-modal" hidden onclick="if(event.target===this)this.hidden=true;">
      <div class="simple-modal-dialog" style="max-width:38rem;">
        <div class="simple-modal-header">
          <h3>Paramètres d'analyse</h3>
          <button type="button" class="btn-close" onclick="this.closest('.simple-modal-overlay').hidden=true;">&times;</button>
        </div>

        <h4 style="margin:0 0 .5rem;">Configurer le rapport automatique pour un partenaire</h4>
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

        <?php
          $schedules = $reportSchedules ?? [];
          $dayLabels = $days;
        ?>
        <?php if ($schedules): ?>
          <div class="table-responsive" style="margin-top:1rem;">
            <table class="data-table" style="font-size:.82rem;">
              <thead><tr><th>Partenaire</th><th>Actif</th><th>Jour</th><th>Heure</th><th>Dernier envoi</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($schedules as $s): ?>
                  <tr>
                    <td><?= \App\View::e($s['partner_name']) ?></td>
                    <td><?= (int) $s['enabled'] ? '✓' : '—' ?></td>
                    <td><?= \App\View::e($dayLabels[(int) $s['day_of_week']] ?? '?') ?></td>
                    <td><?= \App\View::e($s['time_of_day']) ?></td>
                    <td><?= $s['last_sent_at'] ? \App\View::e($s['last_sent_at']) : '—' ?></td>
                    <td>
                      <form method="post" action="/admin/analytics/report-schedule/<?= (int) $s['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Supprimer cette configuration ?');">
                        <button type="submit" class="btn-sm btn-danger" title="Supprimer">✕</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted" style="margin-top:.75rem;font-size:.85rem;">Aucun rapport automatique configuré.</p>
        <?php endif; ?>

        <hr style="margin:1.25rem 0;">

        <h4 style="margin:0 0 .5rem;">Supprimer toutes les données analytiques d'un partenaire</h4>
        <form method="post" action="/admin/analytics/purge-partner" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer TOUTES les données analytiques de ce partenaire ? Cette action est irréversible.');">
          <div class="form-grid cols-2" style="align-items:end;">
            <label>
              <select class="input" name="partner_id" required>
                <option value="">Sélectionner un partenaire...</option>
                <?php foreach ($partners as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"><?= \App\View::e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div><button class="btn-primary" type="submit" style="background:#dc2626;border-color:#dc2626;">Tout supprimer</button></div>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-OLBgp1GsljhM2TJ+sbHjaiH9txEUvgdDTAzHv2P24donTt6/529l+9Ua0vFImLlb" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var visitsByDate = <?= json_encode($visitsByDate, JSON_UNESCAPED_UNICODE) ?>;
  var visitsByHour = <?= json_encode($visitsByHour, JSON_UNESCAPED_UNICODE) ?>;
  var visitsByCountry = <?= json_encode(array_slice($visitsByCountry, 0, 10), JSON_UNESCAPED_UNICODE) ?>;
  var kpis = <?= json_encode($kpis, JSON_UNESCAPED_UNICODE) ?>;
  var isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

  // Country code to lat/lng mapping
  var countryCoords = {
    'AF':[33,65],'AL':[41,20],'DZ':[28,3],'AD':[42.5,1.5],'AO':[-12.5,18.5],'AG':[17.05,-61.8],'AR':[-34,-64],'AM':[40,45],'AU':[-27,133],'AT':[47.3,13.3],
    'AZ':[40.5,47.5],'BS':[24.25,-76],'BH':[26,50.5],'BD':[24,90],'BB':[13.2,-59.5],'BY':[53,28],'BE':[50.8,4],'BZ':[17.25,-88.75],'BJ':[9.5,2.25],'BT':[27.5,90.5],
    'BO':[-17,-65],'BA':[44,18],'BW':[-22,24],'BR':[-10,-55],'BN':[4.5,114.7],'BG':[43,25],'BF':[13,-2],'BI':[-3.5,30],'KH':[13,105],'CM':[6,12],
    'CA':[60,-95],'CV':[16,-24],'CF':[7,21],'TD':[15,19],'CL':[-30,-71],'CN':[35,105],'CO':[4,-72],'KM':[-12.2,44.25],'CG':[-1,15],'CD':[-3,23],
    'CR':[10,-84],'CI':[8,-5],'HR':[45.2,15.5],'CU':[22,-80],'CY':[35,33],'CZ':[49.75,15.5],'DK':[56,10],'DJ':[11.5,43],'DM':[15.4,-61.4],'DO':[19,-70.7],
    'EC':[-2,-77.5],'EG':[27,30],'SV':[13.8,-88.9],'GQ':[2,10],'ER':[15,39],'EE':[59,26],'SZ':[-26.5,31.5],'ET':[8,38],'FJ':[-18,175],'FI':[64,26],
    'FR':[46,2],'GA':[-1,11.75],'GM':[13.5,-15.5],'GE':[42,43.5],'DE':[51,9],'GH':[8,-2],'GR':[39,22],'GD':[12.1,-61.7],'GT':[15.5,-90.25],'GN':[11,-10],
    'GW':[12,-15],'GY':[5,-59],'HT':[19,-72.4],'HN':[15,-86.5],'HU':[47,20],'IS':[65,-18],'IN':[20,77],'ID':[-5,120],'IR':[32,53],'IQ':[33,44],
    'IE':[53,-8],'IL':[31.5,34.75],'IT':[42.8,12.8],'JM':[18.25,-77.5],'JP':[36,138],'JO':[31,36],'KZ':[48,68],'KE':[1,38],'KI':[1.4,173],'KP':[40,127],
    'KR':[37,127.5],'KW':[29.5,47.75],'KG':[41,75],'LA':[18,105],'LV':[57,25],'LB':[33.8,35.8],'LS':[-29.5,28.5],'LR':[6.5,-9.5],'LY':[25,17],'LI':[47.2,9.5],
    'LT':[56,24],'LU':[49.75,6.2],'MG':[-20,47],'MW':[-13.5,34],'MY':[2.5,112.5],'MV':[3.25,73],'ML':[17,-4],'MT':[35.9,14.4],'MH':[9,168],'MR':[20,-12],
    'MU':[-20.3,57.6],'MX':[23,-102],'FM':[6.9,158.2],'MD':[47,29],'MC':[43.7,7.4],'MN':[46,105],'ME':[42.5,19.3],'MA':[32,-5],'MZ':[-18.25,35],'MM':[22,98],
    'NA':[-22,17],'NR':[-0.5,166.9],'NP':[28,84],'NL':[52.5,5.75],'NZ':[-41,174],'NI':[13,-85],'NE':[16,8],'NG':[10,8],'MK':[41.5,22],'NO':[62,10],
    'OM':[21,57],'PK':[30,70],'PW':[7.5,134.5],'PA':[9,-80],'PG':[-6,147],'PY':[-23,-58],'PE':[-10,-76],'PH':[13,122],'PL':[52,20],'PT':[39.5,-8],
    'QA':[25.5,51.25],'RO':[46,25],'RU':[60,100],'RW':[-2,30],'KN':[17.3,-62.7],'LC':[13.9,-61],'VC':[13.25,-61.2],'WS':[-13.6,-172.3],'SM':[43.8,12.4],'ST':[1,7],
    'SA':[25,45],'SN':[14,-14],'RS':[44,21],'SC':[-4.6,55.5],'SL':[8.5,-11.5],'SG':[1.4,103.8],'SK':[48.7,19.5],'SI':[46.1,15],'SB':[-8,159],'SO':[10,49],
    'ZA':[-29,24],'SS':[7,30],'ES':[40,-4],'LK':[7,81],'SD':[15,30],'SR':[4,-56],'SE':[62,15],'CH':[47,8],'SY':[35,38],'TW':[23.5,121],
    'TJ':[39,71],'TZ':[-6,35],'TH':[15,100],'TL':[-8.5,126],'TG':[8,1.2],'TO':[-20,-175],'TT':[10.4,-61.3],'TN':[34,9],'TR':[39,35],'TM':[40,60],
    'TV':[-8,178],'UG':[1,32],'UA':[49,32],'AE':[24,54],'GB':[54,-2],'US':[38,-97],'UY':[-33,-56],'UZ':[41,64],'VU':[-16,167],'VE':[8,-66],
    'VN':[16,108],'YE':[15,48],'ZM':[-15,30],'ZW':[-20,30],'RE':[-21.1,55.5],'GP':[16.25,-61.6],'MQ':[14.7,-61],'GF':[4,-53],'YT':[-12.8,45.15],'NC':[-21.5,165.5]
  };

  // Visits by date chart
  var ctxDate = document.getElementById('chart-visits-date');
  if (ctxDate && visitsByDate.length > 0) {
    var datasets = [
      { label: 'Total', data: visitsByDate.map(function (r) { return parseInt(r.total); }), borderColor: '#E61E4D', backgroundColor: 'rgba(230,30,77,0.1)', fill: true, tension: 0.3 },
      { label: 'Clients', data: visitsByDate.map(function (r) { return parseInt(r.clients); }), borderColor: '#22c55e', backgroundColor: 'transparent', tension: 0.3 },
      { label: 'Partenaires', data: visitsByDate.map(function (r) { return parseInt(r.partners); }), borderColor: '#f59e0b', backgroundColor: 'transparent', tension: 0.3 }
    ];
    if (isAdmin) {
      datasets.push({ label: 'Admin', data: visitsByDate.map(function (r) { return parseInt(r.admins); }), borderColor: '#6366f1', backgroundColor: 'transparent', tension: 0.3 });
    }
    new Chart(ctxDate, {
      type: 'line',
      data: {
        labels: visitsByDate.map(function (r) { return r.visit_date; }),
        datasets: datasets
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

  // Visits by country — Leaflet world map with circle markers
  var mapEl = document.getElementById('map-visits-country');
  if (mapEl && visitsByCountry.length > 0 && typeof L !== 'undefined') {
    var map = L.map(mapEl, { scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(map);
    var maxVisits = Math.max.apply(null, visitsByCountry.map(function (r) { return parseInt(r.visits); }));
    var bounds = [];
    visitsByCountry.forEach(function (r) {
      var code = (r.country_code || '').toUpperCase();
      var coords = countryCoords[code];
      if (!coords) return;
      var visits = parseInt(r.visits);
      var radius = Math.max(6, Math.min(40, (visits / maxVisits) * 40));
      var marker = L.circleMarker([coords[0], coords[1]], {
        radius: radius, fillColor: '#E61E4D', color: '#fff', weight: 1, fillOpacity: 0.7
      }).addTo(map);
      marker.bindPopup('<strong>' + (r.country_name || code) + '</strong><br>' + visits + ' visite' + (visits > 1 ? 's' : ''));
      bounds.push([coords[0], coords[1]]);
    });
    if (bounds.length > 0) {
      map.fitBounds(bounds, { padding: [20, 20], maxZoom: 5 });
    }
  }

  // Visitor type chart
  var ctxType = document.getElementById('chart-visits-type');
  if (ctxType) {
    var pieLabels = ['Clients', 'Partenaires'];
    var pieData = [parseInt(kpis.client_visits) || 0, parseInt(kpis.partner_visits) || 0];
    var pieColors = ['#22c55e', '#f59e0b'];
    if (isAdmin) {
      pieLabels.push('Admin');
      pieData.push(parseInt(kpis.admin_visits) || 0);
      pieColors.push('#6366f1');
    }
    new Chart(ctxType, {
      type: 'pie',
      data: {
        labels: pieLabels,
        datasets: [{ data: pieData, backgroundColor: pieColors }]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
  }
});
</script>
