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

**Corrección del 14/09/2026, al ir a ejecutarlo.** El apex
`marcosdamiangonzalez.ar` resuelve a **Vercel**, no a WNPower: no existe una
cuenta de hosting para ese dominio. La IP compartida de WNPower es
`44.218.66.24`, la misma que sirve `gargonatural.com.ar`, o sea que el único
espacio de hosting disponible es el de esa cuenta.

Eso no invalida la decisión, pero agrega un paso: en cPanel hay que dar de alta
`bot.marcosdamiangonzalez.ar` **como dominio adicional** (no como subdominio,
porque la cuenta no controla el apex), y en Vercel apuntar sólo el registro A de
`bot` hacia `44.218.66.24`. El apex sigue en Vercel, intacto.

La alternativa seguiría siendo `bot.gargonatural.com.ar`, que no necesita ni el
dominio adicional ni tocar DNS porque la cuenta ya controla ese dominio. Se
mantiene descartada por la misma razón de antes —atar un proyecto personal a un
activo de negocio— pero ahora el costo de evitarlo está medido: un alta más en
cPanel y un registro de DNS.

**Segunda corrección, mejor que las dos anteriores.** La cuenta de WNPower es de
**revendedor** (usuario WHM `elmundo5`, servidor `cpanel173.wnpservers.net`). Eso
habilita la opción limpia: **crear una cuenta cPanel propia para el bot**, en vez
de colgarlo como dominio adicional de la cuenta del negocio.

Con cuenta propia el bot queda aislado de verdad: su propio espacio en disco, su
propia base, su propio FTP y su propio `.env`. Un problema en `gargonat` no lo
toca, y al revés tampoco. El aislamiento que el dominio adicional simulaba, acá
es real.

Además, la cuenta existente tiene **SSH habilitado**, así que las migraciones y
la verificación post-despliegue se corren de verdad en el servidor en vez de
programarse a ciegas con un cron.

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


---

## 17. PHP no fue una concesión: era la única opción

**Contexto.** La decisión 2 eligió PHP sin dependencias por prudencia ante un
hosting compartido. Al consultar las *features* de la cuenta por la API de WHM
aparecieron los números que faltaban:

```
lvenodejssel  0    (Setup Node.js App / Node.js Selector)
passengerapps 0    (Application Manager / Passenger)
ssh           1
cron          1
```

**Lo que significa.** Node.js Selector necesita Phusion Passenger para levantar
un proceso por usuario y hacer de proxy desde Apache: por eso los dos aparecían
en cero, son la misma dependencia.

**Corrección del mismo día.** Se afirmó acá que habilitarlo era nivel *root* y
que un revendedor no podía. **Era falso.** El dueño de la cuenta creó una lista
de funciones propia (`elmundo5_Full`) y ahí los tres están habilitados:

```
lvenodejssel  1    (Node.js Selector)
passengerapps 1    (Application Manager / Passenger)
lvepythonsel  1    (Python Selector)
ssh           1
cron          1
```

La lista `elmundo5_Full` la usa el paquete `elmundo5_Paquetessh`, que además
tiene `HASSHELL 1` y sin tope de bases ni de subdominios. O sea que el servidor
sí tenía los componentes y el revendedor sí podía exponerlos: lo que faltaba era
una lista de funciones que los incluyera, no un permiso de WNPower.

**Lo que sigue en pie.** La conclusión práctica no cambia, pero el motivo sí: no
hace falta contratar un plan Cloud porque Node ya está disponible sin costo
adicional, no porque sea imposible.

**Consecuencia para este proyecto.** PHP sigue siendo la elección correcta, pero
ahora por sus propios méritos y no por descarte: consumo cero en reposo, sin
proceso que supervisar y sin Passenger de por medio. Y `ssh 1` confirma que el
despliegue y las migraciones se pueden correr y verificar de verdad en el
servidor.

**Lección de método.** La afirmación "eso no se puede habilitar" salió de
documentación general, no de consultar la instalación concreta. La API de WHM
estaba a un pedido de distancia y decía lo contrario. Ante una pregunta de
capacidades, primero se le pregunta al sistema; la documentación es el respaldo,
no la fuente.


---

## 18. Un puente de una línea en vez de mover el document root

