<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\View;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tokenField */
/** @var list<array<string,mixed>> $jobs */
$statusClass = ['pending' => 'label-info', 'running' => 'label-primary', 'done' => 'label-success', 'dead' => 'label-danger'];
?>
<p><?= View::e($t('log.intro')) ?></p>
<?php if ($jobs === []) : ?>
  <p class="text-muted"><?= View::e($t('log.empty')) ?></p>
<?php else : ?>
<div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead>
      <tr>
        <th><?= View::e($t('log.col_id')) ?></th>
        <th><?= View::e($t('log.col_job')) ?></th>
        <th><?= View::e($t('log.col_ref')) ?></th>
        <th><?= View::e($t('log.col_status')) ?></th>
        <th><?= View::e($t('log.col_attempts')) ?></th>
        <th><?= View::e($t('log.col_next')) ?></th>
        <th><?= View::e($t('log.col_created')) ?></th>
        <th><?= View::e($t('log.col_finished')) ?></th>
        <th><?= View::e($t('log.col_error')) ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $job) : ?>
      <?php $status = (string) $job['status']; ?>
      <tr>
        <td><?= View::e($job['id']) ?></td>
        <td><code><?= View::e($job['job']) ?></code></td>
        <td><?= View::e($job['ref_id']) ?></td>
        <td><span class="label <?= View::e($statusClass[$status] ?? 'label-default') ?>"><?= View::e($t('jobstatus.' . $status)) ?></span></td>
        <td><?= View::e($job['attempts']) ?></td>
        <td><?= View::e($job['next_run_at']) ?></td>
        <td><?= View::e($job['created_at']) ?></td>
        <td><?= View::e($job['finished_at'] ?? '') ?></td>
        <td class="small" style="max-width:32em;word-break:break-word"><?= View::e($job['last_error'] ?? '') ?></td>
        <td>
        <?php if ($status === 'dead') : ?>
          <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'retry_dead'])) ?>" style="margin:0">
            <?= $tokenField ?>
            <input type="hidden" name="back" value="log">
            <input type="hidden" name="id" value="<?= View::e($job['id']) ?>">
            <button type="submit" class="btn btn-default btn-xs"><?= View::e($t('log.retry')) ?></button>
          </form>
        <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
