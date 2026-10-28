# Almacenamiento de inventario y sanidad

Entrega del 28/10/2026. Completa las restricciones que el formulario de ajustes y pérdidas sólo podía aplicar en el cliente. El servidor comparte `StockProductCompatibility` entre productos permitidos y los comandos de stock, dentro de la transacción de cada movimiento.

## Alcance vigente del catálogo

El alta genérica (`POST /api/v1/products`) y `GET /api/v1/reference/options` ofrecen únicamente **Insumo** (`supply`), **Materia prima** (`raw_material`) y **Otros** (`other`). Insumo y Otros pertenecen al depósito de insumos; las materias primas conservan las plantas de ración y gramos. Crear o convertir un producto a `egg`, `medicine`, `vaccine` o `finished_feed` devuelve 422 en `kind`; las Actions también impiden el bypass interno. La edición de metadata histórica puede conservar su tipo actual, respetando las protecciones de cada dominio.

`egg` y el producto técnico `generic_egg` permanecen: el stock de cada UP usa huevos enteros como unidad atómica. Las presentaciones se administran por su catálogo especializado y no son productos genéricos nuevos. No se cambian cantidades ni equivalencias.

## Tipos y compatibilidad

| `StockLocation.type` | Categorías admitidas |
| --- | --- |
| `warehouse` | `supply`, `other` |
| `feed_plant` | `raw_material`, con control de stock en gramos |
| `egg_account` | `egg`, conservando los comandos exclusivos de huevos técnicos |
| `health` | Ninguna; reservado para una entrega futura |
| `unknown` | Ninguna |

El tipo no depende del nombre ni de `system_managed`. Toda ubicación expone `type` y `allowed_product_kinds`. Las relaciones se comprueban además del tipo: una planta debe ser realmente un galpón `feed` de la UP indicada y una cuenta de huevos debe tener una cuenta real de ese dominio. Las cámaras pueden tener UP o ser generales. `finished_feed` sigue disponible para leer historia, pero no tiene destino admitido para movimientos nuevos; sus cantidades antiguas no se convierten automáticamente en alimento por receta.

Ingresos, salidas, pérdidas, recuentos y transferencias exigen productos activos con control de stock, ubicaciones activas y UP activas cuando corresponda. Las plantas deben estar operativas. Esto sustituye la regla anterior que permitía movimientos genéricos con plantas en mantenimiento, fuera de servicio o inactivas. Los totales y detalles históricos de alimentación mantienen su criterio anterior de consulta. Las materias primas conservan los negativos permitidos y la conversión exacta kg/g; las pérdidas filtran saldo local positivo en el selector sin reservarlo.

Las transferencias validan cada origen y destino antes de consolidar líneas; una ubicación incompatible intermedia no se puede esconder mediante deltas que se cancelen. Un recuento valida también las líneas cuyo saldo ya coincide. Se bloquean actor, productos, UP/galpones, ubicaciones y saldos en orden estable. La escritura revalida estado, categoría, unidad y saldo. El movimiento y su auditoría síncrona incluyen cantidades antes/después e instantáneas permitidas. Un rechazo revierte todos los efectos.

## Sanidad y medicamentos

**Sanidad física queda deshabilitada por decisión de alcance.** `config/inventory.php` fija `health_stock_enabled` en `false`, sin variable de entorno que lo active accidentalmente. Se conserva la implementación comentada para el futuro, el enum, los vínculos y las restricciones de base de datos. Crear una UP o ejecutar la preparación ya no crea sanidad ni vincula medicamentos a productos. La Action de aprovisionamiento directo también rechaza su uso. Las ubicaciones anteriores siguen consultables y protegidas, pero no admiten categorías ni movimientos nuevos. No se agregan relaciones usuario-UP.

Los medicamentos se registran globalmente en `POST /api/v1/medicines`, sin crear productos de inventario; las altas nuevas devuelven `product_id: null`. Las vacunas se registran en `POST /api/v1/vacunas`; su producto vinculado conserva identidad, nombre, SKU, unidad y estado del catálogo, con `stock_tracked: false` para productos nuevos. La Action de Vacunas crea y audita esa identidad dentro de su propia transacción, sin usar el alta genérica. Adoptar un producto anterior no reescribe su historia ni su control de stock. Las ediciones genéricas no permiten habilitar stock para medicamentos o vacunas.

