<?php

declare(strict_types=1);

namespace Budget\Expense;

use Budget\Support\Money;
use DateTimeImmutable;

/**
 * Un gasto propuesto, todavía sin confirmar.
 *
 * Inmutable: cada corrección del usuario produce un borrador nuevo en
 * vez de mutar el anterior. Así seguir el historial de una edición es
 * trivial y no hay efectos colaterales escondidos.
 */
final class Draft
{
    public const FUENTE_TEXTO = 'texto';
    public const FUENTE_FOTO = 'foto';
    public const FUENTE_VOZ = 'voz';
    public const FUENTE_REENVIO = 'reenvio';
    public const FUENTE_MAIL = 'mail';
    public const FUENTE_API = 'api';

    public function __construct(
        public readonly Money $monto,
        public readonly DateTimeImmutable $fecha,
        public readonly string $comercio = '',
        public readonly string $descripcion = '',
        public readonly ?string $categoria = null,
        public readonly string $medioPago = '',
        public readonly string $fuente = self::FUENTE_TEXTO,
        public readonly ?float $confianza = null,
        public readonly string $modelo = '',
    ) {
    }

    public function conCategoria(?string $categoria): self
    {
        return $this->copiarCon(categoria: $categoria);
    }

    public function conComercio(string $comercio): self
    {
        return $this->copiarCon(comercio: $comercio);
    }

    public function conMonto(Money $monto): self
    {
        return $this->copiarCon(monto: $monto);
    }

    public function conFecha(DateTimeImmutable $fecha): self
    {
        return $this->copiarCon(fecha: $fecha);
    }

    public function conOrigen(string $fuente, string $modelo = '', ?float $confianza = null): self
    {
        return $this->copiarCon(fuente: $fuente, modelo: $modelo, confianza: $confianza);
    }

    /** Etiqueta principal de la tarjeta de confirmación. */
    public function titulo(): string
    {
        if ($this->comercio !== '') {
            return $this->comercio;
        }

        return $this->descripcion !== '' ? $this->descripcion : 'Gasto';
    }

    /**
     * Por debajo de este umbral conviene preguntar en vez de proponer:
     * un gasto mal categorizado en silencio destruye la confianza en los
     * reportes más rápido que uno que nunca se cargó.
     */
    public function necesitaConfirmacionExplicita(): bool
    {
        return $this->confianza !== null && $this->confianza < 0.70;
    }

    private function copiarCon(
        ?Money $monto = null,
        ?DateTimeImmutable $fecha = null,
        ?string $comercio = null,
        ?string $descripcion = null,
        ?string $categoria = null,
        ?string $medioPago = null,
        ?string $fuente = null,
        ?float $confianza = null,
        ?string $modelo = null,
    ): self {
        return new self(
            monto: $monto ?? $this->monto,
            fecha: $fecha ?? $this->fecha,
            comercio: $comercio ?? $this->comercio,
            descripcion: $descripcion ?? $this->descripcion,
            categoria: $categoria ?? $this->categoria,
            medioPago: $medioPago ?? $this->medioPago,
            fuente: $fuente ?? $this->fuente,
            confianza: $confianza ?? $this->confianza,
            modelo: $modelo ?? $this->modelo,
        );
    }
}
