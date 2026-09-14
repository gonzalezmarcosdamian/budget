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
