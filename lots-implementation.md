# 05 — Lotes y cría — Lots

## Descripción y alcance

Gestionar el ciclo de vida de los lotes de aves: admisión, raza, proveedor u origen, cantidad inicial y viva, fecha de ingreso, galpón, semana actual, redistribución, cuarentena y finalización. Incluye mortalidad, recolección de huevos y métricas de postura.

Es una implementación nueva del backend. No se trasladan las entidades `Division`/`Manejo` del sistema anterior ni se crean relaciones padre–hijo entre lotes.

Dependencias: M03 Instalaciones, M04 Proveedores y productos, Identidad y acceso, Auditoría y trazabilidad, e Inventario para los huevos. No se agregan paquetes.

[Contrato OpenAPI](contracts/openapi/lots.yaml) · [Procedimiento de Do Test](#do-test--validación-manual)

## Implementación realizada

- Módulo `app/Modules/Lots`, modelos en `app/Models/Lots` y Policies en `app/Policies/Lots`.
- Siete tablas nuevas: `breeds`, `mortality_categories`, `flocks`, `flock_operations`, `flock_movements`, `mortality_records` y `egg_collections`. Incluyen FKs restrictivas, índices y restricciones de cantidades, estados y unicidad.
- 28 operaciones HTTP bajo `/api/v1`, con contrato público en español, FormRequests, Actions, Queries y Resources.
- ULID público para lotes, movimientos, mortalidades y recolecciones; IDs numéricos internos para relaciones. Razas, categorías y referencias de otros módulos conservan IDs numéricos.
- Cantidades enteras, bloqueo transaccional de lotes y galpones, control de versión, idempotencia por actor y auditoría síncrona.
- `PoultryHouseOccupancyProvider` obtiene la ocupación real desde Lotes. Las Actions existentes de Instalaciones impiden reducir la capacidad por debajo de la ocupación o desactivar un galpón ocupado.
- `RecordEggProductionAction` es la frontera pública de Inventario. La producción no escribe directamente en sus modelos ni en sus saldos.
- `Clock`, `SystemClock` y `FlockAge` centralizan los cálculos temporales. `config/lots.php` define `LOTS_TIMEZONE`, por defecto `America/Montevideo`.
- `LotsDemoSeeder` se integra a `LocalDemoDataSeeder`, sólo en ambiente local. Sus operaciones se ejecutan por las mismas Actions y su auditoría indica `source=seeder`.

### Decisiones sobre redistribución y cantidades

| Operación | Resultado |
|---|---|
| Alta | Nuevo lote con cantidad inicial igual a cantidad viva; movimiento `admission`. |
| Parcial hacia un galpón | Descuenta del origen y crea un lote independiente con código y ULID propios; movimiento `partial_new`. |
| Parcial hacia un lote existente | Descuenta del origen y aumenta el destinatario; movimiento `partial_existing`. Requiere la misma raza, ambos activos y sus dos versiones. |
| Total | Traslada el mismo lote completo a otro galpón; movimiento `total`. Conserva ULID, código, raza, procedencia y cantidad inicial. |
| Finalización | Registra el egreso de todas las aves remanentes, deja cantidad viva cero y estado `finished`; movimiento `departure`. No crea mortalidad. |

La redistribución conserva el total de aves de los lotes involucrados. La cantidad física máxima de un galpón nunca se incrementa ni decrementa: `disponible = capacidad física - suma de aves vivas`, incluyendo cuarentena.

El destinatario existente conserva su proveedor/origen, fecha de ingreso, edad administrativa y cantidad inicial. Puede recibir aves de otro proveedor si la raza coincide; la procedencia de las aves incorporadas queda identificada por el lote origen y las fotografías del movimiento. No se calcula una edad ponderada.

Un destinatario nuevo hereda raza, procedencia y fecha de ingreso; `establecido_en` indica el instante de creación por redistribución. No tiene `parent_id` ni genealogía. El movimiento, no una jerarquía de lotes, expresa su origen.

La redistribución total no borra el lote ni lo fusiona con uno existente. Se indica solamente otro galpón, sin código ni ULID de destino. Para añadir aves a un lote existente se utiliza la redistribución parcial; no existe un PATCH libre de cantidad ni un ingreso externo adicional sobre un lote ya creado.

Las cantidades iniciales son fotografías por lote, no un contador global de aves compradas: sumarlas después de crear lotes por redistribución produciría doble conteo. Para reconstruir ingresos o salidas se consulta el histórico de movimientos.

### Estados, edad e histórico

Estados estables: `active`, `quarantined` y `finished`. Un lote abierto puede alternar entre activo y cuarentena. Ambos pueden finalizar; no se reabre un finalizado. La cuarentena impide redistribuir, pero admite mortalidad y recolección mientras haya aves para esta última.

`edad_dias` son días calendario desde `fecha_ingreso` en `LOTS_TIMEZONE`; `semana_actual = floor(edad_dias / 7) + 1`. El día del ingreso es semana 1 y el séptimo día transcurrido es semana 2. No representa una edad biológica anterior al ingreso: no se modela fecha de nacimiento ni edad inicial adicional.

Los instantes usan PostgreSQL `timestamp with time zone` y se escriben con desplazamiento explícito. Esto evita que la zona del contenedor desplace movimientos, recolecciones y comparaciones. También se ajustó la serialización temporal de `InventoryMovement`, porque el ingreso de huevos debe conservar el mismo instante. No se reinterpretan ni migran registros previos de Inventario.

`ocurrido_en` admite ISO 8601 con segundos y zona, por ejemplo `2026-08-30T12:00:00-03:00`; si se omite se usa el reloj del servidor. No admite fechas futuras, anteriores a la existencia de la agrupación ni anteriores al último movimiento de aves del lote. Es una política conservadora: la sincronización offline no reordena silenciosamente hechos incompatibles.

Los movimientos contienen actor, operación, origen/destino, cantidad, fecha, motivo y fotografías `antes`/`despues`, indexadas por ULID. Se consultan por fecha de ocurrencia descendente y luego ID descendente; un lote finalizado sigue siendo consultable. Las fotografías guardan el estado de aquel momento, no recalculan la edad histórica.

No hay DELETE, `SoftDeletes` ni `isDeleted` para Lotes, mortalidad, recolección, razas o categorías. Una categoría o raza se desactiva sin romper referencias anteriores.

### Correcciones y compensaciones

- Una redistribución puede revertirse únicamente si ninguno de los lotes involucrados tiene operaciones posteriores. Se verifican sus versiones, estado y la capacidad necesaria. El movimiento original queda intacto y se agrega `redistribution_reversal`, que expone `movimiento_revertido_id`.
- Al revertir una redistribución a un lote nuevo, éste permanece consultable, vacío y finalizado. La reversión a uno existente restaura ambas cantidades sin cambiar sus metadatos. La reversión total restituye el galpón original.
- Mortalidad admite corregir cantidad, categoría y observaciones. Cancelar no borra el registro; su estado pasa a `cancelled`. La cantidad viva se ajusta por la diferencia y la restitución vuelve a validar capacidad en el galpón actual del lote.
- La mortalidad conserva el galpón y la fecha originales del hecho, aunque el lote se traslade después. Las rectificaciones se aplican ahora, mediante `mortality_correction` y auditoría; no se edita el movimiento original.
- Recolección admite corregir cantidad y observaciones. No permite cambiar lote, producto, ubicación ni fecha. La diferencia genera un ingreso o ajuste de inventario; una corrección sólo textual no genera movimientos de stock de cantidad cero.
- Cancelar una recolección retira su cantidad actual. Si esos huevos se consumieron o reservaron y el saldo no permite retirarlos, responde `409` y no cambia ninguna tabla de producción ni inventario.
- Los registros cancelados y los lotes finalizados no se corrigen ni reactivan. Todas las correcciones y cancelaciones requieren `motivo`.
- Un movimiento de corrección o finalización puede tener cantidad cero cuando no modifica aves; queda diferenciado por tipo y auditoría, sin fingir un ingreso.

### Transacciones, acceso y reintentos offline

Cada comando bloquea al actor para serializar sus claves de idempotencia, luego los lotes y galpones involucrados en orden estable. Inventario aplica sus propios bloqueos dentro de la misma transacción. Una falla de capacidad, stock o auditoría revierte la operación completa. Las transacciones reintentan deadlocks hasta tres veces.

Las escrituras exigen `Idempotency-Key` con UUID. Una clave queda asociada al actor y al contenido normalizado del comando. Repetir exactamente la solicitud devuelve la misma operación y sus fotografías originales, incluso si los recursos ya cambiaron. Reutilizar la clave con otro contenido devuelve `409`. Para una intención nueva se genera una clave nueva. No hay expiración automática del registro idempotente.

`version` representa el lote en cambios de estado, finalización, redistribución y registro de mortalidad/recolección. Las correcciones de mortalidad/recolección requieren `version` del registro y `version_lote`. La redistribución hacia un lote existente y las reversiones parciales requieren además `version_destino`.

Un cliente offline debe guardar ULID, payload, clave y versiones; enviar sus operaciones en orden; ante una pérdida de respuesta reintentar el mismo comando; y ante `409` consultar los recursos/histórico y pedir resolución al usuario. No debe modificar automáticamente la versión y reenviar una operación de cantidad. Esta entrega no implementa almacenamiento local mobile, colas del dispositivo, descarga incremental ni resolución visual de conflictos.

| Permiso | Alcance |
|---|---|
| `flocks.view` | Listados, detalle, semana actual e histórico de movimientos. |
| `flocks.manage` | Alta, código/observaciones, activación y cuarentena. |
| `flocks.redistribute` | Redistribuciones y sus reversiones. |
| `flocks.finalize` | Finalización con egreso. |
| `breeds.view` / `breeds.manage` | Lectura / administración de razas. |
| `mortality-categories.view` / `mortality-categories.manage` | Lectura / administración de categorías. |
| `mortality.view` / `mortality.manage` | Consultas / registro, corrección y cancelación de mortalidad. |
| `egg-collections.view` / `egg-collections.manage` | Consultas y métricas / comandos de recolección. |
| `audit.view` | Lectura del endpoint transversal de auditoría. |

`AdminUserSeeder` agrega los doce permisos nuevos al administrador. Son permisos funcionales globales para toda la empresa; no hay asignaciones usuario–UP ni restricciones por propietario. Un operador de producción no necesita permisos de movimiento manual de inventario para que su recolección legítima genere stock.

### Auditoría y eventos

`LotsHistory` usa `AuditRecorder` y `AuditEntryData`, con módulo `lots`, actor, sujeto, operación, traza, UP, resultado, motivo, cambios explícitos y snapshot permitido. Se escribe sincrónicamente dentro de la Action. La auditoría de Inventario comparte `operation_id` con la recolección. No se incluyen credenciales ni tokens.

Eventos de dominio después del commit: `FlockCreated`, `FlockUpdated`, `FlockStatusChanged`, `FlockRedistributed`, `FlockFinalized`, `MortalityRecorded`, `MortalityCorrected`, `EggsCollected` y `EggCollectionCorrected`. No sustituyen a la auditoría persistida ni constituyen un outbox durable para aplicaciones externas.

## Contrato de integración

Todas las rutas siguientes son relativas a `/api/v1`. Las altas, redistribuciones y registros de mortalidad/recolección responden `201`; modificaciones, finalizaciones, reversiones y cancelaciones responden `200`. Cada comando devuelve `data.id_operacion` y los recursos afectados (`lote`, `lote_destino`, `movimiento`, `mortalidad`, `recoleccion` o `catalogo`).

| Método | Ruta | Uso |
|---|---|---|
| GET / POST | `/lotes` | Listar / admitir un lote. |
| GET / PATCH | `/lotes/{lote}` | Detalle / modificar código u observaciones. |
| PATCH | `/lotes/{lote}/estado` | Activo o cuarentena. |
| POST | `/lotes/{lote}/finalizacion` | Egreso y finalización. |
| POST | `/lotes/{lote}/redistribuciones` | Parcial a nuevo/existente o traslado total. |
| POST | `/redistribuciones/{redistribucion}/reversiones` | Compensar una redistribución. |
| GET | `/lotes/{lote}/historial` | Movimientos y fotografías históricas. |
| GET | `/galpones/{galpon}/lotes` | Lotes actualmente ubicados en el galpón. |
| GET / POST | `/razas` | Listar / crear razas. |
| PATCH | `/razas/{raza}` | Nombre o vigencia de raza. |
| GET / POST | `/categorias-mortalidad` | Listar / crear categorías. |
| PATCH | `/categorias-mortalidad/{categoria}` | Nombre o vigencia de categoría. |
| GET | `/mortalidades` | Consulta consolidada. |
| GET / PATCH | `/mortalidades/{mortalidad}` | Detalle / corrección. |
| GET / POST | `/lotes/{lote}/mortalidades` | Histórico / registro. |
| POST | `/mortalidades/{mortalidad}/cancelacion` | Cancelación auditada. |
| GET / POST | `/lotes/{lote}/recolecciones` | Histórico / registro con stock. |
| GET / PATCH | `/recolecciones/{recoleccion}` | Detalle / corrección con stock. |
| POST | `/recolecciones/{recoleccion}/cancelacion` | Cancelación con compensación de stock. |
| GET | `/lotes/{lote}/metricas` | Totales, promedio, agrupaciones diarias y semanales. |

Listados: `pagina` de 1 a 100000 y `por_pagina` de 1 a 100, por defecto 50. El listado de lotes admite búsqueda por código, estado, raza, proveedor, galpón, UP y fechas de ingreso. El histórico admite `tipo` y fechas. Mortalidad admite lote, galpón histórico, estado y fechas; recolección admite esos filtros dentro del lote. Catálogos admiten búsqueda por nombre y estado. Las consultas incluyen finalizados/cancelados por defecto salvo filtro explícito.

Métricas: por defecto últimos 30 días calendario, con máximo 366 días por consulta. `fecha_desde`/`fecha_hasta` son inclusivas. Sólo cuentan recolecciones vigentes con su cantidad corregida. El promedio incluye días sin producción; las series omiten días/semanas sin registros. `por_semana` agrupa por semana calendario iniciada en lunes, no por semana de edad del lote. No se publica porcentaje de postura o mortalidad con denominadores históricos inventados.

Errores: `401` sin sesión, `403` sin permiso, `404` recurso inexistente, `422` formato/campo no permitido y `409` conflicto de negocio o versión. Se conserva el formato transversal `application/problem+json`; los identificadores de estado/evento/permisos y metadatos técnicos siguen siendo estables en inglés.

## Datos demo y despliegue

Migración aditiva: `database/migrations/2026_08_30_230431_create_lots_tables.php`. No ejecuta migraciones de datos del código viejo ni modifica la capacidad de instalaciones existentes.

```bash
docker compose -f compose.dev.yaml exec -T api php artisan migrate --no-interaction
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class=DatabaseSeeder --no-interaction
```

El segundo comando aplica las semillas generales y sólo carga demostración cuando `APP_ENV=local`. Debe estar configurado `ADMIN_PASSWORD`. En una base local ya preparada puede ejecutarse sólo `db:seed --class='Database\Seeders\Lots\LotsDemoSeeder' --no-interaction`; requiere administrador, granjas, galpones y proveedores demo previos. Para incorporar permisos en un entorno sin demo, ejecutar el procedimiento habitual de `AdminUserSeeder` y revisar la asignación a roles funcionales.

No se necesita ni debe usarse `migrate:fresh` sobre una base con datos que deban conservarse. El rollback elimina las siete tablas y su histórico: no es una corrección de negocio ni un mecanismo de recuperación.

| Código demo | Estado final | Aves vivas | Situación |
|---|---|---:|---|
| `DEMO-LOT-A` | `active` | 70 | Admitió 100, redistribuyó 20 y 10 y se trasladó íntegro a Galpón Lotes Demo. |
| `DEMO-LOT-B` | `active` | 48 | Admitió 40, recibió 10, registró 2 bajas y produjo 12 huevos. |
| `DEMO-LOT-C` | `finished` | 0 | Recibió 20 como lote nuevo y finalizó con egreso de esas aves. |
| `DEMO-LOT-D` | `quarantined` | 25 | Origen propio, otra raza, en Galpón Lotes Demo. |

El producto `HUEVO-LOTES-DEMO` y la ubicación `Cámara de huevos - Lotes Demo` aíslan los 12 huevos de los saldos base que recarga el seeder general. Las claves estables del seeder impiden repetir movimientos o sobrescribir fechas, versiones y correcciones realizadas después por un usuario. No se promete restaurar la demo original después de modificarla: para ensayos repetidos crear códigos QA nuevos.

## Verificación automatizada

La cobertura del módulo está en `tests/Feature/Lots` y `tests/Unit/Lots`. Incluye validación HTTP, permisos, capacidad, metadatos del destinatario, traslados totales, compensaciones, idempotencia, versiones, fechas locales, stock, rollback por falla de auditoría y carga demo repetida.

`LotsConcurrencyTest` usa procesos PHP independientes con PostgreSQL real: admisiones que compiten por un galpón, mortalidades con la misma versión y reintentos simultáneos. Se ejecuta únicamente si la conexión usa la base `gam_lots_test`; fuera de ella se omite para impedir una limpieza accidental de desarrollo.

Crear esa base vacía una sola vez, usando las credenciales locales de PostgreSQL (el usuario predeterminado del compose es `gam`):

```bash
docker compose -f compose.dev.yaml exec -T postgres createdb -U gam gam_lots_test
```

No ejecutar dos suites simultáneamente contra la misma base. El comando siguiente usa caché y sesiones en memoria para no compartir Redis con desarrollo:

```bash
docker compose -f compose.dev.yaml exec -T -e DB_HOST=postgres -e DB_DATABASE=gam_lots_test -e APP_ENV=testing -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e PULSE_ENABLED=false api php artisan test --compact tests/Feature/Lots tests/Unit/Lots
```

Para la regresión completa, usar los mismos argumentos y omitir las dos rutas de tests. Las pruebas de demo simulan el disco local para no escribir exportaciones en el almacenamiento normal.

```bash
docker compose -f compose.dev.yaml exec -T api php -d memory_limit=512M vendor/bin/phpstan analyse -c .phpstan-lots.neon --no-progress
docker compose -f compose.dev.yaml exec -T api php vendor/bin/pint --dirty --format agent
docker compose -f compose.dev.yaml exec -T api php artisan route:list --name=api.v1.lots --except-vendor
git diff --check
```

Si la imagen no contiene Git, Pint debe recibir la lista exacta de archivos PHP nuevos/modificados obtenida desde el repositorio del host. No formatear archivos ajenos al cambio.

### Resultados de esta entrega

- Regresión completa sobre PostgreSQL en `gam_lots_test`: **151 pruebas aprobadas, 981 aserciones**, en 181,71 segundos. Incluye 65 casos del módulo y 86 casos existentes.
- Tres pruebas de concurrencia con procesos independientes aprobadas: competencia por capacidad, conflicto de versión y reintentos simultáneos idempotentes.
- Contrato OpenAPI verificado mediante pruebas: YAML válido, referencias internas resueltas, correspondencia de las 28 rutas con Laravel y campos de los Resources públicos.
- PHPStan/Larastan con `.phpstan-lots.neon`, nivel 5: sin errores en el módulo y las integraciones incluidas.
- Pint aplicado a los 122 archivos PHP del cambio. La imagen no contiene Git, por lo que `--dirty` no pudo resolver el conjunto; se utilizó la lista exacta obtenida con Git desde el host.
- `git diff --check`: sin errores. No se agregaron dependencias.
- Migración aditiva `2026_08_30_230431_create_lots_tables` aplicada en el ambiente local, sin reiniciar ni vaciar sus datos. Las pruebas utilizaron exclusivamente la base aislada; el seeder demo fue probado allí y no se ejecutó manualmente sobre desarrollo.

Las excepciones controladas que aparecen en la salida de las pruebas de rollback son intencionales: verifican que una falla de auditoría deshace la operación completa. La aceptación humana **Do Test sigue pendiente**; estos resultados automatizados no la reemplazan. No se realizó ninguna operación en Notion.

## Do Test — validación manual

### 1. Preparación y evidencia

Responsable: una persona diferente de quien implementó, con ambiente local o QA y permisos suficientes. No ejecutar este recorrido sobre producción. Registrar fecha, responsable, rama/commit, URL, `LOTS_TIMEZONE`, versión de PostgreSQL y resultados. No guardar contraseñas ni tokens en capturas o en Notion.

1. Levantar el stack según README y aplicar la migración aditiva y semillas locales anteriores.
2. Importar `contracts/openapi/lots.yaml` en Postman/Insomnia. Definir `base_url` con el origen real del ambiente, sin `/api/v1`. Todas las rutas de este recorrido añaden `/api/v1`.
3. Iniciar sesión con `POST /api/v1/autenticacion/inicio-sesion`: `correo_electronico`, `password` y `device_name: "do-test-lotes"`. Usar las credenciales configuradas con `ADMIN_EMAIL`/`ADMIN_PASSWORD`; no hay una contraseña nueva creada por este módulo.
4. Guardar el `access_token` como variable secreta y usar `Authorization: Bearer <token>`, `Accept: application/json` y `Content-Type: application/json`.
5. Para cada comando generar un UUID (`New-Guid` en PowerShell), copiarlo en `Idempotency-Key` y conservarlo junto al payload y resultado. No usar una variable aleatoria que cambie automáticamente al probar un reintento.
6. Usar un sufijo único para códigos/nombres, por ejemplo `QA-20260830-01`. Conservar las variables de IDs y versiones devueltas. Las plantillas JSON siguientes requieren sustituir los marcadores; no enviar sus nombres literalmente.
7. Crear o elegir tres galpones operativos `G1`, `G2`, `G3`, con al menos 200 plazas disponibles cada uno. Para crearlos usar `POST /api/v1/unidades-productivas/{id}/galpones` con `{"nombre":"QA-G1-<sufijo>","capacidad_aves":200}` y repetir. Pueden pertenecer a UP diferentes, que deben estar activas.
8. Elegir un proveedor activo, un producto activo de tipo `egg`, unidad `unit`, `controla_stock=true`, y una ubicación activa de stock. Guardar los IDs numéricos. Anotar el saldo físico inicial del producto en esa ubicación como `S0` con `GET /api/v1/inventario/saldos?producto_id=<id>&ubicacion_stock_id=<id>`; si no existe fila, `S0=0`.

Por cada caso guardar: método/ruta, cuerpo sin credenciales, clave, estado HTTP, respuesta, `data.id_operacion`, cantidades/versiones antes y después, y evidencia de auditoría. En errores confirmar mediante GET que no cambió ninguna cantidad ni versión.

### 2. Catálogos, alta, lectura y reintento

Crear una raza con `POST /razas` y `{"nombre":"QA Ponedoras <sufijo>"}`. Crear otra raza para incompatibilidad. Crear una categoría con `POST /categorias-mortalidad` y `{"nombre":"QA Observación <sufijo>"}`. Todas las rutas de aquí en adelante son bajo `/api/v1`.

Esperado: `201`, `data.catalogo.id`, estado `active`, versión 1. Repetir mismo nombre variando espacios/mayúsculas con otra clave: `409`. Probar listar y filtrar por nombre/estado; luego renombrar una categoría con PATCH y su versión, y comprobar nueva versión y auditoría. Reservar la raza principal activa para el resto del recorrido.

Crear A en G1:

```json
{
  "codigo": "QA-A-<sufijo>",
  "raza_id": 1,
  "proveedor_id": 1,
  "cantidad_inicial": 100,
  "fecha_ingreso": "2026-08-23",
  "galpon_id": 1,
  "observaciones": "Lote origen de Do Test"
}
```

Sustituir IDs por los seleccionados y fecha por hoy menos siete días en Montevideo. Esperado: `201`, cantidad inicial/viva 100, versión 1, semana 2 y movimiento `admission`. Guardar A=`data.lote.id` (ULID), clave, payload y operación. La capacidad de G1 sigue siendo 200.

Repetir exactamente el alta de A con su misma clave: respuesta idéntica y un único lote/movimiento/auditoría. Repetir con la misma clave pero cantidad 99: `409`, A sigue con 100.

Crear B con 40 aves, la misma raza, G2 y fecha hoy menos catorce días; usar otro proveedor si está disponible. Guardar todos sus metadatos originales. Alternativamente usar `origen: "Cría propia QA"` sin `proveedor_id`. Esperado: semana 3, versión 1. Esta diferencia de fechas debe conservarse al incorporar aves a B.

Consultar `GET /lotes`, `GET /lotes/{A}`, `/galpones/{G1}/lotes` y `/lotes?buscar=QA-A-<sufijo>&por_pagina=1`. Comprobar ULID, datos en español, filtros, metadatos de paginación y acceso a distintas UP con el mismo permiso funcional.

### 3. Redistribución parcial hacia un lote nuevo

`POST /lotes/{A}/redistribuciones` con clave nueva:

```json
{"version":1,"cantidad":20,"galpon_destino_id":2,"codigo_destino":"QA-C-<sufijo>","motivo":"Redistribución parcial QA"}
```

Sustituir G2 y versión actual de A. Esperado: A=80 y versión 2; C=20, cantidad inicial 20, versión 1; C tiene otro ULID, hereda raza/procedencia/fecha de A y no expone padre/hijo. Total A+B+C=140. Las capacidades físicas de los galpones no cambian.

Guardar C=`data.lote_destino.id` y el ULID de movimiento. Consultar el histórico de A y C: ambos muestran `partial_new`, origen A/destino C, cantidad 20 y fotografías 100→80 para A. Repetir mismo comando/clave: no crea otro C ni descuenta otras 20 aves.

### 4. Incorporación a un lote existente

`POST /lotes/{A}/redistribuciones` con clave nueva:

```json
{"version":2,"cantidad":10,"lote_destino_id":"<ULID B>","version_destino":1,"motivo":"Agregar aves a B"}
```

Esperado: A=70/version 3, B=50/version 2, C=20. B conserva cantidad inicial 40, código, proveedor/origen y fecha original; no recibe la edad de A. Total 140. Hay un movimiento `partial_existing` y auditoría de los dos lotes con el mismo `id_operacion`.

Probar otro destinatario de raza diferente: `409`. Repetir con versión obsoleta de A o B y clave nueva: `409`. Sin `version_destino`: `422`. Enviar simultáneamente galpón y lote destino: `422`. Indicar A como su propio destino: `409`.

### 5. Traslado total sin borrar

`POST /lotes/{A}/redistribuciones` con clave nueva:

```json
{"version":3,"cantidad":70,"galpon_destino_id":3,"motivo":"Traslado íntegro a G3"}
```

Esperado: A conserva ULID, código, cantidad inicial 100 y cantidad viva 70; ahora está en G3, con versión 4 y la UP de G3. No aparece un lote nuevo, A no desaparece del listado y su historial incluye `total` desde G1 a G3. G1 queda libre de A.

Probar un total hacia B, un total al mismo galpón o un total con `codigo_destino`: `409`. No se interpreta como fusión ni borrado.

### 6. Reversiones y capacidad

Crear un lote separado R de 30 aves en G1. Trasladar parcialmente 5 a un lote nuevo S en G3 y guardar el movimiento. Sin ninguna otra operación sobre R/S, enviar:

```text
POST /redistribuciones/{movimiento}/reversiones
```

```json
{"version":2,"version_destino":1,"motivo":"Galpón seleccionado por error"}
```

Esperado: `200`, R vuelve a 30, S permanece consultable con 0 y `finished`. El movimiento original no cambia. Aparece `redistribution_reversal` con `movimiento_revertido_id` apuntando al original. Repetir la misma clave devuelve la misma respuesta; repetir con clave nueva no vuelve a restituir aves.

Repetir el caso de reversión con traslado total: R vuelve a su galpón original manteniendo el ULID. Repetir con destinatario existente: se restauran ambas cantidades y metadatos. En una tercera redistribución, editar observaciones de uno de los lotes antes de revertir: ahora debe responder `409` por operaciones posteriores.

Crear un galpón QA de capacidad 5, admitir 5 y comprobar: alta adicional de 1 o traslado entrante de 1 → `409`; PATCH de capacidad a 4 → `409`; cambio de galpón a `inactive` → `409`. Una redistribución parcial entre dos lotes dentro del mismo galpón lleno no aumenta ocupación y debe funcionar. Un origen en `maintenance` puede evacuarse hacia un destino operativo; un destino en mantenimiento no recibe aves.

### 7. Mortalidad, corrección, cancelación e historial

Sobre B (50 aves/version 2), registrar:

```text
POST /lotes/{B}/mortalidades
```

```json
{"version":2,"cantidad":2,"categoria_mortalidad_id":1,"observaciones":"Recuento QA"}
```

Esperado: `201`, B=48/version 3, mortalidad M versión 1 y movimiento `mortality`. La cantidad inicial de B sigue siendo 40. Guardar M=`data.mortalidad.id`.

Corregir `PATCH /mortalidades/{M}`:

```json
{"version":1,"version_lote":3,"cantidad":1,"motivo":"Se confirmó una sola baja"}
```

Esperado: `200`, B=49/version 4, M cantidad 1/version 2. El primer movimiento de 2 bajas permanece intacto; se agrega compensación por 1 ave. Cancelar `POST /mortalidades/{M}/cancelacion` con `version:2`, `version_lote:4`, motivo y nueva clave: B=50/version 5, M=`cancelled`/version 3, sin borrado.

Consultar `/mortalidades?lote_id={B}&galpon_id={G2}&estado=cancelled&fecha_desde=<hoy>&fecha_hasta=<hoy>` y el histórico de B. Si B se traslada después, M debe continuar asociado al galpón original G2.

En otro lote/galpón pequeño: registrar bajas, ocupar las plazas liberadas con otro lote e intentar cancelar las bajas. Debe dar `409` y conservar mortalidad, cantidad viva y versiones. También probar categoría inactiva, cantidad mayor que aves vivas, doble cancelación y versiones viejas: nunca deben producir cantidades negativas ni auditorías de éxito adicionales.

### 8. Recolección, métricas y stock

Consultar de nuevo B y utilizar su versión actual. Registrar en `/lotes/{B}/recolecciones`:

```json
{"version":5,"cantidad":12,"producto_id":1,"ubicacion_stock_id":1,"observaciones":"Turno QA"}
```

Sustituir producto/ubicación seleccionados. Esperado: `201`, recolección E versión 1; B permanece con 50 aves y aumenta su versión a 6; saldo físico `S0 + 12`, un movimiento de inventario con referencia `egg_collection` y el mismo `id_operacion` que la respuesta. La cantidad viva nunca aumenta por producir huevos.

Reintentar el mismo comando/clave: no aumenta el saldo. Corregir `PATCH /recolecciones/{E}` con `version:1`, `version_lote:6`, `cantidad:10`, motivo y clave nueva: saldo `S0 + 10`, E versión 2 y B versión 7. Corregir sólo observaciones con ambas versiones actuales: se audita, pero no aparece un ajuste de stock cero.

Consultar `/lotes/{B}/metricas?fecha_desde=<hoy>&fecha_hasta=<hoy>`: total 10 para este escenario sin otras recolecciones vigentes, promedio 10, series consistentes. Cancelar E con `POST /recolecciones/{E}/cancelacion`, motivo y versiones actualizadas: saldo vuelve a `S0`; E permanece `cancelled`; métricas excluyen sus huevos. No se borra ningún movimiento previo.

En un escenario separado con producto/ubicación sin saldo previo: recolectar 12, retirar o reservar 10 mediante Inventario e intentar cancelar los 12. Debe devolver `409`, sin alterar E, su versión ni los saldos. No cancelar una reserva o salida de otra operación automáticamente para forzar la prueba.

Probar producto no huevo, producto inactivo, no inventariable, unidad distinta de `unit` y ubicación inactiva: `409`, sin recolección ni ingreso parcial. Un lote vacío o finalizado no puede registrar recolecciones.

### 9. Cuarentena, finalización, seguridad y fechas

| Caso | Cómo probar | Esperado |
|---|---|---|
| Cuarentena | PATCH `/lotes/{B}/estado` con versión actual, `estado:quarantined` y motivo. | `200`; conserva aves y ocupación. Redistribuir B devuelve `409`; mortalidad/recolección válida sigue permitida. |
| Reactivación | PATCH de B a `active` con nueva clave/versión y motivo. | `200`, nueva versión. |
| Finalización | POST `/lotes/{C}/finalizacion` con versión 1 y motivo, si C no fue modificado. | `200`, C=0/`finished`, egreso 20, no mortalidad extra, consulta e histórico disponibles. |
| Reapertura | Intentar cambiar C finalizado a `active`. | `409`, sin cambios. |
| Sin autenticación | GET `/lotes` sin token. | `401`. |
| Sin permiso | Usuario activo sin `flocks.view` consulta lotes; sin permiso de escritura intenta un comando. | `403`; no hay escrituras. Repetir para mortalidad, recolección y catálogos. |
| Auditoría restringida | Usuario sin `audit.view` consulta `/auditoria/entradas`. | `403`, aunque tenga permisos de producción. |
| Referencia inexistente | GET de un ULID de lote inexistente. | `404`. |
| Campos protegidos | PATCH de lote con `cantidad_viva`, `cantidad_inicial`, `estado` o identificadores internos. | `422`; las cantidades sólo cambian por Actions específicas. |
| Enteros positivos | En altas/registros enviar 0, negativos, fracciones o más de 2147483647. | `422` y ningún cambio. |
| Clave obligatoria | Omitir Idempotency-Key o enviar una cadena que no sea UUID. | `422`. |
| Código duplicado | Otra alta con el código de A y clave nueva. | `409`. |
| Fecha futura | Ingreso de mañana o `ocurrido_en` posterior a ahora. | `409`; un formato o fecha imposible da `422`. |
| Orden histórico | Registrar mortalidad/recolección anterior a la última redistribución del lote. | `409`; no se reasigna al galpón incorrecto. |
| Zona local | En un lote sin movimientos posteriores, registrar un instante pasado a `01:00:00+00:00`. | Se consulta en el día anterior de Montevideo; misma interpretación en métricas y filtros. |
| Paginación | `por_pagina=101`, `pagina=0` o fechas invertidas. | `422`. Los enlaces válidos conservan filtros. |
| Métricas acotadas | Solicitar más de 366 días. | `409`; no ejecuta una consulta ilimitada. |
| Baja de catálogo | Desactivar la raza principal después del recorrido e intentar otra admisión. | Referencias existentes siguen consultables; alta nueva con raza inactiva da `409`. |

### 10. Concurrencia, auditoría y aceptación

Ejecutar `LotsConcurrencyTest` con el comando aislado documentado, o coordinar dos usuarios con claves diferentes y la misma versión del lote. Sólo una operación de cantidad debe confirmarse; la otra debe recibir conflicto. Para dos altas en el mismo galpón cuya suma supera capacidad, sólo una debe entrar. Para dos envíos simultáneos idénticos con el mismo actor/clave, ambos reciben la misma operación y existe un único efecto.

Para cada operación significativa consultar:

```text
GET /api/v1/auditoria/entradas?operation_id=<data.id_operacion>&por_pagina=100
GET /api/v1/auditoria/entradas?log_name=lots&por_pagina=100
```

Revisar actor, módulo, UP, resultado, traza, snapshot y valores anteriores/nuevos. Redistribuciones con dos lotes tienen entradas de ambos; recolecciones comparten operación con Inventario. No debe aparecer información sensible. Las correcciones agregan entradas, nunca alteran las anteriores. Para fallas de auditoría utilizar las pruebas automatizadas de rollback; no deshabilitar auditoría ni manipular servicios de producción para simularlas.

La aceptación requiere todos los casos aplicables aprobados, conservación de aves demostrada, saldo de huevos conciliado, ausencia de borrados, errores sin efectos parciales, seeder repetible y evidencia guardada. Si existe un caso bloqueado, registrar motivo y dejar Do Test pendiente; no considerarlo aprobado por pasar la suite automática.

### 11. Registro manual en Notion

No se creó, modificó ni consultó contenido de Notion para esta entrega. La persona con acceso debe:

1. Abrir el módulo exacto **`05 — Lotes y cría`**.
2. Incorporar esta documentación y el enlace al contrato OpenAPI desde el commit o PR que se publique posteriormente.
3. Registrar alcance implementado, decisiones de redistribución sin hijos y traslado total sin borrado, dependencias y límites offline.
4. Adjuntar evidencia por caso: responsable, fecha, ambiente, commit, solicitudes anonimizadas, estados HTTP, operaciones, auditoría y conciliación de cantidades/stock.
5. Completar **Do Test** únicamente después de ejecutar y aprobar la validación manual; reportar fallos con pasos reproducibles y dejarlos pendientes hasta su corrección.

## Fuera de alcance

Interfaz Angular/mobile, almacenamiento y sincronización del dispositivo, outbox externo, genealogía, fusiones totales entre lotes, compras/admisiones adicionales directas a un lote existente, porcentajes históricos de productividad sin denominadores confiables y migración del sistema viejo. Notion queda expresamente a cargo del usuario con acceso.
