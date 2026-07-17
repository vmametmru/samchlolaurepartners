<?php declare(strict_types=1);
// Every property gets a marker here, using its exact GPS when Lodgify has
// it ("map_latitude"/"map_longitude" fall back to LodgifyClient's per-city
// estimate otherwise) — filtering on "latitude"/"longitude" (the exact,
// often-absent value) used to hide almost every property from this map.
$items = array_values(array_filter($properties, static fn(array $item): bool => isset($item['map_latitude'], $item['map_longitude']) && $item['map_latitude'] !== null && $item['map_longitude'] !== null));
$latitudes = array_column($items, 'map_latitude');
$longitudes = array_column($items, 'map_longitude');
$minLat = $latitudes ? min($latitudes) : -20.6;
$maxLat = $latitudes ? max($latitudes) : -19.9;
$minLng = $longitudes ? min($longitudes) : 57.1;
$maxLng = $longitudes ? max($longitudes) : 57.9;
$hasEstimated = false;
?>
<aside class="map-board-wrapper">
  <div class="map-board" data-min-lat="<?= \App\View::e((string) $minLat) ?>" data-max-lat="<?= \App\View::e((string) $maxLat) ?>" data-min-lng="<?= \App\View::e((string) $minLng) ?>" data-max-lng="<?= \App\View::e((string) $maxLng) ?>">
    <?php foreach ($items as $item): ?>
      <?php $isEstimated = !empty($item['map_position_is_estimated']); $hasEstimated = $hasEstimated || $isEstimated; ?>
      <a href="/properties/<?= (int) $item['id'] ?>" class="map-marker<?= $isEstimated ? ' map-marker-estimated' : '' ?>" data-lat="<?= \App\View::e((string) $item['map_latitude']) ?>" data-lng="<?= \App\View::e((string) $item['map_longitude']) ?>" title="<?= \App\View::e($item['name']) ?><?= $isEstimated ? ' (position approximative)' : '' ?>">•</a>
    <?php endforeach; ?>
    <div class="map-legend">Carte simplifiée — cliquez sur un point pour ouvrir la fiche.<?= $hasEstimated ? ' Certaines positions sont approximatives (GPS non renseigné).' : '' ?></div>
  </div>
</aside>
