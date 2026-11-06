# 09 — Producción y stock de huevos

## Descripción y alcance

El módulo registra la producción diaria de huevos por lote y mantiene una cuenta corriente de huevos por unidad productiva (UP). Se separan dos conceptos: la producción es un histórico del hecho ocurrido en el lote y el stock disponible es el saldo físico administrado por Inventario.

La unidad atómica es siempre un huevo genérico (`Huevo`), expresado como entero en `unit`. No se solicitan productos, ubicaciones, clasificaciones, descartes ni presentaciones comerciales en la API de recolección. Cajones, mapples y clasificaciones pertenecen a módulos posteriores.

Es una ampliación coordinada de `Lots` e `Inventory`. Sus dependencias son Lotes y cría, Inventario y stock, unidades productivas, identidad y acceso, y auditoría y trazabilidad. No se agregan paquetes.

La estructura sigue la arquitectura Laravel convencional descrita en [arquitectura](../architecture.md) y el nivel de detalle operativo de [lots-implementation.md](lots-implementation.md). El contrato público se encuentra en [contracts/openapi/lots.yaml](../../contracts/openapi/lots.yaml).

### Límites de la entrega

- Las recolecciones sólo reciben `quantity`, `occurred_at` opcional y `notes` opcionales.
- Una recolección no modifica cantidad viva, versión, estado ni ubicación actual del lote.
- Las divisiones, agrupaciones, fusiones y finalizaciones de lotes no eliminan ni reatribuyen recolecciones existentes.
- Las entradas manuales, preparaciones de reparto y pérdidas se registran directamente en la cuenta de stock de la UP; no modifican la producción histórica.
- No se migran datos reales del sistema anterior. Al no existir datos reales, se validan las migraciones mediante `migrate:fresh --seed`.

## Implementación realizada

- Las recolecciones viven en `app/Actions/Lots`, `app/Queries/Lots`, `app/Http/Requests/Lots` y `app/Http/Resources/Lots`. El movimiento físico se solicita a la frontera pública de Inventario y nunca se escriben saldos desde Lotes.
- La migración de `egg_collections` conserva lote, galpón y UP fotografiados, cantidad, fecha efectiva, observaciones, estado, versión y actor.
- Inventario agrega `egg_stock_accounts`, `egg_stock_transactions`, `egg_stock_transaction_revisions` y `egg_stock_commands`.
- `EnsureEggStockAccountAction` materializa de forma idempotente una cuenta técnica por UP, con el producto protegido `Huevo` (`system_key=generic_egg`, `kind=egg`, `base_unit=unit`) y una ubicación técnica exclusiva.
- El saldo real sólo se consulta en `stock_balances`. Una UP sin movimientos responde saldo cero; la cuenta se crea al primer movimiento y los seeders la precrean.
- `EggStockTransaction` es la proyección lógica de cada entrada o salida. Sus tipos estables son `collection_receipt`, `manual_receipt`, `distribution_preparation` y `loss`.
- Los movimientos físicos de Inventario son `receipt` para entradas, `issue` para preparación de reparto, `loss` para pérdidas y `adjustment` para correcciones o compensaciones.
- Las cuentas técnicas no pueden operarse mediante endpoints genéricos de Inventario. Las Actions especializadas validan producto, ubicación, unidad y UP, y protegen su desactivación o reutilización.

## Registro de producción e ingreso automático

`RecordEggCollectionAction` bloquea el lote, la UP y la referencia de Inventario en una única transacción. El lote debe estar abierto, tener aves vivas y pertenecer a una UP operativa. La cantidad debe ser un entero entre 1 y `2147483647`.

El encabezado de la recolección conserva el galpón y la UP fotografiados al momento del hecho. La fecha efectiva usa la zona horaria de `LOTS_TIMEZONE` (por defecto `America/Montevideo`) y no puede estar en el futuro ni antes de la existencia del lote o de su último movimiento de aves. Si no se envía, se utiliza el reloj del servidor.

La Action crea una transacción lógica de tipo `collection_receipt` y un movimiento físico `receipt` por la misma cantidad. Ambos comparten `operation_id` y se confirman junto con la recolección y su auditoría. Un fallo de validación, bloqueo, inventario o auditoría revierte todo el comando.

