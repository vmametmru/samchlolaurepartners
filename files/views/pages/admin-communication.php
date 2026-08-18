<?php declare(strict_types=1);
$selectedLanguage = $selectedLanguage ?? 'fr';
$partners = $partners ?? [];
$logs = $logs ?? [];
$template = $template ?? ['subject' => '', 'body_html' => '', 'is_saved' => false];
?>
<section class="container section-lg">
  <h1>Communication</h1>
  <p class="text-muted">
    Envoyez un email aux partenaires (un seul, plusieurs ou tous). Chaque partenaire reçoit un email
    individuel, à l'adresse email enregistrée dans sa fiche. L'envoi utilise les identifiants
    <a href="/admin/smtp-settings">SMTP par défaut</a> et le template
    <a href="/admin/templates/default?language=<?= \App\View::e($selectedLanguage) ?>">« Communication Admin »</a>,
    dans lequel vous placez les variables <code>{{sujet}}</code>, <code>{{message}}</code> et
    <code>{{piece_jointe}}</code>.
  </p>

  <div class="tabs" role="tablist" style="display:flex;gap:.5rem;margin:0 0 1rem;">
    <a href="/admin/communication?language=fr" class="btn-sm <?= $selectedLanguage === 'fr' ? 'btn-primary' : 'btn-secondary' ?>">🇫🇷 Français</a>
    <a href="/admin/communication?language=en" class="btn-sm <?= $selectedLanguage === 'en' ? 'btn-primary' : 'btn-secondary' ?>">🇬🇧 English</a>
  </div>

  <?php if (empty($template['is_saved'])): ?>
    <p class="empty-state">Le template « Communication Admin » n'a pas encore été créé pour cette langue : le modèle par défaut sera utilisé. <a href="/admin/templates/default?language=<?= \App\View::e($selectedLanguage) ?>">Créer / modifier le template</a></p>
  <?php endif; ?>

  <form class="card card-body stack-md" method="post" action="/admin/communication/send" enctype="multipart/form-data">
    <input type="hidden" name="language" value="<?= \App\View::e($selectedLanguage) ?>">

    <div class="stack-sm">
      <h2 class="section-title">Destinataires</h2>
      <?php if ($partners === []): ?>
        <p class="empty-state">Aucun partenaire enregistré.</p>
      <?php else: ?>
        <label style="display:flex;gap:.5rem;align-items:center;">
          <input type="checkbox" name="send_to_all" value="1" data-communication-all>
          <span><strong>Tous les partenaires actifs</strong></span>
        </label>
        <div class="form-grid cols-3" data-communication-partners>
          <?php foreach ($partners as $partner): ?>
            <?php $hasEmail = trim((string) ($partner['email'] ?? '')) !== ''; ?>
            <label style="display:flex;gap:.5rem;align-items:center;">
              <input type="checkbox" name="partner_ids[]" value="<?= (int) $partner['id'] ?>" <?= $hasEmail ? '' : 'disabled' ?>>
              <span>
                <?= \App\View::e((string) $partner['name']) ?>
                <?php if (empty($partner['active'])): ?><span class="text-muted"> (inactif)</span><?php endif; ?>
                <br><span class="text-muted" style="font-size:.85rem;"><?= \App\View::e($hasEmail ? (string) $partner['email'] : 'Aucune adresse email') ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <label><span>Sujet</span><input class="input" type="text" name="subject" required maxlength="490" placeholder="Objet de l'email"></label>

    <div class="stack-sm">
      <span>Message</span>
      <div class="input" contenteditable="true" data-policy-editor style="min-height:180px;"></div>
      <textarea name="message" data-policy-editor-input hidden></textarea>
      <p class="text-muted" style="margin:0;">Mise en forme : sélectionnez du texte pour afficher les boutons Gras / Souligné. Ce contenu remplace la variable <code>{{message}}</code> du template.</p>
    </div>

    <label>
      <span>Pièce jointe (optionnelle)</span>
      <input class="input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.csv,.zip">
      <span class="text-muted" style="font-size:.85rem;">10 Mo maximum. Le fichier est joint à l'email et la variable <code>{{piece_jointe}}</code> affiche un bouton de téléchargement.</span>
    </label>

    <button class="btn-primary" type="submit">✉️ Envoyer</button>
  </form>

  <h2 class="section-title" style="margin-top:2rem;">Historique des envois</h2>
  <?php if ($logs === []): ?>
    <p class="empty-state">Aucun email envoyé pour l'instant.</p>
  <?php else: ?>
    <div class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Partenaire</th>
            <th>Email</th>
            <th>Sujet</th>
            <th>Pièce jointe</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?= \App\View::e((string) $log['created_at']) ?></td>
              <td><?= \App\View::e((string) $log['partner_name']) ?></td>
              <td><?= \App\View::e((string) $log['recipient_email']) ?></td>
              <td><?= \App\View::e((string) $log['subject']) ?></td>
              <td><?= \App\View::e((string) ($log['attachment_name'] ?? '')) ?></td>
              <td>
                <?php if ((string) $log['status'] === 'SENT'): ?>
                  <span class="badge badge-confirmed">Envoyé</span>
                <?php else: ?>
                  <span class="badge badge-cancelled" title="<?= \App\View::e((string) ($log['error_message'] ?? '')) ?>">Échec</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
