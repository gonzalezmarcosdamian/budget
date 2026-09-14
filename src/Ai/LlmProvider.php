<?php

declare(strict_types=1);

namespace Budget\Ai;

/**
 * La costura que permite cambiar de modelo sin tocar el resto.
 *
 * Cada proveedor declara qué tareas sabe hacer; el Router arma la cadena
 * y va bajando cuando uno agota su cuota o se cae. Cambiar de modelo
 * tiene que ser editar una configuración, no un despliegue de lógica.
 */
interface LlmProvider
{
    public const TAREA_TEXTO = 'texto';
    public const TAREA_IMAGEN = 'imagen';
    public const TAREA_AUDIO = 'audio';

    public function nombre(): string;

    public function disponible(): bool;

    public function soporta(string $tarea): bool;

    /**
     * Devuelve null cuando el modelo no encontró un gasto. Las fallas
     * técnicas se lanzan como excepción para que el Router pase al
     * siguiente proveedor.
     */
    public function extraerDeTexto(string $texto): ?Extraction;

    public function extraerDeImagen(string $binario, string $mimeType, string $epigrafe = ''): ?Extraction;

    public function extraerDeAudio(string $binario, string $mimeType): ?Extraction;
}
