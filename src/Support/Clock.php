<?php

declare(strict_types=1);

namespace Budget\Support;

use DateTimeImmutable;

/**
 * El tiempo se inyecta para que los tests de fechas relativas
 * ("ayer", "el mes pasado") sean deterministas.
 */
interface Clock
{
    public function ahora(): DateTimeImmutable;
}
