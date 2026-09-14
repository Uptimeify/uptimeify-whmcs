<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

/**
 * A queue job ended with an error. The message is the plain-text reason stored in the queue row (spec §8.4).
 * `fatal`: a retry cannot help (quota, configuration or mapping error), the worker marks the job dead
 * at once; otherwise it backs off and retries.
 */
final class JobFailedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $fatal,
        public readonly ReconcileResult $result,
    ) {
        parent::__construct($message);
    }
}
