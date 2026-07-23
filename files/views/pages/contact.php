<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <h1><?= \App\View::e(\App\I18n::t('contact.page_title')) ?></h1>
  <p class="subtitle"><?= \App\View::e(\App\I18n::t('contact.subtitle')) ?></p>
  <form class="card card-body stack-md" data-api-form data-success-message="<?= \App\View::e(\App\I18n::t('contact.success_message')) ?>" method="post" action="/api/contact">
    <div class="form-grid cols-2">
      <label><span><?= \App\View::e(\App\I18n::t('contact.name')) ?></span><input class="input" type="text" name="name" required></label>
      <label><span><?= \App\View::e(\App\I18n::t('contact.email')) ?></span><input class="input" type="email" name="email" required></label>
    </div>
    <label><span><?= \App\View::e(\App\I18n::t('contact.phone')) ?></span><input class="input" type="tel" name="phone"></label>
    <div class="form-grid cols-2">
      <label><span><?= \App\View::e(\App\I18n::t('contact.checkin')) ?></span><input class="input" type="date" name="checkin_date"></label>
      <label><span><?= \App\View::e(\App\I18n::t('contact.checkout')) ?></span><input class="input" type="date" name="checkout_date"></label>
    </div>
    <div class="form-grid cols-2">
      <label><span><?= \App\View::e(\App\I18n::t('contact.adults')) ?></span><input class="input" type="number" name="adults" min="1" max="20" value="2"></label>
      <label><span><?= \App\View::e(\App\I18n::t('contact.children')) ?></span><input class="input" type="number" name="children" min="0" max="20" value="0"></label>
    </div>
    <?php require BASE_PATH . '/files/views/partials/nationalities.php'; ?>
    <label><span><?= \App\View::e(\App\I18n::t('contact.message')) ?></span><textarea class="input" rows="4" name="message" required></textarea></label>
    <button class="btn-primary" type="submit"><?= \App\View::e(\App\I18n::t('contact.send')) ?></button>
    <p class="form-feedback" data-form-feedback></p>
  </form>
</section>
