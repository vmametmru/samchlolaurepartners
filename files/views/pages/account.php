<?php declare(strict_types=1);
/** @var array $userData */
$userData = $userData ?? [];
?>
<section class="container section-lg narrow">
  <div class="card card-body stack-md">
    <div class="section-header"><h1>Mon profil</h1></div>
    <?php if (!empty($userData['photo_url'])): ?>
      <img class="avatar-preview" src="<?= \App\View::e($userData['photo_url']) ?>" alt="Photo de profil" style="width:96px;height:96px;border-radius:50%;object-fit:cover;">
    <?php endif; ?>
    <form method="post" action="/account" enctype="multipart/form-data" class="stack-md">
      <label><span>Email</span><input class="input" type="email" value="<?= \App\View::e($userData['email'] ?? '') ?>" disabled></label>
      <label><span>Prénom</span><input class="input" type="text" name="first_name" value="<?= \App\View::e($userData['first_name'] ?? '') ?>"></label>
      <label><span>Nom</span><input class="input" type="text" name="last_name" value="<?= \App\View::e($userData['last_name'] ?? '') ?>"></label>
      <label><span>Téléphone</span><input class="input" type="tel" name="phone" value="<?= \App\View::e($userData['phone'] ?? '') ?>"></label>
      <label><span>Photo de profil</span><input class="input" type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp"></label>

      <hr>
      <p class="muted">Laissez les champs suivants vides pour conserver votre mot de passe actuel.</p>
      <label><span>Mot de passe actuel</span><input class="input" type="password" name="current_password" autocomplete="current-password"></label>
      <label><span>Nouveau mot de passe</span><input class="input" type="password" name="new_password" minlength="8" autocomplete="new-password"></label>

      <button class="btn-primary" type="submit">Enregistrer</button>
    </form>
  </div>
</section>
