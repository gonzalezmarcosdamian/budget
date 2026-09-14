# budget

Bot de Telegram que lleva la gestión de gastos personales. Le mandás una foto del
ticket, una nota de voz o un mensaje suelto, y te devuelve el gasto extraído para
que lo confirmes con un tap.

Pensado para correr sobre hosting compartido con consumo casi nulo, usando capas
gratuitas de varios proveedores de IA.

```
🧾 Coto — $18.450
📅 14/09/2026  📁 Supermercado  💳 Visa

[ ✓ Guardar ]  [ 📁 Categoría ]  [ ✕ Descartar ]
```

## Cómo cargar un gasto

| Entrada | Ejemplo |
|---|---|
| Texto corto | `1200 super` · `nafta 25k` |
| Texto natural | `gasté 45 lucas en la prepaga` · `ayer 3 palos el auto` |
| Foto | comprobante o captura de la app del banco |
| Nota de voz | audio de Telegram, sin transcodificar |

Entiende la jerga: `luca` = mil, `palo` = millón, `25k` = 25.000. Y el formato
argentino de importes: `18.450,75`.

Comandos: `/hoy`, `/mes`, `/ultimos`, `/ayuda`.

## Estado

Fase 1 y 2 implementadas: alta por invitación, carga por texto con parser de
costo cero, extracción multimodal por IA con cadena de respaldo, tarjeta de
confirmación con botones, categorías que se aprenden de tus correcciones y
reportes diarios y mensuales.

Pendiente: presupuestos con alerta, recurrentes, reporte mensual automático,
ingesta de mails del banco, Mercado Pago, Google Sheets. Ver
[docs/DECISIONES.md](docs/DECISIONES.md) y el plan técnico.

## Arranque rápido

```bash
cp .env.example .env     # completá token, base y código de invitación
docker compose up -d --build
docker compose exec app php bin/migrate.php
docker compose exec app php tests/run.php
```

Si 3310 u 8088 están ocupados:

```bash
DB_HOST_PORT=3320 APP_HOST_PORT=8090 docker compose up -d
```

## Tests

```bash
php tests/run.php --unit                     # unitarios, sin base, milisegundos
docker compose exec app php tests/run.php    # + integración contra MariaDB real
```

Los de integración verifican lo que un doble no podría: que un usuario no pueda
leer el gasto de otro, que un reintento de Telegram no duplique un gasto, y que
las migraciones corran limpias desde una base vacía.

## Despliegue

`git push` a `main` dispara CI: lint en PHP 8.2 y 8.4, unitarios, migraciones
desde cero e integración contra MariaDB. Si todo pasa, sube por FTP y verifica
que el webhook siga contestando.

Secretos necesarios en el repo: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
`FTP_DIR`, `WEBHOOK_URL`.

### Puesta en marcha, una sola vez

El bot vive en `bot.marcosdamiangonzalez.ar`. Esa URL no la ve nadie: los
usuarios sólo ven el bot en Telegram. Telegram necesita una URL HTTPS pública a
la que golpear, y ésa es.

1. **DNS** — registro A del subdominio hacia la IP de WNPower:
   `vercel dns add marcosdamiangonzalez.ar bot A <IP-de-WNPower>`
2. **cPanel** — crear el subdominio con document root en `public/` y habilitar
   el certificado gratuito. Todo lo demás (`src/`, `.env`, `migrations/`) queda
   así fuera del alcance de cualquier request.
3. **Base y webhook**:

```bash
php bin/migrate.php
php bin/webhook.php set https://bot.marcosdamiangonzalez.ar/webhook.php
php bin/webhook.php info
```

4. **Cron horario** para purgar y vigilar que el bot siga recibiendo mensajes:

```
0 * * * * /usr/local/bin/php /home/USUARIO/budget/bin/cron.php --quiet
```

El cron avisa por Telegram a `OWNER_CHAT_ID` si el webhook se cae. Importa más
de lo que parece: si el firewall del hosting llegara a bloquear a Telegram, el
bot deja de recibir mensajes **en silencio** — sin error, sin log, simplemente
nadie escribe.

## Arquitectura

```
public/webhook.php   valida secreto -> reserva update_id -> responde 200 -> procesa
src/Telegram/        cliente de la Bot API, parseo de updates, teclados inline
src/Expense/         parser rápido por regex, borrador inmutable, categorizador
src/Ai/              interfaz de proveedor, router con respaldo, Gemini
src/Repository/      acceso a datos, siempre filtrado por user_id
src/Handler/         despacho, tarjeta de confirmación, reportes
src/Support/         Money, Env, Config, Clock, Http, Logger, Background
```

Sin dependencias de runtime: ni Composer ni `vendor/`. El despliegue es una copia
de archivos. El porqué de cada decisión está en
[docs/DECISIONES.md](docs/DECISIONES.md).

## Seguridad

- El `.env` no se versiona y vive fuera del document root.
- Acceso por invitación: sin `INVITE_CODE` nadie puede darse de alta.
- El webhook valida el `secret_token` de Telegram con `hash_equals`.
- Todas las consultas son parametrizadas y filtran por `user_id`.
- Las imágenes de comprobantes se procesan y se descartan; no se guardan.

## Cómo se trabaja acá

[docs/WORKFLOW.md](docs/WORKFLOW.md) — roles, Definition of Done, quality gates y
los agentes de `.claude/agents/`.
