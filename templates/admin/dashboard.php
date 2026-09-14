<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\View;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tokenField */
/** @var list<array{key:string,kind:string,refId:int,monitorType:string,target:string,clientId:int,clientName:string,customerPublicId:?string,state:string,lastError:?string,publicId:?string,liveStatus:?string,planned:bool}> $rows */
/** @var int $totalRows */
/** @var string $q */
/** @var string $stateFilter */
/** @var list<string> $states */
/** @var array{pending:int,running:int,dead:int} $queueCounts */
/** @var array<mixed>|null $lastReconcile */
/** @var array<mixed>|null $org */
/** @var list<string> $ignoredTargets */
/** @var list<array{publicId:string,name:string,email:string}> $customerOptions */
$stateClass = [
    'not_monitored' => 'label-info', 'no_customer' => 'label-warning', 'active' => 'label-success', 'paused' => 'label-default',
    'paused_admin' => 'label-default', 'error' => 'label-danger', 'quota' => 'label-warning', 'orphaned' => 'label-warning',
];
$liveClass = ['up' => 'text-success', 'down' => 'text-danger', 'paused' => 'text-muted', 'unknown' => 'text-muted'];
$post = static function (string $action, array $fields, string $label, string $class = 'btn btn-default btn-xs') use ($modulelink, $tokenField): string {
    $html = '<form method="post" action="' . View::e(View::url($modulelink, ['action' => $action])) . '" style="display:inline;margin:0">' . $tokenField
        . '<input type="hidden" name="back" value="dashboard">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . View::e($name) . '" value="' . View::e($value) . '">';
    }
    return $html . '<button type="submit" class="' . View::e($class) . '">' . View::e($label) . '</button></form>';
};
?>
<div class="row">
  <div class="col-md-8">
    <p>
    <?php if (is_array($lastReconcile) && is_string($lastReconcile['at'] ?? null)) : ?>
      <?= View::e($t('dashboard.last_run', ['at' => $lastReconcile['at'], 'summary' => is_string($lastReconcile['summary'] ?? null) ? $lastReconcile['summary'] : ''])) ?>
    <?php else : ?>
      <?= View::e($t('dashboard.never')) ?>
    <?php endif; ?>
    </p>
    <p class="<?= $queueCounts['dead'] > 0 ? 'text-danger' : '' ?>"><?= View::e($t('dashboard.queue', $queueCounts)) ?></p>
    <?php if (is_array($org)) : ?>
      <p><?= View::e($t('dashboard.org', [
          'total' => (int) ($org['total'] ?? 0), 'up' => (int) ($org['up'] ?? 0), 'down' => (int) ($org['down'] ?? 0),
          'paused' => (int) ($org['paused'] ?? 0), 'incidents' => (int) ($org['incidents'] ?? 0),
      ])) ?>
      <?php if (($org['incidentsAvailable'] ?? true) === false) : ?>
        <br><small class="text-muted"><?= View::e($t('dashboard.incidents_unavailable')) ?></small>
      <?php elseif (($org['incidentsComplete'] ?? true) === false) : ?>
        <br><small class="text-muted"><?= View::e($t('dashboard.incidents_incomplete')) ?></small>
      <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
  <div class="col-md-4 text-right">
    <?= $post('reconcile', [], $t('dashboard.reconcile'), 'btn btn-primary') ?>
    <?= $post('refresh_status', [], $t('dashboard.refresh_status'), 'btn btn-default') ?>
    <p class="help-block small"><?= View::e($t('dashboard.sync_help')) ?></p>
  </div>
</div>

<form method="get" action="<?= View::e(View::path($modulelink)) ?>" class="form-inline" style="margin-bottom:10px">
  <?= View::hiddenQuery($modulelink) ?>
  <input type="hidden" name="action" value="dashboard">
  <input type="search" class="form-control" name="q" value="<?= View::e($q) ?>" placeholder="<?= View::e($t('dashboard.search')) ?>">
  <select class="form-control" name="state">
    <option value=""><?= View::e($t('dashboard.filter_all')) ?></option>
    <?php foreach ($states as $state) : ?>
      <option value="<?= View::e($state) ?>"<?= $state === $stateFilter ? ' selected' : '' ?>><?= View::e($t('state.' . $state)) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-default"><?= View::e($t('dashboard.filter')) ?></button>
</form>

<form id="uptimeify-selected" method="post" action="<?= View::e(View::url($modulelink, ['action' => 'sync_selected'])) ?>" style="margin:0">
  <?= $tokenField ?>
  <input type="hidden" name="back" value="dashboard">
</form>
<datalist id="uptimeify-customers">
<?php foreach ($customerOptions as $option) : ?>
  <option value="<?= View::e($option['publicId']) ?>"><?= View::e(trim($option['name'] . ' ' . ($option['email'] !== '' ? '<' . $option['email'] . '>' : ''))) ?></option>
