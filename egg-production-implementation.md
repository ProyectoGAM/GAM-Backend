# 09 — Producción y stock de huevos

## Descripción y alcance

El módulo registra la producción diaria de huevos por lote y mantiene una cuenta corriente de huevos por unidad productiva (UP). Se separan dos conceptos: la producción es un histórico del hecho ocurrido en el lote y el stock disponible es el saldo físico administrado por Inventario.

La unidad atómica es siempre un huevo genérico (`Huevo`), expresado como entero en `unit`. No se solicitan productos, ubicaciones, clasificaciones, descartes ni presentaciones comerciales en la API de recolección. Cajones, mapples y clasificaciones pertenecen a módulos posteriores.

Es una ampliación coordinada de `Lots` e `Inventory`. Sus dependencias son Lotes y cría, Inventario y stock, unidades productivas, identidad y acceso, y auditoría y trazabilidad. No se agregan paquetes.

La estructura sigue [module-structure-example.md](module-structure-example.md) y el nivel de detalle operativo de [lots-implementation.md](lots-implementation.md). El contrato público se encuentra en [contracts/openapi/lots.yaml](contracts/openapi/lots.yaml).

### Límites de la entrega

- Las recolecciones sólo reciben `cantidad`, `ocurrido_en` opcional y `observaciones` opcionales.
- Una recolección no modifica cantidad viva, versión, estado ni ubicación actual del lote.
- Las divisiones, agrupaciones, fusiones y finalizaciones de lotes no eliminan ni reatribuyen recolecciones existentes.
- Las entradas manuales, preparaciones de reparto y pérdidas se registran directamente en la cuenta de stock de la UP; no modifican la producción histórica.
- No se migran datos reales del sistema anterior. Al no existir datos reales, se validan las migraciones mediante `migrate:fresh --seed`.

## Implementación realizada

- Las recolecciones viven en `app/Modules/Lots`, con Actions, Queries, FormRequests y Resources. El movimiento físico se solicita a la frontera pública de Inventario y nunca se escriben saldos desde Lotes.
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

La Action crea una transacción lógica de tipo `collection_receipt` y un movimiento físico `receipt` por la misma cantidad. Ambos comparten `id_operacion` y se confirman junto con la recolección y su auditoría. Un fallo de validación, bloqueo, inventario o auditoría revierte todo el comando.

Corregir o cancelar una recolección no cambia aves ni versión del lote. La recolección continúa disponible aunque el lote se divida, fusione o finalice; sólo se exige una UP operativa para registrar operaciones nuevas.

## Cuenta corriente de huevos

Las operaciones manuales se ejecutan desde Inventario, siempre sobre la cuenta técnica de una UP:

| Tipo lógico | Movimiento físico | Uso |
|---|---|---|
| `collection_receipt` | `receipt` | Ingreso automático de una recolección. |
| `manual_receipt` | `receipt` | Ajuste o carga manual sin lote. |
| `distribution_preparation` | `issue` | Retiro para preparar un reparto futuro. |
| `loss` | `loss` | Huevos caídos, rotos u otra pérdida posterior. |

El saldo se obtiene exclusivamente de `stock_balances` y puede ser negativo para cuentas de huevos. Ningún otro producto o ubicación del inventario puede tener saldo negativo. Los reportes generales de saldos y movimientos exponen UP como columna, filtro y agrupación y aumentan sus versiones de definición.

Una UP inactiva o finalizada no admite movimientos nuevos, pero sus operaciones históricas pueden consultarse, corregirse o cancelarse. No se implementan transferencias ni reatribuciones entre UP: la UP fotografiada por una recolección es inmutable.

## Correcciones, cancelaciones e histórico

Las transacciones lógicas son append-only desde el punto de vista histórico. `egg_stock_transaction_revisions` conserva `before`, `after`, motivo obligatorio, actor, fecha real y `operation_id`; `egg_stock_commands` conserva la clave de idempotencia, hash y respuesta.

