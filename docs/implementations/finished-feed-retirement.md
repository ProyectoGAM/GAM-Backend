# Retirada operativa de productos finished_feed

## Alcance de esta primera etapa

La ración actual se administra con `FeedRecipe`, sus ingredientes y `FeedBalance` por receta y UP. Se impide crear productos genéricos `finished_feed` y convertir otros productos a esa categoría. Se retiran las nuevas cargas antiguas de `feed_supplies` para evitar registrar alimentación por dos vías.

Se conserva `ProductKind::FinishedFeed`, el cast de `Product.kind` y el CHECK existente de `products.kind` para poder leer filas antiguas de cualquier entorno. No se elimina ninguna tabla o campo ni se modifica la migración original. Esta entrega no requiere una migración de base de datos y no reclasifica productos, mueve existencias ni transforma suministros en consumos.

Permanecen intactos recetas, composición, cantidades de ingredientes, saldos y mínimos por UP, elaboraciones, cancelaciones, ajustes, alimentación diaria y rectificaciones. Se conserva `RationChange`, incluidos `feed_recipe_id` y sus campos históricos `finished_feed_product_id` y `finished_feed_product_snapshot`.

## Contrato público

Los contratos están en [productos](../../contracts/openapi/products.yaml), [referencias](../../contracts/openapi/reference-data.yaml), [operaciones de reportes](../../contracts/openapi/reporting-operations.yaml) y [Manejo](../../contracts/openapi/management-plans.yaml).

| Operación | Comportamiento |
| --- | --- |
| `POST /api/v1/products` | `kind: finished_feed` responde 422 con error asociado a `kind`. |
| `PATCH /api/v1/products/{product}` | Responde 422 si intenta convertir otro tipo a `finished_feed`. Un producto que ya tenía ese tipo puede conservarlo al editar metadata. |
| `GET /api/v1/products` y detalle | Siguen serializando productos históricos como `finished_feed`; se conserva el filtro de lectura por ese tipo. |
| `GET /api/v1/reference/options` | `types.products` ofrece sólo `raw_material`, `supply`, `egg`, `medicine`, `vaccine` y `other`; las referencias de productos históricos siguen legibles. |
| `GET /api/v1/alimentacion/productos` | Selector deprecado: responde 200 con `data: []` y conserva la metadata de página. |
| `POST /api/v1/flocks/{flock}/alimentacion` | Una operación nueva válida responde 410 con explicación en español y sin efectos. Una operación histórica ya guardada con el mismo actor, clave y contenido reproduce su resultado 201. |
| `GET /api/v1/flocks/{flock}/alimentacion` | Conserva la consulta paginada de suministros registrados y anulados, con nombres guardados. |
| `POST /api/v1/flocks/{flock}/alimentacion/{feedSupply}/anulacion` | Conserva la anulación auditada e idempotente del suministro histórico. No genera consumos ni modifica `feed_balances`. |

Las validaciones de formato y autenticación siguen ejecutándose: un intento inválido puede responder 422, sin sesión responde 401 y sin permiso 403 antes de alcanzar la retirada. El mensaje 410 se devuelve como `application/problem+json`. No se ofrecen productos alternativos ni se deduce una receta a partir de un nombre o SKU.

Las Actions `CreateProductAction` y `UpdateProductAction` también bloquean nuevas altas y conversiones mediante llamadas internas, incluyendo el valor textual y la instancia del enum. Mantienen el conflicto de dominio usado por las acciones del catálogo; el contrato HTTP valida primero y devuelve el 422 de `kind`. Se revisaron las escrituras internas: no hay un flujo de importación de productos implementado y se retiró la creación directa del tipo antiguo en `ReportingDemoSeeder`.

## Permisos, historia y auditoría

Se mantienen las políticas existentes de productos y los permisos de Manejo. El alta y edición de productos requieren `products.manage` o rol admin; las lecturas respetan `products.view`, `management-plans.manage` o admin. Las operaciones de suministro y anulación conservan `management-plans.execute`; la lectura conserva sus permisos actuales. No hay asignaciones por UP ni permisos por registro.

Los intentos rechazados no guardan producto, movimiento, suministro, operación ni auditoría de éxito. Las ediciones válidas del catálogo mantienen su auditoría transaccional. Las anulaciones históricas conservan `feed_supply_cancelled` con actor, operación, motivo y cambio de estado en la misma transacción. No se modifican ni eliminan entradas anteriores de auditoría.

La búsqueda de una operación histórica se realiza antes de la guarda de retirada en `RunLotsCommand`, después de comprobar autorización. El reintento reproduce el resultado persistido sin crear otro suministro ni otra auditoría. Reutilizar una clave con otro contenido sigue siendo un conflicto. Una clave nueva no registra efectos parciales. Las cancelaciones mantienen su protocolo original.

## Inventario de datos antes de retirar el enum

