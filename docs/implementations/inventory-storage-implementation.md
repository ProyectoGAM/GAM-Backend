# Almacenamiento de inventario y sanidad

Entrega del 28/10/2026. Completa las restricciones que el formulario de ajustes y pérdidas sólo podía aplicar en el cliente. El servidor comparte `StockProductCompatibility` entre productos permitidos y los comandos de stock, dentro de la transacción de cada movimiento.

## Tipos y compatibilidad

| `StockLocation.type` | Categorías admitidas |
| --- | --- |
| `warehouse` | `supply`, `other` |
| `feed_plant` | `raw_material`, con control de stock en gramos |
| `egg_account` | `egg`, conservando los comandos exclusivos de huevos técnicos |
| `health` | `medicine`, `vaccine` |
| `unknown` | Ninguna |

El tipo no depende del nombre ni de `system_managed`. Toda ubicación expone `type` y `allowed_product_kinds`. Las relaciones se comprueban además del tipo: una planta debe ser realmente un galpón `feed` de la UP indicada y una cuenta de huevos debe tener una cuenta real de ese dominio. Las cámaras pueden tener UP o ser generales. `finished_feed` sigue disponible para leer historia, pero no tiene destino admitido para movimientos nuevos; sus cantidades antiguas no se convierten automáticamente en alimento por receta.

Ingresos, salidas, pérdidas, recuentos y transferencias exigen productos activos con control de stock, ubicaciones activas y UP activas cuando corresponda. Las plantas deben estar operativas. Esto sustituye la regla anterior que permitía movimientos genéricos con plantas en mantenimiento, fuera de servicio o inactivas. Los totales y detalles históricos de alimentación mantienen su criterio anterior de consulta. Las materias primas conservan los negativos permitidos y la conversión exacta kg/g; las pérdidas filtran saldo local positivo en el selector sin reservarlo.

Las transferencias validan cada origen y destino antes de consolidar líneas; una ubicación incompatible intermedia no se puede esconder mediante deltas que se cancelen. Un recuento valida también las líneas cuyo saldo ya coincide. Se bloquean actor, productos, UP/galpones, ubicaciones y saldos en orden estable. La escritura revalida estado, categoría, unidad y saldo. El movimiento y su auditoría síncrona incluyen cantidades antes/después e instantáneas permitidas. Un rechazo revierte todos los efectos.

## Sanidad y medicamentos

Existe una única ubicación de sanidad por UP, protegida por índice único parcial y creada por `EnsureHealthStockLocationAction`. El alta de una UP la crea en la misma transacción. La preparación explícita crea las de las UP existentes, incluso si actualmente están inactivas; no habilita movimientos en una UP inactiva. La ubicación técnica no se renombra, reasigna ni desactiva por los endpoints genéricos. No se agregan relaciones usuario-UP: los permisos siguen siendo funcionales y de alcance empresarial.

El alta de medicamento crea un producto `medicine`, activo, con stock y unidad `unit`, y devuelve `product_id`. Su SKU se genera como `MED-<ULID>`; el nombre del producto añade el ULID a un prefijo acotado para conservar los nombres repetidos permitidos en las fichas. El vínculo es único y no crea saldos ni movimientos. Las fichas antiguas se vinculan durante la preparación; hasta entonces `product_id` puede ser null. Tipo, unidad y control de stock de un medicamento vinculado no pueden alterarse por edición genérica del producto. Vacunas conservan su producto asociado y su unidad base.

La existencia física se ingresa mediante los comandos de Inventario usando `product_id` y el ID de sanidad. Las operaciones requieren los permisos existentes `inventory.move` o `inventory.adjust`. La consulta exige `inventory.view`.

El contador global de medicamentos de Planes de manejo se conserva como registro clínico independiente y se identifica con `balance_scope: clinical_global` en su consulta. No equivale a existencia física por UP y sus ajustes no ingresan stock físico. No se trasladan sus cantidades antiguas a una UP: carecen de procedencia suficiente para decidir esa asignación. El inventario físico se consulta por `product_id` y ubicación en Inventario.

