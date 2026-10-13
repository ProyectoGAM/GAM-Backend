# Recetas, elaboración inmediata y stock de alimento

## Alcance

El catálogo global conserva su contrato: ID estable, nombres repetidos, ingredientes existentes de Inventario, edición exclusiva de sus cantidades y estados activo/inactivo. Rendimiento e ingredientes usan gramos enteros positivos; el rendimiento es independiente de su suma. Sólo se elaboran múltiplos enteros positivos de la combinación base.

El segundo bloque incorpora elaboración inmediata, distribución de ingredientes entre plantas de la misma UP, saldo acumulado de alimento por receta y UP, cancelación compensatoria, ajustes independientes y consultas paginadas. No incorpora alimentación heredada del plan, consumo de lotes, transferencias entre UP, compras de alimento, vencimientos, partidas, alertas, notificaciones ni frontend.

## Catálogo y cálculo existentes

Crear receta exige nombre, rendimiento y composición no vacía con cada producto una sola vez. Los ingredientes deben ser materias primas existentes, con unidad base `g` y control de stock; el alta y el cálculo no exigen producto activo ni existencias. Nombre, nota, rendimiento y productos quedan fijados al crear. La edición recibe todos los productos originales y cambia únicamente sus cantidades; agregar, quitar o sustituir devuelve 409. Desactivar conserva receta e historia.

El catálogo mantiene su rango de 1 a 9223372036854775807 gramos y respuestas como cadenas para evitar pérdida de precisión en JavaScript. Acepta enteros JSON y cadenas de dígitos; rechaza floats, decimales y notación científica. El segundo bloque no reduce ese rango del catálogo ni cambia `RecipeGrams` o `FeedRecipeCalculator`.

El cálculo lee la receta activa y su composición bajo lock compartido. Exige `Q % R === 0`, obtiene el factor con `intdiv(Q, R)` y comprueba cada multiplicación antes de ejecutarla. No reserva existencias, no congela la composición, no elabora y no modifica saldos o auditoría. Las ediciones y cambios de estado toman el lock exclusivo de la misma receta.

Los permisos del catálogo siguen siendo `feed-recipes.view` para listar, consultar y calcular, y `feed-recipes.manage` para crear, editar cantidades y cambiar estado. Alta idempotente por actor y encabezado UUID. El reintento conserva el estado actual y no restaura cantidades antiguas. Edición y estado no requieren clave; repetir valores actuales no genera nueva auditoría.

## Elaboración inmediata

La entrada contiene receta activa, planta operativa y cantidad resultante entera positiva. La UP se deriva exclusivamente de la planta. Se usa la fecha y hora del registro; no se admiten fechas aportadas por el cliente ni estados pendientes o en proceso.

Dentro de una única transacción, `ManufactureFeedAction`:

1. Protege el reintento por actor y clave.
2. Lee y bloquea la composición vigente y comprueba receta activa.
3. Reutiliza el cálculo exacto y valida el límite de persistencia de cada cantidad.
4. Bloquea y valida ingredientes activos.
5. Resuelve plantas operativas de la misma UP, ubicaciones técnicas y saldos.
6. Calcula la distribución íntegra antes de descontar.
7. Registra el descuento mediante el motor de movimientos existente.
8. Acredita el saldo de alimento, guarda instantánea y distribución, y audita síncronamente.

Para cada ingrediente se consume primero el saldo positivo de la planta seleccionada. El faltante usa otras plantas operativas de la misma UP, ordenadas por saldo de ese ingrediente de menor a mayor y, en empate, por ID de planta ascendente. La prioridad se resuelve por ingrediente: no hay una lista única de plantas para toda la receta.

Mantenimiento, fuera de servicio e inactivo no son operativos. Saldos cero o negativos no aportan disponibilidad. Si cualquier ingrediente no alcanza, se devuelve 409 sin descuentos, crédito de alimento, operación ni auditoría parcial. Las elaboraciones no generan negativos de ingredientes, aunque el motor genérico conserva sus reglas previas para otros movimientos.

