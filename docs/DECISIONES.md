# Decisiones de arquitectura

Registro de por qué el proyecto es como es. Cada entrada existe para que dentro
de seis meses nadie deshaga una decisión sin conocer el motivo que la produjo.

Formato: contexto, decisión, consecuencias. Una decisión revertida no se borra:
se marca como reemplazada y se agrega la nueva.

---

## 1. Webhook en vez de polling

**Contexto.** El hosting es WNPower, compartido, con el requisito explícito de
consumir poco.

**Decisión.** Modo webhook con PHP-FPM. Nada de `getUpdates` en bucle.

**Consecuencias.** El proceso existe sólo durante el request, unos 200 ms;
consumo en reposo cero. A cambio hace falta HTTPS con certificado válido (lo da
Let's Encrypt gratis) y una URL pública estable. Un bot con polling habría
necesitado un proceso vivo 24/7, que en hosting compartido te lo matan.

---

## 2. Cero dependencias de runtime

**Contexto.** Composer no está garantizado en hosting compartido, y `vendor/`
agrega cientos de archivos a cada despliegue por FTP.

**Decisión.** Autoloader PSR-4 propio (25 líneas), cliente HTTP propio sobre
cURL, runner de tests propio (80 líneas). Sin Composer, ni en desarrollo.

**Consecuencias.** El despliegue es copia de archivos y nada más. Los tests
corren igual en la máquina de desarrollo, en Docker y en CI sin paso de
instalación. El costo es que no se puede usar PHPUnit, PHPStan ni ninguna
librería del ecosistema sin revisar esta decisión.

**Cuándo revisarla.** Si aparece una necesidad real de una librería grande
(un parser de PDF, por ejemplo), conviene reevaluar en vez de escribir uno.

---

## 3. Importes en centavos, no en float

**Contexto.** La aplicación suma plata y muestra totales mensuales.

**Decisión.** `Money` guarda un entero de centavos. La base usa `DECIMAL(14,2)`.
Ningún importe pasa por `float` salvo en el instante del parseo.

**Consecuencias.** Diez sumas de `0.10` dan exactamente `1.00`, y hay un test que
lo verifica. Toda operación con plata tiene que pasar por `Money`; un cálculo
suelto con floats es un defecto, aunque en las pruebas dé bien.

---

## 4. `monto_ars` congelado al día del gasto

**Contexto.** Inflación y tipo de cambio argentinos. Los reportes comparan meses.

**Decisión.** Cada gasto guarda `monto`, `moneda` y `monto_ars` calculado con el
tipo de cambio del día del gasto, más el `tipo_cambio` usado. Nunca se recalcula.

**Consecuencias.** Los reportes del pasado no cambian. Si se reconvirtiera al
valor de hoy, el total de marzo sería distinto cada mañana y cualquier
comparación mes a mes quedaría inservible.

---

## 5. El bot nunca guarda a ciegas

**Contexto.** La extracción por IA acierta mucho, pero no siempre.

**Decisión.** Todo gasto nace en estado `borrador` y pasa a `confirmado` sólo por
acción explícita del usuario. Con confianza baja, la tarjeta lo dice.

**Consecuencias.** Un tap más por gasto. A cambio, no hay gastos mal
categorizados en silencio, que es lo que destruye la confianza en los reportes
más rápido que un gasto que nunca se cargó.

---

## 6. Idempotencia por `update_id` en la base

**Contexto.** Telegram reintenta los updates que no responden rápido.

**Decisión.** `INSERT IGNORE` sobre `updates_seen` antes de procesar, y la
decisión de si es nuevo la toma la base por `rowCount`, no la aplicación.

**Consecuencias.** Dos reintentos simultáneos no pueden procesarse ambos. La
reserva ocurre **antes** de responder 200: si la base está caída devolvemos 500
para que Telegram reintente, en vez de tragarnos el gasto con un 200 mentiroso.

---

## 7. Multi-usuario desde la primera migración

**Contexto.** El proyecto puede volverse producto.

**Decisión.** Toda tabla de datos lleva `user_id` desde `001_core.sql`, y el
filtrado vive dentro del repositorio, no en el llamador.

**Consecuencias.** Agregar multi-tenancy después habría implicado reescribir cada
consulta. El costo hoy es cero. Hay tests de integración que verifican que un
usuario no puede leer, confirmar ni borrar el gasto de otro.

---

## 8. Cupo de IA por usuario sobre un pool común

**Contexto.** Las capas gratuitas se miden por API key, no por usuario final. Con
una sola key, veinte usuarios activos la agotan en horas.

**Decisión.** Las claves las pone el dueño del bot; cada usuario tiene un cupo
mensual (`AI_MONTHLY_QUOTA`). Al agotarse, el bot sigue funcionando en modo
texto con el parser rápido. BYOK queda como escape para usuarios avanzados.

**Alternativas descartadas.** BYOK como puerta de entrada mata la adopción de
cualquiera que no sea técnico. Sin cupo, un solo usuario intensivo deja sin
servicio a todos.

**Consecuencias.** `users` lleva `plan`, `quota_used` y `quota_period` desde el
día uno, y `ai_calls` registra cada intento: sin ese número no se puede decidir
si el modelo gratuito cierra.

---

## 9. Acceso por invitación

**Contexto.** El username de un bot de Telegram es público y descubrible.

**Decisión.** Sin `INVITE_CODE` configurado nadie puede darse de alta. El alta
requiere `/start CODIGO`.

**Consecuencias.** Posterga las obligaciones de un producto abierto (política de
privacidad, borrado a pedido, escalamiento de cupos) sin cerrar la puerta a
abrirlo después. El default seguro es "cerrado", incluso para el dueño.

---

## 10. PHP 8.2 en Docker y CI, 8.4 en desarrollo

**Contexto.** El hosting compartido suele ir varias versiones atrás.

**Decisión.** El contenedor y un job de CI usan 8.2; el desarrollo local usa 8.4,
y CI corre la matriz completa.

**Consecuencias.** Cualquier sintaxis demasiado nueva explota en local o en CI,
nunca en producción.

---

## 11. Integración bancaria por mail, no por scraping

**Contexto.** Galicia, Supervielle y Ualá no ofrecen API pública para leer la
propia cuenta personal. No hay open banking para personas en Argentina.

**Decisión.** Parseo de los mails de aviso de consumo, con una plantilla por
emisor y respaldo en un modelo cuando la plantilla no matchea. Mercado Pago sí
tiene API oficial con OAuth.

**Alternativa descartada.** Scrapear homebanking con las credenciales del
usuario: rompe los términos de servicio, choca contra el 2FA, se cae con cada
cambio de HTML y puede terminar en el bloqueo de la cuenta bancaria. El costo de
un fallo no es un bug, es que alguien se quede sin acceso a su banco.

---

## 12. Sin `ffmpeg`

**Contexto.** Telegram entrega las notas de voz en OGG/Opus. Hosting compartido
casi nunca tiene `ffmpeg`.

**Decisión.** Se manda el OGG tal cual al proveedor de IA. Gemini y Whisper vía
Groq lo aceptan sin transcodificar.

**Consecuencias.** El pipeline de voz no necesita binarios externos. Si en el
futuro un proveedor exigiera otro formato, queda descartado antes de evaluarlo.

---

## 13. La cadena de respaldo también corre entre modelos del mismo proveedor

**Contexto.** Medido el 14/09/2026 contra la API real con una clave de capa
gratuita recién creada:

| Modelo | Resultado |
|---|---|
| `gemini-2.5-flash` | `404` — "no longer available to new users" |
| `gemini-3.8-flash`, `gemini-3.5-flash` | `503` — "currently experiencing high demand" |
| `gemini-3.5-flash-lite` | Responde bien, 1 a 11 segundos |

**Decisión.** `GEMINI_MODELS` define una lista ordenada y `App` instancia un
proveedor por modelo. Un 503 en el primero baja al siguiente sin que el usuario
se entere. El default es `gemini-3.5-flash-lite`.

**Consecuencias.** La cadena de respaldo, que estaba pensada entre proveedores
distintos, sirve igual entre modelos del mismo: en capa gratuita la saturación es
más frecuente que la caída de un proveedor entero. `ai_calls` registra el modelo
concreto de cada intento, así que cuál conviene poner primero se decide con datos.

**Lo que esto enseña.** El modelo por defecto que escribí de memoria estaba
desactualizado y devolvía 404. Ningún test lo habría detectado sin una clave
real: por eso la Definition of Done exige pasar por `evaluador-extraccion` cuando
se toca un proveedor.

---

## 14. Un solo pedido contra producción, sin reintentos

**Contexto.** El firewall de WNPower bloquea la IP ante ráfagas de pedidos y
devuelve una página de bloqueo con captcha que **sólo destraba una persona**. Con
la IP bloqueada no se verifica ni se despliega, y **un FTP en curso se corta a la
mitad**, dejando archivos incompletos en producción. Ocurrido tres veces en otro
proyecto sobre el mismo hosting.

**Decisión.**

- `cancel-in-progress` sólo en pull requests; en `main` nunca, porque cancelar
  cortaría un FTP vivo.
- El job de despliegue tiene su propio grupo de concurrencia sin cancelación: dos
  push seguidos se encolan en vez de pisarse.
- La verificación post-despliegue hace **un** pedido con timeout, sin reintentos,
  y si no recibe 403 dice explícitamente que un bloqueo lo destraba una persona.

**Consecuencias.** El despliegue es más lento ante pushes seguidos y a cambio no
puede dejar el sitio a medio publicar. Vale también para cualquier script de
verificación que se agregue después: contra producción, una sola pasada.

---

## 15. Subdominio del dominio personal, no un dominio nuevo

**Contexto.** Un webhook de Telegram exige una URL HTTPS pública. No hay forma de
evitarlo: la alternativa, *polling*, necesita un proceso vivo 24/7 y está
descartada por la decisión 1. Pero esa URL **no la ve ningún usuario**: la gente
sólo ve el bot dentro de Telegram.

**Decisión.** `bot.marcosdamiangonzalez.ar` — subdominio del dominio personal,
con DNS en Vercel (donde ya está el apex) y un registro A hacia la IP de WNPower.

**Alternativas descartadas.**

- *Comprar un dominio nuevo*: gasto anual para una URL que nadie va a leer.
- *Subdominio de `gargonatural.com.ar`*: técnicamente más simple, porque DNS y
  hosting quedan en el mismo panel. Descartado porque ata un proyecto personal a
  un activo de negocio: si ese dominio se mueve o se vende, el bot se cae con él.
- *Subcarpeta de un sitio existente*: no requiere DNS, pero mezcla el bot con el
  document root de un sitio en producción, y un despliegue podría pisar al otro.

**Consecuencias.** DNS y hosting quedan en proveedores distintos, así que el
certificado no se emite hasta que el registro A propaga. Es un paso más, una sola
vez.

---

## 16. El bot puede morirse en silencio, así que hay que vigilarlo

**Contexto.** Si el firewall de WNPower bloqueara a Telegram, o venciera el
certificado, o un despliegue moviera `webhook.php`, el bot deja de recibir
mensajes **sin producir un solo error en el servidor**. No hay log que mirar:
simplemente nadie escribe, y uno se entera días después.

**Decisión.** Un cron horario (`bin/cron.php`) consulta `getWebhookInfo` y avisa
por Telegram a `OWNER_CHAT_ID` cuando hay `last_error_message` o demasiados
updates encolados. `HealthCheck` traduce el error de Telegram a la causa más
probable en este hosting, porque un mensaje genérico no sirve de madrugada.

**Por qué el aviso va por Telegram.** El envío sale del servidor **hacia**
Telegram, que es la dirección contraria a la que está rota cuando Telegram no
puede entregarnos nada. Un mail dependería de otra pieza más.

**Consecuencias.** El mismo cron purga `updates_seen`. Es la única tarea
programada del proyecto, y una corrida por hora es suficiente: menos sería
enterarse tarde, más sería golpear al hosting sin necesidad.