<?php endforeach; ?>
</datalist>

<?php if ($rows === []) : ?>
  <p class="text-muted"><?= View::e($t('dashboard.empty')) ?></p>
<?php else : ?>
<div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead>
      <tr>
        <th><input type="checkbox" title="<?= View::e($t('dashboard.select_all')) ?>" onclick="document.querySelectorAll('.uptimeify-key').forEach(function (box) { box.checked = this.checked; }, this);"></th>
        <th><?= View::e($t('dashboard.col_target')) ?></th>
        <th><?= View::e($t('dashboard.col_type')) ?></th>
        <th><?= View::e($t('dashboard.col_client')) ?></th>
        <th><?= View::e($t('dashboard.col_customer')) ?></th>
        <th><?= View::e($t('dashboard.col_state')) ?></th>
        <th><?= View::e($t('dashboard.col_live')) ?></th>
        <th><?= View::e($t('dashboard.col_error')) ?></th>
        <th><?= View::e($t('dashboard.col_actions')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row) : ?>
      <?php $error = (string) $row['lastError']; ?>
      <tr data-state="<?= View::e($row['state']) ?>">
        <td><input type="checkbox" class="uptimeify-key" form="uptimeify-selected" name="keys[]" value="<?= View::e($row['key']) ?>"></td>
        <td><?= View::e($row['target']) ?></td>
        <td><?= View::e($t('type.' . $row['monitorType'])) ?></td>
        <td><?= View::e('#' . $row['clientId'] . ' ' . $row['clientName']) ?></td>
        <td><small><?= View::e($row['customerPublicId'] ?? '—') ?></small></td>
        <td><span class="label <?= View::e($stateClass[$row['state']] ?? 'label-default') ?>"><?= View::e($t('state.' . $row['state'])) ?></span></td>
        <td>
        <?php if ($row['liveStatus'] !== null) : ?>
          <span class="<?= View::e($liveClass[$row['liveStatus']] ?? '') ?>"><?= View::e($t('status.' . $row['liveStatus'])) ?></span>
        <?php else : ?>
          —
        <?php endif; ?>
        </td>
        <td class="small" title="<?= View::e($error) ?>"><?= View::e(mb_strimwidth($error, 0, 80, '…', 'UTF-8')) ?></td>
        <td style="white-space:nowrap">
          <?php if (($row['planned'] && in_array($row['state'], ['not_monitored', 'paused', 'error', 'quota', 'orphaned'], true)) || $row['state'] === 'paused_admin') : ?>
            <?= $post('enable', ['key' => $row['key']], $t('dashboard.enable')) ?>
          <?php endif; ?>
          <?php if (in_array($row['monitorType'], Reconciler::STATUS_TYPES, true) && in_array($row['state'], ['active', 'paused'], true) && $row['publicId'] !== null) : ?>
            <?= $post('pause', ['key' => $row['key']], $t('dashboard.pause')) ?>
          <?php endif; ?>
          <?= $post('ignore', ['target' => $row['target']], $t('dashboard.ignore')) ?>
          <?php if ($row['state'] === 'no_customer' && $row['clientId'] > 0) : ?>
            <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'set_customer'])) ?>" class="form-inline" style="display:inline;margin:0">
              <?= $tokenField ?>
              <input type="hidden" name="back" value="dashboard">
              <input type="hidden" name="client_id" value="<?= View::e($row['clientId']) ?>">
              <input type="text" class="form-control input-sm" name="customer_public_id" list="uptimeify-customers" placeholder="<?= View::e($t('dashboard.customer_placeholder')) ?>" required>
              <button type="submit" class="btn btn-default btn-xs"><?= View::e($t('dashboard.set_customer')) ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($totalRows > count($rows)) : ?>
  <p class="text-muted"><?= View::e($t('dashboard.truncated', ['shown' => count($rows), 'total' => $totalRows])) ?></p>
<?php endif; ?>
<button type="submit" form="uptimeify-selected" class="btn btn-primary"><?= View::e($t('dashboard.sync_selected')) ?></button>
<?php endif; ?>

<?php if ($ignoredTargets !== []) : ?>
  <h4><?= View::e($t('dashboard.ignored_targets')) ?></h4>
  <p class="help-block small"><?= View::e($t('dashboard.ignored_help')) ?></p>
  <ul class="list-unstyled">
  <?php foreach ($ignoredTargets as $target) : ?>
    <li><?= View::e($target) ?> <span class="label label-default"><?= View::e($t('state.ignored')) ?></span> <?= $post('unignore', ['target' => $target], $t('dashboard.unignore')) ?></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