- En una corrección de cantidad en la misma fecha se registra sólo la diferencia.
- Si cambia explícitamente `ocurrido_en`, se compensa el efecto completo en la fecha anterior y se registra el efecto completo en la nueva. La transacción muestra la nueva fecha y la revisión conserva ambas.
- Si no se envía `ocurrido_en`, se conserva exactamente la fecha efectiva anterior.
- Una corrección textual incrementa versión y auditoría, pero no crea movimientos de cantidad cero.
- Una cancelación mantiene el estado `cancelled`, agrega una compensación inversa y excluye la transacción de la proyección vigente.
- UP, dirección y tipo son inmutables. Para cambiar alguno se cancela la operación y se crea otra.
- Las transacciones originadas por una recolección sólo se corrigen o cancelan desde los endpoints de recolecciones. Las operaciones manuales usan los endpoints de stock.
- Todas las correcciones requieren `version` vigente, `motivo_correccion` y una nueva `Idempotency-Key`.

La concurrencia se serializa por actor, UP, cuenta y transacción. Los deadlocks se reintentan hasta tres veces. Repetir una clave con el mismo payload devuelve la respuesta original; reutilizarla con otro contenido responde `409` sin aplicar efectos parciales.

## Consultas, métricas y permisos

El histórico de recolecciones se consulta por lote o globalmente y permite filtrar por lote, galpón, UP, estado y fechas. El histórico de stock se pagina por UP y permite filtrar por tipo, estado y fechas; el detalle incluye revisiones y referencias a Inventario.

Las métricas de producción diaria, semanal y mensual cuentan únicamente recolecciones con estado `recorded`. Las correcciones se reflejan en la cantidad vigente y las cancelaciones se excluyen. El período por defecto es de 30 días calendario, con máximo de 366 días inclusivos; las semanas comienzan el lunes y los meses usan el calendario local. Las entradas manuales, pérdidas y salidas no alteran estas métricas.

| Permiso | Alcance |
|---|---|
| `egg-collections.view` | Histórico y métricas de recolección. |
| `egg-collections.manage` | Registro, corrección y cancelación de recolecciones. |
| `egg-stock.view` | Saldos, movimientos y revisiones de huevos. |
| `egg-stock.move` | Entradas manuales, preparación de reparto y pérdidas. |
| `egg-stock.adjust` | Correcciones y cancelaciones de operaciones manuales. |

Los permisos son funcionales y globales dentro de la empresa: no existe asignación usuario–UP ni permisos con IDs de unidades. La auditoría se registra sincrónicamente dentro de la misma transacción y nunca contiene credenciales, tokens ni secretos.

## Contrato HTTP

Todas las rutas son relativas a `/api/v1`, usan Bearer token y mantienen errores `application/problem+json` (`401`, `403`, `404`, `409` y `422`). Los tipos internos permanecen en inglés y el contrato público en español. Toda escritura exige `Idempotency-Key`.

| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| POST | `/lotes/{lote}/recolecciones` | Registrar cantidad de huevos e ingreso automático en la UP. | `egg-collections.manage` |
| PATCH | `/recolecciones/{recoleccion}` | Corregir cantidad, fecha u observaciones. | `egg-collections.manage` |
| POST | `/recolecciones/{recoleccion}/cancelacion` | Cancelar y compensar el ingreso. | `egg-collections.manage` |
| GET | `/lotes/{lote}/recolecciones` | Histórico del lote. | `egg-collections.view` |
| GET | `/recolecciones` | Histórico global filtrable. | `egg-collections.view` |
| GET | `/recolecciones/{recoleccion}` | Detalle de la recolección. | `egg-collections.view` |
| GET | `/recolecciones/metricas` | Producción diaria, semanal y mensual. | `egg-collections.view` |
| GET | `/lotes/{lote}/metricas` | Métricas acotadas al lote. | `egg-collections.view` |
| GET | `/unidades-productivas/{up}/stock-huevos` | Saldo entero actual, incluso negativo. | `egg-stock.view` |
| GET | `/unidades-productivas/{up}/stock-huevos/movimientos` | Cuenta corriente paginada. | `egg-stock.view` |
| GET | `/stock-huevos/movimientos/{movimiento}` | Detalle, revisiones y referencias físicas. | `egg-stock.view` |
| POST | `/unidades-productivas/{up}/stock-huevos/ingresos` | Entrada manual. | `egg-stock.move` |
| POST | `/unidades-productivas/{up}/stock-huevos/salidas` | Preparación de reparto o pérdida. | `egg-stock.move` |
| PATCH | `/stock-huevos/movimientos/{movimiento}` | Corrección de una operación manual. | `egg-stock.adjust` |
| POST | `/stock-huevos/movimientos/{movimiento}/cancelacion` | Cancelación de una operación manual. | `egg-stock.adjust` |

