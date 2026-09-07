# Catálogo de medicamentos — implementación

La definición confirmada reemplaza el plan inicial de intervenciones por lote. Un medicamento es una ficha de catálogo con **nombre, descripción y proveedor**. La relación con un plan de manejo y las fechas de aplicación —pasadas o futuras— quedan diferidas hasta definir ese dominio. Las dosis son una posible ampliación futura, todavía no confirmada.

**Estado funcional: catálogo de Medicamentos implementado; módulo 06 — Manejo productivo y sanidad aún incompleto.** Faltan las demás secciones del módulo. El seguimiento en [notion.md](notion.md) registra este avance parcial y no propone mover la tarjeta del módulo completo a `Do Test`. La propiedad técnica del catálogo corresponde a `SuppliersAndCatalogs`.

## Alcance

- Propiedad de SuppliersAndCatalogs, con modelo Medicine y tabla medicines.
- Alta y consulta exclusivamente para usuarios activos con rol admin.
- Nombres repetidos permitidos; cada ficha tiene ULID propio.
- Proveedor obligatorio del catálogo existente, activo al crear. Su desactivación no oculta fichas anteriores ni impide repetir un alta ya confirmada.
- Sin SKU, unidad base, stock, lotes, galpones, dosis, fechas operativas, edición, borrado ni estados de medicamento.
- No se crea un Product ni una relación vacía con futuros planes.
- El sistema conserva la fecha automática de registro y los nombres de proveedor y administrador conocidos al crear.

## Contrato HTTP

Fuente: [medication.yaml](contracts/openapi/medication.yaml).

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

Para pruebas normales, usar la receta del [README](README.md), que ejecuta PHPUnit dentro de `api` y deriva `<DB_DATABASE>_testing` desde la base normal configurada. Seleccionar las pruebas de endpoint, contrato y seeder.

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
