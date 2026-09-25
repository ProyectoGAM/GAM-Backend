# Plantas de ración y stock de ingredientes

## Alcance implementado

Los galpones distinguen los tipos `poultry` (avícola, valor por defecto) y `feed` (planta de ración). Las plantas de ración no reciben lotes, guardan `bird_capacity` como `null` y mantienen una ubicación técnica de Inventario exclusiva. El tipo queda fijo después del alta.

El stock de ingredientes se conserva en Inventario, con `raw_material` como tipo de producto y gramos (`g`) como unidad base. Los movimientos pueden declarar gramos o kilogramos; el backend convierte los kilogramos a gramos con aritmética decimal antes de persistirlos. El saldo puede ser negativo en una planta y la respuesta expone `is_negative`. La transición a saldo negativo queda auditada de forma síncrona; la notificación visible se agregará cuando exista el módulo correspondiente.

Una planta puede estar operativa, en mantenimiento, fuera de servicio o inactiva. Los movimientos se permiten en cualquiera de esos estados. Una planta inactiva conserva su saldo y su historial. En la consulta de esa planta, `total_g` se devuelve como `0` y el saldo real permanece en `details.stock_g` con `included_in_totals: false`. En los totales de la unidad productiva y global, ese detalle se excluye hasta que la planta vuelva a estar activa. Las plantas en mantenimiento u `out_of_service` siguen contando en los totales.

## API

El contrato completo está en [contracts/openapi/feed-stock.yaml](contracts/openapi/feed-stock.yaml). Las operaciones requieren autenticación y permisos de Inventario; el alta conjunta requiere permisos de catálogo y movimientos.

- `POST /api/v1/plantas-racion/{poultryHouse}/ingredientes`: crea la ficha de una materia prima y su primera carga en una única operación idempotente. Con `proveedor_id` registra una recepción; sin proveedor registra un saldo inicial.
- `GET /api/v1/plantas-racion/{poultryHouse}/stock`: consulta el stock por planta, incluidos los saldos negativos y las plantas inactivas.
- `GET /api/v1/unidades-productivas/{productionUnit}/stock-alimentacion`: suma por ingrediente las plantas activas de una unidad productiva.
- `GET /api/v1/stock-alimentacion`: suma por ingrediente las plantas activas de todas las unidades productivas.

Los movimientos posteriores usan los endpoints de Inventario existentes. Las líneas dirigidas a una ubicación de planta sólo aceptan materias primas en gramos como unidad base; las ubicaciones generales no aceptan materias primas.

El alta recibe `sku`, `nombre`, `cantidad` y, opcionalmente, `unidad` (`g` o `kg`, con `g` por defecto) y `proveedor_id`. La cantidad debe ser positiva. `Idempotency-Key` es obligatorio y se utiliza como identificador estable del movimiento: repetir la misma solicitud devuelve el resultado existente, mientras que reutilizarla con otros datos devuelve un conflicto. Con proveedor se registra una recepción; sin proveedor se registra un saldo inicial.

Las consultas responden con `scope`, `scope_id` e `items`. Cada ítem contiene el producto, `total_g`, `is_negative` y el detalle por planta (`plant_id`, `plant_name`, `poultry_house_id`, `production_unit_id`, `stock_location_id`, `stock_g`, `is_negative` e `included_in_totals`). En la consulta por planta se incluye el saldo aunque la planta esté inactiva; en los agregados, ese detalle queda marcado como excluido.

## Datos demo locales

`DatabaseSeeder` registra [FeedStockDemoSeeder](database/seeders/Inventory/FeedStockDemoSeeder.php) a través de `LocalDemoDataSeeder`. Sólo se ejecuta con `APP_ENV=local` y usa claves fijas para que las recargas sean idempotentes.

La demo conserva los tres galpones avícolas existentes y crea una planta feed para cada depósito de alimentos demo, con una ubicación técnica 1:1. Maíz y soja conservan las cantidades anteriores expresadas en gramos: 1.240.000 g y 680.000 g en El Ombú; 760.000 g y 420.000 g en Santa Clara. Las líneas históricas de apertura, recepción y consumo también están expresadas en gramos.

Si la base local conserva el demo anterior, ejecuta `docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force` con `APP_ENV=local` para reemplazar los valores históricos en kg por su representación en gramos. No se presume una migración de inventario productivo.

## Límites actuales

Esta entrega no implementa recetas, elaboración de alimento ni notificaciones visibles. No se define capacidad física máxima para las plantas. La aceptación manual de los flujos queda pendiente y debe ejecutarse en un ambiente local o QA.
