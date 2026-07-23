<?php declare(strict_types=1); ?>
<section class="container section-lg narrow-wide">
  <h1><?= \App\View::e(\App\I18n::t('update.title')) ?></h1>

  <div class="card card-body stack-md">
    <h2 class="section-title"><?= \App\View::e(\App\I18n::t('update.deploy_title')) ?></h2>
    <p class="text-muted"><?= \App\View::e(\App\I18n::t('update.deploy_desc')) ?> <code>images/</code>, <code>files/storage/</code>, <code>.env</code></p>
    <form method="post" action="/admin/mise-a-jour" enctype="multipart/form-data" class="stack-md" data-update-form>
      <label>
        <span><?= \App\View::e(\App\I18n::t('update.zip_label')) ?></span>
        <input class="input" type="file" name="update_zip" accept=".zip" required>
      </label>
      <button class="btn-primary" type="submit" data-update-submit><?= \App\View::e(\App\I18n::t('update.submit')) ?></button>
      <div class="update-progress" data-update-progress hidden
           data-label-uploading="<?= \App\View::e(\App\I18n::t('update.progress_uploading')) ?>"
           data-label-applying="<?= \App\View::e(\App\I18n::t('update.progress_applying')) ?>"
           data-label-done="<?= \App\View::e(\App\I18n::t('update.progress_done')) ?>">
        <div class="update-progress-track"><span class="update-progress-bar" data-update-progress-bar style="width:0%"></span></div>
        <p class="update-progress-label"><span data-update-progress-text><?= \App\View::e(\App\I18n::t('update.progress_uploading')) ?></span> <span data-update-progress-pct>0%</span></p>
      </div>
    </form>
  </div>

  <div class="card card-body stack-md">
    <h2 class="section-title"><?= \App\View::e(\App\I18n::t('update.restore_title')) ?></h2>
    <?php if (empty($backups)): ?>
      <p class="empty-state"><?= \App\View::e(\App\I18n::t('update.no_backup')) ?></p>
    <?php else: ?>
      <p class="text-muted"><?= \App\View::e(\App\I18n::t('update.last_backup')) ?> <strong><?= \App\View::e($backups[0]['label']) ?></strong></p>
      <form method="post" action="/admin/mise-a-jour/rollback" data-confirm-submit="<?= \App\View::e(\App\I18n::t('update.restore_confirm')) ?>">
        <button class="btn-secondary" type="submit"><?= \App\View::e(\App\I18n::t('update.restore_button')) ?></button>
      </form>
      <?php if (count($backups) > 1): ?>
        <details class="stack-sm" style="margin-top:.5rem">
          <summary class="text-muted" style="cursor:pointer"><?= \App\View::e(\App\I18n::t('update.all_backups')) ?> (<?= count($backups) ?>)</summary>
          <ul class="stack-sm" style="margin-top:.5rem">
            <?php foreach ($backups as $b): ?>
              <li><code><?= \App\View::e($b['label']) ?></code></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
