# 09 — Producción y stock de huevos

## Descripción y alcance

El módulo registra la producción diaria de huevos de cada lote y la incorpora automáticamente al inventario. Permite conservar el histórico por lote y galpón, clasificar la producción por producto y ubicación, registrar descartes en la recolección, registrar pérdidas posteriores y consultar totales diarios, semanales y mensuales.

Es una ampliación del módulo `Lots`, no una entidad separada de cría. Sus dependencias son Lotes y cría, Inventario y stock, productos y ubicaciones de stock, identidad y acceso, y auditoría y trazabilidad.

La estructura sigue [module-structure-example.md](module-structure-example.md) y el nivel de detalle operativo de [lots-implementation.md](lots-implementation.md). El contrato público se encuentra en [contracts/openapi/lots.yaml](contracts/openapi/lots.yaml).

### Límites de la entrega

- La unidad canónica es el huevo individual (`unit`) y todas las cantidades son enteras positivas.
- Los cajones, mapples y otras presentaciones comerciales quedan fuera de este módulo. La clasificación por líneas deja preparada la integración futura sin convertir todavía esas presentaciones en stock.
- No se migran registros reales del sistema anterior. Los aliases heredados (`cantidad`, `producto_id`, `ubicacion_stock_id` y `movimiento_inventario_id`) se conservan para compatibilidad con clientes existentes, mientras que las líneas de clasificación son la fuente de verdad para nuevas operaciones.
- No se modifica la cantidad de aves del lote al producir huevos.

## Implementación realizada

- Las operaciones viven en `app/Modules/Lots`, con Actions para cada mutación, Queries para lecturas, FormRequests para autorización y validación, y Resources para el contrato JSON en español.
- `EggCollection` conserva el encabezado de la recolección: lote, galpón y unidad productiva fotografiados, fecha, cantidad recolectada, descarte inicial, motivo, observaciones, estado y versión.
- `EggCollectionLine` registra cada combinación única de producto/clasificación, ubicación de stock y cantidad utilizable. La suma de sus líneas debe coincidir con `cantidad_recolectada - cantidad_descartada`.
- Los movimientos de inventario se vinculan mediante `reference_type=egg_collection` y el ULID público de la recolección. Las pérdidas posteriores y sus reversiones permanecen relacionadas con esa misma referencia.
- `EggCollectionRules` concentra las reglas de productos de tipo huevo, unidad `unit`, control de stock, ubicaciones activas, cantidades y unicidad de líneas.
- Las recolecciones, correcciones, cancelaciones, pérdidas y reversiones usan idempotencia por actor, control de versión, bloqueos transaccionales y auditoría síncrona.
- `GetEggProductionMetricsQuery` consolida recolecciones vigentes y pérdidas posteriores activas en la zona horaria configurada por `LOTS_TIMEZONE` (por defecto `America/Montevideo`).

### Registro de una recolección

`RecordEggCollectionAction` bloquea el lote y las referencias de inventario dentro de la transacción. El lote debe estar abierto, tener aves vivas y aportar una versión vigente. La fecha `ocurrido_en` no puede ser futura, anterior a la existencia del lote ni anterior al último movimiento de aves.

El payload nuevo admite `cantidad_recolectada`, `cantidad_descartada`, `motivo_descarte` y `lineas`. Cada línea requiere `producto_id`, `ubicacion_stock_id` y una cantidad entera mayor que cero. El producto debe ser un huevo activo, inventariable y expresado en unidades; la ubicación debe estar activa. El descarte inicial exige un motivo y no entra al stock.

Para clientes antiguos se aceptan `cantidad`, `producto_id` y `ubicacion_stock_id` cuando representan una única línea. Una recolección completamente descartada puede tener `lineas=[]`: se conserva el hecho y su auditoría, pero no se crea un movimiento de ingreso ni se altera el saldo.

### Ingreso automático al inventario

La Action de Lotes llama al contrato público `RecordEggProductionAction` de Inventario. El ingreso y la recolección comparten `id_operacion`, se confirman en una única transacción y generan un movimiento de tipo `receipt` por cada clasificación afectada. Un fallo de validación, stock o auditoría revierte tanto el registro de producción como el movimiento de inventario.

El saldo de huevos se incrementa sólo por la cantidad utilizable clasificada. La cantidad viva, capacidad del galpón y metadatos del lote permanecen sin cambios, excepto por la versión del lote utilizada para serializar la operación.

