# Notificaciones dentro de GAM

La bandeja personal muestra avisos persistidos en PostgreSQL. Una campana en Administración y Repartos muestra el número de no leídas. Los avisos operativos se integran por módulo según el plan. [Plan de implementación](alerts-and-notifications-implementation-plan.md)

El [resumen operativo](operational-summary-implementation.md#alertas-operativas) lista y cuenta episodios abiertos con permisos de origen y filtros por UP y urgencia. Cada episodio tiene su propio identificador, independiente de los avisos de la bandeja.

## Publicar desde un módulo

Inyectar `PublishNotificationAction` y pasarle un `NotificationData` con usuario destinatario, tipo, clave estable de origen, título y mensaje en español y fecha del hecho. Para empleados con dispositivo/PIN, incluir la UP del hecho. La referencia opcional acepta el tipo y el ID público de la entidad, además de una ruta interna de GAM. Usar textos que el destinatario esté autorizado a ver.

El emisor indica `urgency` mediante `NotificationUrgency::Low`, `Medium` o `High` y `module` mediante un código estable, por ejemplo `management_plans`, `inventory` o `deliveries`. El módulo identifica el origen aunque el aviso carezca de entidad relacionada. La migración asigna `bajo` y `general` a los avisos existentes. Las publicaciones que omiten ambos valores reciben esos mismos valores iniciales.

La combinación de destinatario, tipo y clave de origen identifica una publicación. Un reintento devuelve el aviso original con su fecha de lectura. Si la publicación se realiza dentro de la transacción del módulo, se revierte junto con ella. El marcado de lectura conserva la primera fecha aunque se repita la solicitud. [Contrato de la API](../../contracts/openapi/notifications.yaml)

El listado, el detalle y la respuesta al marcar como leída incluyen `urgencia` (`bajo`, `medio`, `alto`) y `modulo`. El listado usa fecha descendente por defecto. `GET /api/v1/notificaciones?orden=urgencia` ordena de alto a bajo antes de paginar, con los avisos más recientes primero dentro de cada nivel. Los filtros `urgencia`, `modulo` y `estado_lectura` se pueden combinar. Por ejemplo, `GET /api/v1/notificaciones?urgencia=alto&modulo=management_plans`.

## Mostrar el estado de una alerta

Publicar con `isAlert: true` para que la bandeja devuelva `estado_resolucion: no_resuelto`. Cuando el flujo operativo cierre la alerta, el módulo emisor puede invocar `ResolveAlertNotificationAction` sobre el aviso publicado. La API devuelve `estado_resolucion: resuelto` y `resuelta_en` con la primera fecha de resolución. La acción emite la señal de actualización después de confirmar la transacción. Leer el aviso mantiene su estado operativo.

La API devuelve ambos campos como `null` para las notificaciones informativas, incluidas las que ya existían al aplicar la migración. El estado guardado en la bandeja corresponde al aviso de cada destinatario. El módulo de origen define la autorización, ejecuta y audita el cierre operativo, y sincroniza los avisos que haya publicado. Las reglas y disparadores de cada módulo se integran por separado.

En `GET /api/v1/notificaciones`, el filtro `estado_resolucion=vigente` incluye avisos informativos (`null`) y alertas pendientes (`no_resuelto`). `estado_resolucion=resuelto` incluye las alertas cerradas. Al omitir el parámetro se devuelven todos los avisos. El filtro se aplica antes de paginar y se combina con lectura, módulo, urgencia y orden.

## Entrega y autorización

La bandeja usa `entidad.ruta` para abrir Alimentación en revisiones de ración manual, Postura en avisos de falta de recolección, Pesajes en anomalías de peso, el plan vigente del lote en actividades programadas y Existencias de raciones en cobertura de stock. Una migración completa las rutas faltantes de actividades programadas y cobertura de stock. Cada pantalla aplica sus propios permisos.

Después de confirmar la escritura, Laravel envía `notifications.changed` a Reverb mediante la cola Redis existente. Angular/Ionic recibe la señal con Echo y consulta la API para actualizar la bandeja y el contador. La conexión transporta una señal vacía. La API entrega el contenido y verifica destinatario, permiso y UP. Abrir la app, volver a primer plano o suscribirse de nuevo recupera las notificaciones persistidas.

Cada sesión usa un canal privado propio. El endpoint de suscripción usa Sanctum y las mismas verificaciones de sesión, permisos y equipo que la bandeja. Antes de emitir se excluyen sesiones expiradas o revocadas y, en equipos compartidos, sesiones inactivas o con una generación o UP inválida.

## Configuración

En Docker de desarrollo, Reverb usa credenciales exclusivas para local. En producción, configurar `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` y `REVERB_ALLOWED_ORIGINS` en `.env.production`. El último valor es una lista de nombres de host separados por comas, incluidos los orígenes web y nativos que correspondan. El frontend usa el mismo origen que la API para abrir el WebSocket. Los proxies web encaminan `/app/` hacia Reverb y el proxy público debe permitir el upgrade a WebSocket sobre TLS. Las credenciales incompletas deshabilitan la conexión y la bandeja sigue disponible por HTTP.

Ejecutar `php artisan db:seed --class=IdentityPermissionSeeder --force --no-interaction` para registrar `can_view_notifications` y otorgarlo al rol administrador mediante el catálogo central. Los roles operativos existentes pueden recibirlo desde Roles y permisos. En local, `NotificationsDemoSeeder` genera una notificación de bienvenida para el administrador y forma parte de la carga de demostración.

Si el entorno local ya tiene el volumen de dependencias PHP, actualizarlo antes de iniciar Reverb. Ejecutar `docker compose -f compose.dev.yaml exec -T -u root api composer install --no-interaction --no-scripts`, luego `docker compose -f compose.dev.yaml exec -T api php artisan package:discover --no-interaction` y recrear el gateway para cargar su ruta WebSocket. Las versiones de Guzzle y PSR-7 del lock se ajustaron a las que admite Reverb 1.12.

## Configurar alertas operativas

El catálogo incluye los 19 tipos de alerta operativa que emite GAM. Cada tipo tiene un nombre en español y su código coincide con `tipo` en la bandeja. El alcance general cubre Vehículos, las ubicaciones generales de Inventario y los repartos sin UP o con datos de varias UP. Los ajustes de una unidad son independientes de los ajustes generales.

| Operación | Ruta |
| --- | --- |
| Listar el catálogo general | `GET /api/v1/configuracion-alertas` |
| Guardar una alerta general | `PATCH /api/v1/configuracion-alertas/{operationalAlertType}` |
| Listar el catálogo de una UP | `GET /api/v1/unidades-productivas/{productionUnit}/configuracion-alertas` |
| Guardar una alerta de una UP | `PATCH /api/v1/unidades-productivas/{productionUnit}/configuracion-alertas/{operationalAlertType}` |

Para construir el formulario, usar `nombre` y `modulo` para presentar cada alerta, `configuracion` para cargar sus valores efectivos y `campos` para conocer los controles admitidos, sus valores iniciales y sus límites u opciones. Enviar a `ruta` sólo los campos modificados. Por ejemplo, `{"dias_anticipacion": 14, "urgencia_fija": "medio"}` adelanta el aviso de vencimiento de documentos a 14 días y lo muestra con urgencia media.

Todas las alertas admiten `habilitada` y `urgencia_fija`. Usar `null` en `urgencia_fija` para recuperar la urgencia calculada, que puede subir conforme se acerca el vencimiento o empeora la condición. Para restablecer otro campo, enviar el valor `predeterminado` que informa el catálogo.

| Alerta | Parámetros adicionales |
| --- | --- |
| Capacidad de galpón | Días antes de la adultez y exceso mínimo de aves sobre la capacidad física |
| Stock negativo de huevos y saldo negativo del reparto | Déficit mínimo en huevos, inclusive |
| Huevos sobrantes del reparto | Cantidad mínima devuelta a stock, inclusive |
| Intervención prevista y documento de vehículo | Días de anticipación en el calendario de Montevideo |
| Plan preventivo de vehículo | Días y kilómetros de anticipación. Basta alcanzar uno de los límites |
| Cobertura de ración y avisos temporales de lotes y planes | Valores vigentes y ruta de edición en `criterio_relacionado` |
| Stock mínimo por ubicación y anomalía de pesaje | `criterio_relacionado` enlaza con la configuración de mínimos o la referencia de pesaje |

`criterio_relacionado` incluye el método HTTP y la ruta de la API que administra el criterio. En cobertura y umbrales temporales incluye los valores efectivos para la UP. En stock mínimo, completar `{stockBalance}` con el saldo del producto en la ubicación. La capacidad física se sigue editando en Galpones y la edad de adultez en Pesajes. Cada API relacionada conserva su validación y permisos. [Contrato de alertas](../../contracts/openapi/notifications.yaml) · [Cobertura de ración](../../contracts/openapi/feed-recipes.yaml) · [Pesajes](../../contracts/openapi/weighings.yaml)

El permiso `can_manage_alert_thresholds` habilita la lectura y escritura. Las sesiones PIN operan en su propia UP y reciben 403 en el catálogo general. Una UP ajena o un tipo que no se configura en el ámbito de la ruta devuelve 404.

Los evaluadores aplican las preferencias en la siguiente ejecución. Al deshabilitar una alerta periódica, sus avisos vigentes pasan al historial de resueltos. Los avisos emitidos al registrar un pesaje o una operación de reparto conservan su historial y la nueva configuración se aplica a futuras publicaciones. En Vehículos, una intervención habilitada puede reunir el aviso de un plan preventivo vinculado. Se conserva la mayor urgencia aplicable, salvo que la intervención tenga una urgencia fija.

Aplicar la migración de `alert_configurations` antes de ejecutar el backend y los evaluadores de esta versión. La tabla guarda únicamente las diferencias frente a los valores iniciales y mantiene una fila única por ámbito y tipo. Las preferencias y umbrales anteriores siguen disponibles. El evento de auditoría `alert_configuration_changed` guarda actor, UP cuando aplica y valores anteriores y nuevos dentro de la misma transacción. Las escrituras simultáneas se serializan por ámbito y tipo. Repetir un cambio efectivo idéntico devuelve la configuración sin agregar otro evento.

## Verificación local

La configuración operativa pasó 97 pruebas con 1.229 aserciones en PostgreSQL aislado. La regresión cubrió el catálogo y sus valores existentes, la validación, el aislamiento de sesiones PIN, la auditoría idempotente y la aplicación de los cambios en los evaluadores y publicaciones por evento. Pint y PHPStan pasaron en los archivos modificados. Se comprobó el YAML de OpenAPI, sus referencias internas y la coincidencia de sus 19 códigos con los del backend. La integración se verificó desde la API y los comandos del backend.

Se comprobó una suscripción privada real a través del proxy de Angular y Nginx. Publicar un aviso y marcar otro como leído emitieron la señal por la cola y Reverb. Las consultas HTTP confirmaron el contenido, la fecha de lectura y el contador. La prueba del cliente cubrió resincronización al recibir una señal, reconexión y cierre al salir. La compilación del frontend completó con un aviso de optimización por el formato CommonJS de `pusher-js`.

Las pruebas de backend cubren publicación idempotente, rollback, lectura, firma de canal, revocación y aislamiento por UP. La verificación usó Docker local y PostgreSQL de pruebas. La auditoría de Composer informó avisos en paquetes que conservan las mismas versiones que antes del cambio. La verificación en dispositivos nativos y producción queda pendiente.
