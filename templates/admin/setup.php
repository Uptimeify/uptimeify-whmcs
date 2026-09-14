<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\View;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tokenField */
/** @var string $apiBaseUrl */
/** @var array{publicId:string,label:string,boundLabel:string,pending:bool}|null $organizationSwitch */
?>
<?php if ($organizationSwitch !== null) : ?>
  <?= View::render('organization_switch', get_defined_vars()) ?>
<?php endif; ?>
<h2><?= View::e($t('setup.title')) ?></h2>
<p><?= View::e($t('setup.intro')) ?></p>
<form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'connect'])) ?>" class="form-horizontal">
  <?= $tokenField ?>
  <input type="hidden" name="back" value="settings">
  <div class="form-group">
    <label class="col-sm-3 control-label" for="uptimeify-api-token"><?= View::e($t('setup.token')) ?></label>
    <div class="col-sm-6">
      <input type="password" class="form-control" id="uptimeify-api-token" name="api_token" placeholder="wsm_…" autocomplete="off" required>
      <p class="help-block"><?= View::e($t('setup.token_help')) ?></p>
    </div>
  </div>
  <details class="form-group">
    <summary class="col-sm-offset-3 col-sm-6"><?= View::e($t('setup.advanced')) ?></summary>
    <label class="col-sm-3 control-label" for="uptimeify-api-base-url"><?= View::e($t('settings.api_base_url')) ?></label>
    <div class="col-sm-6">
      <input type="url" class="form-control" id="uptimeify-api-base-url" name="api_base_url" value="<?= View::e($apiBaseUrl) ?>">
      <p class="help-block"><?= View::e($t('settings.api_base_url_help')) ?></p>
    </div>
  </details>
  <div class="form-group">
    <div class="col-sm-offset-3 col-sm-6"><button type="submit" class="btn btn-primary"><?= View::e($t('setup.connect')) ?></button></div>
  </div>
</form>
