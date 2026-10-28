# Plantas de ración y stock de ingredientes

## Alcance implementado

Los galpones distinguen los tipos `poultry` (avícola, valor por defecto) y `feed` (planta de ración). Las plantas de ración no reciben lotes, guardan `bird_capacity` como `null` y mantienen una ubicación técnica de Inventario exclusiva. El tipo queda fijo después del alta.

El stock de ingredientes se conserva en Inventario, con `raw_material` como tipo de producto y gramos (`g`) como unidad base. Los movimientos pueden declarar gramos o kilogramos; el backend convierte los kilogramos a gramos con aritmética decimal antes de persistirlos. El saldo puede ser negativo en una planta y la respuesta expone `is_negative`. La transición a saldo negativo queda auditada de forma síncrona; la notificación visible se agregará cuando exista el módulo correspondiente.

Una planta puede estar operativa, en mantenimiento, fuera de servicio o inactiva. Desde las restricciones de almacenamiento del 28/10/2026, los movimientos nuevos exigen una planta operativa y una UP activa. Una planta inactiva conserva su saldo y su historial. En la consulta de esa planta, `total_g` se devuelve como `0` y el saldo real permanece en `details.stock_g` con `included_in_totals: false`. En los totales de la unidad productiva y global, ese detalle se excluye hasta que la planta vuelva a estar activa. Las plantas en mantenimiento u `out_of_service` siguen contando en los totales.

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

## Productos permitidos por ubicación y operación

El registro de movimientos dispone de `GET /api/v1/inventario/productos-permitidos`.
La compatibilidad por categoría y unidad se centraliza en `StockProductCompatibility`,
utilizada tanto por esta consulta como por `RecordInventoryMovementAction`. Agregar
tipos de ubicación requiere extender esa regla del backend; el cliente consume sus
resultados sin mantener una tabla propia de categorías compatibles.

Parámetros:

- `stock_location_id`: obligatorio, ID de Inventario devuelto por el catálogo de
  ubicaciones; no es el ID del galpón.
- `operation`: obligatorio; `receipt`, `issue`, `loss`, `adjustment` o `transfer`.
- `search`: búsqueda parcial por nombre, sin distinguir mayúsculas, hasta 120 caracteres.
- `per_page`: entre 1 y 100, por defecto 50. `page`: entero positivo, por defecto 1.
  Los enlaces de paginación conservan los filtros validados de ubicación, operación y búsqueda.

Ejemplo de consulta para pérdidas:

```http
GET /api/v1/inventario/productos-permitidos?stock_location_id=12&operation=loss
```

Cada elemento de `data` contiene exclusivamente los datos necesarios para seleccionar
el producto y conocer el saldo local; la respuesta incluye `links` y `meta` de paginación,
ordenada por nombre e ID:

```json
{
  "id": 18,
  "sku": "MAIZ-026",
  "name": "Maíz partido",
  "kind": "raw_material",
  "base_unit": "g",
  "available_quantity": "2500.000000"
}
```

Reglas de elegibilidad:

- Sólo productos activos con control de stock. Se excluyen el producto técnico
  `generic_egg` y los pares producto/ubicación administrados por cuentas de huevos.
- Cámaras: sólo `supply` y `other`; sanidad: `medicine` y `vaccine`; cuentas de huevos: `egg` con las protecciones del dominio; plantas: sólo `raw_material` en gramos. Una ubicación desconocida, inactiva, con UP inactiva o planta no operativa devuelve `data: []`, con total cero. La política completa y su migración están en la [guía de almacenamiento](inventory-storage-implementation.md).
- `loss`: exclusivamente saldo local mayor que cero, incluso cantidades tan pequeñas
  como `0.000001`; stock positivo en otra ubicación no habilita el producto.
- Las otras operaciones ofrecen productos compatibles aunque no tengan cuenta, su saldo
  sea cero o sea negativo. Sin cuenta se informa `0.000000`. `finished_feed` conserva lectura histórica, pero no admite movimientos nuevos por el selector ni por los comandos genéricos. Los saldos incompatibles se corrigen mediante regularización auditada.

Integración del formulario:

1. Elegir ubicación y operación antes de habilitar la selección de productos.
2. Consultar el endpoint y cargar las opciones, con su unidad base y saldo local.
3. Al cambiar ubicación u operación, invalidar las opciones anteriores, reiniciar la
   búsqueda/paginación y limpiar los productos cuya compatibilidad no esté confirmada.
   Limpiar toda la selección antes de recargar es una estrategia válida y evita inferir
   que un producto no existe sólo porque quedó fuera de la página o búsqueda actual.
