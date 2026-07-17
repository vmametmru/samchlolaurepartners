<?php declare(strict_types=1); ?>
<section class="container section-lg narrow">
  <div class="card card-body stack-md" style="margin-top: 4rem;">
    <h1>Bienvenue</h1>
    <p>Merci d'entrer le code partenaire que vous avez reçu avec votre agence de voyage.</p>
    <form method="post" action="/partner-code" class="stack-md">
      <label><span>Code partenaire</span><input class="input" type="text" name="code" required autofocus></label>
      <input type="hidden" name="next" value="">
      <button class="btn-primary" type="submit">Ouvrir le site</button>
    </form>
  </div>
</section>
<?php // Start buffering the Accueil hero video in the background while the
      // visitor is still on this gate page (see initPartnerCodeFromHash() in
      // assets/js/app.js), so it plays instantly once the "#code" auto-submit
      // (or manual code entry) lands them on /accueil. Hidden and never
      // played here — the <link rel="preload"> in layout.php's <head>
      // covers browsers that fetch that eagerly; this <video preload> tag
      // is a fallback for browsers that don't act on a video preload link. ?>
<video src="/medias/home.mp4" preload="auto" muted playsinline aria-hidden="true" style="display:none"></video>
