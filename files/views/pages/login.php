<?php declare(strict_types=1); ?>
<section class="container section-lg narrow auth-wrap">
  <div class="card card-body stack-md">
    <div class="section-header center">
      <h1>Connexion</h1>
      <p>Espace partenaire / administration</p>
    </div>
    <form method="post" action="/login" class="stack-md">
      <label><span>Email</span><input class="input" type="email" name="email" required></label>
      <label><span>Mot de passe</span><input class="input" type="password" name="password" required></label>
      <button class="btn-primary" type="submit">Se connecter</button>
    </form>
    <p class="center"><a class="text-link" href="/forgot-password">Mot de passe oublié ?</a></p>
  </div>
</section>
