<?php declare(strict_types=1); ?>
<section class="container section-lg narrow auth-wrap">
  <div class="card card-body stack-md">
    <div class="section-header center">
      <h1>Mot de passe oublié</h1>
      <p>Indiquez votre email, nous vous enverrons un lien de réinitialisation.</p>
    </div>
    <form method="post" action="/forgot-password" class="stack-md">
      <label><span>Email</span><input class="input" type="email" name="email" required autofocus></label>
      <button class="btn-primary" type="submit">Envoyer le lien</button>
    </form>
    <p class="center"><a class="text-link" href="/login">Retour à la connexion</a></p>
  </div>
</section>
