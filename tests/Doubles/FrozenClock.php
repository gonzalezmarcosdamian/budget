<?php

declare(strict_types=1);

namespace Budget\Tests\Doubles;

use Budget\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reloj congelado. Sin esto, los tests de "ayer" fallan una vez al día,
 * a medianoche, y nadie sabe por qué.
 */
final class FrozenClock implements Clock
{
    private function __construct(private readonly DateTimeImmutable $momento)
    {
    }

    public static function en(string $fecha, string $zona = 'America/Argentina/Buenos_Aires'): self
    {
        return new self(new DateTimeImmutable($fecha, new DateTimeZone($zona)));
    }

    public function ahora(): DateTimeImmutable
    {
        return $this->momento;
    }
}
