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
    public const TAREA_DOCUMENTO = 'documento';

    public function nombre(): string;

    /** Modelo concreto en uso. Se registra en ai_calls para poder comparar. */
    public function modelo(): string;

    public function disponible(): bool;

    public function soporta(string $tarea): bool;

    /**
     * Devuelve la lista de gastos que encontró, vacía si no encontró
     * ninguno. Un mensaje puede describir varios.
     *
     * La lista vacía es una respuesta válida, no una falla: las fallas
     * técnicas se lanzan como excepción para que el Router pase al
     * siguiente proveedor.
     *
     * @return list<Extraction>
     */
    public function extraerDeTexto(string $texto): array;

    /** @return list<Extraction> */
    public function extraerDeImagen(string $binario, string $mimeType, string $epigrafe = ''): array;

    /** @return list<Extraction> */
    public function extraerDeAudio(string $binario, string $mimeType): array;

    /**
     * Un resumen de tarjeta: decenas de consumos en un PDF.
     *
     * @return list<Extraction>
     */
    public function extraerDeResumen(string $binario, string $mimeType): array;
}