**Contexto.** En la cuenta de cPanel, el dominio principal sirve desde
`public_html`, y la aplicación necesita que el document root sea `public/`. Mover
el document root del dominio principal de una cuenta no es una operación limpia
por API.

**Decisión.** La aplicación vive en `/budget` (fuera del alcance de HTTP) y en
`/public_html/webhook.php` hay un archivo de una línea:

```php
require dirname(__DIR__) . '/budget/public/webhook.php';
```

**Consecuencias.** El repositorio no lleva ninguna ruta parcheada: el código
desplegado es idéntico al versionado. El único archivo que existe sólo en el
servidor es el puente, y no cambia nunca. `__DIR__` dentro del webhook real
sigue apuntando a `/budget/public`, así que la resolución de rutas no cambia.

Verificado en producción: `src/App.php` da 404 y `.env` da 403 por HTTP.

---

## 19. Sin SSH, el cron es la consola

**Contexto.** La cuenta tiene `/bin/bash` y la feature `ssh` habilitada, pero el
puerto no responde desde afuera: o está en un puerto no estándar o el firewall
lo filtra. Insistir probando puertos es exactamente lo que dispara el bloqueo de
IP descripto en la decisión 14.

**Decisión.** Las tareas que necesitan ejecutar PHP en el servidor —migraciones,
diagnóstico— se corren agregando una línea de cron por la API de cPanel, que
escribe su salida a `storage/setup.log`, y después se borra la línea. El archivo
se baja por FTPS.

**Consecuencias.** Es más lento que un `ssh` (hay que esperar a que el cron
dispare) pero no requiere abrir nada ni adivinar puertos. La primera instalación
se hizo así y quedó registrada: PHP 8.3.33, MariaDB 10.11.19, migraciones
aplicadas, `.env` en 0600 y fuera del document root.

**Detalle que costó una corrida.** Git Bash convierte rutas absolutas al pasarlas
como argumento: `/usr/local/bin/php` llegó al crontab como
`C:/Program Files/Git/usr/local/bin/php`. Se resuelve con `MSYS_NO_PATHCONV=1`.

**Y volvió a morder, peor.** El mismo mecanismo transformó `/budget/` en
`C:/Program Files/Git/budget/` al cargar el secreto `FTP_DIR` con
`gh secret set --body`. El despliegue **no falló**: creó ese árbol en el servidor
y publicó ahí durante dos corridas, con CI en verde, mientras la aplicación real
quedaba con el código viejo. Se descubrió porque una migración no aparecía en el
servidor; el rastro fue una carpeta llamada `C:` en el home.

Un error silencioso que deja el CI en verde es peor que uno ruidoso, así que la
lección no quedó sólo escrita: el job de despliegue ahora **valida la ruta**
antes de subir nada y falla si no empieza con `/` o si parece una ruta de
Windows. Anotarlo en un documento no alcanzó la primera vez.

---

## 20. La API de Mercado Pago sí devuelve lo que uno gastó

**Contexto.** Se concluyó, leyendo documentación, que la API de MP servía
sólo para cobrar: todo lo documentado tiene forma de vendedor (comisiones,
liquidaciones, contracargos, `external_reference`). La conclusión era **falsa**.

**Lo que lo resolvió.** Una POC previa del dueño, en su propia máquina, ya lo
tenía medido. Verificado de nuevo contra la cuenta real:

```
GET /v1/payments/search?payer.id=<id>&status=approved   → 550 pagos propios
GET /v1/account/balance                                 → 404
GET /v1/account/movements                               → 404
```

**Decisión.** `payments/search` filtrado por `payer.id` es la fuente de gastos en
tiempo real. No hay endpoint de saldo ni de movimientos: no hace falta.

**La lección, por segunda vez en el día.** Ante una pregunta de capacidades, se
le pregunta al sistema. La documentación es el respaldo, no la fuente. Es
exactamente lo mismo que pasó con Node.js Selector en la decisión 17.

---

## 21. Qué movimiento de Mercado Pago es un gasto

**Contexto.** La mitad de lo que devuelve la API no es un gasto. Clasificación
sobre 50 movimientos reales:

