# budget — bot de gastos por Telegram

Bot privado que convierte una foto de ticket, una nota de voz o un mensaje suelto
en un gasto categorizado. Corre sobre hosting compartido (WNPower) y usa capas
gratuitas de varios proveedores de IA.

## Reglas que no se negocian

1. **Cero dependencias de runtime.** Nada de Composer en producción. Si hace falta
   una librería, primero se evalúa escribir las 80 líneas que se usan de verdad.
   El despliegue es una copia de archivos y así tiene que seguir.
2. **El bot nunca guarda a ciegas.** Todo gasto nace en estado `borrador` y sólo
   pasa a `confirmado` cuando el usuario toca el botón.
3. **Toda consulta filtra por `user_id` dentro del repositorio**, nunca en el
   llamador. Un solo lugar donde equivocarse, y tests de integración que lo verifican.
4. **Nada de secretos en el repo.** El `.env` vive fuera del document root y no
   se versiona. El repo es público.
5. **Inmutabilidad.** `Draft`, `Money` y `Extraction` son readonly; cada cambio
   devuelve una copia nueva.
6. **Consultas parametrizadas siempre.** Nunca concatenar en SQL.
7. **Escapar todo lo que va a Telegram** con `ExpenseCard::escapar()`: el HTML de
   Telegram se rompe con un `&` en el nombre de un comercio.

## Arquitectura

```
public/webhook.php   punto de entrada: valida secreto -> reserva update_id
                     -> responde 200 -> recién ahí procesa
src/App.php          cableado (sin contenedor de DI, el grafo entra en una pantalla)
src/Telegram/        cliente de la Bot API, parseo de updates, teclados
src/Expense/         FastParser (regex, costo cero), Draft, CategoryGuesser
src/Ai/              LlmProvider + Router con cadena de respaldo + proveedores
src/Repository/      acceso a datos, filtrado por user_id
src/Handler/         Dispatcher, ExpenseCard, Reports
src/Support/         Money, Env, Config, Clock, Http, Logger, Background
migrations/          .sql numerados, aplicados por bin/migrate.php
```

El orden de `webhook.php` no es casual: validar y deduplicar van **antes** del
200 para que un fallo de base provoque un reintento de Telegram en vez de perder
el gasto en silencio.

## Comandos

```bash
php tests/run.php --unit          # unitarios, sin base (milisegundos)
php bin/cron.php                  # purga + chequeo de salud del webhook
docker compose up -d --build      # entorno de pre-producción
docker compose exec app php bin/migrate.php
docker compose exec app php tests/run.php   # unitarios + integración
find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Puertos ocupados: `DB_HOST_PORT=3320 APP_HOST_PORT=8090 docker compose up -d`.

## Antes de dar algo por terminado

- [ ] `php tests/run.php --unit` en verde
- [ ] `docker compose exec app php tests/run.php` en verde (incluye integración)
- [ ] Lint sin errores en PHP 8.2 **y** 8.4
- [ ] Funciones < 50 líneas, archivos < 800
- [ ] Sin `var_dump`, `print_r`, `error_log` de depuración
- [ ] Si tocaste SQL: migración nueva, nunca editar una ya aplicada
- [ ] Si tocaste extracción: fixtures actualizadas

## Agentes de este proyecto

Están en `.claude/agents/`. Ver `docs/WORKFLOW.md` para cuándo usar cada uno.

| Agente | Para qué |
|---|---|
| `entrega` | Orquestador: toma una historia y la lleva hasta Definition of Done |
| `planificador` | Descompone una historia en tareas con criterios de aceptación |
| `revisor-php` | Calidad de código PHP: tipos, inmutabilidad, tamaño, legibilidad |
| `revisor-seguridad` | Secretos, inyección, aislamiento entre usuarios, escape |
| `revisor-datos` | Migraciones e impacto en producción |
| `evaluador-extraccion` | Mide qué modelo acierta más sobre fixtures reales |
| `qa-integracion` | Levanta Docker y corre la suite completa |

## Contexto de negocio

- Los importes se guardan en **centavos** (`Money`), nunca en float.
- `monto_ars` se congela al tipo de cambio del día del gasto. Recalcularlo haría
  que los reportes del pasado cambien cada mañana, inservible con esta inflación.
- Jerga argentina que el parser entiende: `luca` = mil, `palo` = millón, `25k`.
- No existe open banking para cuentas personales en Argentina. La integración
  bancaria va por parseo de mails de aviso, nunca por scraping de homebanking.
- El bot vive en `bot.marcosdamiangonzalez.ar` (subdominio del dominio personal,
  DNS en Vercel, hosting en WNPower). Nadie ve esa URL: es sólo la dirección
  donde Telegram entrega los mensajes.
- **Contra producción, una sola pasada.** El firewall de WNPower bloquea la IP
  ante ráfagas y sólo lo destraba una persona con un captcha. Nada de `curl` en
  ráfaga ni reintentos automáticos.
