# Catálogo de medicamentos — implementación

La definición confirmada reemplaza el plan inicial de intervenciones por lote. Un medicamento es una ficha global de catálogo con **nombre, descripción y proveedor**. Las aplicaciones se implementan en Planes de manejo; el vínculo al inventario físico queda reservado y deshabilitado para una entrega futura. Las dosis son una posible ampliación futura, todavía no confirmada.

**Estado funcional: catálogo de Medicamentos implementado; módulo 06 — Manejo productivo y sanidad aún incompleto.** Faltan las demás secciones del módulo. El seguimiento en [notion.md](../notion.md) registra este avance parcial y no propone mover la tarjeta del módulo completo a `Do Test`. La propiedad técnica del catálogo corresponde a `SuppliersAndCatalogs`.

## Alcance

- Propiedad de SuppliersAndCatalogs, con modelo Medicine y tabla medicines.
- Alta y consulta exclusivamente para usuarios activos con rol admin.
- Nombres repetidos permitidos; cada ficha tiene ULID propio.
- Proveedor obligatorio del catálogo existente, activo al crear. Su desactivación no oculta fichas anteriores ni impide repetir un alta ya confirmada.
- El formulario de alta mantiene nombre, descripción y proveedor, sin crear producto de inventario, ubicación ni saldo físico. No incorpora dosis, edición o borrado de la ficha.
- Las altas nuevas devuelven `product_id: null`. Se conserva cualquier vínculo anterior y su historia sin reclasificación. La preparación no crea vínculos con inventario. Véase la [guía de almacenamiento](inventory-storage-implementation.md). Las aplicaciones no registran cantidad ni modifican el contador clínico global anterior; ese contador queda sólo como historia y para compensaciones de aplicaciones antiguas.
- El sistema conserva la fecha automática de registro y los nombres de proveedor y administrador conocidos al crear.

## Contrato HTTP

