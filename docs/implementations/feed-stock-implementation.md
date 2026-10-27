# Plantas de ración y stock de ingredientes

## Alcance implementado

Los galpones distinguen los tipos `poultry` (avícola, valor por defecto) y `feed` (planta de ración). Las plantas de ración no reciben lotes, guardan `bird_capacity` como `null` y mantienen una ubicación técnica de Inventario exclusiva. El tipo queda fijo después del alta.

El stock de ingredientes se conserva en Inventario, con `raw_material` como tipo de producto y gramos (`g`) como unidad base. Los movimientos pueden declarar gramos o kilogramos; el backend convierte los kilogramos a gramos con aritmética decimal antes de persistirlos. El saldo puede ser negativo en una planta y la respuesta expone `is_negative`. La transición a saldo negativo queda auditada de forma síncrona; la notificación visible se agregará cuando exista el módulo correspondiente.

Una planta puede estar operativa, en mantenimiento, fuera de servicio o inactiva. Los movimientos se permiten en cualquiera de esos estados. Una planta inactiva conserva su saldo y su historial. En la consulta de esa planta, `total_g` se devuelve como `0` y el saldo real permanece en `details.stock_g` con `included_in_totals: false`. En los totales de la unidad productiva y global, ese detalle se excluye hasta que la planta vuelva a estar activa. Las plantas en mantenimiento u `out_of_service` siguen contando en los totales.

## API

El contrato completo está en [contracts/openapi/feed-stock.yaml](../../contracts/openapi/feed-stock.yaml). Las operaciones requieren autenticación y permisos de Inventario; el alta conjunta requiere permisos de catálogo y movimientos.

- `POST /api/v1/plantas-racion/{poultryHouse}/ingredientes`: crea la ficha de una materia prima y su primera carga en una única operación idempotente. Con `proveedor_id` registra una recepción; sin proveedor registra un saldo inicial.
- `GET /api/v1/plantas-racion/{poultryHouse}/stock`: consulta el stock por planta, incluidos los saldos negativos y las plantas inactivas.
- `GET /api/v1/unidades-productivas/{productionUnit}/stock-alimentacion`: suma por ingrediente las plantas activas de una unidad productiva.
- `GET /api/v1/stock-alimentacion`: suma por ingrediente las plantas activas de todas las unidades productivas.

Los movimientos posteriores usan los endpoints de Inventario existentes. Las líneas dirigidas a una ubicación de planta sólo aceptan materias primas en gramos como unidad base; las ubicaciones generales no aceptan materias primas.

El alta recibe `sku`, `nombre`, `cantidad` y, opcionalmente, `unidad` (`g` o `kg`, con `g` por defecto) y `proveedor_id`. La cantidad debe ser positiva. `Idempotency-Key` es obligatorio y se utiliza como identificador estable del movimiento: repetir la misma solicitud devuelve el resultado existente, mientras que reutilizarla con otros datos devuelve un conflicto. Con proveedor se registra una recepción; sin proveedor se registra un saldo inicial.

Las consultas responden con `scope`, `scope_id` e `items`. Cada ítem contiene el producto, `total_g`, `is_negative` y el detalle por planta (`plant_id`, `plant_name`, `poultry_house_id`, `production_unit_id`, `stock_location_id`, `stock_g`, `is_negative` e `included_in_totals`). En la consulta por planta se incluye el saldo aunque la planta esté inactiva; en los agregados, ese detalle queda marcado como excluido.

## Nombre de planta en las consultas generales de Inventario

Las ubicaciones técnicas conservan su `name` generado (`Planta de ración #ID`) y sus identificadores. Para que el frontend identifique la planta sin una consulta adicional, su representación agrega `poultry_house_name`, junto a `poultry_house_id`. El nuevo campo contiene el nombre vigente de la planta vinculada y es `null` en ubicaciones sin planta. Se resuelve desde la relación al consultar, por lo que renombrar una planta se refleja también en las ubicaciones existentes sin sincronizar nombres ni migrar datos.

El campo aparece en:

- `GET /api/v1/stock-locations` y `GET /api/v1/stock-locations/{stockLocation}`: `data[].poultry_house_name` y `data.poultry_house_name`, respectivamente.
- `GET /api/v1/inventory/balances`: `data[].stock_location.poultry_house_name`.
- `PATCH /api/v1/inventory/balances/{stockBalance}/minimum-stock`: `data.stock_location.poultry_house_name`.
- `POST /api/v1/plantas-racion/{poultryHouse}/ingredientes`: `data.stock.stock_location.poultry_house_name`, tanto en el alta como en su repetición idempotente.
- Las respuestas de alta, edición y cambio de estado de ubicaciones manuales incluyen el campo como `null`.

En `GET /api/v1/reference/options`, las etiquetas de `stock_locations` usan el nombre actual de la planta seguido de la UP, y las de `inventory_balances` usan SKU, producto y nombre actual de la planta. Las ubicaciones sin planta conservan sus etiquetas anteriores. Los `value` siguen siendo los IDs de la ubicación y del saldo; no deben sustituirse por el ID de la planta. Los contratos están en [feed-stock.yaml](../../contracts/openapi/feed-stock.yaml) y [reference-data.yaml](../../contracts/openapi/reference-data.yaml).

