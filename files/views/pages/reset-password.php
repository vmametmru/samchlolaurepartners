<?php declare(strict_types=1);
/** @var string $token */
?>
<section class="container section-lg narrow auth-wrap">
  <div class="card card-body stack-md">
    <div class="section-header center">
      <h1>Réinitialiser le mot de passe</h1>
      <p>Choisissez un nouveau mot de passe (8 caractères minimum).</p>
    </div>
    <form method="post" action="/reset-password/<?= \App\View::e($token) ?>" class="stack-md">
      <label><span>Nouveau mot de passe</span><input class="input" type="password" name="password" minlength="8" required autofocus></label>
      <label><span>Confirmer le mot de passe</span><input class="input" type="password" name="password_confirm" minlength="8" required></label>
      <button class="btn-primary" type="submit">Mettre à jour</button>
    </form>
  </div>
</section>
