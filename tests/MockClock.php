<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;

class MockClock implements ClockInterface
{
    private DateTimeImmutable $now;
    private DateTimeZone $timezone;

    public function __construct(string $time = 'now', string $timezone = 'UTC')
    {
        $this->timezone = new DateTimeZone($timezone);
        $this->now = new DateTimeImmutable($time, $this->timezone);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function setNow(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function modify(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