Ejemplo: se requieren 10000 g; A seleccionada tiene 2000, B 3000 y C 8000. Se descuentan 2000 de A, 3000 de B y 5000 de C.

### Exactitud y límites

El inventario existente conserva `decimal(18,6)`. Las entradas del segundo bloque y el saldo entero de alimento tienen máximo absoluto **999999999999 g**. El rendimiento y la composición del catálogo pueden ser mayores, pero su elaboración se rechaza si la cantidad resultante o un ingrediente calculado excede ese límite. También se comprueba el saldo acumulado antes de persistir.

Las necesidades calculadas son enteras. Los saldos históricos de ingredientes pueden contener fracciones: se distribuyen y compensan exactamente con sus seis decimales, sin redondearlos ni descartarlos. Por ejemplo, consumir 2000.123456 g de una planta reduce exactamente el faltante de la siguiente. La distribución y sus movimientos muestran cadenas con seis decimales.

Un ajuste de ingredientes comprueba tanto el saldo como el delta contra el rango real de Inventario. Para alimento, establecer el extremo opuesto puede producir un delta de hasta 1999999999998 g; el historial usa `decimal(19,6)` para conservar esa diferencia. El saldo sigue siendo entero y limitado, sin cambios a la precisión del inventario genérico.

## Cuenta de alimento e historia

`feed_balances` guarda una única cuenta por receta y UP, con restricción única y cantidad entera firmada. Las elaboraciones se acumulan desde cualquier planta de esa UP. No hay cuentas por planta, versión o partida; no hay vencimiento. El saldo puede ser cero o negativo, incluidos los ajustes y el futuro consumo.

Una consulta puntual devuelve cero cuando todavía no existe la cuenta, sin insertar filas. El listado paginado muestra las cuentas materializadas. Recetas inactivas conservan cuentas e historia consultables.

`feed_stock_operations` conserva elaboración, cancelación, ajuste de ingrediente y ajuste de alimento. Incluye actor, instante con zona horaria, UUID de operación, clave/hash de idempotencia, receta/UP/planta/producto cuando corresponden, cantidad, factor, motivo, acción, saldo anterior/resultante, delta y referencia al movimiento genérico.

La elaboración conserva nombre y rendimiento de la receta, cantidades base/requeridas y nombres/SKU de ingredientes, además de cada planta y ubicación originalmente usadas, cantidad descontada, saldos anterior/resultante y línea de movimiento. Editar nombres, cantidades o estados no recalcula esa instantánea.

La cancelación se vincula mediante una referencia única a la operación original. El estado de la elaboración se deriva de esa relación: **no se actualiza ni borra la fila original**. Su detalle incluye la operación compensatoria y sus referencias.

## Cancelación

Cancelar y revertir son la misma operación. No existe edición de elaboración: se cancela y registra otra.

`CancelFeedManufactureAction` bloquea la operación original, conserva receta y composición históricas y devuelve exactamente la inversa de sus líneas. La fórmula actual no interviene. Resta exactamente la cantidad elaborada de la cuenta actual de alimento. Rechaza si ese resultado sería negativo o si cualquier planta original receptora no está operativa. Comprueba también los límites de persistencia de las devoluciones.

Permite receta e ingredientes desactivados, y los mantiene desactivados. La excepción del motor para ingredientes inactivos se limita a una cancelación materializada que referencia la elaboración original y su inversa completa. No hay flag público ni habilitación general de productos inactivos.

La transacción conserva movimientos originales y agrega movimiento compensatorio, operación y auditoría. La reversión genérica rechaza movimientos vinculados a elaboración, cancelación y ajuste de ingredientes del segundo bloque, para impedir correcciones que eludan sus invariantes.

Cada línea de distribución de la cancelación se vincula, por producto y ubicación, con su propia línea del movimiento compensatorio antes de guardar la instantánea y auditar. En API y auditoría, el identificador de línea siempre pertenece al movimiento de esa operación. La elaboración conserva sus referencias al descuento original; la devolución conserva las cantidades y plantas históricas con referencias a las líneas de devolución.

