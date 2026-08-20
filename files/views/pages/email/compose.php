<?php declare(strict_types=1);
/** @var array|null $replyTo */
$replyTo = $replyTo ?? null;
$to = '';
$subject = '';
$body = '';

if ($replyTo) {
  $to = $replyTo['from_email'];
  $subject = 'Re: ' . $replyTo['subject'];
  $body = "\n\n---\nLe " . date('d/m/Y H:i', strtotime($replyTo['received_at'])) . ", " . htmlspecialchars($replyTo['from_name'] ?: $replyTo['from_email']) . " a écrit:\n\n" . strip_tags($replyTo['body_html'] ?: $replyTo['body_text']);
}
?>
<section class="container section-lg narrow">
  <div class="card card-body stack-md">
    <div class="section-header">
      <a href="/email" class="btn">← Retour aux emails</a>
      <h1>Nouvel email</h1>
    </div>

    <form method="post" action="/email/send" class="stack-md">
      <label>
        <span>À</span>
        <input class="input" type="email" name="to" value="<?= \App\View::e($to) ?>" required>
      </label>

      <label>
        <span>Sujet</span>
        <input class="input" type="text" name="subject" value="<?= \App\View::e($subject) ?>" required>
      </label>

      <label>
        <span>Message</span>
        <textarea class="input" name="body" rows="15" required><?= \App\View::e($body) ?></textarea>
      </label>

      <div class="form-actions">
        <button class="btn-primary" type="submit">Envoyer</button>
        <a href="/email" class="btn">Annuler</a>
      </div>
    </form>
  </div>
</section>

<style>
.form-actions {
  display: flex;
  gap: 0.5rem;
}

textarea {
  font-family: 'Courier New', monospace;
  font-size: 0.9rem;
}
</style>