La aplicación sanitaria tiene su propio [contrato y reglas](#aplicaciones-sanitarias-sin-cantidad-ni-stock); el contrato de esta sección corresponde al catálogo.

Fuente: [medication.yaml](../../contracts/openapi/medication.yaml).

| Método y ruta | Comportamiento |
| --- | --- |
| POST /api/v1/medicines | Registra una ficha y devuelve su representación completa con 201. |
| GET /api/v1/medicines | Lista fichas completas con paginación, búsqueda por nombre y filtro de proveedor. |

Alta: name —texto obligatorio, máximo 160 caracteres—, description —texto obligatorio, máximo 5000— y supplier_id —identificador entero positivo—. Nombre y descripción no pueden quedar vacíos después de recortar espacios exteriores.

El encabezado Idempotency-Key debe contener un UUID. No se admite como campo del cuerpo. Mismo actor, clave y contenido normalizado devuelven exactamente la ficha original con 201; otro contenido produce 409. Otra clave o actor permite otra ficha, incluso con el mismo nombre.

Consulta: search, supplier_id, page —1 a 100000— y per_page —1 a 100, por defecto 50—. Búsqueda parcial literal sin distinguir mayúsculas; los símbolos de porcentaje y guion bajo no actúan como comodines suministrados por el usuario. Orden descendente por creación e ID interno; enlaces conservan filtros. Un proveedor sin coincidencias devuelve una página vacía.

Salida allowlist:

- id, name, description;
- supplier.id y supplier.name_at_registration;
- created_by.id y created_by.name_at_registration;
- created_at, expresado siempre en UTC;
- operation_id, compartido con auditoría.

Los nombres conservados se identifican como tales; no representan necesariamente el nombre actual de la referencia. No se exponen claves de idempotencia, hashes, correos ni otros atributos del usuario.

Errores en español y application/problem+json: 401 sin autenticación, 403 sin administrador activo, 404 proveedor inexistente, 409 proveedor inactivo o clave reutilizada con otros datos, 422 entradas inválidas. Se rechazan campos y filtros desconocidos.

## Persistencia, concurrencia y auditoría

La migración aditiva crea medicines; no altera tablas existentes. Incluye IDs, los tres datos del catálogo, snapshots de nombres, creador, UUID de operación, clave/hash de idempotencia y timestamps. Las FKs de proveedor y usuario usan RESTRICT.

Restricciones: unique de ULID, operación y combinación created_by/idempotency_key; CHECK de nombres/descripciones no vacíos y sus longitudes. Índices para orden cronológico global y por proveedor. No hay unicidad por nombre.

CreateMedicineAction:

1. Autoriza y valida invariantes para invocaciones HTTP y desde seeders.
2. Normaliza contenido y calcula un hash determinista.
3. Bloquea al actor, vuelve a autorizarlo y resuelve reintentos antes de consultar al proveedor mutable.
4. Obtiene el proveedor con bloqueo compartido, verifica su estado y guarda la ficha.
5. Registra medicine_created en suppliers_and_catalogs mediante AuditRecorder y AuditEntryData, dentro de la misma transacción.
6. Confirma todo o revierte todo, incluida la disponibilidad de la clave para reintentar.

El alta genera explícitamente su ULID: la entrada oficial DatabaseSeeder suprime eventos de modelos y no puede depender de un evento creating. La respuesta normaliza UTC para que un replay no cambie de representación por la zona horaria de PostgreSQL.

No se escriben datos ni movimientos de inventario, productos, lotes o instalaciones. La UP de auditoría queda vacía porque la ficha no tiene ubicación.

## Integración y datos locales

- MedicinePolicy está registrada en el provider existente de Catálogos.
- El controller sólo adapta FormRequests, Action/Query y Resource.
- La lectura usa snapshots y no hace consultas por registro.
- El catálogo aparece en el selector Swagger de Compose.
- MedicineDemoSeeder carga dos fichas ficticias, con claves estables y a través de la Action, después de crear el proveedor demo «Agroinsumos del Sur».
- El seeder sólo se ejecuta en local, no sustituye datos históricos y puede repetirse sin duplicar fichas ni auditoría.
- No se agregaron dependencias ni permisos asignables a otros roles.

## Validación

La cobertura está en las clases MedicineEndpointTest, MedicineContractTest, MedicineConcurrencyTest y MedicineDemoSeederTest, bajo tests/Feature/SuppliersAndCatalogs.

Incluye alta, límites, errores localizados, autorización HTTP y de Action, nombres repetidos, normalización, claves por actor, replay después de modificar/desactivar referencias, rollback de auditoría, paginación, búsqueda literal, snapshots, ausencia de efectos ajenos, contrato HTTP real y seeder oficial.

Para pruebas normales, usar la receta del [README](../../README.md), que ejecuta PHPUnit dentro de `api` y deriva `<DB_DATABASE>_testing` desde la base normal configurada. Seleccionar las pruebas de endpoint, contrato y seeder.

Para concurrencia, usar la misma base `<DB_DATABASE>_testing`; el archivo de configuración separado conserva el grupo de concurrencia sin crear una segunda base:

    vendor/bin/phpunit --configuration phpunit.medicines-concurrency.xml

El test y los procesos secundarios comprueban explícitamente el nombre de la base. La configuración normal omite ese caso si no usa la base exclusiva; una omisión no equivale a validación de concurrencia.

Análisis estático:

    php -d memory_limit=512M vendor/bin/phpstan analyse -c .phpstan-medicines.neon --no-progress

Formato: vendor/bin/pint --dirty --format agent. El contenedor actual no incluye Git, por lo que esa opción no está disponible: se calculó la lista de PHP modificados y nuevos con Git en Windows y se pasó explícitamente a Pint con --format agent, sin instalar dependencias. Inspección: git diff --check.

Comprobaciones completadas el 2026-09-05:

- 52 pruebas aprobadas, 826 aserciones: catálogo, contrato, demo y regresiones seleccionadas de Proveedores/Catálogos, Lotes, administrador y demo de mantenimiento.
- Concurrencia ejecutada por separado en la misma base `<DB_DATABASE>_testing`: 1 prueba aprobada, 4 aserciones; ambos procesos reciben la misma operación y queda una sola ficha auditada.
- Pint aplicado a los 20 archivos PHP del cambio; la anotación final de Supplier también fue formateada.
- Larastan, nivel 5 y configuración del catálogo: sin errores.
- Revisión del diff sin problemas de espacios; preservada la modificación preexistente de la skill de testing.

## Activación local

Activación realizada el 2026-09-05: migración aditiva aplicada, MedicineDemoSeeder ejecutado y servicio swagger-ui recreado con el contrato nuevo. Artisan confirmó los endpoints GET/POST y el esquema de medicines con sus índices y referencias RESTRICT. No se reinicializó la base ni se eliminaron volúmenes.

Aplicar la nueva migración con php artisan migrate --no-interaction. Para datos demo en una instalación local ya sembrada:

    php artisan db:seed --class='Database\Seeders\SuppliersAndCatalogs\MedicineDemoSeeder' --no-interaction

Recrear únicamente el servicio swagger-ui si sigue mostrando su configuración anterior de URLs. No es necesario reconstruir imágenes ni eliminar volúmenes.

Prueba manual propuesta: iniciar sesión como administrador, seleccionar un proveedor activo, crear una ficha, consultar y filtrar el catálogo, repetir la misma clave y comprobar que se conserva un único registro. La aceptación manual de producto no se sustituye por las pruebas automatizadas.

## Evidencia del entorno y documentación

Verificados PHP 8.5.10, Laravel 13.29.0, PostgreSQL 18.4, Sanctum 4.3.3, Permission 8.3.0, Activitylog 5.1.0 y PHPUnit 12.5.34. La inspección inicial confirmó que no existían rutas ni tablas de Medicación.

Boost MCP no está expuesto en esta sesión; se inspeccionó el esquema con Artisan y se consultó la documentación oficial de [validación](https://laravel.com/framework/docs/13.x/validation) y [transacciones](https://laravel.com/framework/docs/13.x/database), además de los patrones de pruebas existentes.

## Aplicaciones sanitarias sin cantidad ni stock

Cambio del 30/10/2026, compartido por medicamentos y vacunas. Los endpoints `POST /api/v1/flocks/{flock}/medicaciones` y `POST /api/v1/flocks/{flock}/vacunaciones` crean únicamente un registro sanitario. No requieren existencia, cantidad aplicada, ubicación de inventario ni saldo clínico. Enviar `quantity` o `stock_consumption` con contenido devuelve 422; las Actions también bloquean esos campos en llamadas internas, incluso si se habilita la bandera futura de sanidad.

Cada aplicación requiere el ULID de catálogo (`medicine_id` o `vaccine_id`), `occurred_at` con zona horaria y el encabezado `Idempotency-Key` con UUID. Admite `notes`, responsable opcional y vínculo explícito `plan_activity_id`. Las vacunas conservan además `vaccine_batch` opcional. No se crea ningún movimiento, balance ni advertencia de saldo negativo.

| Situación | Motivo `reason` |
| --- | --- |
| Sin `plan_activity_id` | Obligatorio, entre 1 y 500 caracteres después de recortar espacios. Las notas no lo sustituyen. |
| Con actividad válida del plan vigente del lote y del tipo de manejo correspondiente | Opcional; puede omitirse o ser nulo. Si se informa, conserva el límite de 500 caracteres. |
| Actividad ajena, obsoleta, inexistente o de otro tipo | Conflicto 409; no registra la aplicación. |
| Actividad con referencia de catálogo diferente del medicamento o vacuna seleccionados | Conflicto 409; para registrar una aplicación fuera del plan se omite el vínculo y se informa el motivo. |

La condición de prevista depende del vínculo validado; no se infiere automáticamente por fecha o coincidencia de catálogo. Se conserva la clasificación `outside_plan` del historial. Una actividad sanitaria sin referencia concreta de catálogo admite la ficha seleccionada.

Ejemplo de medicación fuera del plan:

```json
{
  "medicine_id": "01K6VQ7G1GNN2GPN3VQ2B3T9KD",
  "occurred_at": "2026-10-30T10:00:00-03:00",
  "reason": "Indicación veterinaria por síntomas observados",
  "notes": "Revisar la evolución durante la próxima recorrida."
}
```

Ejemplo de vacuna prevista, sin motivo obligatorio:

```json
{
  "vaccine_id": "01K6VQ7G1GNN2GPN3VQ2B3T9KE",
  "plan_activity_id": "01K6VQ7G1GNN2GPN3VQ2B3T9KF",
  "occurred_at": "2026-10-30T10:00:00-03:00",
  "vaccine_batch": "PARTIDA-2026-10"
}
```

La respuesta 201 contiene `data.operation_id`, `data.medicine_application` o `data.vaccination_application`, y `data.warnings: []`. El registro incluye identificador, lote, vínculo al plan, catálogo histórico, responsable, fecha, `reason` y notas. No incluye cantidad aplicada, consumo ni saldo de stock. `reason` queda nulo cuando se omite en una aplicación prevista y aparece también en `module_data.reason` del historial y en la instantánea de una corrección.

### Persistencia y compatibilidad

La migración `2026_10_30_012112_allow_health_applications_without_quantity_and_add_vaccination_reason` hace anulables `medicine_applications.quantity`, `reason` y `stock_movement_id`, y agrega `vaccination_applications.reason` anulable. No rellena cantidades ficticias, no reescribe las aplicaciones anteriores y conserva claves foráneas y la restricción de cantidad positiva para valores históricos no nulos. Su reversión se bloquea expresamente para evitar inventar cantidades o borrar motivos; una vuelta de alcance requiere una migración correctiva.

Los saldos, movimientos, operaciones idempotentes e instantáneas anteriores permanecen legibles. El historial puede seguir mostrando las cantidades y referencias originales para registros antiguos; en registros nuevos esos campos históricos permanecen nulos. Un reintento de una operación antigua ya confirmada conserva su resultado guardado cuando supera la validación HTTP vigente; las llamadas internas mantienen la resolución idempotente anterior a la consulta de estado mutable.

`POST /api/v1/medicines/{medicine}/ajustes-stock` queda retirado: una solicitud válida y autorizada devuelve 409 y no modifica nada. `GET /api/v1/medicines/{medicine}/stock` conserva la lectura del contador histórico; no representa stock operativo vigente. Ambos siguen documentados por compatibilidad en [management-plans.yaml](../../contracts/openapi/management-plans.yaml).

Una corrección de una aplicación nueva agrega su registro compensatorio sin crear movimientos ni buscar un saldo de medicamento. Las aplicaciones antiguas con movimiento clínico o físico vinculado conservan la compensación exacta de ese movimiento, incluso con sanidad deshabilitada. No se elimina ni modifica la aplicación original ni su auditoría.

### Autorización, auditoría e idempotencia

Se mantiene `management-plans.execute` tanto en los FormRequests dedicados como en los comandos transaccionales. La consulta del historial exige `management-plans.view`. No se introducen asignaciones de usuarios por UP: el acceso sigue siendo funcional y global.

La aplicación, su instantánea permitida en auditoría (`medicine_application_recorded` o `vaccination_application_recorded`), el asiento en la cronología del lote y el resultado idempotente se confirman sincrónicamente en la misma transacción. La instantánea guarda el motivo y los nombres históricos, operación, actor, responsable y UP. Un fallo de auditoría revierte el registro y permite reintentar la clave. Misma clave y mismo contenido reproducen la respuesta; otro contenido devuelve 409. Se conserva la serialización por actor y el bloqueo del lote para no competir con cambios de su plan.

### Verificación de este cambio

Se verifican mediante PHPUnit los registros previstos con motivo omitido, nulo o informado; motivo obligatorio fuera del plan; actividades ajenas, obsoletas, de otro tipo o catálogo; rechazo de cantidad y consumo; permisos; idempotencia; persistencia del motivo en historial; reversión por fallo de auditoría; correcciones nuevas sin stock; y compensaciones físicas y clínicas históricas sin borrar registros. Las pruebas usan PostgreSQL de testing, separado de los datos de desarrollo.

Resultado: `php artisan test --compact tests/Feature/ManagementPlans tests/Feature/Inventory/FlockFeedingDesignDemoSeederTest.php` pasó con **47 pruebas y 595 aserciones**, incluidos contratos OpenAPI y el demo de alimentación que registra una vacuna prevista. Pint pasó sobre todos los PHP cambiados y nuevos usando rutas explícitas del host, después de intentar `--dirty`, que no dispone de Git dentro del contenedor. Larastan/PHPStan nivel 5 pasó sin errores sobre las fuentes y factories cambiadas con `.phpstan-vaccines.neon`, sin baselines ni exclusiones nuevas. `git diff --check` pasó.

La migración se aplicó correctamente a la base local de desarrollo mediante `migrate --path=database/migrations/2026_10_30_012112_allow_health_applications_without_quantity_and_add_vaccination_reason.php --no-interaction`. Se confirmó Laravel 13.29.0 y PHPUnit 12.5.34. No se modifican dependencias ni frontend y no se ejecuta aceptación manual de interfaces en este cambio. La documentación de [validación](https://laravel.com/docs/13.x/validation), [migraciones](https://laravel.com/docs/13.x/migrations) y [pruebas HTTP](https://laravel.com/docs/13.x/http-tests) de Laravel respalda la sintaxis utilizada; Boost no expuso `search-docs` ni `record-rule` en esta sesión.
