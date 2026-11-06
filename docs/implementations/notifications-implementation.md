# Notificaciones dentro de GAM

La bandeja personal muestra avisos persistidos en PostgreSQL. Una campana en Administración y Repartos muestra el número de no leídas. Los avisos operativos se integran por módulo según el plan. [Plan de implementación](alerts-and-notifications-implementation-plan.md)

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

## Verificación local

Se comprobó una suscripción privada real a través del proxy de Angular y Nginx. Publicar un aviso y marcar otro como leído emitieron la señal por la cola y Reverb. Las consultas HTTP confirmaron el contenido, la fecha de lectura y el contador. La prueba del cliente cubrió resincronización al recibir una señal, reconexión y cierre al salir. La compilación del frontend completó con un aviso de optimización por el formato CommonJS de `pusher-js`.

Las pruebas de backend cubren publicación idempotente, rollback, lectura, firma de canal, revocación y aislamiento por UP. La verificación usó Docker local y PostgreSQL de pruebas. La auditoría de Composer informó avisos en paquetes que conservan las mismas versiones que antes del cambio. La verificación en dispositivos nativos y producción queda pendiente.