Aplicaciones de medicamentos y vacunas admiten `stock_consumption` opcional, con `quantity` en unidad base y `stock_location_id`. Exigen sanidad de la misma UP del lote y stock físico suficiente, sin negativos. El permiso es `management-plans.execute`; no se exige adicionalmente `inventory.move` para ejecutar el consumo propio de la aplicación. Omitir `stock_consumption` conserva el registro clínico existente y no afirma un consumo físico. La corrección del manejo compensa el movimiento físico y, para medicamentos, también el contador clínico en una única transacción. El historial conserva el movimiento original y su vínculo. La reversión genérica de consumos sanitarios se rechaza: deben corregirse desde el manejo.

## Proveedor interno

`suppliers.system_key = internal_supplier` identifica al proveedor interno con una restricción única. `SupplierResource.is_internal` permite reconocerlo sin comparar nombres. El nombre es editable; la identidad permanece estable.

La preparación explícita adopta un único proveedor cuyo nombre, sin espacios exteriores ni diferencias de mayúsculas, sea Propio; si no existe crea uno activo, sin localidad, con dirección Producción propia. No reactiva uno inactivo ni elige entre candidatos ambiguos. Repetir la preparación no duplica proveedores ni auditorías. Si el proveedor ya está configurado, conserva su nombre y estado actuales.

En `POST /api/v1/inventory/receipts`, `supplier_id` es opcional y admite null. Omitirlo o enviar null resuelve el proveedor configurado dentro de la transacción, bajo bloqueo compartido. Si falta o está inactivo devuelve 409. Un ID explícito válido tiene prioridad. No se crea, reactiva ni configura un proveedor como efecto del ingreso. El hash conserva la intención original de omitir proveedor y el movimiento guarda el ID real resuelto; los reintentos confirmados no duplican stock aunque el proveedor se haya desactivado después.

## Migración, revisión y regularización

La migración agrega tipo de ubicación inicialmente `unknown`, identidad de proveedor, vínculo de medicamento a producto y vínculo de aplicación a movimiento. No toca cantidades, ledger ni auditorías existentes. La clasificación de ubicaciones se realiza desde una Action con auditoría, no mediante actualizaciones ocultas de saldos en la migración.

