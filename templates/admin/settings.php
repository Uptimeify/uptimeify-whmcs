<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\View;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tokenField */
/** @var string $organizationName */
/** @var array{publicId:string,label:string,boundLabel:string,pending:bool}|null $organizationSwitch */
/** @var array<string,array<string,mixed>> $packages */
/** @var string|null $packagesError */
/** @var bool $packageMissing */
/** @var array{api_base_url:string,default_package_type:string,default_monitoring_type:string,default_check_interval:int,on_terminate:string,brand_name:string,status_portal_url:string} $values */
/** @var array<string,bool> $bools */
/** @var list<string> $monitoringTypes */
$checkbox = static function (string $key) use ($t, $bools): string {
    return '<div class="checkbox"><label>'
        . '<input type="hidden" name="form_bools[]" value="' . View::e($key) . '">'
        . '<input type="checkbox" name="' . View::e($key) . '" value="1"' . (($bools[$key] ?? false) ? ' checked' : '') . '> '
        . View::e($t('settings.' . $key))
        . '</label></div>';
};
$package = $values['default_package_type'];
?>
<?php if ($organizationSwitch !== null) : ?>
  <?= View::render('organization_switch', get_defined_vars()) ?>
<?php endif; ?>
<h3><?= View::e($t('settings.connection')) ?></h3>
<div class="form-horizontal">
  <div class="form-group">
    <label class="col-sm-3 control-label"><?= View::e($t('settings.organization')) ?></label>
    <div class="col-sm-6"><p class="form-control-static"><?= View::e($organizationName) ?></p></div>
    <div class="col-sm-3">
      <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'disconnect'])) ?>" style="margin:0">
        <?= $tokenField ?>
        <input type="hidden" name="back" value="settings">
        <button type="submit" class="btn btn-default"><?= View::e($t('settings.disconnect')) ?></button>
      </form>
    </div>
  </div>
</div>
<form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'connect'])) ?>" class="form-horizontal">
  <?= $tokenField ?>
  <input type="hidden" name="back" value="settings">
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-api-token"><?= View::e($t('settings.reset_token')) ?></label>
    <div class="col-sm-6">
      <input type="password" class="form-control" id="uptimeify-api-token" name="api_token" placeholder="wsm_…" autocomplete="off" required>
      <p class="help-block"><?= View::e($t('settings.reset_token_help')) ?></p>
    </div>
    <div class="col-sm-3"><button type="submit" class="btn btn-default"><?= View::e($t('settings.reset_token')) ?></button></div>
  </div>
</form>

