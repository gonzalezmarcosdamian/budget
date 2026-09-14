<?php

declare(strict_types=1);

use Budget\Support\Check;
use Budget\Support\Doctor;

prueba('acepta la versión mínima de PHP del hosting', function (): void {
    esIgual(Check::OK, Doctor::versionDePhp('8.2.0')->estado);
    esIgual(Check::OK, Doctor::versionDePhp('8.4.22')->estado);
});

prueba('rechaza una versión de PHP vieja y dice dónde cambiarla', function (): void {
    $check = Doctor::versionDePhp('8.1.29');

    esIgual(Check::FALLA, $check->estado);
    afirmar(str_contains($check->remedio, 'cPanel'), 'el remedio apunta al panel del hosting');
});

prueba('un check en falla siempre trae remedio', function (): void {
    $check = Check::falla('Algo', 'se rompió', 'arreglalo así');

    afirmar($check->fallo(), 'una falla se reporta como falla');
    afirmar($check->remedio !== '', 'un diagnóstico sin remedio obliga a abrir el código');
});

prueba('un aviso no cuenta como falla', function (): void {
    afirmar(!Check::aviso('Algo', 'ojo con esto')->fallo(), 'un aviso no corta el despliegue');
    afirmar(!Check::ok('Algo')->fallo(), 'y un ok menos');
});