### Descartes y pérdidas posteriores

El descarte ocurrido durante la recolección se guarda en `cantidad_descartada` y se resta antes de crear el ingreso. Las pérdidas posteriores tienen un flujo independiente:

1. `RecordEggCollectionLossAction` recibe una o más líneas de una recolección vigente.
2. Cada línea debe corresponder a una clasificación existente y no puede superar la cantidad utilizable pendiente de esa clasificación.
3. Inventario registra una salida de tipo `loss` con `reference_type=egg_collection` y `reference_id` igual al ULID de la recolección.
4. La fecha de la pérdida debe estar entre la recolección y el momento actual; las métricas la agrupan por su fecha real.

La pérdida no se borra para corregirla. `CancelEggCollectionLossAction` usa `ReverseInventoryMovementAction` y crea un movimiento compensatorio con `reverses_movement_id`. El histórico original queda intacto y la pérdida deja de contar en el stock y las métricas activas. Si el saldo ya fue consumido o reservado, Inventario responde `409` y no se aplica una restitución parcial.

### Correcciones y cancelación

`CorrectEggCollectionAction` permite reemplazar cantidades, descarte, motivo, observaciones y líneas, siempre que se envíen `version` de la recolección y `version_lote` vigentes. La diferencia entre las líneas anteriores y las nuevas se registra como un ajuste de inventario; nunca se edita el movimiento original.

- Una corrección sólo textual no crea un ajuste de cantidad cero.
- Una corrección no puede reducir una clasificación por debajo de las pérdidas posteriores activas.
- Cancelar una recolección exige motivo y no admite pérdidas posteriores activas. Retira la cantidad actualmente registrada mediante una compensación de inventario y cambia el estado a `cancelled`.
- Una cancelación que no pueda retirar el saldo completo responde `409`, sin modificar recolección, stock, versiones ni auditoría de éxito.
- Los registros cancelados no se reabren ni se corrigen. Las correcciones y cancelaciones son entradas nuevas de auditoría y no eliminan historia.

## Histórico, consultas y métricas

Las consultas devuelven recolecciones registradas y canceladas, salvo que se filtre explícitamente por estado. Se ordenan por `ocurrido_en` descendente y luego por identificador. El galpón y la unidad productiva se conservan como parte de la fotografía histórica, por lo que un traslado posterior del lote no cambia dónde se produjo el hecho.

Las métricas sólo incluyen recolecciones `recorded` y pérdidas posteriores que no tengan una reversión activa. Por defecto abarcan los últimos 30 días calendario; el período solicitado debe ser inclusivo y tener entre 1 y 366 días. El promedio diario incluye los días sin producción. Las series sólo muestran días/semanas/meses que tienen recolección o pérdida.

Los campos consolidados son:

| Campo | Significado |
|---|---|
| `huevos_recolectados` | Total bruto registrado. |
| `huevos_descartados_iniciales` | Descarte informado al recolectar. |
| `huevos_utilizables` | Bruto menos descarte inicial. |
| `huevos_perdidos_posteriores` | Pérdidas activas ocurridas después de la recolección. |
| `huevos_netos` | Utilizable menos pérdidas posteriores. |
| `promedio_diario_neto` | Neto dividido por todos los días del período. |

Las semanas comienzan el lunes y los meses usan el calendario local. La zona horaria es explícita para no mover una producción de día cuando PostgreSQL o el contenedor utilizan UTC.

## Contrato de integración

Todas las rutas son relativas a `/api/v1` y exigen Bearer token. Las respuestas mantienen el formato transversal `application/problem+json` para errores: `401` sin sesión, `403` sin permiso, `404` para referencias inexistentes, `422` para formato/validación y `409` para conflictos de negocio, stock o versión.

