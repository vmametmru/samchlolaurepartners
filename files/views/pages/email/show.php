<?php declare(strict_types=1);
/** @var array $email */
$email = $email ?? [];

// Helper function for safe HTML sanitization
function sanitizeEmailHtml(string $html): string {
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOEMPTY);
    
    $xpath = new DOMXPath($dom);
    
    // Remove dangerous elements
    foreach ($xpath->query('//*[@onclick or @onerror or @onload or @onmouseover]') as $node) {
        $node->parentNode->removeChild($node);
    }
    
    // Remove script tags and style tags
    foreach ($xpath->query('//script | //style') as $node) {
        $node->parentNode->removeChild($node);
    }
    
    // Remove javascript: protocol from href/src
    foreach ($xpath->query('//*[@href or @src]') as $node) {
        foreach (['href', 'src'] as $attr) {
            if ($node->hasAttribute($attr)) {
                $value = $node->getAttribute($attr);
                if (stripos($value, 'javascript:') === 0) {
                    $node->removeAttribute($attr);
                }
            }
        }
    }
    
    // Export and clean up
    $html = $dom->saveHTML();
    $html = preg_replace('/<\?xml[^>]*\?>/', '', $html);
    $html = preg_replace('/^<!DOCTYPE[^>]*>/', '', $html);
    $html = str_replace(['<html>', '</html>', '<body>', '</body>'], '', $html);
    
    return $html;
}
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
          <a href="/email/compose?reply_to=<?= (int) $email['id'] ?>" class="btn-primary">Répondre</a>
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
          echo sanitizeEmailHtml($email['body_html']);
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
