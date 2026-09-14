<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

/**
 * Counters and messages of one reconcile run or one queue job (spec §8.3 step 10).
 */
final class ReconcileResult
{
    public int $customersCreated = 0;
    public int $monitorsCreated = 0;
    public int $adopted = 0;
    public int $resumed = 0;
    public int $paused = 0;
    public int $deleted = 0;
    public int $orphaned = 0;
    /** Map rows let go while the remote monitor stays untouched (configuration exclusion, adopted monitor, round 2 D1). */
    public int $detached = 0;
    /** Websites pointed at a changed target in place (round 2, D4). */
    public int $updated = 0;
    public int $quotaSkipped = 0;
    public int $errors = 0;
    /** Queue jobs pushed for the work a time-limited run left over (spec §9.1). */
    public int $queued = 0;
    /** @var list<string> */
    public array $messages = [];
    /**
     * Map rows this run or job left in `state=error`, "kind:refId:type" => `last_error`. Stale errors of rows not
     * handled are not listed, nor a known invalidUrl or quota error of a row that was not sent again.
     *
     * @var array<string,string>
     */
    public array $rowErrors = [];
    public bool $aborted = false;
    public ?string $abortReason = null;

    public function summary(): string
    {
        return sprintf(
            'customers=%d created=%d adopted=%d resumed=%d paused=%d deleted=%d orphaned=%d quota=%d errors=%d',
            $this->customersCreated,
            $this->monitorsCreated,
            $this->adopted,
            $this->resumed,
            $this->paused,
            $this->deleted,
            $this->orphaned,
            $this->quotaSkipped,
            $this->errors,
        ) . ($this->updated > 0 ? ' updated=' . $this->updated : '') . ($this->detached > 0 ? ' detached=' . $this->detached : '')
            . ($this->queued > 0 ? ' queued=' . $this->queued : '') . ($this->aborted ? ' aborted=' . $this->abortReason : '');
    }
}
