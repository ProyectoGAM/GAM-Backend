# Reportes y exportación — card 21

La implementación reutiliza el motor ReportingAndAnalytics y agrega una pantalla Angular de consulta, configuraciones privadas y exportaciones. El DOCX `Reportes_de_SGA.docx` se interpreta como necesidades de negocio: no exige nombres de columnas de base de datos. Se conservan datos históricos desconocidos como vacíos.

La actualización de [acceso por UP](access-methods-and-production-unit-implementation.md) restringe las sesiones PIN: las fuentes SQL filtran antes de agregar u ofrecer opciones, y las fuentes sin UP demostrable quedan denegadas. Configuraciones y exportaciones conservan su UP; el worker genera con ese contexto y rechaza un traslado posterior del solicitante. La descarga privada revalida la sesión. Las exportaciones restringidas no admiten enlaces públicos.

## Fuentes entregadas

| Fuente | Alcance | Permiso funcional |
| --- | --- | --- |
| `inventory.stock-balances` | Stock actual; rechaza fechas históricas | `can_view_inventory` |
| `inventory.movements` | Movimientos físicos y sus unidades | `can_view_inventory` |
| `lots.egg-production` | Postura válida y total vitalicio por galpón | `can_view_egg_collections` |
| `lots.admissions` | Ingresos y alojamientos no revertidos; cantidad ingresada | `can_view_flocks` |
| `lots.summary` | Una fila por lote, galpón actual, ingreso, raza, capacidad, mortandad y postura del período y estado actual | `can_view_flocks`, `can_view_mortality` y `can_view_egg_collections` |
| `lots.mortality` | Mortandad detallada por lote y galpón histórico, sin anulaciones | `can_view_mortality` |
| `lots.medications` | Medicación detallada del lote con medicamento/responsable históricos, sin ejecuciones corregidas | `can_view_management_plans` |
| `lots.weighing-measurements` | Muestras históricas y diarias; promedio ponderado por aves | `can_view_weighings` |
| `management.vaccinations` | Aplicaciones válidas, vacuna y partida registrada | `can_view_management_plans` |
| `management.executions` | Manejos realizados y programación de la revisión aplicable | `can_view_management_plans` |
| `management.overview` | Manejos por secciones: lotes, plan vigente, actividades, pesadas y movimientos/fraccionamientos | `can_view_management_plans`, `can_view_flocks`, `can_view_mortality`, `can_view_egg_collections` y `can_view_weighings` |
| `management.feed-supply` | Alimentación histórica: suministros antiguos y pauta estimada separadas | `can_view_management_plans` |
| `management.feeding` | Alimentación: consumos diarios por receta y última rectificación | `can_view_flock_feeding` |
| `inventory.feed-manufacturing` | Elaboraciones con ingredientes, distribución, faltantes y estado de cancelación | `can_view_feed_stock` |
| `inventory.feed-balances` | Saldos actuales por receta/UP con mínimos, atención y negativos | `can_view_feed_stock` |
| `accounts.movements` | Cuenta corriente completa, cargos, cobros, ajustes y reversiones | `can_view_customer_accounts` |
| `expenses.poultry-house-maintenance` | Gastos de mantenimientos de galpones completados, por moneda | `can_view_poultry_houses` |
| `deliveries.deliveries` | Una fila por entrega confirmada, cantidades, presentaciones, valor y notas | `can_view_delivery_history` |
| `deliveries.collections` | Reparto completo, con cargas, sobrantes, precios, observaciones, cobros y cuenta corriente | `can_view_delivery_history` y `can_view_customer_accounts` |
| `vehicles.usage` | Usos, responsables y kilometraje registrado | `can_view_vehicles` |
| `vehicles.fuel` | Cargas de combustible, litros e importe en UYU | `can_view_vehicle_fuel` |
| `vehicles.maintenance` | Intervenciones, estado y costos | `can_view_vehicle_maintenance` |
| `commerce.egg-outflow` | Vendidos, donados y total, excluyendo cargas/ajustes/pérdidas | `can_view_delivery_history` y `can_view_egg_stock` |

