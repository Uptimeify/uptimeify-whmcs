<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Admin;

use WHMCS\Module\Addon\Uptimeify\Container;

/** Log tab (spec §9.1): the last 200 queue jobs, and "retry" for dead ones. */
final class LogController
{
    public const LIMIT = 200;

    /** @param array<string,mixed> $ctx */
    public function show(array $ctx): string
    {
        $vars = $ctx + ['jobs' => Container::queue()->recent(self::LIMIT)];
        return View::render('layout', $vars + ['content' => View::render('log', $vars)]);
    }

    public function retryDead(int $id): void
    {
        if ($id <= 0 || !Container::queue()->retryDead($id)) {
            throw new \InvalidArgumentException(View::t('error.unknown_job', ['id' => $id]));
        }
        Flash::set('success', View::t('log.retried', ['id' => $id]));
    }
}