| Método | Ruta | Uso | Permiso |
|---|---|---|---|
| GET | `/lotes/{lote}/recolecciones` | Histórico de un lote con filtros. | `egg-collections.view` |
| GET | `/recolecciones` | Histórico consolidado de la empresa. | `egg-collections.view` |
| GET | `/recolecciones/{recoleccion}` | Detalle y líneas de una recolección. | `egg-collections.view` |
| POST | `/lotes/{lote}/recolecciones` | Registrar recolección, descarte y clasificación. | `egg-collections.manage` |
| PATCH | `/recolecciones/{recoleccion}` | Corregir cantidades, líneas u observaciones. | `egg-collections.manage` |
| POST | `/recolecciones/{recoleccion}/cancelacion` | Cancelar con compensación de stock. | `egg-collections.manage` |
| POST | `/recolecciones/{recoleccion}/perdidas` | Registrar pérdida posterior. | `egg-collections.manage` |
| POST | `/recolecciones/{recoleccion}/perdidas/{movimiento}/cancelacion` | Revertir una pérdida sin borrar historia. | `egg-collections.manage` |
| GET | `/lotes/{lote}/metricas` | Producción y pérdidas de un lote. | `egg-collections.view` |
| GET | `/recolecciones/metricas` | Producción y pérdidas de toda la empresa. | `egg-collections.view` |

Los listados usan `pagina` y `por_pagina` acotados, además de lote, galpón, estado y fechas. Los identificadores públicos de recolecciones son ULID; los IDs de productos, ubicaciones y movimientos de inventario continúan siendo numéricos. El permiso es funcional y global dentro de la empresa: no hay asignaciones de usuario a una unidad productiva.

## Transacciones, auditoría y reintentos

Cada comando exige `Idempotency-Key` con UUID. La clave queda asociada al actor y al payload normalizado: repetir exactamente la solicitud devuelve la operación original y no duplica stock; reutilizarla con otro contenido responde `409`.

Las Actions bloquean el lote, la recolección, las líneas y los saldos en orden estable, y reintentan deadlocks hasta tres veces mediante `RunLotsCommand`. La auditoría se registra con `AuditRecorder` y `AuditEntryData` dentro de la misma transacción que la producción o el movimiento de inventario. Una recolección comparte `operation_id` con su ingreso; las pérdidas y compensaciones tienen su propia operación vinculada al mismo sujeto.

La corrección y la cancelación son compensaciones append-only. No se actualizan ni eliminan movimientos de inventario o entradas de auditoría anteriores. Los snapshots contienen el estado permitido de la recolección y el lote para preservar el histórico si después cambian sus datos.

## Permisos y eventos

`egg-collections.view` habilita listados y métricas; `egg-collections.manage` habilita registro, corrección, cancelación, pérdidas y reversiones. El administrador recibe ambos permisos mediante `AdminUserSeeder`. No se requiere permiso de movimiento manual de Inventario para el ingreso legítimo generado por una recolección.

Después del commit se publican `EggsCollected` y `EggCollectionCorrected`. Los eventos no reemplazan la auditoría persistida ni funcionan como outbox para integraciones externas.

## Datos demo y despliegue

`database/seeders/Lots/EggProductionDemoSeeder.php` crea datos ficticios sólo en `APP_ENV=local` y está registrado en `LocalDemoDataSeeder`. Usa las mismas Actions de producción, inventario y pérdidas que la API, con operaciones idempotentes 901 a 905:

| Operación | Escenario |
|---:|---|
| 901 | Crea el lote ponedoras demo. |
| 902 | Registra una recolección clasificada de 30 huevos. |
| 903 | Registra 24 huevos, 2 descartes y dos clasificaciones. |
| 904 | Registra una pérdida posterior de 3 huevos. |
| 905 | Conserva una recolección completamente descartada sin aumentar stock. |

El seeder utiliza `HUEVO-001`, crea `HUEVO-CLASIFICADO-DEMO` si hace falta y crea una ubicación aislada de producción. Es repetible y no pisa cambios posteriores realizados por un usuario. No se ejecuta en producción.

Para reconstruir datos locales ficticios se sigue el procedimiento del README:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

No ejecutar `docker compose down -v`, porque elimina los volúmenes y datos existentes. En una base con datos que deban conservarse, aplicar la migración aditiva `2026_08_30_230431_create_lots_tables.php` y ejecutar el seeder de demo sólo si el ambiente es local.

## Verificación automatizada

La cobertura específica está en `tests/Feature/Lots/EggProductionEndpointTest.php` y `tests/Feature/Lots/EggCollectionEndpointTest.php`. Comprueba clasificación multilinea, descarte inicial, ingreso atómico al stock, pérdidas posteriores, reversión de pérdidas, recolecciones completamente descartadas, correcciones/reclasificación, límites por stock, fechas locales, idempotencia, ciclo de vida del lote y rollback cuando falla la auditoría. `LotsContractTest` valida el contrato OpenAPI y `LotsDemoSeederTest` verifica repetibilidad y ejecución sólo local.