| `operation_type` | Qué es | Decisión |
|---|---|---|
| `regular_payment` | Compras, con el comercio en la descripción | Gasto, confianza 0.95 |
| `money_transfer` | Transferencias salientes, descripción siempre "Varios" | Gasto, confianza 0.55 |
| `account_fund` | Cargar saldo | **No** |
| `partition_transfer` | Alcancías | **No** |
| `investment` | Invertir el saldo | **No** |

**Decisión.** Los tres últimos son plata del usuario cambiando de bolsillo:
contarlos infla el total y hace que el reporte mensual mienta. Un
`operation_type` desconocido también se descarta — mejor perder un gasto que
cargar basura en un total que se usa para decidir.

**Consecuencias.** Las transferencias entran con confianza baja y comercio
genérico "Transferencia", porque "Varios" no dice nada y ensuciaría el nombre.

---

## 22. Mercado Pago se guarda confirmado, y es la excepción a la decisión 5

**Contexto.** El dueño pidió explícitamente no tener que confirmar los
movimientos de Mercado Pago.

**Decisión.** Los gastos que llegan por la API se guardan ya `confirmado`, no
como borrador.

**Por qué se sostiene.** La decisión 5 existe porque un modelo leyendo una foto
borrosa se equivoca, y un gasto mal cargado en silencio destruye la confianza en
los reportes. Acá el dato no lo interpretó nadie: viene de una API con importe
exacto, fecha e identificador estable. Confirmar de a uno cuarenta movimientos
ciertos es fricción sin información.

**La salida sigue existiendo.** El lote permite deshacer la importación entera de
un toque, y el aviso ofrece "Ver detalle" y "Deshacer" en vez de "Guardar". La
regla que no se toca no es "siempre confirmar": es **que el usuario siempre pueda
revertir**.

**Dónde NO aplica.** Fotos, audios, texto y resúmenes en PDF siguen pidiendo
confirmación. Ahí sí hay un modelo interpretando.

---

## 23. La idempotencia de una importación la garantiza la base

**Contexto.** La sincronización mira seis horas hacia atrás de la última corrida,
porque un pago puede aprobarse después de creado. Ese solape trae movimientos ya
importados en cada corrida.

**Decisión.** `origen_externo` guarda la referencia en el sistema de origen
(`mp:178019948833`), con índice único `(user_id, origen_externo)`. Un duplicado
lo rechaza MySQL y la aplicación cuenta cero, en vez de consultar antes de cada
inserción.

**Consecuencias.** Sincronizar dos veces seguidas importa cero la segunda,
verificado. Y como MySQL admite varios NULL en un índice único, los gastos
cargados a mano —que no tienen origen externo— no se estorban entre sí.

---

## 24. El menú de comandos se versiona, no se registra a mano

**Contexto.** El menú que Telegram muestra al tocar "/" se publicó una sola vez,
a mano, con los cuatro comandos que existían ese día. El bot siguió aprendiendo
—preguntas en lenguaje natural, resúmenes en PDF, recordatorios, transferencias
neteadas— y nada de eso apareció nunca en ningún lado. El usuario lo dijo así:
"no veo nuevos métodos". Tenía razón: **una función que nadie descubre no
existe**.

**Decisión.** La lista canónica vive en `Budget\Telegram\Menu::COMANDOS`. De ahí
salen las dos cosas que el usuario ve: el payload de `setMyCommands`
(`bin/comandos.php`) y el bloque de comandos de `/ayuda`. Dos tests cierran el
círculo en las dos direcciones: todo comando que el `Dispatcher` atiende tiene
que estar en el menú, y todo comando del menú tiene que estar atendido.

**Consecuencias.** Agregar un comando y olvidarse de publicarlo ahora rompe la
suite. El costo es correr `php bin/comandos.php` después de agregar uno, que es
justamente el paso que se había olvidado. Los alias que Telegram no acepta
—`/año`, porque sólo admite `[a-z0-9_]`— siguen funcionando escritos a mano pero
quedan fuera del menú, declarados como excepción en el test.

---

## 25. `php -l` no ve una clase que no existe