Las cantidades aceptan sólo enteros entre 1 y `2147483647`. Las salidas requieren `tipo=distribution_preparation|loss`; motivo y observaciones son texto controlado. Para cambios de UP, dirección o tipo se debe cancelar y registrar una operación nueva.

## Datos demo y despliegue

`database/seeders/Lots/EggProductionDemoSeeder.php` está registrado en `LocalDemoDataSeeder` y sólo corre con `APP_ENV=local`. Usa las mismas Actions que la API y crea un único producto técnico `Huevo`, una cuenta por UP, recolecciones, una entrada manual, una preparación de reparto, una pérdida y una corrección. Sus claves idempotentes evitan duplicados al repetir el seeder.

Como no existen datos reales, el cambio reemplaza la estructura anterior y se valida desde cero:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

No ejecutar `docker compose down -v`, porque elimina volúmenes y datos del entorno. En un ambiente con datos que deban conservarse se debe coordinar una migración específica; esta entrega no agrega compatibilidad ni migración del modelo anterior.

## Verificación automatizada

La cobertura específica está en `tests/Feature/Lots/EggCollectionEndpointTest.php`, `tests/Feature/Inventory/EggStockEndpointTest.php`, `tests/Feature/Lots/LotsContractTest.php` y `tests/Feature/Lots/LotsDemoSeederTest.php`. Incluye ingreso atómico, separación entre producción y saldo, cuenta negativa sólo para huevos, entradas y salidas manuales, corrección `4000 → 400`, correcciones de fecha, cancelación, idempotencia, versiones, permisos, concurrencia, auditoría, rollback, protección de endpoints genéricos, filtros por UP y repetibilidad del seeder.

La suite específica y `migrate:fresh --seed` fueron ejecutadas durante la implementación. PHPStan/Larastan, Pint y `git diff --check` también se validaron. La aceptación humana **Do Test** permanece pendiente y no se debe marcar la tarjeta de Notion como probada sólo por la suite automática.

## Do Test — validación manual pendiente

1. Levantar Compose, cargar la demo desde [README.md](README.md) y autenticar un usuario con permisos funcionales.
2. Registrar una recolección en un lote activo. Verificar que el histórico del lote aumenta, que la cuenta de la UP recibe un `receipt` y que la cantidad viva y la versión del lote permanecen iguales.
3. Repetir la misma clave y confirmar que no se duplica el ingreso. Consultar métricas y comprobar que sólo cuentan recolecciones vigentes.
4. Registrar una entrada manual, una salida `distribution_preparation` y una salida `loss`; comprobar saldo, referencias físicas y que ninguna cambia las métricas de producción. Confirmar que una salida puede dejar saldo negativo sólo en huevos.
5. Corregir una entrada de `4000` a `400`, corregir sólo observaciones y corregir moviendo explícitamente la fecha. Revisar compensaciones, revisiones `before/after`, versiones y auditoría.
6. Cancelar una recolección y una operación manual. Confirmar estado `cancelled`, compensación completa, histórico intacto y bloqueo de correcciones posteriores sin nueva operación.
7. Intentar operar las cuentas técnicas mediante endpoints genéricos de Inventario, cambiar el producto o ubicación técnica y registrar sobre una UP inactiva. Cada caso debe responder `403`, `409` o `422` sin escrituras parciales.
8. Dividir, fusionar o finalizar un lote y consultar sus recolecciones previas. Deben conservar lote, galpón, UP, fecha y cantidad originales.
9. Ejecutar el seeder local dos veces, comprobar idempotencia y verificar que con `APP_ENV=production` no genera datos.

Registrar ambiente, commit, zona horaria, requests anonimizados, claves de idempotencia, respuestas, saldos y evidencia de auditoría. Si algún escenario no se ejecuta, mantener Do Test pendiente.

## Fuera de alcance

Clasificación por calidad, cajones, mapples, conversiones de presentación, repartos y reservas automáticas, transferencias entre UP, interfaz web/mobile, sincronización offline del dispositivo, outbox externo, migración de datos reales y sincronización directa con Notion.
