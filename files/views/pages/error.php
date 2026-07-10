<?php declare(strict_types=1); ?>
<section class="container section-lg narrow center">
  <h1>Service temporairement indisponible</h1>
  <p class="subtitle"><?= \App\View::e($message ?? 'Une erreur est survenue. Veuillez réessayer dans quelques instants.') ?></p>
  <a class="btn-primary" href="/">Retour à l'accueil</a>
</section>
