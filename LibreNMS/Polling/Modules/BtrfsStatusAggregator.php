<?php

namespace LibreNMS\Polling\Modules;

class BtrfsStatusAggregator extends StatusAggregator
{
    public const CAT_IO = 'io';
    public const CAT_SCRUB = 'scrub';
    public const CAT_BALANCE = 'balance';

    public function addIoStatus(int $code): void
    {
        $this->add(self::CAT_IO, $code);
    }

    public function addScrubStatus(int $code): void
    {
        $this->add(self::CAT_SCRUB, $code);
    }

    public function addBalanceStatus(int $code): void
    {
        $this->add(self::CAT_BALANCE, $code);
    }

    public function hasAnyStatus(): bool
    {
        return $this->hasAny(self::CAT_IO) || $this->hasAny(self::CAT_SCRUB) || $this->hasAny(self::CAT_BALANCE);
    }

    public function hasData(): bool
    {
        return $this->hasAnyStatus();
    }

    public function hasMissing(): bool
    {
        return $this->has(self::CAT_IO, BtrfsStatusMapper::STATUS_MISSING)
            || $this->has(self::CAT_SCRUB, BtrfsStatusMapper::STATUS_MISSING)
            || $this->has(self::CAT_BALANCE, BtrfsStatusMapper::STATUS_MISSING);
    }

    public function hasError(): bool
    {
        return $this->has(self::CAT_IO, BtrfsStatusMapper::STATUS_ERROR)
            || $this->has(self::CAT_SCRUB, BtrfsStatusMapper::STATUS_ERROR)
            || $this->has(self::CAT_BALANCE, BtrfsStatusMapper::STATUS_ERROR)
            || $this->hasMissing();
    }

    public function hasRunning(): bool
    {
        return $this->has(self::CAT_SCRUB, BtrfsStatusMapper::STATUS_RUNNING)
            || $this->has(self::CAT_BALANCE, BtrfsStatusMapper::STATUS_RUNNING);
    }

    public function ioHasData(): bool
    {
        return $this->hasAny(self::CAT_IO);
    }

    public function ioMissing(): bool
    {
        return $this->has(self::CAT_IO, BtrfsStatusMapper::STATUS_MISSING);
    }

    public function ioHasError(): bool
    {
        return $this->has(self::CAT_IO, BtrfsStatusMapper::STATUS_ERROR)
            || $this->ioMissing();
    }

    public function scrubHasData(): bool
    {
        return $this->hasAny(self::CAT_SCRUB);
    }

    public function scrubHasError(): bool
    {
        return $this->has(self::CAT_SCRUB, BtrfsStatusMapper::STATUS_ERROR);
    }

    public function scrubRunning(): bool
    {
        return $this->has(self::CAT_SCRUB, BtrfsStatusMapper::STATUS_RUNNING);
    }

    public function balanceHasData(): bool
    {
        return $this->hasAny(self::CAT_BALANCE);
    }

    public function balanceHasError(): bool
    {
        return $this->has(self::CAT_BALANCE, BtrfsStatusMapper::STATUS_ERROR);
    }

    public function balanceRunning(): bool
    {
        return $this->has(self::CAT_BALANCE, BtrfsStatusMapper::STATUS_RUNNING);
    }
}
