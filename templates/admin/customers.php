<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\CustomersController;
use WHMCS\Module\Addon\Uptimeify\Admin\View;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tokenField */
/** @var list<array{client:\WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRow,customerPublicId:?string,customerName:?string,source:?string,mode:string}> $rows */
/** @var int $totalRows */
/** @var string $q */
/** @var string $filterMode */
/** @var list<array{publicId:string,name:string,email:string}> $customerOptions */
$modeLabel = ['default' => 'customers.mode_default', 'sync' => 'customers.mode_sync', 'skip' => 'customers.mode_skip'];
$sources = ['matched_id', 'matched_email', 'matched_name', 'created', 'manual'];
?>
<form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'save_filter'])) ?>" style="margin-bottom:15px">
  <?= $tokenField ?>
  <input type="hidden" name="back" value="customers">
  <strong><?= View::e($t('customers.filter_mode')) ?></strong>
  <?php foreach (['blacklist', 'whitelist'] as $mode) : ?>
    <div class="radio"><label><input type="radio" name="filter_mode" value="<?= View::e($mode) ?>"<?= $mode === $filterMode ? ' checked' : '' ?>> <?= View::e($t('customers.' . $mode)) ?></label></div>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-default btn-sm"><?= View::e($t('customers.save')) ?></button>
  <p class="help-block small"><?= View::e($t('customers.detach_note')) ?></p>
</form>

<form method="get" action="<?= View::e(View::path($modulelink)) ?>" class="form-inline" style="margin-bottom:10px">
  <?= View::hiddenQuery($modulelink) ?>
  <input type="hidden" name="action" value="customers">
  <input type="search" class="form-control" name="q" value="<?= View::e($q) ?>" placeholder="<?= View::e($t('customers.search')) ?>">
  <button type="submit" class="btn btn-default"><?= View::e($t('dashboard.filter')) ?></button>
</form>

<datalist id="uptimeify-customers">
<?php foreach ($customerOptions as $option) : ?>
  <option value="<?= View::e($option['publicId']) ?>"><?= View::e(trim($option['name'] . ' ' . ($option['email'] !== '' ? '<' . $option['email'] . '>' : ''))) ?></option>
<?php endforeach; ?>
</datalist>
<?php if ($customerOptions === []) : ?>
  <p class="text-muted small"><?= View::e($t('customers.no_cache')) ?></p>
<?php endif; ?>

<?php if ($rows === []) : ?>
  <p class="text-muted"><?= View::e($t('customers.empty')) ?></p>
<?php else : ?>
<div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead>
      <tr>
        <th><?= View::e($t('customers.col_client')) ?></th>
        <th><?= View::e($t('customers.col_status')) ?></th>
        <th><?= View::e($t('customers.col_customer')) ?></th>
        <th><?= View::e($t('customers.col_source')) ?></th>
        <th><?= View::e($t('customers.set_mapping')) ?></th>
        <th title="<?= View::e($t('customers.detach_note')) ?>"><?= View::e($t('customers.col_mode')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row) : ?>
      <?php $client = $row['client']; ?>
      <tr>
        <td><?= View::e('#' . $client->id . ' ' . $client->displayName) ?><br><small class="text-muted"><?= View::e($client->email) ?></small></td>
        <td><?= View::e(in_array($client->status, ['Active', 'Inactive', 'Closed'], true) ? $t('clientstatus.' . strtolower($client->status)) : $client->status) ?></td>
        <td>
        <?php if ($row['customerPublicId'] !== null) : ?>
          <?= View::e($row['customerName'] ?? '') ?><br><small class="text-muted"><?= View::e($row['customerPublicId']) ?></small>
        <?php else : ?>
          —
        <?php endif; ?>
        </td>
        <td><?= $row['source'] !== null ? View::e(in_array($row['source'], $sources, true) ? $t('source.' . $row['source']) : $row['source']) : '—' ?></td>
        <td>
          <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'set_customer'])) ?>" class="form-inline" style="margin:0">
            <?= $tokenField ?>
            <input type="hidden" name="back" value="customers">
            <input type="hidden" name="client_id" value="<?= View::e($client->id) ?>">
            <input type="text" class="form-control input-sm" name="customer_public_id" list="uptimeify-customers" placeholder="<?= View::e($t('dashboard.customer_placeholder')) ?>" required>
            <button type="submit" class="btn btn-default btn-xs"><?= View::e($t('customers.set_mapping')) ?></button>
          </form>
        </td>
        <td>
          <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'set_client_mode'])) ?>" class="form-inline" style="margin:0">
            <?= $tokenField ?>
            <input type="hidden" name="back" value="customers">
            <input type="hidden" name="client_id" value="<?= View::e($client->id) ?>">
            <select class="form-control input-sm" name="mode">
            <?php foreach (CustomersController::MODES as $mode) : ?>
              <option value="<?= View::e($mode) ?>"<?= $mode === $row['mode'] ? ' selected' : '' ?>><?= View::e($t($modeLabel[$mode])) ?></option>
            <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-default btn-xs"><?= View::e($t('customers.save')) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($totalRows > count($rows)) : ?>
  <p class="text-muted"><?= View::e($t('dashboard.truncated', ['shown' => count($rows), 'total' => $totalRows])) ?></p>
<?php endif; ?>
<?php endif; ?>
