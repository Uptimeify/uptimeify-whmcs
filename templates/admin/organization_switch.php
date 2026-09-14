<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\View;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tokenField */
/** @var array{publicId:string,label:string,boundLabel:string,pending:bool} $organizationSwitch */
// "Organisation wechseln" (round 2, D9): a separate POST with its own CSRF token, never part of another form.
?>
<div class="panel panel-danger uptimeify-organization-switch">
  <div class="panel-heading"><strong><?= View::e($t('organization.switch_title')) ?></strong></div>
  <div class="panel-body">
    <p><?= View::e($t('organization.switch_text', ['new' => $organizationSwitch['label'], 'bound' => $organizationSwitch['boundLabel']])) ?></p>
    <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'switch_organization'])) ?>" style="display:inline;margin:0">
      <?= $tokenField ?>
      <input type="hidden" name="back" value="settings">
      <input type="hidden" name="organization_public_id" value="<?= View::e($organizationSwitch['publicId']) ?>">
      <button type="submit" class="btn btn-danger" onclick="return window.confirm(<?= View::e((string) json_encode($t('organization.switch_confirm_prompt', ['new' => $organizationSwitch['label']]), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>);"><?= View::e($t('organization.switch_confirm')) ?></button>
    </form>
    <?php if ($organizationSwitch['pending']) : ?>
      <form method="post" action="<?= View::e(View::url($modulelink, ['action' => 'cancel_organization_switch'])) ?>" style="display:inline;margin:0">
        <?= $tokenField ?>
        <input type="hidden" name="back" value="settings">
        <button type="submit" class="btn btn-default"><?= View::e($t('organization.switch_cancel')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