La misma clave y motivo reintentan sin duplicar devoluciones. Una nueva clave para una elaboración ya cancelada devuelve 409. Si cualquier devolución o auditoría falla, no hay efecto parcial.

## Ajustes independientes

Las decisiones confirmadas son:

- **Sumar/restar**: magnitud entera estrictamente positiva.
- **Establecer ingrediente**: cero o entero positivo.
- **Establecer alimento**: cualquier entero dentro del rango, incluidos cero y negativos.
- **Motivo**: obligatorio, texto no vacío de hasta 2000 caracteres.

Ingredientes requieren planta operativa y materia prima activa. Ningún ajuste puede dejar el ingrediente negativo. Se preservan las fracciones existentes al sumar/restar; establecer asigna el entero indicado y registra la diferencia exacta.

Alimento requiere receta activa y se ajusta por UP, sin cuenta por planta. No exige seleccionar una planta. Restar alimento no devuelve ingredientes; sumar alimento no consume ingredientes. Ajustar ingredientes no cambia alimento ni recalcula elaboraciones. El historial previo no se modifica.

Establecer el saldo que ya existe registra recuento y motivo con delta cero. No se crea una línea nula en el motor genérico; la operación y su auditoría sí quedan guardadas. Reintentar con la misma clave no las duplica.

## API pública

Contrato: [OpenAPI de recetas y alimento](../../contracts/openapi/feed-recipes.yaml). Prefijo `/api/v1`.

| Método | Ruta | Acceso y comportamiento |
| --- | --- | --- |
| GET/POST | `/recetas` | Catálogo con sus permisos existentes |
| GET | `/recetas/{recipe}` | Composición actual, incluidas inactivas |
| PATCH | `/recetas/{recipe}/cantidades` | Sólo cantidades originales |
| PATCH | `/recetas/{recipe}/estado` | Activa o desactiva preservando historia |
| POST | `/recetas/{recipe}/calculo` | Cálculo vigente, sin reserva |
| POST | `/elaboraciones` | Elaboración inmediata |
| GET | `/elaboraciones` | Historia paginada por ID descendente |
| GET | `/elaboraciones/{operation}` | Fórmula histórica, distribución y cancelación |
| POST | `/elaboraciones/{operation}/cancelacion` | Devuelve el detalle de la elaboración cancelada |
| GET | `/saldos-alimento` | Cuentas materializadas paginadas |
| GET | `/unidades-productivas/{productionUnit}/recetas/{recipe}/saldo` | Cuenta puntual o cero |
| POST | `/unidades-productivas/{productionUnit}/recetas/{recipe}/ajustes` | Ajuste de alimento por UP |
| POST | `/plantas-racion/{poultryHouse}/ingredientes/{product}/ajustes` | Ajuste de ingrediente |
| GET | `/ajustes-alimentacion` | Historia paginada con motivo y saldos |

Todas las nuevas mutaciones requieren `Idempotency-Key` UUID exclusivamente en el encabezado. Ejemplo de elaboración:

```json
{"receta_id": 1, "planta_id": 2, "cantidad_g": "26000"}
```

Ejemplo de ajuste de alimento:

```json
{"accion": "establecer", "valor_g": "-1000", "motivo": "Recuento físico"}
```

Cancelación: `{"motivo":"Corrección de elaboración"}`.

Filtros de elaboraciones: `receta_id`, `up_id`, `planta_id`, `estado=elaborada|cancelada`, `por_pagina` y `page`. Ajustes reutilizan los identificadores, admiten `producto_id` y `tipo=ingrediente|alimento`. Saldos aceptan receta y UP. Paginación de 1 a 100, predeterminada 25; página entre 1 y 1000000. No se aceptan campos ajenos al contrato.

Las cantidades de alimento se exponen como cadenas enteras; ingredientes y distribución usan cadenas decimales exactas. El detalle incluye `operacion_id`, `actor_id`, `registrado_en`, `ingredientes`, `distribucion`, `movimiento_id`, `estado` y `cancelacion`.