Corregir o cancelar una recolección no cambia aves ni versión del lote. La recolección continúa disponible aunque el lote se divida, fusione o finalice; sólo se exige una UP operativa para registrar operaciones nuevas.

## Aviso por falta de recolección diaria

`lotes:evaluar-recolecciones-diarias` revisa cada cinco minutos los lotes abiertos con aves en UP activas, con los mismos criterios de estado que el registro de recolección. Usa la fecha efectiva de la última recolección vigente del lote en `LOTS_TIMEZONE` y cuenta jornadas completas consecutivas desde el día siguiente. Si el lote aún no tiene recolecciones vigentes, cuenta desde su jornada de admisión. La jornada actual cuenta al cerrar a medianoche.

El aviso empieza en `bajo` al primer día, sube a `medio` al segundo y a `alto` al séptimo. Se actualiza el mismo aviso por destinatario durante la racha y una escalada vuelve a marcarlo como no leído. Registrar la recolección del día resuelve el aviso en la siguiente evaluación. Las correcciones de fecha y anulaciones recalculan la racha a partir de los registros vigentes.

La API devuelve `modulo: lots`, `tipo: daily_egg_collection_missing` y el lote en `entidad`, con `entidad.ruta` en `/administracion/lotes/lotes/{id}/recoleccion`. Los destinatarios necesitan `can_view_egg_collections` y `can_view_notifications` dentro del alcance de la UP. `GET` y `PUT /api/v1/unidades-productivas/{productionUnit}/umbrales-alertas/daily_egg_collection_missing` requieren `can_manage_alert_thresholds`. La edición de umbrales se audita y repetir los mismos valores conserva el registro original. [Contrato de notificaciones](../../contracts/openapi/notifications.yaml)

Pasaron 55 pruebas en PostgreSQL aislado, incluidas la escalada, el corte local de jornada, la resolución, la anulación y la configuración de umbrales. También se verificaron el horario de evaluación, el contrato OpenAPI y el análisis de tipos. La alerta usa las recolecciones existentes y la configuración común de umbrales.

## Cuenta corriente de huevos

Las operaciones manuales se ejecutan desde Inventario, siempre sobre la cuenta técnica de una UP:

| Tipo lógico | Movimiento físico | Uso |
|---|---|---|
| `collection_receipt` | `receipt` | Ingreso automático de una recolección. |
| `manual_receipt` | `receipt` | Ajuste o carga manual sin lote. |
| `distribution_preparation` | `issue` | Retiro para preparar un reparto futuro. |
| `loss` | `loss` | Huevos caídos, rotos u otra pérdida posterior. |

El saldo se obtiene exclusivamente de `stock_balances` y puede ser negativo para cuentas de huevos. Ningún otro producto o ubicación del inventario puede tener saldo negativo. Los reportes generales de saldos y movimientos exponen UP como columna, filtro y agrupación y aumentan sus versiones de definición.