Las consultas específicas de alimentación conservan `items[].details[].plant_name`. Las relaciones de plantas se cargan anticipadamente para evitar consultas por cada fila. No cambia el orden de los listados ni los filtros por nombre técnico de ubicación.

Se conservan los permisos de cada operación: `inventory.view` para consultar stock y las autorizaciones existentes para administrar ubicaciones y mínimos. Las referencias de Inventario no requieren permisos de estructura de granjas para mostrar el nombre. Esta ampliación de lectura no agrega mutaciones, auditorías ni cambios de idempotencia, concurrencia o cantidades.

La verificación automatizada cubre el nombre actual tras renombrar una planta, ubicaciones sin planta, listado y detalle de ubicaciones, saldos, ajuste de mínimo, alta y repetición de ingredientes, y etiquetas de referencia. Ejecutar:

```sh
docker compose -f compose.dev.yaml exec -T api php artisan test --compact tests/Feature/Inventory/FeedStockEndpointTest.php tests/Feature/Inventory/InventoryEndpointTest.php tests/Feature/ReferenceData/ReferenceOptionsEndpointTest.php
docker compose -f compose.dev.yaml exec -T api php vendor/bin/pint --dirty --format agent
```

Resultado: 23 pruebas aprobadas y 160 aserciones en la base de testing, separada de los datos de desarrollo. Ambos contratos YAML se analizaron correctamente. Pint pasó sobre los diez archivos PHP modificados, indicando sus rutas explícitamente porque `--dirty` no dispone de Git dentro del contenedor. No se ejecutó una aceptación visual en el frontend.

## Responsable de los movimientos

La representación compartida de movimientos de Inventario agrega `created_by_name`, conservando `created_by` como ID. El campo contiene el nombre vigente del usuario que registró el movimiento, incluido cuando fue dado de baja mediante borrado lógico. No se atribuye el movimiento al usuario que está consultando. Cuando no hay usuario asociado, ambos campos son `null`.

Ejemplo parcial:

```json
{
  "created_by": 7,
  "created_by_name": "Ana Pérez"
}
```

Se incluye en `GET /api/v1/inventory/movements`, su detalle `GET /api/v1/inventory/movements/{inventoryMovement}`, las respuestas de ingresos, salidas, pérdidas, ajustes, transferencias y reversiones, y `data.movement` del alta de ingredientes, también en repeticiones idempotentes. El contrato compartido está en [feed-stock.yaml](../../contracts/openapi/feed-stock.yaml).

El nombre se resuelve desde la relación `creator`, ya cargada anticipadamente en las consultas y operaciones existentes, sin consultas adicionales por fila ni exposición del resto del usuario. Es un nombre actual, no una instantánea del nombre al registrar. No requiere migraciones ni cambios de datos, permisos, auditoría, idempotencia, concurrencia o cantidades de stock.

La verificación cubre alta, repetición, reversión, listado y detalle, nombres actualizados, usuarios dados de baja y movimientos sin responsable, además del movimiento anidado del alta de ingredientes. Ejecutar:

```sh
docker compose -f compose.dev.yaml exec -T api php artisan test --compact tests/Feature/Inventory/InventoryEndpointTest.php tests/Feature/Inventory/FeedStockEndpointTest.php
```

Resultado: 21 pruebas aprobadas y 172 aserciones en la base de testing. Pint y PHPStan pasaron sobre los archivos PHP afectados; Pint recibió las rutas explícitas porque `--dirty` requiere Git, que no está disponible dentro del contenedor. La visualización del nombre en el frontend requiere consumir `created_by_name`; esta entrega modifica la respuesta del backend y no incluye verificación visual.

## Datos demo locales

`DatabaseSeeder` registra [FeedStockDemoSeeder](../../database/seeders/Inventory/FeedStockDemoSeeder.php) a través de `LocalDemoDataSeeder`. Sólo se ejecuta con `APP_ENV=local` y usa claves fijas para que las recargas sean idempotentes.

La demo conserva los tres galpones avícolas existentes y crea una planta feed para cada depósito de alimentos demo, con una ubicación técnica 1:1. Maíz y soja conservan las cantidades anteriores expresadas en gramos: 1.240.000 g y 680.000 g en El Ombú; 760.000 g y 420.000 g en Santa Clara. Las líneas históricas de apertura, recepción y consumo también están expresadas en gramos.

Si la base local conserva el demo anterior, ejecuta `docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force` con `APP_ENV=local` para reemplazar los valores históricos en kg por su representación en gramos. No se presume una migración de inventario productivo.

## Límites actuales

La entrega original de stock no implementó recetas, elaboración de alimento ni notificaciones visibles. El catálogo global y el cálculo exacto de recetas se incorporan ahora en la [guía de recetas](feed-recipes-implementation.md); la elaboración y las notificaciones continúan pendientes. No se define capacidad física máxima para las plantas. La aceptación manual de los flujos queda pendiente y debe ejecutarse en un ambiente local o QA.