No se crean saldos físicos ni ubicaciones para estos catálogos. Los comandos genéricos y sus selectores los rechazan incluso si un producto o ubicación históricos conservan sus flags anteriores. Se conservan los permisos, auditoría síncrona e idempotencia de los catálogos globales: las vacunas requieren admin y los medicamentos mantienen sus controles actuales.

El contador global de medicamentos de Planes de manejo se conserva como registro clínico independiente y se identifica con `balance_scope: clinical_global` en su consulta. No equivale a existencia física por UP y sus ajustes no ingresan stock físico. No se trasladan sus cantidades antiguas a una UP: carecen de procedencia suficiente para decidir esa asignación. El inventario físico se consulta por `product_id` y ubicación en Inventario.

Aplicaciones de medicamentos y vacunas conservan el registro clínico sin descuento físico. Enviar `stock_consumption` devuelve 422 con mensaje en español; las Actions bloquean también llamadas internas. Se conserva la implementación futura y su cobertura mediante habilitación explícita sólo dentro de esas pruebas. Las correcciones de aplicaciones históricas conservan sus compensaciones y no borran movimientos ni auditoría.

La compensación histórica exige `management-plans.execute`, una aplicación real vinculada al movimiento y la inversa completa de sus líneas, con idénticos productos y ubicaciones. Rechaza cantidades superiores o parciales y reversiones repetidas. Puede restaurar el consumo original aunque el producto ya no controle stock, sin reactivarlo ni habilitar nuevos movimientos sanitarios; la corrección y su auditoría siguen siendo transaccionales e idempotentes.

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
{"mode":"trasladar","to_stock_location_id":23,"quantity":"8","reason":"Traslado revisado de materia prima desde el depósito histórico a una planta"}
```

```json
{"mode":"recontar","reason":"Recuento revisado: el saldo histórico incompatible debe cerrarse a cero"}
```

Trasladar conserva la cantidad total, reduce un saldo positivo incompatible sin cruzar cero y exige destino compatible activo. Recontar cierra exactamente a cero un saldo incompatible positivo o negativo, registrando la diferencia como ajuste; requiere revisión humana y no debe usarse para simular un traslado. Puede regularizarse una ubicación de origen inactiva. El cierre a cero también permite productos históricos inactivos o sin control de stock, sin reactivarlos; el traslado sigue exigiendo un producto activo con control de stock. No se admiten saldos compatibles, productos técnicos de huevos, nuevos negativos ni aumentos de stock en el origen incompatible. El motivo, origen, destino y cantidades quedan en el ledger y la auditoría. La clave evita duplicaciones y el movimiento no puede revertirse genéricamente porque reintroduciría una incompatibilidad.

La preparación no regulariza cantidades automáticamente. Un operador debe decidir los destinos o validar el recuento. Para materias primas antiguas con unidad distinta de g tampoco se cambia el catálogo ni se convierten cantidades implícitamente. El rollback elimina los metadatos nuevos y no deshace auditorías ni compensaciones; en ambientes compartidos se recomienda una corrección hacia adelante.

## Contratos e integración

- [Almacenamiento](../../contracts/openapi/inventory-storage.yaml): tipos, ingresos, revisión y regularización.
- [Stock y productos permitidos](../../contracts/openapi/feed-stock.yaml): selección y representación de ubicaciones.
- [Productos](../../contracts/openapi/products.yaml): tres tipos admitidos en el alta genérica y tipos reservados legibles.
- [Medicamentos](../../contracts/openapi/medication.yaml): catálogo global sin vínculo físico nuevo.
- [Vacunas](../../contracts/openapi/vaccination.yaml): identidad de catálogo sin control de stock nuevo.
- [Planes de manejo](../../contracts/openapi/management-plans.yaml): aplicaciones clínicas y rechazo de consumo físico.

El frontend debe usar `types.products` para el alta genérica y los catálogos especializados para medicamentos, vacunas y presentaciones. La lectura de ubicaciones históricas `health` devuelve `allowed_product_kinds: []`; no es un destino operativo de stock. Para ingresos puede omitir proveedor, sin buscar o inventar un ID Propio. El selector sigue paginado por ubicación y operación, conserva la intersección de transferencias y debe invalidar selecciones mientras carga o cuando cambian los filtros.

## Demo y verificación

`HealthStockDemoSeeder` queda conservado para el futuro, sin ejecutar preparación ni ingresos mientras sanidad esté deshabilitada. La carga general tampoco genera saldos o líneas nuevas de medicamentos/vacunas; deja intacta la historia que ya exista. No se ha ejecutado ningún seeder ni migración sobre la base de desarrollo para este cambio. Los tests utilizan PostgreSQL aislado mediante el bootstrap existente.

Verificación de la entrega inicial, anterior a la decisión de deshabilitar sanidad, en Docker con PHP 8.5.10, Laravel 13.29.0 y PHPUnit 12.5.34:

- 308 pruebas y 2.887 aserciones aprobadas: Inventario completo, ejecución y consumo sanitario, capacidades de Productos, Proveedores/Catálogos y Medicamentos.
- Comprobación final de almacenamiento y stock de plantas: 79 pruebas y 523 aserciones aprobadas, incluidas matriz, permisos, reintentos, regularización, demo, protección por tipo, invariantes de producto vinculado y referencias/rutas del contrato OpenAPI. Esta selección se solapa con la ejecución anterior.
- Concurrencia real de medicamentos: 1 prueba y 5 aserciones aprobadas usando su configuración aislada. Concurrencia real de vacunas: 4 pruebas y 18 aserciones aprobadas, ejecutadas por separado para no mezclar recreación de esquema con pruebas transaccionales.
- PHPStan nivel 5 sin errores sobre todos los archivos PHP cambiados de aplicación, rutas, factories y seeders. Pint aprobado sobre todos los PHP cambiados, incluidos los nuevos. Se intentó `--dirty`, pero el contenedor no dispone del contexto Git que Pint exige; se pasó explícitamente la lista de archivos cambiados. `git diff --check` sin errores.

En aquella entrega inicial se aplicó la preparación a la base local y se comparó una huella de todas las cantidades, mínimos y permisos de negativos antes/después: los 15 saldos permanecieron idénticos, junto con los mismos 193 movimientos y 209 líneas. Se crearon las ubicaciones de sanidad de las dos UP y se prepararon los vínculos y el proveedor interno. La revisión detectó dos saldos incompatibles de Vacuna aviar Newcastle: 120 dosis en la cámara y 2 dosis en una cuenta de huevos. Se conservaron íntegros para que un operador decida su regularización auditada. El seeder de ingresos ficticios no se ejecutó sobre esa base durante esta comprobación. La corrección actual conserva esas ubicaciones, vínculos y cantidades históricas, sin utilizarlos para nuevos movimientos sanitarios.

No se probó la UI del frontend ni un despliegue productivo. No se agrega migración ni se ejecuta una reclasificación o limpieza de datos para deshabilitar sanidad.

### Verificación del alcance actual

- Regresión general: **449 pruebas y 3.899 aserciones aprobadas**, sobre Inventario, Proveedores/Catálogos, Estructura de granja, opciones de referencia, ejecución clínica, recolección de huevos y cargas demo completas. Incluye las tres categorías de alta genérica, rechazo de tipos reservados, catálogos sanitarios sin existencia física y preservación del stock atómico y presentaciones.
- Comprobación final tras preservar las compensaciones históricas: **125 pruebas y 986 aserciones aprobadas**. Incluye inventario genérico, almacenamiento, elaboraciones, stock de huevos, ejecución y contratos de manejo. Comprueba que una compensación conserva cantidad/ubicación y es idempotente con sanidad deshabilitada y producto sin seguimiento; un exceso de devolución se rechaza sin cambios. Esta selección se solapa con la regresión general.
- Concurrencia real ejecutada por separado: **1 prueba y 7 aserciones** de medicamentos y **4 pruebas y 18 aserciones** de vacunas aprobadas. Los reintentos de medicamentos crean una única ficha global sin producto físico; las pruebas de movimientos de vacunas habilitan explícitamente sólo en su proceso de testing la integración futura conservada.
- PHPStan nivel 5 sin errores sobre los PHP modificados de aplicación, configuración y seeders; Pint aprobado sobre todos los PHP cambiados. `--dirty` no está disponible dentro del contenedor sin contexto Git; se pasó la lista explícita de archivos modificados y nuevos. `git diff --check` sin errores.
- Las pruebas usan bases PostgreSQL de testing; no se ejecutaron migraciones, preparación, seeders ni regularizaciones sobre la base de desarrollo en esta corrección. La UI y el despliegue productivo quedan fuera de esta verificación.