Cualquier movimiento confirmado que deje este saldo por debajo de cero abre o actualiza el [aviso alto de stock negativo de la UP](alerts-and-notifications-implementation-plan.md#10-stock-negativo-de-huevos-por-up). Al llegar a cero o recuperar un saldo positivo, el aviso se resuelve.

Una UP inactiva o finalizada no admite movimientos nuevos, pero sus operaciones históricas pueden consultarse, corregirse o cancelarse. No se implementan transferencias ni reatribuciones entre UP: la UP fotografiada por una recolección es inmutable.

## Correcciones, cancelaciones e histórico

Las transacciones lógicas son append-only desde el punto de vista histórico. `egg_stock_transaction_revisions` conserva `before`, `after`, motivo obligatorio, actor, fecha real y `operation_id`; `egg_stock_commands` conserva la clave de idempotencia, hash y respuesta.

- En una corrección de cantidad en la misma fecha se registra sólo la diferencia.
- Si cambia explícitamente `occurred_at`, se compensa el efecto completo en la fecha anterior y se registra el efecto completo en la nueva. La transacción muestra la nueva fecha y la revisión conserva ambas.
- Si no se envía `occurred_at`, se conserva exactamente la fecha efectiva anterior.
- Una corrección textual incrementa versión y auditoría, pero no crea movimientos de cantidad cero.
- Una cancelación mantiene el estado `cancelled`, agrega una compensación inversa y excluye la transacción de la proyección vigente.
- UP, dirección y tipo son inmutables. Para cambiar alguno se cancela la operación y se crea otra.
- Las transacciones originadas por una recolección sólo se corrigen o cancelan desde los endpoints de recolecciones. Las operaciones manuales usan los endpoints de stock.
- Todas las correcciones requieren `version` vigente, `correction_reason` y una nueva `Idempotency-Key`.

La concurrencia se serializa por actor, UP, cuenta y transacción. Los deadlocks se reintentan hasta tres veces. Repetir una clave con el mismo payload devuelve la respuesta original; reutilizarla con otro contenido responde `409` sin aplicar efectos parciales.

## Consultas, métricas y permisos

El histórico de recolecciones se consulta por lote o globalmente y permite filtrar por lote, galpón, UP, estado y fechas. El histórico de stock se pagina por UP y permite filtrar por tipo, estado y fechas; el detalle incluye revisiones y referencias a Inventario.

Las métricas de producción diaria, semanal y mensual cuentan únicamente recolecciones con estado `recorded`. Las correcciones se reflejan en la cantidad vigente y las cancelaciones se excluyen. El período por defecto es de 30 días calendario, con máximo de 366 días inclusivos; las semanas comienzan el lunes y los meses usan el calendario local. Las entradas manuales, pérdidas y salidas no alteran estas métricas.

| Permiso | Alcance |
|---|---|
| `can_view_egg_collections` | Histórico y métricas de recolección. |
| `can_manage_egg_collections` | Registro, corrección y cancelación de recolecciones. |
| `can_view_egg_stock` | Saldos, movimientos y revisiones de huevos. |
| `can_move_egg_stock` | Entradas manuales, preparación de reparto y pérdidas. |
| `can_adjust_egg_stock` | Correcciones y cancelaciones de operaciones manuales. |

Los permisos son funcionales y globales dentro de la empresa: no existe asignación usuario–UP ni permisos con IDs de unidades. La auditoría se registra sincrónicamente dentro de la misma transacción y nunca contiene credenciales, tokens ni secretos.

## Contrato HTTP

Todas las rutas son relativas a `/api/v1`, usan Bearer token y mantienen errores `application/problem+json` (`401`, `403`, `404`, `409` y `422`). Los tipos internos permanecen en inglés y el contrato público en español. Toda escritura exige `Idempotency-Key`.

| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| POST | `/flocks/{flock}/collections` | Registrar cantidad de huevos e ingreso automático en la UP. | `can_manage_egg_collections` |
| PATCH | `/collections/{collection}` | Corregir cantidad, fecha u observaciones. | `can_manage_egg_collections` |
| POST | `/collections/{collection}/cancellation` | Cancelar y compensar el ingreso. | `can_manage_egg_collections` |
| GET | `/flocks/{flock}/collections` | Histórico del lote. | `can_view_egg_collections` |
| GET | `/collections` | Histórico global filtrable. | `can_view_egg_collections` |
| GET | `/collections/{collection}` | Detalle de la recolección. | `can_view_egg_collections` |
| GET | `/collections/metrics` | Producción diaria, semanal y mensual. | `can_view_egg_collections` |
| GET | `/flocks/{flock}/metrics` | Métricas acotadas al lote. | `can_view_egg_collections` |
| GET | `/production-units/{up}/egg-stock` | Saldo entero actual, incluso negativo. | `can_view_egg_stock` |
| GET | `/production-units/{up}/egg-stock/movements` | Cuenta corriente paginada. | `can_view_egg_stock` |
| GET | `/egg-stock/movements/{movement}` | Detalle, revisiones y referencias físicas. | `can_view_egg_stock` |
| POST | `/production-units/{up}/egg-stock/receipts` | Entrada manual. | `can_move_egg_stock` |
| POST | `/production-units/{up}/egg-stock/issues` | Preparación de reparto o pérdida. | `can_move_egg_stock` |
| PATCH | `/egg-stock/movements/{movement}` | Corrección de una operación manual. | `can_adjust_egg_stock` |
| POST | `/egg-stock/movements/{movement}/cancellation` | Cancelación de una operación manual. | `can_adjust_egg_stock` |

Las cantidades aceptan sólo enteros entre 1 y `2147483647`. Las salidas requieren `kind=distribution_preparation|loss`; motivo y observaciones son texto controlado. Para cambios de UP, dirección o tipo se debe cancelar y registrar una operación nueva.

## Datos demo y despliegue

`database/seeders/Lots/EggProductionDemoSeeder.php` está registrado en `LocalDemoDataSeeder` y corre en `local`, o en `production` con `DEMO_DB_RESET_ENABLED=true`. Usa las mismas Actions que la API y crea un único producto técnico `Huevo`, una cuenta por UP, recolecciones, una entrada manual, una preparación de reparto, una pérdida y una corrección. Sus claves idempotentes evitan duplicados al repetir el seeder.

Como no existen datos reales, el cambio reemplaza la estructura anterior y se valida desde cero:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

No ejecutar `docker compose down -v`, porque elimina volúmenes y datos del entorno. En un ambiente con datos que deban conservarse se debe coordinar una migración específica; esta entrega no agrega compatibilidad ni migración del modelo anterior.

### Fixture demo `LOT-EGG-TEST`

Desde el 20 de octubre de 2026, `EggHistoryDemoSeeder` forma parte de `LocalDemoDataSeeder`, después de los demos básicos y reportes, para mostrar producción abundante y su relación con mortalidades y pesajes. Se ejecuta automáticamente en `local` y en `production` únicamente con `DEMO_DB_RESET_ENABLED=true`. Requiere administrador con permisos, una UP activa, una raza activa y un plan publicado. También puede repetirse individualmente en local con:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\Lots\EggHistoryDemoSeeder' --no-interaction
```

- Crea `LOT-EGG-TEST` en un `Galpón Huevos Test` propio con capacidad física de 240 aves. Ingresa 240 aves y queda con 229 después de 11 bajas repartidas en ocho registros semanales.
- En la primera carga el lote tiene 30 semanas según el cálculo de edad del módulo. Sus ocho semanas de historial terminan el día anterior a la ejecución; las fechas quedan ancladas al ingreso para que repetir otro día conserve el mismo historial.
- Crea 168 recolecciones: tres por día durante 56 días, a las 08:00, 13:00 y 17:00 de `LOTS_TIMEZONE`. Las cantidades diarias varían y aumentan progresivamente, sumando 9.291 huevos; cada recolección ingresa automáticamente al stock de su UP.
- Crea ocho jornadas semanales de pesaje diario, cada una con diez ingresos individuales y dos grupos de cinco aves: 96 ingresos y muestras de veinte aves por jornada. Los pesos se expresan en gramos; se confirma expresamente cualquier valor fuera del rango configurado.
- Usa las Actions existentes para permisos, versiones, población histórica, movimientos físicos y auditoría sincrónica. La carga completa es transaccional y restaura el reloj al finalizar, incluso si falla.
- Sus claves de idempotencia conservan las operaciones, el stock y la auditoría al repetir. No modifica capacidad física ni borra registros. Producción sin la opción demo y los demás entornos quedan excluidos.

No cambia endpoints, permisos, contratos OpenAPI, esquema ni dependencias. La cobertura está en `tests/Feature/Lots/EggHistoryDemoSeederTest.php`: carga general local, historial, fechas, cantidad viva, capacidad física, pesajes mixtos, correspondencia con ingresos a stock y auditoría, repetición otro día y exclusión de producción sin la opción demo. `AdminSeederTest` comprueba la inclusión con esa opción activa. En esta actualización no se pudieron ejecutar PHPUnit ni Pint porque Docker Desktop no estaba disponible y no hay PHP ni dependencias instaladas fuera de Docker.

La prueba de exclusión en producción invoca `run` mediante el contenedor de Laravel. Así comprueba directamente la restricción de ambiente del seeder, sin pasar por la confirmación interactiva de `db:seed` que interrumpía la suite de CI al simular producción.

Verificación de esa corrección: las dos pruebas del fixture aprobaron con 28 aserciones y la suite completa, ejecutada localmente con las variables del workflow de CI sobre `gam_testing`, aprobó con 454 pruebas y 3.804 aserciones. Pint sobre el archivo de prueba y `git diff --check` también aprobaron. Los mensajes de error de auditoría corresponden a fallos provocados deliberadamente por las pruebas de rollback, que aprobaron.

Verificación del fixture: dos pruebas aprobadas con 28 aserciones en PostgreSQL aislado, Pint aprobado sobre los dos archivos PHP modificados y `git diff --check` sin errores. Se intentó `pint --dirty --format agent`, pero el contenedor no permite detectar Git; por eso se formatearon las rutas explícitas. El análisis completo con `.phpstan-lots.neon` queda limitado por dos errores preexistentes de `withResourceMetadata()` en `RecordInventoryMovementAction.php` (líneas 301 y 424), fuera del alcance del fixture.

El 13 de octubre de 2026 se ejecutó dos veces en la base local `gam`: ingreso del lote el 24 de marzo de 2026, recolecciones desde el 18 de agosto hasta el 12 de octubre, 229 aves vivas, ocho mortalidades con once bajas, ocho jornadas con 96 ingresos de pesaje y 168 recolecciones e ingresos de stock por 9.291 huevos. La consulta posterior confirmó que la segunda ejecución conservó esos totales.

## Verificación automatizada

La cobertura específica está en `tests/Feature/Lots/EggCollectionEndpointTest.php`, `tests/Feature/Inventory/EggStockEndpointTest.php`, `tests/Feature/Lots/LotsContractTest.php` y `tests/Feature/Lots/LotsDemoSeederTest.php`. Incluye ingreso atómico, separación entre producción y saldo, cuenta negativa sólo para huevos, entradas y salidas manuales, corrección `4000 → 400`, correcciones de fecha, cancelación, idempotencia, versiones, permisos, concurrencia, auditoría, rollback, protección de endpoints genéricos, filtros por UP y repetibilidad del seeder.

La suite específica y `migrate:fresh --seed` fueron ejecutadas durante la implementación. PHPStan/Larastan, Pint y `git diff --check` también se validaron. La aceptación humana **Do Test** permanece pendiente y no se debe marcar la tarjeta de Notion como probada sólo por la suite automática.

## Do Test — validación manual pendiente

1. Levantar Compose, cargar la demo desde [README.md](../../README.md) y autenticar un usuario con permisos funcionales.
2. Registrar una recolección en un lote activo. Verificar que el histórico del lote aumenta, que la cuenta de la UP recibe un `receipt` y que la cantidad viva y la versión del lote permanecen iguales.
3. Repetir la misma clave y confirmar que no se duplica el ingreso. Consultar métricas y comprobar que sólo cuentan recolecciones vigentes.
4. Registrar una entrada manual, una salida `distribution_preparation` y una salida `loss`; comprobar saldo, referencias físicas y que ninguna cambia las métricas de producción. Confirmar que una salida puede dejar saldo negativo sólo en huevos.
5. Corregir una entrada de `4000` a `400`, corregir sólo observaciones y corregir moviendo explícitamente la fecha. Revisar compensaciones, revisiones `before/after`, versiones y auditoría.
6. Cancelar una recolección y una operación manual. Confirmar estado `cancelled`, compensación completa, histórico intacto y bloqueo de correcciones posteriores sin nueva operación.
7. Intentar operar las cuentas técnicas mediante endpoints genéricos de Inventario, cambiar el producto o ubicación técnica y registrar sobre una UP inactiva. Cada caso debe responder `403`, `409` o `422` sin escrituras parciales.
8. Dividir, fusionar o finalizar un lote y consultar sus recolecciones previas. Deben conservar lote, galpón, UP, fecha y cantidad originales.
9. Ejecutar el seeder local dos veces, comprobar idempotencia y verificar que con `APP_ENV=production` sólo genera datos cuando `DEMO_DB_RESET_ENABLED=true`.

Registrar ambiente, commit, zona horaria, requests anonimizados, claves de idempotencia, respuestas, saldos y evidencia de auditoría. Si algún escenario no se ejecuta, mantener Do Test pendiente.

## Fuera de alcance

Clasificación por calidad, cajones, mapples, conversiones de presentación, repartos y reservas automáticas, transferencias entre UP, interfaz web/mobile, sincronización offline del dispositivo, outbox externo, migración de datos reales y sincronización directa con Notion.
