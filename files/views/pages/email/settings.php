<?php declare(strict_types=1);
/** @var array|null $account */
$account = $account ?? null;
?>
<section class="container section-lg narrow">
  <div class="card card-body stack-md">
    <div class="section-header">
      <a href="/email" class="btn">← Retour aux emails</a>
      <h1>Paramètres Email</h1>
    </div>

    <?php if ($account): ?>
      <div class="alert alert-success">
        ✓ Compte email configuré depuis <?= date('d/m/Y', strtotime($account['created_at'])) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/email/settings" class="stack-md">
      <p class="muted">
        Configurez votre compte email IMAP pour lire vos emails dans le portail partenaire.
        Vos identifiants sont chiffrés et stockés de façon sécurisée.
      </p>

      <label>
        <span>Email</span>
        <input class="input" type="email" name="email" value="<?= \App\View::e($account['email'] ?? '') ?>" required>
      </label>

      <label>
        <span>Serveur IMAP</span>
        <input class="input" type="text" name="server" value="<?= \App\View::e($account['imap_server'] ?? '') ?>" placeholder="imap.gmail.com" required>
        <small class="muted">Exemples: imap.gmail.com, imap.outlook.com, mail.example.com</small>
      </label>

      <label>
        <span>Port</span>
        <input class="input" type="number" name="port" value="<?= (int) ($account['imap_port'] ?? 993) ?>" min="1" max="65535" required>
        <small class="muted">Généralement 993 (SSL) ou 143 (TLS)</small>
      </label>

      <label>
        <span>Nom d'utilisateur IMAP</span>
        <input class="input" type="text" name="username" value="<?= \App\View::e($account['imap_username'] ?? '') ?>" placeholder="votreemail@example.com" required>
      </label>

      <label>
        <span>Mot de passe IMAP</span>
        <input class="input" type="password" name="password" placeholder="<?= $account ? '••••••••' : 'Votre mot de passe' ?>">
        <small class="muted">
          <?php if ($account): ?>
            Laissez vide pour conserver le mot de passe actuel.
          <?php else: ?>
            Requis pour la première configuration.
          <?php endif; ?>
          Pour Gmail, utilisez un <a href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener noreferrer">mot de passe d'application</a>.
        </small>
      </label>

      <hr>

      <div class="form-actions">
        <button class="btn-primary" type="submit">Enregistrer et synchroniser</button>
        <a href="/email" class="btn">Annuler</a>
      </div>
    </form>

    <hr>

    <div class="info-section">
      <h3>Configuration pour différents fournisseurs</h3>
      
      <h4>Gmail</h4>
      <ul>
        <li>Serveur: imap.gmail.com</li>
        <li>Port: 993</li>
        <li>Utilisateur: votreemail@gmail.com</li>
        <li>Mot de passe: <a href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener noreferrer">Générez un mot de passe d'application</a></li>
      </ul>

      <h4>Outlook / Hotmail</h4>
      <ul>
        <li>Serveur: imap-mail.outlook.com</li>
        <li>Port: 993</li>
        <li>Utilisateur: votreemail@outlook.com</li>
        <li>Mot de passe: Votre mot de passe Microsoft</li>
      </ul>

      <h4>Mail personnalisé</h4>
      <ul>
        <li>Contactez votre hébergeur pour obtenir les paramètres IMAP</li>
      </ul>
    </div>
  </div>
</section>

<style>
.form-actions {
  display: flex;
  gap: 0.5rem;
}

.info-section {
  background: #f5f5f5;
  padding: 1rem;
  border-radius: 4px;
  margin-top: 1rem;
}

.info-section h3 {
  margin-top: 0;
  font-size: 1.1rem;
}

.info-section h4 {
  margin: 1rem 0 0.5rem;
  font-size: 0.95rem;
}

.info-section ul {
  margin: 0.5rem 0;
  padding-left: 1.5rem;
}

.info-section li {
  margin: 0.25rem 0;
}

small a {
  color: #2563eb;
}
</style>
