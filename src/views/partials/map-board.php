<?php declare(strict_types=1);
$items = array_values(array_filter($properties, static fn(array $item): bool => isset($item['latitude'], $item['longitude']) && $item['latitude'] !== null && $item['longitude'] !== null));
$latitudes = array_column($items, 'latitude');
$longitudes = array_column($items, 'longitude');
$minLat = $latitudes ? min($latitudes) : -20.6;
$maxLat = $latitudes ? max($latitudes) : -19.9;
$minLng = $longitudes ? min($longitudes) : 57.1;
$maxLng = $longitudes ? max($longitudes) : 57.9;
?>
<aside class="map-board-wrapper">
  <div class="map-board" data-min-lat="<?= \App\View::e((string) $minLat) ?>" data-max-lat="<?= \App\View::e((string) $maxLat) ?>" data-min-lng="<?= \App\View::e((string) $minLng) ?>" data-max-lng="<?= \App\View::e((string) $maxLng) ?>">
    <?php foreach ($items as $item): ?>
      <a href="/properties/<?= (int) $item['id'] ?>" class="map-marker" data-lat="<?= \App\View::e((string) $item['latitude']) ?>" data-lng="<?= \App\View::e((string) $item['longitude']) ?>" title="<?= \App\View::e($item['name']) ?>">•</a>
    <?php endforeach; ?>
    <div class="map-legend">Carte simplifiée — cliquez sur un point pour ouvrir la fiche.</div>
  </div>
</aside>