Errores: 401 sin sesión/contexto válido, 404 entidad inexistente o detalle que no es una elaboración, 422 entrada inválida y 409 conflicto de stock, estado, múltiplo calculado, límites calculados o idempotencia. El cálculo previo del catálogo mantiene su 422 para un múltiplo inválido.

## Autorización, idempotencia, auditoría y bloqueos

Por decisión expresa del usuario, **los nuevos endpoints permiten cualquier usuario autenticado con sesión válida** hasta definir el permiso funcional específico. El catálogo conserva sus permisos. No se inventan roles ni asignaciones por UP.

Las rutas nuevas reutilizan `auth:sanctum`, `shared.device:any` y `shared.session.conditional`. El acceso compartido nativo exige Bearer, `X-Shared-Device-Token`, `X-GAM-Session` y generación vigente. Web conserva sesión stateful, CSRF, cookie HttpOnly `gam_shared_device` y contexto de sesión compartida. Los secretos nunca se copian al historial o auditoría.

La fila del actor serializa reintentos antes de buscar la clave. Unicidad por actor/clave abarca las cuatro operaciones del bloque: usar una clave en otro tipo o con contenido distinto devuelve 409. Cantidades y motivo se normalizan antes del hash. Reintentar tras editar o desactivar la receta devuelve la operación original y su cancelación actual.

El orden técnico de adquisición es receta compartida, productos compartidos por ID, plantas compartidas por ID, ubicaciones exclusivas por ID y saldos por ubicación/producto. La prioridad de consumo se calcula después. Las cancelaciones bloquean primero la operación/movimiento original; mantienen el mismo orden técnico para ingredientes. La cuenta receta/UP se materializa con unicidad y se bloquea antes de leer y actualizar. Las transacciones pueden reintentarse ante deadlock hasta tres veces sin conservar efectos de intentos fallidos.

Los Actions propietarios escriben auditoría con `AuditRecorder` y `AuditEntryData` dentro de la misma transacción. Eventos: `feed_manufacture_recorded`, `feed_cancellation_recorded`, `feed_ingredient_adjustment_recorded` y `feed_feed_adjustment_recorded`, además del movimiento de Inventario. Conservan operación, traza, actor, sujeto, UP, origen, resultado, instantánea permitida y cambios explícitos de saldo y distribución. La auditoría es append-only. Consultarla desde la API transversal sigue requiriendo `audit.view`; las consultas de operaciones de alimento no exponen entradas crudas de auditoría.

## Migración y demo

La migración `2026_10_13_075223_create_feed_stock_operations_tables.php` crea `feed_balances` y `feed_stock_operations`, con restricciones únicas de cuenta, actor/clave y cancelación, referencias restrictivas y CHECK de cantidades/operaciones. No modifica la precisión de Inventario ni las tablas del catálogo. Aplicar con migraciones normales; no requiere borrar datos. El rollback elimina las tablas nuevas y su contenido: no usarlo para corregir operaciones.

`FeedRecipeDemoSeeder` conserva la receta global “Ración demo inicial”: 5000 g de maíz y 7000 g de soja, rendimiento 13000 g. `FeedManufactureDemoSeeder` se registra inmediatamente después en `LocalDemoDataSeeder`: elabora una combinación, la cancela para conservar los saldos demo de ingredientes y registra un ajuste independiente de 26000 g de alimento. Cada paso usa las Actions reales y claves estables. Repetirlo no sobrescribe saldos posteriores ni duplica operaciones.

Sólo se ejecuta en local o en la demo de producción explícitamente habilitada por la configuración existente. La implementación se verificó con PostgreSQL aislado de testing; no se aplicó esta segunda migración a la base local ni a producción.

## Verificación

Cobertura nueva:

