<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <div class="card card-body stack-md" style="margin-top: 4rem;">
    <h1>Bienvenue</h1>
    <p>Merci d'entrer le code partenaire que vous avez reçu avec votre agence de voyage.</p>
    <form method="post" action="/partner-code" class="stack-md">
      <label><span>Code partenaire</span><input class="input" type="text" name="code" required autofocus></label>
      <button class="btn-primary" type="submit">Ouvrir le site</button>
    </form>
  </div>
</section>
