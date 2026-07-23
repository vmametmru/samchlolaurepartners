<?php declare(strict_types=1); $image = $property['images'][0]['url'] ?? 'https://via.placeholder.com/400x240?text=No+Photo'; $propertyName = \App\View::localized($property, 'name'); ?>
<a class="card property-card" href="/properties/<?= (int) $property['id'] ?>">
  <div class="property-card-image"><img src="<?= \App\View::e($image) ?>" alt="<?= \App\View::e($propertyName) ?>"></div>
  <div class="card-body">
    <h3><?= \App\View::e($propertyName) ?></h3>
    <p><?= \App\View::plainText(\App\View::localized($property, 'description'), 140) ?></p>
    <div class="property-meta"><span><?= (int) $property['bedrooms'] ?> ch.</span><span>·</span><span><?= (int) $property['max_guests'] ?> pers. max</span></div>
  </div>
</a>