Resultados de la implementación final:

- Regresión completa PHPUnit: **217 pruebas aprobadas, 1338 aserciones y 3 omitidas**.
- Suite específica de producción y recolección: **13 pruebas aprobadas**.
- PHPStan/Larastan con `.phpstan-lots.neon`: sin errores.
- Pint aplicado a los archivos PHP del cambio.
- `git diff --check`: sin errores.

La aceptación humana **Do Test sigue pendiente**. La suite automática no sustituye la validación manual ni la actualización de la tarjeta de Notion.

## Do Test — validación manual

Ejecutar en ambiente local o QA, nunca en producción, con un usuario distinto de quien implementó cuando sea posible. Registrar responsable, fecha, rama/commit, zona horaria, ambiente, requests anonimizados, claves de idempotencia, respuestas HTTP, `data.id_operacion`, saldos y evidencia de auditoría. No guardar contraseñas ni tokens.

### 1. Preparación

1. Levantar Compose, aplicar migraciones y cargar datos locales según [README.md](README.md).
2. Iniciar sesión y usar `Authorization: Bearer`, `Accept: application/json` y `Content-Type: application/json`.
3. Elegir un lote activo con aves, un producto activo de tipo `egg` en unidad `unit`, una ubicación activa y anotar el saldo inicial `S0`.
4. Generar una clave UUID nueva para cada intención y conservarla para repetir exactamente los comandos.

### 2. Recolección clasificada y descarte

Registrar una recolección con dos líneas, por ejemplo 20 huevos genéricos y 10 clasificados, con 2 descartes y motivo. Esperar `201`, una versión nueva de la recolección y del lote, líneas persistidas, `cantidad_utilizable=28`, un ingreso de `S0+28` y el mismo `id_operacion` en Lotes e Inventario. Repetir la misma clave y comprobar que no se duplica el saldo.

Probar producto no huevo, inactivo, no inventariable, unidad distinta de `unit`, ubicación inactiva, línea duplicada, cantidad fraccionaria, descarte sin motivo y suma de líneas inconsistente. Cada caso debe responder `409` o `422` según corresponda, sin registros parciales.

### 3. Pérdida posterior y reversión

Registrar una pérdida sobre una de las líneas ya ingresadas. Esperar una salida `loss`, saldo reducido y `cantidad_perdida_posterior` incrementada. Intentar superar la cantidad pendiente: `409` sin cambios. Cancelar la pérdida; esperar una entrada compensatoria, saldo restituido, `reverses_movement_id` y el movimiento original conservado. Repetir la cancelación o usar otra recolección: `409`.

### 4. Corrección, cancelación y stock consumido

Reclasificar cantidades o reemplazar líneas con versiones vigentes. Esperar un ajuste sólo por la diferencia y que las pérdidas activas sigan limitando la cantidad mínima. Corregir sólo observaciones y comprobar que no aparece un movimiento de cantidad cero. Consumir o reservar parte del stock y luego intentar cancelar la recolección: `409`, sin cambios.

Cancelar otra recolección sin pérdidas posteriores y comprobar compensación completa, estado `cancelled`, exclusión de métricas e histórico intacto. Intentar corregir o reabrirla: `409`.

### 5. Métricas, histórico y permisos

Consultar histórico por lote, galpón y fechas; comprobar que las pérdidas se ubican en el día en que ocurrieron y que los totales diarios, semanales y mensuales concilian con los saldos. Solicitar más de 366 días o fechas invertidas: `409`/`422`. Verificar `401` sin sesión, `403` sin permiso y que un usuario con permiso funcional pueda consultar distintas unidades productivas.

### 6. Seeder y aceptación

Ejecutar el seeder local dos veces y comprobar que no crea duplicados ni sobrescribe operaciones. Ejecutarlo con `APP_ENV=production` no debe crear registros. Considerar la aceptación completa sólo cuando stock, histórico, auditoría, errores sin efectos parciales e idempotencia tengan evidencia. Si algún caso queda bloqueado, mantener Do Test pendiente.

## Fuera de alcance

Presentaciones en cajones o mapples, conversiones de unidades, ventas y despachos, reservas automáticas para otros módulos, interfaz Angular/mobile, almacenamiento offline del dispositivo, outbox externo, migración de datos reales del sistema anterior y sincronización directa con Notion. Estas capacidades pueden consumir las líneas de clasificación y los movimientos de Inventario en módulos posteriores.