4. Ignorar respuestas de consultas anteriores que lleguen después de cambiar la ubicación.
   Para transferencias, consultar origen y destino y permitir sólo productos válidos en ambos.
5. Refrescar las opciones tras guardar una pérdida: el saldo puede haber llegado a cero.

La limpieza del selector corresponde al frontend. Esta entrega implementa el contrato
backend y rechaza selecciones incompatibles al guardar, sin modificar una interfaz web.
La consulta requiere sesión autenticada, contexto compartido vigente cuando corresponde
y `inventory.view` o rol `admin`, igual que la lectura de movimientos. El acceso sigue
siendo global para todas las UP, sin asignaciones por usuario. Las escrituras conservan
sus permisos específicos (`inventory.move` o `inventory.adjust`).

Es una lectura sin caché de aplicación, reservas, saldos nuevos, auditorías ni claves de
idempotencia. No requiere migraciones, seeders, cambios de datos ni dependencias.
El saldo es informativo: puede cambiar entre consulta y envío. Las escrituras conservan
sus locks, validación transaccional, idempotencia y auditoría síncrona. El filtro positivo
de pérdidas orienta el selector y no modifica las reglas existentes de saldos negativos
permitidos para materias primas.

La verificación cubre categorías por ubicación, estado, control de stock, huevos técnicos,
saldos ausentes/cero/negativos, aislamiento del saldo por ubicación, cambios de ubicación,
rechazo transaccional de selecciones anteriores, búsqueda, paginación, filtros inválidos,
autenticación y permiso. El contrato está en [feed-stock.yaml](../../contracts/openapi/feed-stock.yaml).

```sh
docker compose -f compose.dev.yaml exec -T api php artisan test --compact tests/Feature/Inventory/AllowedStockProductsEndpointTest.php tests/Feature/Inventory/InventoryEndpointTest.php tests/Feature/Inventory/FeedStockEndpointTest.php tests/Feature/Inventory/FeedManufactureEndpointTest.php tests/Feature/Inventory/FeedAdjustmentEndpointTest.php tests/Feature/Inventory/EggStockEndpointTest.php
docker compose -f compose.dev.yaml exec -T api php vendor/bin/pint --dirty --format agent
```

Resultado: 88 pruebas aprobadas y 581 aserciones en la base de testing. Tras agregar
la conservación de filtros en los enlaces de paginación se repitieron las 21 pruebas
del nuevo endpoint, aprobadas con 109 aserciones. PHPStan pasó sobre los archivos PHP
afectados y se repitió sobre la consulta final; el contrato YAML se analizó correctamente
y `route:list` confirmó la ruta. Pint pasó con las rutas exactas de los PHP modificados,
porque `--dirty` necesita Git y el contenedor no lo incluye. No se ejecutó la suite
completa ni una aceptación visual del frontend.

## Datos demo locales

`DatabaseSeeder` registra [FeedStockDemoSeeder](../../database/seeders/Inventory/FeedStockDemoSeeder.php) a través de `LocalDemoDataSeeder`. Sólo se ejecuta con `APP_ENV=local` y usa claves fijas para que las recargas sean idempotentes.

La demo conserva los tres galpones avícolas existentes y crea una planta feed para cada depósito de alimentos demo, con una ubicación técnica 1:1. Maíz y soja conservan las cantidades anteriores expresadas en gramos: 1.240.000 g y 680.000 g en El Ombú; 760.000 g y 420.000 g en Santa Clara. Las líneas históricas de apertura, recepción y consumo también están expresadas en gramos.

Si la base local conserva el demo anterior, ejecuta `docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force` con `APP_ENV=local` para reemplazar los valores históricos en kg por su representación en gramos. No se presume una migración de inventario productivo.

## Límites actuales

La entrega original de stock se amplió con recetas, elaboración inmediata y alimentación de lotes, descritas en las guías de recetas y alimentación. Las restricciones actuales y la regularización histórica están en la [guía de almacenamiento](inventory-storage-implementation.md); las notificaciones visibles conservan su alcance documentado en Alertas. No se define capacidad física máxima para las plantas. La aceptación manual de los flujos queda pendiente y debe ejecutarse en un ambiente local o QA.
