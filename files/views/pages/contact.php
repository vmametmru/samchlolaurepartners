<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1>Nous contacter</h1>
  <p class="subtitle">Une question ? Un projet de séjour ? Écrivez-nous, nous vous répondrons dans les plus brefs délais.</p>
  <form class="card card-body stack-md" data-api-form data-success-message="Message envoyé ! Nous vous contacterons très prochainement." method="post" action="/api/contact">
    <div class="form-grid cols-2">
      <label><span>Nom *</span><input class="input" type="text" name="name" required></label>
      <label><span>Email *</span><input class="input" type="email" name="email" required></label>
    </div>
    <label><span>Téléphone</span><input class="input" type="tel" name="phone"></label>
    <div class="form-grid cols-2">
      <label><span>Arrivée souhaitée</span><input class="input" type="date" name="checkin_date"></label>
      <label><span>Départ souhaité</span><input class="input" type="date" name="checkout_date"></label>
    </div>
    <div class="form-grid cols-2">
      <label><span>Adultes</span><input class="input" type="number" name="adults" min="1" max="20" value="2"></label>
      <label><span>Enfants (&lt;12)</span><input class="input" type="number" name="children" min="0" max="20" value="0"></label>
    </div>
    <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
    <label><span>Message *</span><textarea class="input" rows="4" name="message" required></textarea></label>
    <button class="btn-primary" type="submit">Envoyer le message</button>
    <p class="form-feedback" data-form-feedback></p>
  </form>
</section>
