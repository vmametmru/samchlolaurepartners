<?php declare(strict_types=1);
/** @var array $email */
$email = $email ?? [];
?>
<section class="container section-lg">
  <div class="card card-body stack-md">
    <div class="section-header">
      <a href="/email" class="btn">← Retour aux emails</a>
      <h1><?= \App\View::e($email['subject']) ?></h1>
    </div>

    <div class="email-detail">
      <div class="email-detail-header">
        <div>
          <p><strong><?= \App\View::e($email['from_name'] ?: $email['from_email']) ?></strong> &lt;<?= \App\View::e($email['from_email']) ?>&gt;</p>
          <p class="muted">À: <?= \App\View::e($email['to_emails']) ?></p>
          <?php if (!empty($email['cc_emails'])): ?>
            <p class="muted">Cc: <?= \App\View::e($email['cc_emails']) ?></p>
          <?php endif; ?>
          <p class="muted"><?= date('d/m/Y H:i', strtotime($email['received_at'])) ?></p>
        </div>
        <div class="email-actions">
          <a href="/email/<?= (int) $email['id'] ?>/reply" class="btn-primary">Répondre</a>
          <form method="post" action="/email/<?= (int) $email['id'] ?>/delete" style="display: inline;">
            <button type="submit" class="btn-danger" onclick="return confirm('Êtes-vous sûr?')">Supprimer</button>
          </form>
        </div>
      </div>

      <hr>

      <div class="email-body">
        <?php
        // Display HTML if available, otherwise plain text
        if (!empty($email['body_html'])) {
          // Sanitize HTML to prevent XSS while preserving formatting
          echo strip_tags($email['body_html'], '<p><br><strong><em><u><h1><h2><h3><h4><h5><h6><ul><ol><li><a><img><table><tr><td><th>');
        } else {
          echo '<pre>' . \App\View::e($email['body_text']) . '</pre>';
        }
        ?>
      </div>
    </div>
  </div>
</section>

<style>
.email-detail-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid #e0e0e0;
}

.email-actions {
  display: flex;
  gap: 0.5rem;
  white-space: nowrap;
}

.email-body {
  line-height: 1.6;
  word-wrap: break-word;
}

.email-body pre {
  background: #f5f5f5;
  padding: 1rem;
  border-radius: 4px;
  overflow-x: auto;
}

.email-body a {
  color: #2563eb;
  text-decoration: underline;
}
</style>