**Contexto.** Al mover el listado de recurrentes del `Dispatcher` a `Reports` se
borró de más el `use Budget\Repository\RecurringRepository`, pero quedó vivo un
`new RecurringRepository(...)` en el manejo del callback. Sin el `use`, el nombre
resuelve al namespace propio: `Budget\Handler\RecurringRepository`, que no
existe. El lint pasa —es sintaxis válida— y la suite entera quedó en verde,
porque ningún test recorría ese botón. El error habría aparecido recién en
producción, cuando el usuario tocara "Cargar" en un recordatorio: y como el
webhook ya contestó 200, Telegram tampoco reintenta. El botón no haría nada, en
silencio.

**Decisión.** `tests/ClasesResuelvenTest.php` tokeniza todo `src/`, imita la
resolución de nombres de PHP —los `use` del archivo, incluidos los `as`, y si no
el namespace propio— y verifica que cada clase instanciada exista. Son 40
instanciaciones en 43 archivos, en milisegundos y sin base.

**Consecuencias.** Se verificó que falla con el bug original y con el mensaje
correcto antes de darlo por bueno. Cubre `new`, que es donde duele; las llamadas
estáticas y los type hints quedan fuera por ahora, porque el autoloader los
resuelve en el mismo momento y el costo de tokenizarlos no se justificaba
todavía. La lección general: un lenguaje con resolución de nombres en runtime
necesita un chequeo en tests, no alcanza con el linter.

---

## 26. En un cobro, la contraparte es quien paga

**Contexto.** `contraparteDe()` devuelve el `collector` de un pago de Mercado
Pago: correcto para una transferencia que mando, porque el que cobra es el otro.
La importación de cobros reusó esa misma función, y en un cobro el `collector`
soy yo. Resultado medido en producción: **171 de 172 ingresos quedaron sin
contraparte**, todos llamados "Transferencia". El neteo por persona —que el
usuario pidió explícitamente, "siempre hacé neto en transferencia"— mostraba
"recibido $0" en todas las filas. La función existía, se veía bien y no neteaba
nada.

**Decisión.** `MercadoPago::quienPago()` lee `payer.id`, que además viene en la
respuesta de la búsqueda: el cobro no necesita la llamada extra al detalle que sí
necesita el pago. Al cobro se le aplica el mismo alias que al pago, para que el
neteo diga un nombre y no "Transferencia" contra "Transferencia".

**Consecuencias.** Sólo arregla los cobros nuevos: los ya importados no se tocan
por el índice único de `origen_externo`, así que la contraparte de los viejos se
rellena aparte. La lección: una función de una sola dirección reusada para la
otra dirección compila, corre y devuelve un valor plausible. Lo que la delató
fueron los datos, no los tests.

---

## 27. Esto es flujo de caja, no un estado de resultados

**Contexto.** Se agregó `/ingresos` restando gastos de ingresos y anunciando
"saldo en rojo". Contra los datos reales daba, todos los meses, millones de rojo:
septiembre cerraba con $107.888 de ingresos contra $3.938.563 de gastos. El
primer diagnóstico fue que faltaban datos —el sueldo no pasa por Mercado Pago— y
el primer arreglo, sacar del cálculo lo que "no es gasto": préstamos,
inversiones, transferencias.

Los dos razonamientos estaban mal, y el usuario lo dijo en tres palabras: **es
cash flow.**

**Decisión.** El bot no lleva un estado de resultados, lleva **caja**. Lo que
tiene son movimientos de plata entre cuentas, y la diferencia no es cosmética:
prestarle a alguien no es un gasto pero la plata se fue igual; comprar CEDEARs no
empobrece pero la caja baja. Un flujo de caja al que le sacás movimientos porque
"no son gastos" deja de explicar dónde está la plata, que es para lo único que
sirve.

Así que `/flujo` no excluye nada: muestra qué entró, qué salió, la **variación de
caja**, y clasifica la salida en fijos, consumo, prestado e invertido. Los tres
últimos bajan la caja igual, pero sólo uno es plata que no vuelve.

**Consecuencias.** Se cae el concepto de "saldo" y con él la idea de diagnosticar
un rojo: la variación de caja es un hecho medible sobre las cuentas que el bot
ve, y el reporte aclara ese alcance en vez de pronunciarse sobre la economía del
usuario. La regla general para todo reporte nuevo: **primero decidir qué se está
midiendo —caja o resultado— porque los mismos datos sostienen un número y no el
otro.** Relacionado: `/mes` aclara cuánto del total es estimado y no medido, por
los meses de alquiler reconstruidos con el IPC.
