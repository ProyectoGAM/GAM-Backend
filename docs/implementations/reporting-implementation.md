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
| `lots.weighing-measurements` | Muestras históricas y diarias; promedio ponderado por aves | `can_view_weighings` |
| `management.vaccinations` | Aplicaciones válidas, vacuna y partida registrada | `can_view_management_plans` |
| `management.executions` | Manejos realizados y programación de la revisión aplicable | `can_view_management_plans` |
| `management.feed-supply` | Ración registrada y pauta estimada separadas | `can_view_management_plans` |
| `deliveries.deliveries` | Una fila por entrega confirmada, cantidades, presentaciones, valor y notas | `can_view_delivery_history` |
| `deliveries.collections` | Reparto completo, con cargas, sobrantes, precios, observaciones, cobros y cuenta corriente | `can_view_delivery_history` y `can_view_customer_accounts` |
| `vehicles.usage` | Usos, responsables y kilometraje registrado | `can_view_vehicles` |
| `vehicles.fuel` | Cargas de combustible, litros e importe en UYU | `can_view_vehicle_fuel` |
| `vehicles.maintenance` | Intervenciones, estado y costos | `can_view_vehicle_maintenance` |
| `commerce.egg-outflow` | Vendidos, donados y total, excluyendo cargas/ajustes/pérdidas | `can_view_delivery_history` y `can_view_egg_stock` |

La [guía del resumen operativo](operational-summary-implementation.md#valor-entregado-y-gastos) documenta el valor global entregado con cobertura de importes y `GET /gastos/resumen`, con totales por moneda para la empresa o una UP.

El servidor define filtros, columnas, ordenamientos, dimensiones, métricas, opciones, consultas estándar y capacidades. La UI ofrece las consultas estándar antes de las opciones avanzadas. Las nuevas fuentes admiten todo el historial registrado (`max_range_days=0`), con 100 filas por página y 50.000 por XLSX. PDF se limita a 2.000 filas. No se recortan archivos silenciosamente: superar el límite produce un fallo accionable.

## Lotes completos y tipos de manejo

La fuente **Lotes completos** (`lots.summary`) publica una fila por lote. Su consulta estándar **Detalle por lote** incluye lote, galpón asignado actualmente, fecha de ingreso, raza, capacidad física del galpón, aves actuales del lote, mortandad y postura del período y estado actual. Un lote finalizado conserva su último galpón asignado. La capacidad representa el máximo físico y se mantiene separada de las aves actuales. Las correcciones y anulaciones se reflejan mediante los registros efectivos.

`from` y `to` abarcan días completos de America/Montevideo. El rango se aplica a las bajas y recolecciones e incluye los lotes ingresados y constituidos como máximo al final del período y cuya finalización ocurrió como mínimo al comienzo, incluidos los que siguen abiertos. Un fraccionamiento constituido después del período queda fuera aunque haya heredado una fecha de ingreso anterior. Un lote sin actividad tiene cero en mortandad y postura. Omitir el rango suma todo el historial registrado. Las sumas de bajas y huevos se calculan por separado antes de unirlas a cada lote para evitar multiplicar los totales.

El filtro `entry_date` permite elegir la fecha de ingreso independientemente del período de actividad. También se filtra por lote, galpón, UP, raza, estado actual, capacidad y cantidades. El resumen por galpón usa el alojamiento actual. Las agrupaciones por día, semana y mes se refieren al ingreso de los lotes de cada grupo. Las métricas publicadas cuentan lotes y suman aves actuales, bajas y huevos. La capacidad física se consulta en el detalle de cada lote.

Leer la fuente requiere `can_view_reports`, `can_view_flocks`, `can_view_mortality` y `can_view_egg_collections`. Exportarla requiere `can_export_reports` y los permisos de sus módulos. Las sesiones PIN filtran tanto los lotes como los hechos de producción por su UP antes de devolver filas, opciones o agregados. Se reutilizan las rutas de Reportes, las configuraciones guardadas y las exportaciones XLSX/PDF.

**Manejo general** (`management.executions`) agrega `management_type` como columna, filtro y agrupación. Publica Despique, Colocación de nidos, Práctica manual, Vacunación, Medicación, Cambio de ración y Suministro de alimento. Conserva lote, galpón histórico, fecha de ejecución y descripción del manejo realizado. Las ejecuciones corregidas y suministros anulados se excluyen. Esta ampliación reutiliza los datos existentes.

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

## Alimentación registrada y estimada

`feed_supplies` conserva lote, galpón/UP del hecho, alimento y nombre histórico, cantidad decimal, unidad, fecha, operación y responsable. Las nuevas cantidades representan alimento administrado al lote; se aceptan kg y g. No se infiere consumo de alimento a partir de movimientos de materias primas. El registro de suministro no modifica automáticamente esos movimientos ni el inventario: elaboración y administración son hechos diferentes.

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