La revisión de la base **local** del 26/10/2026, mediante `database-query` de Boost, encontró **cero productos `finished_feed`**. Esto no acredita el estado de otras bases ni autoriza a eliminar el enum.

Se identificaron referencias con FK en `stock_balances`, `inventory_movement_lines`, `feed_supplies`, `ration_changes`, `feed_recipe_ingredients`, `feed_stock_operations`, `egg_stock_accounts`, `vaccination_applications` y `vaccines`. Los historiales de Manejo y reportes también conservan instantáneas y nombres que deben sobrevivir a futuras reclasificaciones.

La siguiente consulta de sólo lectura permite inventariar los casos principales por producto en cada entorno, sin alterar cantidades:

```sql
SELECT p.id, p.sku, p.name, p.status, p.base_unit, p.stock_tracked,
       (SELECT count(*) FROM stock_balances b WHERE b.product_id = p.id) AS balances,
       (SELECT coalesce(sum(b.on_hand_quantity), 0)::text
          FROM stock_balances b WHERE b.product_id = p.id) AS quantity,
       (SELECT count(*) FROM inventory_movement_lines l WHERE l.product_id = p.id) AS movement_lines,
       (SELECT count(*) FROM feed_supplies s WHERE s.product_id = p.id) AS feed_supplies,
       (SELECT count(*) FROM ration_changes r WHERE r.finished_feed_product_id = p.id) AS ration_changes,
       (SELECT count(*) FROM feed_recipe_ingredients i WHERE i.product_id = p.id) AS recipe_ingredients
FROM products p
WHERE p.kind = 'finished_feed'
ORDER BY p.id;
```

Antes de la segunda etapa debe revisarse el detalle por ubicación y cada referencia, no sólo la suma de cantidades, que puede ocultar saldos opuestos. Las referencias adicionales se pueden enumerar desde PostgreSQL con:

```sql
SELECT conrelid::regclass::text AS table_name, pg_get_constraintdef(oid) AS reference
FROM pg_constraint
WHERE confrelid = 'products'::regclass AND contype = 'f'
ORDER BY 1;
```

La clasificación de cada producto debe decidirse explícitamente. No existe una equivalencia demostrada con `FeedRecipe`; no se trasladan cantidades a `feed_balances` ni se sustituyen por materias primas automáticamente. Cuando todas las bases estén resueltas y no queden filas que requieran el valor, la retirada definitiva del caso del enum debe acompañarse de una **migración nueva** para actualizar `products_kind_check`, conservando la lectura de instantáneas históricas. No basta con editar la migración de creación.

## Demo y reportes

`ReportingDemoSeeder` conserva las donaciones y configuraciones de reportes. Ya no crea `RACION-REPORTES`, otro producto `finished_feed` ni nuevos `feed_supplies`. Si esos registros ya existen, repetir el seeder los deja intactos. Los demos del sistema de recetas siguen siendo los propietarios de sus elaboraciones y consumos.

Las consultas de `FeedSupplyReportSource` no cambian: siguen usando `feed_supplies`, unidades y nombres guardados, y excluyendo anulaciones de los totales efectivos. Los fixtures `finished_feed` de las pruebas representan filas anteriores a la transición; no constituyen un seeder ni una nueva vía operativa.

## Verificación

`FinishedFeedRetirementTest` cubre 422 de alta/conversión, guardas internas, lectura histórica de productos, stock y Manejo, selectores, conservación exacta de recetas/ingredientes/saldos/mínimos, permisos y seeder idempotente. `BusinessReportSourcesTest` comprueba retiro 410, reproducción histórica, auditorías conservadas y anulación sin descuentos sobre una cuenta actual de recetas.

Se ejecutan además las regresiones de catálogo, referencias, reportes, Manejo e Inventario para proteger creación y edición de recetas, cálculo, elaboraciones, compensaciones, ajustes, mínimos, consumos diarios y rectificaciones. Las pruebas usan la base PostgreSQL de testing; no se ejecuta un saneamiento de datos ni una migración destructiva en local o producción. No se verifica la UI del frontend.

Resultado integrado: **322 pruebas aprobadas y 3283 aserciones**. Comando ejecutado:

```powershell
docker compose -f compose.dev.yaml exec -T -e LOG_CHANNEL=null api php artisan test --compact tests/Feature/SuppliersAndCatalogs tests/Feature/ReferenceData tests/Feature/ReportingAndAnalytics tests/Feature/ManagementPlans tests/Feature/Inventory tests/Unit/Inventory
```

Pint pasó con rutas explícitas de los PHP modificados y nuevos, tras intentar `--dirty`, que no dispone de Git dentro del contenedor. PHPStan/Larastan nivel 5 pasó sobre las fuentes y el seeder modificados con la configuración existente `.phpstan-vaccines.neon`, sin baselines nuevos. Los contratos se verificaron contra las rutas y referencias locales en las pruebas. `git diff --check` pasó y las decisiones de compatibilidad se registraron con `record-rule` de Boost.

Volver al [índice de implementaciones](README.md).