- `FeedManufactureEndpointTest`: distribución por ingrediente, prioridad y empate, misma UP, estados, insuficiencia sin efectos parciales, acumulación, historia, devoluciones exactas de inactivos, cancelación inválida, reintentos, límites, precisión, autenticación y sesión compartida, rollback de auditoría.
- `FeedAdjustmentEndpointTest`: independencia, motivos, acciones y valores válidos/ inválidos, saldo cero/negativo, rango, fracciones, idempotencia, filtros y auditoría transaccional.
- `FeedManufactureConcurrencyTest`: procesos reales contra PostgreSQL, reintentos, doble consumo entre recetas/actores con plantas iniciales opuestas, cancelación única y acumulación concurrente.
- `FeedManufactureDemoSeederTest`: registro en la entrada oficial, instantáneas, auditoría, reintentos y protección por ambiente.
- `FeedRecipeContractTest`: rutas reales y referencias del contrato ampliado.

Se ejecutaron además las regresiones del catálogo, cálculo, stock de ingredientes, motor de inventario, huevos, reglas de productos y reporte de saldos. La ejecución integrada del 13/10/2026 pasó con **126 pruebas y 1549 aserciones**, incluidos los cuatro casos de concurrencia real:

```powershell
docker compose -f compose.dev.yaml exec -T -e LOG_CHANNEL=null api php artisan test --compact tests/Feature/Inventory tests/Unit/Inventory tests/Feature/SuppliersAndCatalogs/ProductIngredientRulesTest.php tests/Feature/SuppliersAndCatalogs/ProductResourceCapabilitiesTest.php tests/Feature/ReportingAndAnalytics/InventoryStockBalancesReportTest.php
```

Se confirmó PHP 8.5.10, Laravel 13.29.0, PHPUnit 12.5.34 y Brick Math 0.18.0. Pint pasó sobre todos los PHP del cambio. Se intentó el comando obligatorio `vendor/bin/pint --dirty --format agent`; el contenedor no expone Git, por lo que se usó la lista explícita del host con `--format agent`. PHPStan/Larastan nivel 5 pasó sin errores con la configuración existente `.phpstan-vaccines.neon` y los paths fuente del cambio, sin baselines nuevos ni exclusiones de errores. `git diff --check` y la comprobación de espacios finales de archivos nuevos pasaron.

La inyección de fallas de auditoría verifica rollback de fabricación, compensación y ajuste de ingredientes. Las pruebas de acceso usan tanto Sanctum simulado para las reglas de negocio como credenciales Bearer y sesión compartida reales para exigir dispositivo y generación vigentes. La documentación de APIs y locks se contrastó con Laravel Boost y documentación oficial de Laravel 13; el esquema existente fue confirmado mediante `database-schema` y las decisiones confirmadas se registraron con `record-rule`.

No hay verificación de UI ni de producción. Las pruebas de concurrencia cubren carreras reales de dos procesos; no constituyen una prueba de carga. No se ejecuta la suite completa del repositorio.

### Corrección de referencias de devolución

La regresión `test_cancellation_distribution_references_compensating_movement_lines` reprodujo que la cancelación heredaba IDs de líneas del descuento. La corrección reemplaza esas referencias por las líneas compensatorias correspondientes antes de guardar y auditar. La prueba comprueba pertenencia, producto, ubicación y cantidad de las seis devoluciones; verifica coincidencia entre API, persistencia y auditoría, reintento estable y conservación de la elaboración y auditoría originales.

La verificación focalizada posterior pasó con **36 pruebas y 849 aserciones**, cubriendo `FeedManufactureEndpointTest`, `FeedManufactureConcurrencyTest`, `FeedManufactureDemoSeederTest` y `FeedRecipeContractTest`. Pint pasó con paths explícitos por la limitación de Git en el contenedor; PHPStan nivel 5 pasó sobre la acción corregida y `git diff --check` también pasó. Esta corrección no reescribe operaciones o auditorías históricas ya registradas, ni modifica datos locales o de producción.

## Decisiones pendientes

Desempate y valores de ajustes quedaron confirmados. El permiso funcional específico permanece pendiente; se implementa la política provisional autenticada expresamente acordada.

El consumo futuro de lotes debe continuar con recetas desactivadas o saldo cero/negativo hasta cambiar alimentación o desactivar el lote. Es contexto para una entrega posterior; no se implementa ahora.

Volver al [índice de implementaciones](README.md).
