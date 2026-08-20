<?php declare(strict_types=1);
/** @var array|null $account */
/** @var array $emails */
/** @var int $unreadCount */
$account = $account ?? null;
$emails = $emails ?? [];
$unreadCount = $unreadCount ?? 0;
?>
<section class="container section-lg">
  <div class="card card-body stack-md">
    <div class="section-header">
      <h1>Email</h1>
      <div class="section-actions">
        <a href="/email/compose" class="btn-primary">Nouvel email</a>
        <a href="/account" class="btn">Paramètres</a>
      </div>
    </div>

    <?php if (!$account): ?>
      <div class="alert alert-warning">
        <p>Vous n'avez pas encore configuré votre mot de passe email.</p>
        <p><a href="/account">Configurer mon mot de passe email →</a></p>
      </div>
    <?php else: ?>
      <div class="email-header">
        <p class="muted">Compte: <strong><?= \App\View::e($account['email']) ?></strong></p>
        <p class="muted">Emails non lus: <strong><?= $unreadCount ?></strong></p>
        <form method="post" action="/email/sync" style="display: inline;">
          <button type="submit" class="btn-secondary">Synchroniser</button>
        </form>
      </div>

      <?php if (empty($emails)): ?>
        <div class="alert alert-info">
          Aucun email dans votre boîte de réception.
        </div>
      <?php else: ?>
        <div class="email-list">
          <?php foreach ($emails as $email): ?>
            <div class="email-item <?= $email['is_read'] ? '' : 'unread' ?>">
              <div class="email-item-header">
                <div>
                  <strong class="email-from"><?= \App\View::e($email['from_name'] ?: $email['from_email']) ?></strong>
                  <span class="muted"><?= \App\View::e($email['from_email']) ?></span>
                </div>
                <span class="muted email-date">
                  <?php 
                  $date = strtotime($email['received_at']);
                  $now = time();
                  $diff = $now - $date;
                  
                  if ($diff < 3600) {
                    echo floor($diff / 60) . 'm';
                  } elseif ($diff < 86400) {
                    echo floor($diff / 3600) . 'h';
                  } else {
                    echo date('d/m/Y', $date);
                  }
                  ?>
                </span>
              </div>
              <div class="email-item-preview">
                <p class="email-subject">
                  <a href="/email/<?= (int) $email['id'] ?>">
                    <?= \App\View::e($email['subject']) ?>
                  </a>
                </p>
                <p class="email-preview muted">
                  <?php
                  $preview = strip_tags($email['body_html'] ?: $email['body_text']);
                  echo \App\View::e(substr($preview, 0, 150));
                  if (strlen($preview) > 150) echo '...';
                  ?>
                </p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<style>
.email-header {
  padding: 1rem;
  background: #f5f5f5;
  border-radius: 4px;
  margin-bottom: 1.5rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.email-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.email-item {
  padding: 1rem;
  border: 1px solid #e0e0e0;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  transition: background 0.2s;
}

.email-item:hover {
  background: #fafafa;
}

.email-item.unread {
  background: #f0f8ff;
  border-left: 4px solid #2563eb;
}

.email-item-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.5rem;
}

.email-from {
  display: block;
  font-weight: 600;
}

.email-date {
  white-space: nowrap;
  font-size: 0.875rem;
}

.email-subject {
  margin: 0;
  font-weight: 500;
}

.email-subject a {
  text-decoration: none;
  color: inherit;
}

.email-subject a:hover {
  text-decoration: underline;
}

.email-preview {
  margin: 0;
  font-size: 0.9rem;
}
</style>
