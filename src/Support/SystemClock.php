<?php

declare(strict_types=1);

namespace Budget\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function __construct(private readonly DateTimeZone $zona)
    {
    }

    public function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zona);
    }
}