<form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'save_settings'])) ?>" class="form-horizontal">
  <?= $tokenField ?>
  <input type="hidden" name="back" value="settings">

  <h3><?= View::e($t('settings.section_sync')) ?></h3>
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-package"><?= View::e($t('settings.default_package_type')) ?></label>
    <div class="col-sm-6">
      <select class="form-control" id="uptimeify-package" name="default_package_type">
        <option value=""><?= View::e($t('settings.package_none')) ?></option>
        <?php if ($package !== '' && !isset($packages[$package])) : ?>
          <option value="<?= View::e($package) ?>" selected><?= View::e($package) ?></option>
        <?php endif; ?>
        <?php foreach ($packages as $key => $row) : ?>
          <?php $label = is_string($row['displayName'] ?? null) && $row['displayName'] !== '' ? $row['displayName'] : $key; ?>
          <option value="<?= View::e($key) ?>"<?= $key === $package ? ' selected' : '' ?>><?= View::e($label) ?><?= is_int($row['maxUrls'] ?? null) ? ' (' . View::e($t('settings.package_urls', ['count' => $row['maxUrls']])) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($packageMissing) : ?>
        <p class="help-block text-danger"><?= View::e($t('settings.package_missing', ['package' => $package])) ?></p>
      <?php endif; ?>
      <?php if ($packagesError !== null) : ?>
        <p class="help-block text-danger"><?= View::e($t('settings.packages_unavailable', ['message' => $packagesError])) ?></p>
      <?php endif; ?>
      <p class="help-block"><?= View::e($t('settings.default_package_type_help')) ?></p>
    </div>
  </div>
  <div class="form-group">
    <div class="col-sm-offset-3 col-sm-9">
      <?= $checkbox('auto_create_customers') ?>
      <?= $checkbox('auto_sync') ?>
    </div>
  </div>
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-monitoring-type"><?= View::e($t('settings.default_monitoring_type')) ?></label>
    <div class="col-sm-6">
      <select class="form-control" id="uptimeify-monitoring-type" name="default_monitoring_type">
        <?php foreach ($monitoringTypes as $type) : ?>
          <option value="<?= View::e($type) ?>"<?= $type === $values['default_monitoring_type'] ? ' selected' : '' ?>><?= View::e($t('settings.monitoring_type.' . $type)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-interval"><?= View::e($t('settings.default_check_interval')) ?></label>
    <div class="col-sm-2">
      <input type="number" class="form-control" id="uptimeify-interval" name="default_check_interval" min="1" max="60" value="<?= View::e($values['default_check_interval']) ?>">
    </div>
  </div>
  <div class="form-group">
    <label class="col-sm-3 control-label"><?= View::e($t('settings.on_terminate')) ?></label>
    <div class="col-sm-6">
      <?php foreach (['pause', 'delete'] as $mode) : ?>
        <div class="radio"><label><input type="radio" name="on_terminate" value="<?= View::e($mode) ?>"<?= $mode === $values['on_terminate'] ? ' checked' : '' ?>> <?= View::e($t('settings.on_terminate.' . $mode)) ?></label></div>
      <?php endforeach; ?>
    </div>
  </div>

  <h3><?= View::e($t('settings.section_types')) ?></h3>
  <div class="form-group">
    <div class="col-sm-offset-3 col-sm-9">
      <?= $checkbox('sync_hosting_website') ?>
      <?= $checkbox('sync_hosting_domain_expiry') ?>
      <?= $checkbox('sync_domain_expiry') ?>
      <?= $checkbox('sync_domain_dns') ?>
      <?= $checkbox('sync_server_ips') ?>
      <p class="help-block"><?= View::e($t('settings.types_detach_note')) ?></p>
    </div>
  </div>

  <h3><?= View::e($t('settings.section_display')) ?></h3>
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-brand"><?= View::e($t('settings.brand_name')) ?></label>
    <div class="col-sm-6">
      <input type="text" class="form-control" id="uptimeify-brand" name="brand_name" maxlength="100" value="<?= View::e($values['brand_name']) ?>" placeholder="Uptimeify">
      <p class="help-block"><?= View::e($t('settings.brand_name_help')) ?></p>
    </div>
  </div>
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-portal"><?= View::e($t('settings.status_portal_url')) ?></label>
    <div class="col-sm-6">
      <input type="url" class="form-control" id="uptimeify-portal" name="status_portal_url" value="<?= View::e($values['status_portal_url']) ?>">
      <p class="help-block"><?= View::e($t('settings.status_portal_url_help')) ?></p>
    </div>
  </div>
  <div class="form-group">
    <div class="col-sm-offset-3 col-sm-9">
      <?= $checkbox('product_block_enabled') ?>
      <?= $checkbox('drop_data_on_deactivate') ?>
    </div>
  </div>
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-base-url"><?= View::e($t('settings.api_base_url')) ?></label>
    <div class="col-sm-6">
      <input type="url" class="form-control" id="uptimeify-base-url" name="api_base_url" value="<?= View::e($values['api_base_url']) ?>">
      <p class="help-block"><?= View::e($t('settings.api_base_url_help')) ?></p>
    </div>
  </div>
  <div class="form-group">
    <div class="col-sm-offset-3 col-sm-6"><button type="submit" class="btn btn-primary"><?= View::e($t('settings.save')) ?></button></div>
  </div>
</form>