Con las escrituras suspendidas en el despliegue, ejecutar:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan migrate --force --no-interaction
docker compose -f compose.dev.yaml exec -T api php artisan inventario:preparar-almacenamiento --actor=<ID_RESPONSABLE> --no-interaction
docker compose -f compose.dev.yaml exec -T api php artisan inventario:preparar-almacenamiento --revisar --no-interaction
```

Sustituir Compose por el del ambiente correspondiente. El actor debe tener `inventory.manage` o ser admin. El modo `--revisar` es de sólo lectura. La preparación es reiniciable, con transacciones por registro; un error posterior no deshace registros preparados previamente y un reintento no los duplica. Clasifica plantas por relación real, huevos por `egg_stock_accounts` y cámaras manuales sin galpón; una ubicación gestionada huérfana permanece `unknown` y bloqueada. Revisar el informe de incompatibilidades antes de reabrir las escrituras.

`GET /api/v1/inventory/balances?incompatible_only=1` ofrece esa revisión paginada y combinable con filtros por producto y ubicación. No confunde inactividad con incompatibilidad estructural. No borra ni oculta saldos históricos de las consultas generales.

`POST /api/v1/inventario/saldos/{stockBalance}/regularizaciones`, con `Idempotency-Key` UUID y permiso `inventory.adjust`, tiene dos modos:

```json
{"mode":"trasladar","to_stock_location_id":23,"quantity":"8","reason":"Traslado revisado desde el depósito histórico a sanidad"}
```

```json
{"mode":"recontar","reason":"Recuento revisado: el saldo histórico incompatible debe cerrarse a cero"}
```

Trasladar conserva la cantidad total, reduce un saldo positivo incompatible sin cruzar cero y exige destino compatible activo. Recontar cierra exactamente a cero un saldo incompatible positivo o negativo, registrando la diferencia como ajuste; requiere revisión humana y no debe usarse para simular un traslado. Puede regularizarse una ubicación de origen inactiva. El cierre a cero también permite productos históricos inactivos o sin control de stock, sin reactivarlos; el traslado sigue exigiendo un producto activo con control de stock. No se admiten saldos compatibles, productos técnicos de huevos, nuevos negativos ni aumentos de stock en el origen incompatible. El motivo, origen, destino y cantidades quedan en el ledger y la auditoría. La clave evita duplicaciones y el movimiento no puede revertirse genéricamente porque reintroduciría una incompatibilidad.

La preparación no regulariza cantidades automáticamente. Un operador debe decidir los destinos o validar el recuento. Para materias primas antiguas con unidad distinta de g tampoco se cambia el catálogo ni se convierten cantidades implícitamente. El rollback elimina los metadatos nuevos y no deshace auditorías ni compensaciones; en ambientes compartidos se recomienda una corrección hacia adelante.

## Contratos e integración

- [Almacenamiento](../../contracts/openapi/inventory-storage.yaml): tipos, ingresos, revisión y regularización.
- [Stock y productos permitidos](../../contracts/openapi/feed-stock.yaml): selección y representación de ubicaciones.
- [Medicamentos](../../contracts/openapi/medication.yaml): vínculo público a producto.
- [Planes de manejo](../../contracts/openapi/management-plans.yaml): consumo sanitario opcional.

El frontend puede consultar `/stock-locations?type=health&production_unit_id=<ID>` y usar `type` en lugar de inferencias por nombre o `system_managed`. Para ingresos puede omitir proveedor, sin buscar o inventar un ID Propio. El selector sigue paginado por ubicación y operación, conserva la intersección de transferencias y debe invalidar selecciones mientras carga o cuando cambian los filtros.

## Demo y verificación

`HealthStockDemoSeeder` queda incluido en la carga demo oficial local y en el entorno de demo autorizado. Prepara metadatos y registra ingresos idempotentes ficticios de 100 unidades de un medicamento y una vacuna en sanidad de la primera UP. No se ejecuta en testing, staging ni producción sin la habilitación de demo. Los tests utilizan PostgreSQL aislado mediante el bootstrap existente; no reconstruyen la base de desarrollo.

Verificación completada en Docker con PHP 8.5.10, Laravel 13.29.0 y PHPUnit 12.5.34:

- 308 pruebas y 2.887 aserciones aprobadas: Inventario completo, ejecución y consumo sanitario, capacidades de Productos, Proveedores/Catálogos y Medicamentos.
- Comprobación final de almacenamiento y stock de plantas: 79 pruebas y 523 aserciones aprobadas, incluidas matriz, permisos, reintentos, regularización, demo, protección por tipo, invariantes de producto vinculado y referencias/rutas del contrato OpenAPI. Esta selección se solapa con la ejecución anterior.
- Concurrencia real de medicamentos: 1 prueba y 5 aserciones aprobadas usando su configuración aislada. Concurrencia real de vacunas: 4 pruebas y 18 aserciones aprobadas, ejecutadas por separado para no mezclar recreación de esquema con pruebas transaccionales.
- PHPStan nivel 5 sin errores sobre todos los archivos PHP cambiados de aplicación, rutas, factories y seeders. Pint aprobado sobre todos los PHP cambiados, incluidos los nuevos. Se intentó `--dirty`, pero el contenedor no dispone del contexto Git que Pint exige; se pasó explícitamente la lista de archivos cambiados. `git diff --check` sin errores.

La preparación se aplicó a la base local y se comparó una huella de todas las cantidades, mínimos y permisos de negativos antes/después: los 15 saldos permanecieron idénticos, junto con los mismos 193 movimientos y 209 líneas. Se crearon las ubicaciones de sanidad de las dos UP y se prepararon los vínculos y el proveedor interno. La revisión detectó dos saldos incompatibles de Vacuna aviar Newcastle: 120 dosis en la cámara y 2 dosis en una cuenta de huevos. Se conservaron íntegros para que un operador decida su regularización auditada. El seeder de ingresos ficticios no se ejecutó sobre esa base durante esta comprobación.

No se probó la UI del frontend ni un despliegue productivo. La integración del cliente debe adoptar el tipo explícito y el nuevo vínculo de medicamentos; la guía describe los campos y rutas necesarios.
