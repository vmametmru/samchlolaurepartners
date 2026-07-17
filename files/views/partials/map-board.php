<?php declare(strict_types=1);
// Every property gets a marker here, using its exact GPS when Lodgify has
// it ("map_latitude"/"map_longitude" fall back to LodgifyClient's per-city
// estimate otherwise) — filtering on "latitude"/"longitude" (the exact,
// often-absent value) used to hide almost every property from this map.
// Rendered as a real, pannable/zoomable OpenStreetMap (via Leaflet, loaded
// in layout.php) instead of a flat gradient placeholder, so it actually
// looks and behaves like a map.
$items = array_values(array_filter($properties, static fn(array $item): bool => isset($item['map_latitude'], $item['map_longitude']) && $item['map_latitude'] !== null && $item['map_longitude'] !== null));
$hasEstimated = false;
$points = [];
foreach ($items as $item) {
    $isEstimated = !empty($item['map_position_is_estimated']);
    $hasEstimated = $hasEstimated || $isEstimated;
    $points[] = [
        'id' => (int) $item['id'],
        'lat' => (float) $item['map_latitude'],
        'lng' => (float) $item['map_longitude'],
        'name' => (string) $item['name'],
        'url' => '/properties/' . (int) $item['id'],
        'estimated' => $isEstimated,
    ];
}
$mapId = 'map-board-' . bin2hex(random_bytes(4));
?>
<aside class="map-board-wrapper">
  <div id="<?= \App\View::e($mapId) ?>" class="map-board" data-points="<?= \App\View::e(json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"></div>
  <?php if ($points !== []): ?>
    <div class="map-legend">Cliquez sur un point pour ouvrir la fiche.<?= $hasEstimated ? ' Certaines positions sont approximatives (GPS non renseigné).' : '' ?></div>
  <?php endif; ?>
</aside>