La [guía del resumen operativo](operational-summary-implementation.md#valor-entregado-y-gastos) documenta el valor global entregado con cobertura de importes y `GET /gastos/resumen`, con totales por moneda para la empresa o una UP.

El servidor define filtros, columnas, ordenamientos, dimensiones, métricas, opciones, consultas estándar y capacidades. La UI ofrece las consultas estándar antes de las opciones avanzadas. Las nuevas fuentes admiten todo el historial registrado (`max_range_days=0`), con 100 filas por página y 50.000 por XLSX. PDF se limita a 2.000 filas. No se recortan archivos silenciosamente: superar el límite produce un fallo accionable.

## Módulos de reportes dedicados

El catálogo tiene 23 fuentes y publica `module: {key, label}` en cada definición. El front puede agruparlas sin deducir el área desde el nombre o la clave de la fuente. Mortandad y Medicación pertenecen a **Lotes**; la cuenta completa a **Cuenta corriente**; las elaboraciones a **Elaboración de ración**; sus saldos a **Stock de alimento**. Postura conserva su grupo propio. **Gastos** contiene `expenses.poultry-house-maintenance` y `vehicles.maintenance`: se conserva esta última clave para que sigan funcionando sus configuraciones previas. Se reutilizan los endpoints de Reportes, las configuraciones privadas, XLSX/PDF y las instantáneas web. Esta entrega agrega fuentes y metadatos de agrupación de API, sin modificar pantallas ni crear nuevos registros de negocio.

### Lotes: Mortandad y Medicación

`lots.mortality` muestra fecha, lote, galpón/UP del hecho, cantidad de bajas y observaciones. Excluye registros anulados y suma bajas por lote, galpón o período. `lots.medications` muestra fecha, lote, alojamiento histórico, identidad y nombre guardados del medicamento, cantidad registrada si consta, motivo, responsable histórico y observaciones. Excluye originales compensados por correcciones de tipo `medication`. No inventa cantidades ni unidades de dosis cuando el registro no las tiene: la métrica cuenta aplicaciones. Ambas permiten filtrar lote, galpón, UP y fechas; Medicación agrega medicamento y responsable. Los datos provienen de los registros existentes y se mantienen disponibles para lotes finalizados; no se los reasigna al galpón actual.

### Cuenta corriente completa

`accounts.movements` incluye cargos por ventas, cobros, ajustes a débito/crédito y reversiones, aunque no pertenezcan a un reparto. Mantiene los importes con signo y los saldos anterior/posterior de cada movimiento, secuencia, cliente y responsable guardados, medio y referencia de pago, motivo, reparto opcional e identificador del movimiento compensado. Las compensaciones no borran los originales: `net_amount` suma los importes firmados y `movement_count` cuenta asientos. El saldo actual de la cuenta se expone sólo en detalle, identificado por separado, y no se suma repetidamente entre movimientos. Se filtra por cliente, tipo, medio de pago y fecha; se agrupa por cliente, moneda o períodos.

La cuenta completa requiere `can_view_customer_accounts`, además de permisos de Reportes. Es de alcance empresarial: no publica una UP artificial ni permite sesiones PIN, ya que una cuenta puede contener movimientos globales no atribuibles a una UP. Sus exportaciones se descargan de forma autenticada y no admiten enlaces públicos.

### Elaboración y stock de alimento

`inventory.feed-manufacturing` utiliza una fila por operación `manufacture`. Conserva fecha, receta y planta seleccionada de la instantánea histórica, multiplicador, kg elaborados, ingredientes y cantidades, distribución por planta y faltantes confirmados en kg. Los arrays se resumen mediante subconsultas escalares sin multiplicar la producción por cada ingrediente o planta. La cancelación se presenta como estado vigente, fecha y motivo en la misma fila. El historial incluye canceladas; `effective_quantity` suma sólo las que siguen elaboradas, y `manufacturing_count` cuenta originales. El estado es vigente al consultar, aunque la cancelación sea posterior al rango seleccionado; el rango recorta la fecha de elaboración. No se reinterpretan ajustes como fabricaciones.

`inventory.feed-balances` muestra los saldos vigentes materializados de cada receta/UP, incluso negativos y recetas inactivas. Publica kg actuales, mínimo nullable, estado y atención: se requiere atención con mínimo configurado y saldo menor o igual. Un mínimo nulo no equivale a cero. Las cantidades se convierten desde gramos con decimal exacto en PostgreSQL. Esta fuente rechaza fechas y agrupaciones temporales porque no reconstruye existencias históricas; el catálogo indica `supports_date_range: false`. Ambas fuentes usan `can_view_feed_stock` y filtran la UP antes de agregar u ofrecer opciones.

### Gastos de mantenimiento y monedas

`expenses.poultry-house-maintenance` incluye sólo mantenimientos de galpones completados: fecha, galpón/UP, descripción, costo, moneda y responsable guardado. Las cancelaciones no se suman. `vehicles.maintenance` conserva el historial operativo y sus estados, agrega filtro por estado y consulta estándar de gastos completados. Su métrica `cost_total` suma únicamente los completados; los pendientes/cancelados pueden consultarse como historial, pero no inflan ese gasto. El primero necesita `can_view_poultry_houses`; el segundo mantiene `can_view_vehicle_maintenance`.

El normalizador añade automáticamente la columna de moneda al seleccionar un importe con unidad variable, y la dimensión de moneda al agrupar su métrica. `cost_total` y `net_amount` nunca mezclan monedas por omisión del cliente. Se mantienen los permisos funcionales por fuente: una categoría común no amplía accesos. Las sesiones PIN sólo consultan mantenimientos de galpones de su UP; el mantenimiento vehicular global sigue denegado. El reporte de gastos de galpones no admite enlaces públicos. Todos son reportes de lectura: no modifican stock, cuentas, correcciones ni auditorías. Las operaciones del negocio siguen auditándose en sus Actions; solicitudes de exportación conservan la auditoría del motor. No hay migraciones ni dependencias nuevas.

Verificación del 17/11/2026: pasaron 72 pruebas y 627 aserciones funcionales/unitarias de Reportes. Las siete pruebas nuevas cubren bajas anuladas y medicaciones corregidas, instantáneas clínicas y cantidades desconocidas, contabilidad firmada con cobros/reversiones/ajustes independientes, elaboraciones sin multiplicación por ingredientes/plantas, cancelaciones, negativos y mínimos de stock, separación de monedas y gastos pendientes/cancelados, metadatos de módulo y sesión PIN real con filas, opciones y agregados restringidos. Se generaron XLSX/PDF reales y la instantánea común de vista web. El catálogo ejecutó las 23 fuentes y sus consultas estándar; el contrato OpenAPI, Pint y PHPStan nivel 5 pasaron.

## Configuraciones iniciales y mes calendario anterior

`DefaultReportPresetsSeeder` crea tres configuraciones privadas para la cuenta indicada en `auth.admin.email`, incluso sin datos operativos:

| Nombre | Fuente | Contenido inicial |
| --- | --- | --- |
| Manejos | `management.overview` | Datos del lote, plan vigente y su revisión, actividades programadas, manejos realizados y responsable, pesadas y movimientos/fraccionamientos |
| Repartos | `deliveries.collections` | Todos los campos publicados: reparto y cliente, huevos cargados/entregados/devueltos, valores predeterminados e ingresados, cobro, pago pendiente, medio de pago, deuda/crédito actual y observaciones |
| Postura | `lots.egg-production` | Fecha, UP, lote, galpón y huevos |

El seeder se integra en `DatabaseSeeder` después del administrador y los permisos. También se puede ejecutar solo, sin cargar demos, crear usuarios ni modificar stock:

```powershell
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\ReportingAndAnalytics\DefaultReportPresetsSeeder' --no-interaction
```

Bloquea la cuenta durante la transacción y busca por nombre normalizado y propietario antes de crear. Repetirlo conserva IDs, nombres coincidentes y personalizaciones existentes. No elimina otras configuraciones ni seedea datos del negocio. La visibilidad sigue siendo privada por usuario; no concede permisos ni crea configuraciones automáticamente para otras cuentas.

Las tres guardan `date_preset: "last_month"`, con `from` y `to` nulos. Se resuelve en America/Montevideo al **mes calendario anterior completo**, desde el día 1 hasta el último día, ambos inclusivos; por ejemplo, el 17/11/2026 consulta 01/10/2026–31/10/2026. En marzo consulta todo febrero, incluido el 29 en años bisiestos; en enero consulta diciembre del año anterior. La API de configuraciones entrega la plantilla en `configuration` y fechas concretas actualizadas en `resolved_configuration`, para que el front inicialice su rango. Se puede enviar la plantilla directamente a previsualización/exportación. Las tres configuraciones ya seedeadas se ajustan automáticamente al resolver el mismo marcador; no requieren reescribir filas ni perder personalizaciones. Fuentes y configuraciones anteriores sin `date_preset` mantienen su comportamiento.

En una consulta, fechas explícitas prevalecen sobre el rango relativo. Al guardar una configuración con `date_preset`, el servidor vuelve a dejar ambas fechas nulas; para guardar un rango fijo se omite el marcador o se envía nulo. La solicitud de exportación conserva fechas concretas desde el primer momento: que el worker se ejecute otro día no desplaza el período. La fuente de stock actual continúa rechazando rangos históricos.

### Vista conjunta Manejos

`management.overview` reutiliza el detalle filtrado de Lotes completos, Manejo general y Pesadas, y agrega programación vigente y movimientos no revertidos. Publica `section`: **Lotes**, **Plan de manejo**, **Manejos realizados**, **Pesadas**, **Movimientos y fraccionamientos**. Cada fila corresponde a un dato de una sección; las demás columnas son nulas cuando no aplican. No hace joins entre actividades, pesos y movimientos que multipliquen cantidades. Las fuentes individuales siguen disponibles.

Los datos del lote incluyen fecha de ingreso, raza, capacidad, aves actuales, mortandad, postura y estado; el lote se incluye si estuvo presente durante el período, aunque haya ingresado antes. Sus bajas y postura se recortan al rango. Las ejecuciones y pesadas usan la fecha y galpón del hecho. Las actividades corresponden a la revisión vigente y a la programación que se solapa con el rango; las condicionales/sin fecha y las recurrencias vigentes se incluyen mientras el lote estuvo presente. No se expanden las recurrencias en ocurrencias inventadas. `plan_id`, `plan_revision` y `plan` identifican el plan vigente del lote y el nombre actual de su manual, incluso en filas de hechos anteriores; no representan una reconstrucción del manual pasado.

Los movimientos conservan fecha, tipo, origen, destino, aves y motivo. Se muestran por cada lote afectado para que filtrar cualquiera de ellos no pierda el fraccionamiento. Se excluyen las compensaciones y sus originales revertidos. Por eso no hay una métrica que sume indiscriminadamente aves del movimiento: `record_count` cuenta filas y `flock_count` lotes distintos. El detalle permite consultar las cantidades de cada movimiento.

Se admiten filtros por `section`, `flock_id`, `poultry_house_id`, `production_unit_id` y `plan_id`; selección de columnas y agrupaciones por sección, lote, galpón o períodos. En Lotes/Plan el alojamiento es actual; en hechos es histórico cuando consta. Se requieren todos los permisos listados en el catálogo. En sesiones PIN, los componentes se filtran por UP y un movimiento que involucra otra UP se excluye completo. Se reutilizan XLSX, PDF e instantánea web existentes, con una tabla que distingue secciones. No se agregan hojas separadas ni se cambia aún el motor a configuraciones arbitrarias de varias fuentes. No hay migraciones ni dependencias nuevas.

Verificación inicial del 17/11/2026: pasaron 61 pruebas de Reportes con 488 aserciones en PostgreSQL de testing. Las cinco pruebas nuevas cubren carga repetible sin datos operativos, conservación de personalizaciones, columnas completas de Repartos, rango relativo y fin de mes en hora local, actualización de configuraciones, rango congelado antes del worker, las cinco secciones de Manejos sin multiplicación y XLSX real, permisos e aislamiento de filas/opciones/agregados con sesión PIN real. Se ejecutó el seeder en el entorno local y se comprobaron las tres filas con `last_month` y fechas nulas. El catálogo y OpenAPI se verificaron en la regresión; Pint y PHPStan nivel 5 pasaron. La corrección posterior cambia el rango predeterminado a mes calendario anterior completo según la elección del usuario: pasaron las 9 pruebas de configuraciones con 79 aserciones, incluyendo febrero bisiesto, cambio de año, zona local, fechas personalizadas y período congelado antes del worker. Pint y PHPStan pasaron para la corrección.

## Lotes completos y tipos de manejo

La fuente **Lotes completos** (`lots.summary`) publica una fila por lote. Su consulta estándar **Detalle por lote** incluye lote, galpón asignado actualmente, fecha de ingreso, raza, capacidad física del galpón, aves actuales del lote, mortandad y postura del período y estado actual. Un lote finalizado conserva su último galpón asignado. La capacidad representa el máximo físico y se mantiene separada de las aves actuales. Las correcciones y anulaciones se reflejan mediante los registros efectivos.

`from` y `to` abarcan días completos de America/Montevideo. El rango se aplica a las bajas y recolecciones e incluye los lotes ingresados y constituidos como máximo al final del período y cuya finalización ocurrió como mínimo al comienzo, incluidos los que siguen abiertos. Un fraccionamiento constituido después del período queda fuera aunque haya heredado una fecha de ingreso anterior. Un lote sin actividad tiene cero en mortandad y postura. Omitir el rango suma todo el historial registrado. Las sumas de bajas y huevos se calculan por separado antes de unirlas a cada lote para evitar multiplicar los totales.

El filtro `entry_date` permite elegir la fecha de ingreso independientemente del período de actividad. También se filtra por lote, galpón, UP, raza, estado actual, capacidad y cantidades. El resumen por galpón usa el alojamiento actual. Las agrupaciones por día, semana y mes se refieren al ingreso de los lotes de cada grupo. Las métricas publicadas cuentan lotes y suman aves actuales, bajas y huevos. La capacidad física se consulta en el detalle de cada lote.

Leer la fuente requiere `can_view_reports`, `can_view_flocks`, `can_view_mortality` y `can_view_egg_collections`. Exportarla requiere `can_export_reports` y los permisos de sus módulos. Las sesiones PIN filtran tanto los lotes como los hechos de producción por su UP antes de devolver filas, opciones o agregados. Se reutilizan las rutas de Reportes, las configuraciones guardadas y las exportaciones XLSX/PDF.

**Manejo general** (`management.executions`) agrega `management_type` como columna, filtro y agrupación. Publica Despique, Colocación de nidos, Práctica manual, Vacunación, Medicación, Cambio de ración y Suministro de alimento. Conserva lote, galpón histórico, fecha de ejecución y descripción del manejo realizado. Las ejecuciones corregidas y suministros anulados se excluyen. Esta ampliación reutiliza los datos existentes.

`management.executions` y `management.vaccinations` publican `responsible` con la etiqueta **Responsable**. Se puede incluir u omitir con `columns`, filtrar por los nombres históricos publicados en `opciones?field=responsible` y agrupar u ordenar por ese campo. El nombre proviene de `responsible_name_snapshot` para prácticas, vacunaciones, medicaciones y cambios de ración. Si la cuenta cambia de nombre, el reporte conserva el de la aplicación. Los suministros legados sin instantánea del responsable devuelven nulo. Las configuraciones guardadas con una selección explícita conservan sus columnas y las exportaciones utilizan esa selección.

La ampliación de Responsable se verificó el 17/11/2026 con 17 pruebas y 190 aserciones de reportes, incluida una exportación XLSX real. Se comprobaron selección y omisión de la columna, filtros, opciones históricas, agrupación y nombres preservados tras renombrar la cuenta. Pint y PHPStan pasaron.

La verificación del 17/11/2026 pasó con 65 pruebas y 404 aserciones de Reportes y aislamiento por UP. La comprobación final pasó con 4 pruebas y 31 aserciones, incluidos límites de día con zona horaria, lotes sin actividad, finalizados y constituidos fuera del período, hechos cancelados, sumas independientes, permisos, tipos de manejo, filtro y agrupación, y un archivo XLSX real. Pint, PHPStan nivel 5 y la lectura del contrato OpenAPI pasaron. La factory de correcciones se ajustó a `Str::ulid()` para poder crear registros de prueba con el generador instalado.

## Reparto completo

Seleccionar **Reparto completo** en el catálogo de Reportes. La clave `deliveries.collections` se usa en las rutas de previsualización y exportación XLSX/PDF. La fuente abre la consulta estándar **Detalle de entregas** y exige permiso de Reportes, lectura del historial de Repartos y `can_view_customer_accounts`. Sus exportaciones se descargan de forma autenticada y privada.

Cada fila representa una entrega confirmada. Incluye la fecha de la visita, fecha de inicio e identificador del reparto, cliente, huevos cargados, entregados y devueltos al cierre, valor de mercadería, precio predeterminado, precios ingresados y observaciones. La carga y la devolución son totales del reparto. Antes del cierre, la devolución es nula. El valor predeterminado se calcula con el precio de catálogo guardado por línea al confirmar la entrega y las cantidades vigentes. Un cambio de catálogo posterior no altera esos valores. Las entregas históricas con datos incompletos muestran nulo para el valor predeterminado desconocido.

El cobro y el importe pendiente al entregar se obtienen de los movimientos originales vinculados a la visita. Los cobros posteriores, rectificaciones y reversiones afectan la cuenta vigente. El reporte conserva lo que ocurrió al entregar. Una entrega fiada confirmada tiene cobro cero y una entrega legada sin conciliar tiene cobro nulo. `current_debt`, `current_credit` y `current_debt_status` reflejan la cuenta al generar el reporte, incluso si se eligió un período anterior. Una cuenta sin vínculo verificable se muestra como desconocida.

`from` y `to` filtran la fecha local de entrega e incluyen ambos extremos. Se puede filtrar además por cliente, reparto, fecha de inicio, cantidad, importes, observaciones, medios de pago y estados. Los operadores numéricos admiten enteros o decimales en texto. `contains` busca texto literal sin distinguir mayúsculas. Los medios y estados del reporte se filtran con las etiquetas publicadas por la fuente, como `Cheque`, `Fiado` y `Al día`.

Las consultas estándar **Resumen por día** y **Resumen por cliente** incluyen la deuda actual. Al agrupar por día, semana, mes, reparto o cliente, `loaded_total` y `returned_total` cuentan cada reparto una vez dentro de cada grupo. `current_debt_total`, `current_credit_total` y los conteos de clientes usan una cuenta por cliente dentro de cada grupo. Un reparto o cliente puede aparecer en grupos distintos. Solicitar un total sin agrupación aplica la deduplicación a todo el resultado filtrado. Las observaciones y los precios unitarios se consultan en el detalle. `unknown_payment_count` identifica la cobertura del cobro histórico.

La migración amplía el catálogo de medios de pago de PostgreSQL con `check`. Revertirla requiere que no existan movimientos con ese medio. Los cheques anteriores registrados como `other` conservan su clasificación y los precios históricos faltantes permanecen desconocidos. Registrar una entrega o un cobro sigue usando las operaciones auditadas e idempotentes del módulo de origen. El reporte sólo consulta esos datos y la exportación congela la vista que obtuvo al generarse.

El frontend carga el catálogo y las consultas estándar desde la API. Los selectores de cobro del cliente actual todavía carecen de la opción `check` y las comparaciones numéricas nuevas muestran una etiqueta genérica.

Pasaron 56 pruebas y 396 aserciones en PostgreSQL de testing. La suite verificó el cobro con cheque, la entrega fiada que se pagó después, el cierre con devolución, los valores predeterminados históricos, los filtros, la deduplicación por grupo, la descarga privada y el archivo XLSX. Pint y PHPStan pasaron para el núcleo del reporte. PHPStan señaló seis diagnósticos en líneas preexistentes al ampliar la revisión a solicitudes de Reparto y creación de enlaces temporales.

## Postura y Alimentación como fuentes independientes

**Postura** reutiliza `lots.egg-production`: fecha, lote, galpón de la recolección y cantidad de huevos. Sólo incluye recolecciones registradas, incluso de lotes finalizados; las anuladas quedan fuera. Agrega filtro numérico `quantity` y consultas estándar por día/lote/galpón y por mes/galpón, además del total vitalicio existente.

**Alimentación** usa la nueva clave `management.feeding`. Cada fila representa un consumo diario ya registrado en `feed_consumptions`, con la receta y cantidad de su última rectificación por ID, o los valores originales si no hubo rectificaciones. Un cero rectificado es cero; las compensaciones de stock no son consumos adicionales. No se proyectan consumos futuros ni jornadas pendientes. Incluye fecha, lote, galpón, UP, alimento (`feed_recipe_id`/`feed_recipe`), cantidad en kg, unidad, modo Automático/Manual y observaciones de la última rectificación. La conversión desde gramos y las sumas se realizan en PostgreSQL con decimales exactos. El nombre del alimento se consulta en el catálogo actual de recetas; la identidad de la receta consumida o rectificada es histórica.

El galpón de Alimentación se reconstruye desde el último movimiento del lote anterior al comienzo del día siguiente en America/Montevideo. Los traslados del día se atribuyen al alojamiento al cierre; una recuperación posterior no reasigna el consumo al galpón actual. Sin instantánea comprobable permanece nulo. La UP proviene del propio consumo y se valida que el galpón reconstruido pertenezca a esa UP. `feed_quantity` suma kg efectivos y `consumption_count` cuenta jornadas registradas de lotes, incluidas las de cantidad cero. Las consultas estándar ofrecen detalle, día/lote/galpón/alimento y mes/galpón/alimento.

Ambas fuentes aceptan `from`/`to` inclusivos en la zona de la granja, con una misma fecha para un día específico. Comparten filtros por `production_unit_id`, `poultry_house_id` y `flock_id`, opciones históricas, y agrupaciones por `day`, `week`, `month`, `production_unit`, `poultry_house` y `flock`. La semana comienza el lunes. `quantity` permite comparar cantidades de cada registro antes de agrupar, en huevos para Postura y kg para Alimentación. Alimentación permite además filtrar por `feed_recipe_id` y `mode`, y agrupar por alimento o modo. Los IDs acompañan las dimensiones internamente para conservar entidades distintas aunque compartan nombre.

Ejemplo de configuración de Alimentación para reutilizar el mismo lote, galpón y período de otra sección:

```json
{
  "from": "2026-11-01",
  "to": "2026-11-30",
  "filters": [
    {"field": "flock_id", "operator": "eq", "value": "ID_PUBLICO_DEL_LOTE"},
    {"field": "poultry_house_id", "operator": "eq", "value": 12}
  ],
  "groupings": ["month", "flock", "poultry_house"],
  "metrics": ["feed_quantity"]
}
```

Se envía a `POST /api/v1/reportes/management.feeding/previsualizaciones`. Para Postura se utiliza `lots.egg-production` y la métrica `egg_count`. Cada fuente conserva sus propias configuraciones y usa el motor existente de exportaciones XLSX/PDF e instantáneas web. Los filtros compatibles facilitan una futura configuración con varias secciones; esta entrega no agrega todavía esa composición al motor, que sigue recibiendo una fuente por configuración/exportación.

Se requiere `can_view_reports` y el permiso de origen: `can_view_egg_collections` o `can_view_flock_feeding`. Exportar y guardar configuraciones mantienen sus permisos existentes. Las sesiones PIN quedan limitadas a su UP en detalle, totales, opciones y exportaciones. Son consultas sobre hechos existentes: no agregan mutaciones de negocio, movimientos de stock ni nuevas auditorías de lectura. Guardar configuraciones y solicitar exportaciones conserva la auditoría del motor actual. No hay migraciones ni dependencias nuevas.

Verificación del 17/11/2026: las cinco pruebas nuevas comprobaron límites de día, agrupaciones por día/semana/mes, filtros, alimentación manual/automática, rectificaciones sucesivas y cero, recuperación tras traslados, permisos y aislamiento HTTP con una sesión PIN real. Incluyen una configuración guardada, archivos XLSX/PDF reales y una instantánea que conserva el valor anterior tras rectificar. Se ejecutaron las 56 pruebas funcionales y unitarias de Reportes; el barrido de catálogo excedió su límite de frecuencia y se aisló ese middleware únicamente en la prueba. La repetición del archivo de catálogo pasó con 12 pruebas y 140 aserciones; las otras 44 pruebas de la corrida conjunta pasaron. Pint, PHPStan nivel 5 y la lectura de OpenAPI pasaron.

## Alimentación histórica registrada y estimada

`management.feed-supply` se presenta como **Alimentación histórica** y conserva las configuraciones guardadas y sus resultados. `feed_supplies` contiene lote, galpón/UP del hecho, alimento y nombre histórico, cantidad decimal, unidad, fecha, operación y responsable. El registro de suministros nuevos fue retirado; se conservan consulta, reintentos históricos y anulaciones. Esta fuente no incluye los consumos diarios por receta de `management.feeding` ni los infiere de movimientos de materias primas.

La pauta suma todos los suministros reales de un mismo día por lote, galpón y alimento. En días posteriores sin carga repite esa cantidad diaria, hasta otra fecha con suministro real. Se detiene antes del día de cierre o traslado para no estimar un día completo de alojamiento parcial; también respeta la fecha actual y el rango consultado. Si una observación tiene varios alimentos se conserva la mezcla hasta la próxima observación. No se estiman días anteriores al primer dato. Las filas estimadas se expanden por día para que las agrupaciones semanales y mensuales sean correctas.

Las métricas `feed_quantity` y `estimated_feed_quantity` muestran columnas separadas. Ninguna métrica suma las dos cantidades por defecto. La consulta estándar vitalicia agrupa por UP, galpón, alimento y unidad. Anular un suministro registra otra operación y auditoría; puede cambiar la pauta estimada de futuras consultas, pero no archivos ya generados.

## Histórico y precisión

Postura utiliza el galpón de la recolección. Los manejos nuevos capturan galpón/UP; registros anteriores y pesadas diarias resuelven el alojamiento mediante snapshots de movimientos ocurridos antes del hecho. No se usa el galpón actual para reasignar producción antigua. Los grupos incorporan identificadores para no fusionar entidades homónimas.

Pesadas conserva origen, modo y aves representadas. El promedio agregado es `SUM(peso total)/SUM(aves)`; no es un promedio de promedios. Los registros diarios anulados y las ejecuciones compensadas se excluyen. Los importes de una entrega no se multiplican por su número de líneas. PostgreSQL calcula agregados; Angular sólo presenta valores exactos. XLSX convierte cantidades numéricas de hasta quince cifras significativas a celdas numéricas y conserva valores de mayor precisión como texto.

## Contratos públicos

Rutas canónicas bajo `/api/v1`, documentadas en `contracts/openapi/reporting.yaml`:

- `GET reportes/fuentes`; `GET reportes/{source}/opciones?field=...&search=...&page=...`.
- `POST reportes/{source}/previsualizaciones`; `POST reportes/{source}/exportaciones`.
- CRUD `configuraciones-reportes` y `configuraciones-reportes/{reportPreset}`.
- `GET exportaciones-reportes`; `GET exportaciones-reportes/{reportExport}`.
- `GET exportaciones-reportes/{reportExport}/descarga`; `POST exportaciones-reportes/{reportExport}/enlaces-temporales`.

Las rutas inglesas anteriores siguen disponibles. Los enlaces ya emitidos conservan su ruta y firma. La pantalla usa rutas españolas; se corrige la diferencia previa entre `download` real y `descarga` documentada. Las opciones de cada fuente usan su permiso funcional y admiten búsqueda/paginación sin exigir permisos de edición del catálogo.

Suministros históricos y donaciones (`contracts/openapi/reporting-operations.yaml`):

- `GET alimentacion/productos` está deprecado y devuelve una lista vacía; `GET flocks/{flock}/alimentacion` conserva el historial.
- `POST flocks/{flock}/alimentacion` sólo reproduce operaciones históricas guardadas; las cargas nuevas reciben 410 sin efectos.
- `POST flocks/{flock}/alimentacion/{feedSupply}/anulacion`.
- `GET donaciones/unidades-productivas`; `POST unidades-productivas/{productionUnit}/donaciones`.

Formato histórico de suministro, conservado para reintentos con su `Idempotency-Key` original; no permite una carga nueva:

```json
{"product_id": 3, "quantity": "25.500000", "unit": "kg", "occurred_at": "2026-10-12T10:00:00-03:00", "notes": "Ración administrada"}
```

La [guía de retirada de finished_feed](finished-feed-retirement.md) detalla la transición. Se conservan el reporte, nombres guardados, cantidades y anulaciones, sin convertir suministros en consumos ni descontar otra vez el saldo de recetas. `ReportingDemoSeeder` deja de crear los productos y suministros antiguos; preserva los datos ya existentes.

Ejemplo de donación, con el mismo protocolo de idempotencia:

```json
{"quantity": 12, "occurred_at": "2026-10-12T10:00:00-03:00", "reason": "Entrega a escuela", "notes": "Dos envases de seis"}
```

La donación utiliza `egg_stock_transactions.type=donation` y la Action existente para descontar stock físico, bajo `can_move_egg_stock`. Corrección/anulación reutiliza inventario con `can_adjust_egg_stock`. El historial de huevos permite filtrar el nuevo tipo. El registro/anulación de alimento exige `can_execute_management_plans`; consulta/opciones exige view o execute. Ambos hechos son idempotentes y se auditan dentro de la misma transacción. Alimentación también participa en el diario del lote para proteger las reversiones de redistribución.

## Exportación, cola y compartición

La exportación normaliza la consulta sin la página visible, conserva propietario y clave idempotente y despacha después de commit en Redis `reporting`. La recuperación programada reencola solicitudes pendientes antiguas usando la unicidad existente. Job/supervisor/retry_after quedan en 300/330/360 segundos. Las fallas de límites se registran sin repetir el cálculo; las transitorias conservan retries/backoff.

Se materializa un dataset privado por páginas de cien filas en una lectura consistente. XLSX, PDF y HTML consumen esa instantánea. Sus etiquetas y valores no cambian con modificaciones posteriores del sistema. Los archivos anteriores sin instantánea pueden descargarse; su HTML solicita regeneración.

Los archivos se habilitan al completar. Disponibilidad predeterminada: 24 horas desde finalización. Compartir ofrece 30 minutos, 1, 6, 12 y 24 horas, 7 días y 30 días; el API conserva la compatibilidad con duraciones enteras en minutos de 1 a 43200, restringibles mediante `REPORTING_SHARED_LINK_MAX_MINUTES`. Su valor predeterminado ahora es 43200. La creación bloquea la exportación en una transacción y prolonga su disponibilidad, si es necesario, hasta el vencimiento del enlace solicitado, registrando ese cambio en auditoría. Devuelve `file_expires_at` para actualizar el historial de archivos. Un enlace posterior más corto no acorta la disponibilidad ni revoca firmas anteriores; no se recuperan archivos expirados o eliminados.

El modelo `ReportExport` conserva el offset al persistir fechas (`Y-m-d H:i:sP`), como los otros modelos con fechas de negocio, evitando desfases si la zona de PostgreSQL difiere de la aplicación. No se cambian zonas del entorno ni se reinterpretan registros existentes. Limpieza y descarga revalidan el vencimiento bajo bloqueo para respetar una ampliación concurrente. El HTML pagina con enlaces firmados distintos; alterar parámetros invalida la firma. La limpieza elimina archivo y dataset, manteniendo metadata y auditoría. Una firma válida autoriza visita anónima a la instantánea compartida hasta su vencimiento. No existe revocación individual anticipada de enlaces en este alcance.

Propietario y permisos de fuente se revalidan al descargar privadamente y emitir nuevos enlaces. Las rutas de reporting y descargas privadas validan sesión/dispositivo compartido. Las respuestas privadas usan no-store. Excel protege texto que podría ejecutarse como fórmula; Blade/PDF escapa contenido. No se guardan URLs firmadas ni credenciales en logs/storage web.

## Frontend y nativo

Ruta del menú: `/administracion/reportes/reportes`. Feature `reporting` lazy, standalone y zoneless; formularios tipados, signals y RxJS cancelan resultados obsoletos. Ante error conserva la consulta aplicada y su resultado, evitando exportar parámetros diferentes. Polling acotado se pausa en segundo plano y se detiene en estados terminales o al cambiar identidad.

La ventana de enlace separa Copiar enlace y Compartir, con otro botón de portapapeles junto a la URL. Copiar escribe al portapapeles web o al [plugin oficial Clipboard 8.0.1](https://capacitorjs.com/docs/apis/clipboard) en Android/iOS; Compartir abre las opciones del dispositivo/navegador y nunca copia automáticamente. Si no hay soporte web para compartir, se explica cómo copiar y enviar el enlace. Cambiar duración crea una firma nueva sin cerrar la ventana; mientras se prepara, las acciones y el enlace anterior quedan deshabilitados. Los errores conservan el enlace y el plazo anterior, y cerrar la ventana descarta respuestas pendientes. El temporizador del frontend divide esperas mayores al límite del navegador para evitar que un enlace de 30 días desaparezca inmediatamente.

Donaciones reemplaza su placeholder en Inventario. Alimentación tiene ruta propia en Manejo y acceso desde el detalle del lote. Usa colores semánticos GAM en claro/oscuro, tablas semánticas y controles táctiles.

ApiClient incorpora descarga Blob con manejo de errores JSON. En nativo se usan Filesystem 8.1.4, File Transfer 2.0.6 y Share 8.0.3: credenciales en headers sólo hacia el API autorizado, sin redirecciones; archivo en caché y diálogo de guardar/compartir. La caché temporal se limpia al cambiar sesión o cumplir una hora, permitiendo que la aplicación receptora lea el archivo. Se agrega el manifiesto iOS del plugin y se sincronizan ambas plataformas. El manifiesto sigue la [documentación oficial de Filesystem](https://capacitorjs.com/docs/apis/filesystem).

## Migración, demo y operación

`2026_10_12_223401_add_reporting_business_context` agrega contexto nullable a los cuatro manejos existentes, partida de vacuna, suministros cuantitativos y tipo de donación. No inventa backfills. La reversión se rechaza si hay donaciones; eliminar suministros/contexto puede perder datos, por lo que en producción se prefiere corregir hacia adelante.

El seeder de reporting añade alimento, suministro/donación demo y configuración de Postura de forma idempotente, después de los seeders de lotes. En despliegue: ejecutar migraciones, mantener almacenamiento privado común entre API/worker, scheduler y Redis, y reiniciar workers para cargar cambios. No ejecutar resets de datos reales.

La carga general de demo incluye actualmente 31 operaciones en `flock_operations`: las 30 del ciclo de lotes y su alimentación diaria, y el registro de suministro usado por Reportes. Su prueba de integración verifica también un único suministro de 25,5 kg y que repetir la carga conserve las mismas operaciones y no duplique suministros. La donación demo deja −12 huevos en su cuenta de stock y conserva los 477 huevos de la cuenta de producción. Antes de integrar alimentación diaria se esperaban 24 operaciones; esa expectativa había corregido el fallo de PHPUnit del [CI del PR 43](https://github.com/ProyectoGAM/GAM-Backend/actions/runs/38347268423/job/115131458551?pr=43).

Validación de la corrección: las 477 pruebas completas del backend pasaron con 3937 aserciones usando la configuración de pruebas del workflow, PHP 8.5 y PostgreSQL aislado en `gam_card21_testing`. También pasaron las tres pruebas de la demo por separado, PHPStan sobre todo `app` con el nivel predeterminado del CI, Pint y la validación de Compose.

### Integración con alimentación diaria de lotes

La integración de `develop` en `alimentacionLotes` omitió `feed_supply` al combinar las claves de `LotsOperationResource`. El alta guardaba el suministro, pero la respuesta no incluía su `public_id`; los clientes no podían construir correctamente la URL de anulación. El recurso vuelve a incluir el snapshot del suministro en el alta, su repetición idempotente y la anulación, junto con las claves de configuración diaria. Los dos contratos OpenAPI explicitan ese identificador y el estado de la respuesta de anulación.

La corrección conserva rutas, permisos, auditoría transaccional e idempotencia existentes. No introduce migraciones ni modifica datos fuera de las pruebas. Las 15 pruebas focalizadas de demo, fuentes de negocio y contrato de planes pasaron con 261 aserciones en PostgreSQL aislado `gam_testing`, incluyendo cantidades/unidades e identificador del suministro en la respuesta y estado `cancelled` al anularlo.

La suite completa de CI pasó localmente con **603 pruebas y 5576 aserciones**, PHP 8.5.10 y la base aislada `gam_testing`. PHPStan aprobó todo `app` con el nivel predeterminado 0 del workflow. Pint aprobó los tres archivos PHP modificados: el contenedor no tiene Git para `--dirty`, por lo que se pasaron las rutas obtenidas del diff en el host. `git diff --check` pasó. Esta verificación no incluye frontend ni despliegue.

## Límites funcionales y verificación

La ampliación de compartición del 13 de octubre de 2026 se verificó con 44 pruebas de ReportingAndAnalytics (216 aserciones), incluyendo los siete plazos, rechazo de duraciones fuera de rango, retención/limpieza, firmas con vencimientos independientes, bloqueo de exportaciones vencidas y descarga con modelo desactualizado. Frontend: 496 pruebas aprobadas, lint y build aprobados, auditoría de dependencias de producción sin vulnerabilidades y sincronización de Android/iOS con Clipboard. Chrome contra Docker Compose comprobó PDF y Excel con los siete plazos reales, copia desde el botón y el icono junto a la URL, compartición separada, apertura sin sesión y teléfono claro/oscuro. La llamada a compartir web se comprobó con una implementación controlada para evitar abrir la hoja del sistema operativo en Chrome sin interfaz; no se probó un dispositivo físico.

Partidas de vacuna son opcionales para nuevos registros; los históricos carecen de dato. La pauta de alimentación se estima con la configuración vigente de cada período y “Toda la vida” representa todo el historial registrado.

Las pruebas nuevas cubren catálogo/rutas, cantidades históricas, pautas separadas y recortadas, idempotencia y auditoría, donaciones/stock, archivos y firmas, precisión/formularios, cancelación de consultas, polling y cambio de usuario. La verificación se registra al cerrar el cambio. No se considera probado el comportamiento físico de Android/iOS sólo por `cap sync`; se requiere recorrido en dispositivos y Xcode para compilar iOS.

### Verificación del 12 de octubre de 2026

- Backend: 96 pruebas y 914 aserciones aprobadas en las suites de ReportingAndAnalytics, ManagementPlans, Deliveries e Inventory. Se ejecutaron en una imagen PHP 8.5 con PostgreSQL, montando este checkout y usando la base aislada `gam_card21_testing`, sin ejecutar pruebas sobre la base habitual.
- Frontend: suite completa de 466 pruebas aprobada; después del ajuste final de interpretación de errores de descarga, sus cinco pruebas específicas también pasaron. Node utilizado: 22.22.3, compatible con Angular 22.
- Lint y build de producción aprobados; permanecen los avisos existentes de presupuestos SCSS y módulos CommonJS de mapas. La nueva ruta de Reportes se entrega como chunk lazy.
- `npx cap sync` completado para Android/iOS con los tres plugins nuevos. No se ejecutó compilación Xcode ni recorrido en dispositivos físicos.
- Pint y `git diff --check` aprobados. PHPStan nivel 5 pasó para servicios de reporting, fuente/Action de alimentación y controllers de reporting. La configuración global de Lotes mantiene dos hallazgos en `RecordInventoryMovementAction.php` (líneas 301 y 424, `withResourceMetadata`), archivo no modificado por este cambio; no se ocultaron con supresiones.
- `npm audit --omit=dev`: cero vulnerabilidades.

### Recorrido del sistema real con Docker Compose

Por pedido explícito se bajaron los proyectos locales de backend y frontend con `down --volumes --remove-orphans`, eliminando sus siete volúmenes. Se reconstruyeron las imágenes desde este checkout de `Granja-Milpa`, se levantó el stack, se ejecutaron migraciones y se cargó el demo mediante `db:seed`. La migración `2026_10_12_223401_add_reporting_business_context` figura aplicada en el batch 1. API, frontend, gateway, PostgreSQL, Redis y Horizon quedaron saludables; el scheduler está activo y no hay jobs fallidos.

Chrome contra la aplicación real aprobó 17 comprobaciones: login web con cookies/CSRF, catálogo de diez fuentes y sus consultas estándar, Postura vitalicia, guardar y recuperar configuraciones, generar/descargar XLSX y PDF por Redis/Horizon, abrir HTML firmado sin sesión, rechazar una firma alterada, mantener la instantánea tras registrar nueva producción, registrar/anular alimentación con seis decimales y medidas separadas, registrar donaciones y restaurar stock mediante su anulación, eliminar configuraciones y revisar escritorio/móvil. No hubo excepciones JavaScript. Se inspeccionó el XLSX descargado: contiene UP, galpón y 400 huevos como celda numérica, coincidiendo con la consulta real.

El recorrido detectó y corrigió la selección visual inicial de la UP en Donaciones: ahora la opción visible coincide con la unidad utilizada para consultar historial y registrar. Su prueba de regresión cubre la llegada asíncrona de opciones y el cambio de unidad; pasó en el contenedor de frontend. Se repitieron lint y build de producción con éxito, conservando los avisos existentes de SCSS y CommonJS. Se revisó también la vista móvil iniciando Chrome a 390 × 844 en oscuro, con navegación cerrada, y se eliminaron las configuraciones temporales de los recorridos. Las operaciones de prueba se anularon de forma compensatoria, preservando su auditoría.

### URLs de desarrollo y producción

`APP_ENV` controla el host reenviado por el gateway. Con `local` conserva el puerto de `Host`, permitiendo visitar enlaces generados desde `localhost:4200` o directamente desde `localhost:8080`. Con `production` y otros entornos mantiene el dominio sin puerto explícito. El sitio público debe reenviar `Host` y `X-Forwarded-Proto` correctos; `APP_URL` contiene su origen público para generación fuera de peticiones HTTP. La configuración local no fija el dominio ni los puertos del despliegue final.

Ambos Compose montan una plantilla de Nginx, sustituyen sólo `APP_ENV` al arrancar y escriben la configuración en un tmpfs, manteniendo el filesystem de la imagen en modo read-only. Desarrollo comparte el valor de entorno con Laravel; producción fija `production`. Ver configuración y recreación del gateway en el [README](../../README.md#urls-según-el-entorno).

Se validó con la misma imagen de gateway en ambos modos: un enlace local conservó `:8080`; uno emitido por un gateway aislado con `APP_ENV=production`, `Host=reports.example:8081` y protocolo externo HTTPS produjo `https://reports.example` sin puerto. Ambos enlaces abrieron su HTML con firma válida, sin sesión. Esta comprobación simula el proxy público y no constituye un despliegue al sitio final.

## Exportaciones de varios módulos

El creador consulta el catálogo autorizado y previsualiza cada sección con su fuente habitual. Envía las consultas en orden a POST /api/v1/exportaciones-reportes/combinadas con el formato y una clave de idempotencia. Cada consulta conserva sus columnas, agrupaciones, período y filtros. Las fuentes de saldo actual se solicitan sin fechas.

La cola de reportes genera un Excel con una hoja por sección o un PDF con secciones sucesivas. Los límites se aplican a cada fuente y al conjunto completo. El historial identifica estas exportaciones con source_key igual a combined. El acceso al archivo requiere una sesión del propietario y se verifican los permisos de todas las fuentes al solicitar, generar y descargar. El formato combinado admite descarga privada.
