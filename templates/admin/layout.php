<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Admin\Dispatcher;
use WHMCS\Module\Addon\Uptimeify\Admin\View;

/** @var \Closure(string, array<string,scalar>=):string $t */
/** @var string $modulelink */
/** @var string $tab */
/** @var string $content */
/** @var string $version */
/** @var array{type:string,message:string}|null $flash */
/** @var list<array{type:string,message:string,link:?string,linkText:?string}> $banners */
$alertClass = ['success' => 'alert-success', 'warning' => 'alert-warning', 'error' => 'alert-danger', 'danger' => 'alert-danger'];
?>
<div class="uptimeify-admin">
<?php if ($tab !== 'setup') : ?>
  <ul class="nav nav-tabs" role="tablist">
  <?php foreach (Dispatcher::TABS as $name) : ?>
    <li role="presentation" class="<?= $tab === $name ? 'active' : '' ?>"><a href="<?= View::e(View::url($modulelink, ['action' => $name])) ?>"><?= View::e($t('tabs.' . $name)) ?></a></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php foreach ($banners as $banner) : ?>
  <div class="alert <?= View::e($alertClass[$banner['type']] ?? 'alert-info') ?>" role="alert" style="margin-top:15px">
    <?= View::e($banner['message']) ?>
    <?php if ($banner['link'] !== null) : ?>
      <a class="alert-link" href="<?= View::e($banner['link']) ?>"><?= View::e($banner['linkText']) ?></a>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($flash !== null) : ?>
  <div class="alert <?= View::e($alertClass[$flash['type']] ?? 'alert-info') ?>" role="alert" style="margin-top:15px"><?= View::e($flash['message']) ?></div>
<?php endif; ?>
  <div class="tab-content" style="padding-top:15px"><?= $content ?></div>
  <p class="text-muted small"><?= View::e($t('footer.version', ['version' => $version])) ?></p>
</div>
