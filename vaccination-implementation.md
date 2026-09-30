# Catálogo e inventario de vacunas — implementación

La sección de Vacunas pertenece funcionalmente a **06 — Manejo productivo y sanidad** y técnicamente a `SuppliersAndCatalogs`, con integración sobre el inventario compartido. Una vacuna representa en esta etapa una ficha de catálogo con **SKU, nombre, descripción, detalles opcionales y proveedor**, vinculada uno a uno con un `Product` existente o creado durante el alta.

**Estado funcional: catálogo e inventario de Vacunas implementados; módulo 06 aún incompleto.** La relación con Plan Maestro o Plan de Manejo, aplicaciones a lotes, historial sanitario, programación, próximas aplicaciones, notificaciones y consumo de stock por vacunación permanecen fuera de alcance. El seguimiento local se registra en [notion.md](notion.md) sin mover la tarjeta completa del módulo a `Do Test`.

## Alcance y decisiones confirmadas

- El SKU identifica el producto de inventario de la vacuna y no se implementan variantes.
- El nombre es único mediante el catálogo global de Productos; no se diferencia por dosis.
- La descripción es obligatoria y los detalles son opcionales.
- El proveedor es obligatorio y reutiliza el catálogo existente de `Supplier`.
- Los cinco endpoints de Vacunas requieren un usuario activo con rol `admin`.
- La baja es lógica mediante el estado del Producto; no existe eliminación física.
- Una vacuna con stock positivo no puede desactivarse y devuelve `409 Conflict`.
- `dose` es la unidad predeterminada para un Producto nuevo. La API admite otra `BaseUnit`, y la unidad puede cambiarse antes del primer movimiento de inventario.
- El alta es idempotente por actor y `Idempotency-Key`.
- No se agregan movimientos de inventario al crear, actualizar, desactivar o reactivar una ficha.

## Persistencia e identidad

La migración [create_vaccines_table](database/migrations/2026_09_07_223657_create_vaccines_table.php) agrega una tabla independiente sin alterar las tablas históricas de inventario. Cada ficha conserva:

- ID interno y ULID público estable;
- relación única con `products` mediante `product_id`;
- proveedor actual y snapshot de su nombre al asociarlo;
- descripción y detalles;
- creador y snapshot de su nombre;
- `operation_id`, clave/hash de idempotencia y timestamps con zona horaria.

Las FKs de Producto, Proveedor y Usuario usan `RESTRICT`. La combinación `created_by + idempotency_key`, el ULID, `product_id` y `operation_id` son únicos. Los `CHECK` de PostgreSQL exigen descripción no vacía y limitan descripción y detalles a 5000 caracteres.

La PK interna y el ULID no cambian cuando se modifican nombre, proveedor, unidad o estado. La baja no elimina la ficha ni sus referencias, por lo que una futura entrada de Plan de Manejo podrá referenciarla de manera estable.

## Integración con Productos e Inventario

Vacunas no crea un subsistema de stock. `Product` continúa siendo la identidad canónica para SKU, nombre, tipo, unidad, seguimiento y estado; `stock_balances`, `inventory_movements` e `inventory_movement_lines` continúan siendo la única fuente de saldos e historial.

Durante el alta:

1. Se bloquea y vuelve a autorizar al actor.
2. Se bloquea y valida el proveedor activo.
3. Si el SKU no existe, se crea un Product activo, `kind=vaccine`, con stock controlado y unidad recibida o `dose`.
4. Si existe un Product compatible, se adopta sin reemplazar su ID, unidad ni movimientos.
5. Si el Product está inactivo, tiene otro tipo, no controla stock, no coincide en nombre o ya posee una ficha, se devuelve `409`.
6. Se crea la ficha y se registran las auditorías dentro de la misma transacción.

El SKU de una vacuna vinculada es inmutable. El endpoint genérico de Productos tampoco puede cambiar su tipo, desactivar el seguimiento de stock ni modificar la unidad después del primer movimiento. Cambiar el nombre mediante Productos actualiza y audita también la ficha de Vacuna.

`RecordInventoryMovementAction` y `AdjustStockToCountAction` bloquean los Productos antes de validar estado y unidad. Las transiciones de estado y los cambios de unidad usan bloqueo exclusivo del Product, por lo que un movimiento concurrente no puede confirmar una combinación incompatible de estado, unidad y saldo.

## Proveedor

La ficha guarda `supplier_id` y `supplier_name_snapshot`. Crear o cambiar la asociación exige un proveedor existente y activo; un ID inexistente devuelve `404` y uno inactivo devuelve `409`. Una desactivación posterior del proveedor no borra ni oculta vacunas históricas. Las consultas cargan Product y Supplier anticipadamente para evitar N+1.

No se copian dirección, contacto ni otros datos del proveedor. El snapshot de nombre es deliberado y permite reconstruir cómo se conocía la asociación al registrarla.

## Contrato HTTP

Fuente: [vaccination.yaml](contracts/openapi/vaccination.yaml). Todas las rutas son relativas a `/api/v1`, usan `auth:sanctum`, FormRequests dedicados y autorización mediante `VaccinePolicy`.

| Método y ruta | Comportamiento |
| --- | --- |
| `GET /vacunas` | Lista fichas paginadas; permite búsqueda, SKU, proveedor y estado. |
| `POST /vacunas` | Crea una ficha o adopta un Product compatible. Requiere `Idempotency-Key`. |
| `GET /vacunas/{vaccine}` | Devuelve una ficha por ULID público. |
| `PATCH /vacunas/{vaccine}` | Actualiza nombre, descripción, detalles o proveedor. |
| `PATCH /vacunas/{vaccine}/estado` | Desactiva o reactiva mediante el estado del Product. |

La primera ejecución de POST devuelve `201`. Repetir el mismo actor, clave y comando devuelve `200` con la representación actual sin duplicar Producto, ficha, movimientos ni auditoría. Reutilizar la clave con otro payload devuelve `409`.

La salida pública contiene ID, SKU, `producto_id`, unidad, estado, nombre, descripción, detalles, proveedor actual y fotografiado, creador fotografiado, timestamps y operación inicial. No expone la clave de idempotencia ni su hash.

## Baja lógica

`ChangeVaccineStatusAction` delega el estado canónico a `ChangeProductStatusAction` dentro de la misma transacción.

- Sin saldo o con saldo cero: permite pasar a `inactive`.
- Con saldo positivo: responde `409` y no modifica estado ni auditoría.
- Con movimientos históricos y saldo cero: permite la baja y conserva todo el historial.
- Estado repetido: responde la representación actual sin tocar timestamps ni duplicar auditoría.
- Reactivación: conserva Product, Vaccine, proveedor, movimientos y saldos.

Los productos de vacuna normales no permiten saldo negativo. Si una instalación contiene excepcionalmente un balance negativo autorizado, la transición sólo bloquea saldos positivos.

## Auditoría, transacciones e idempotencia

La implementación reutiliza `AuditRecorder` y `AuditEntryData`; no existe auditoría específica o paralela para Vacunas.

- Un alta con Product nuevo registra `product_created` y `vaccine_created` con el mismo `operation_id`.
- Adoptar un Product existente registra el alta de la ficha sin inventar un movimiento.
- Cambiar nombre o unidad mediante Product registra `product_updated` y `vaccine_updated`.
- Cambiar descripción, detalles o proveedor registra `vaccine_updated`.
- Cambiar estado registra `product_status_changed` y `vaccine_status_changed`.

Los snapshots son allowlist y contienen identidad de Producto, proveedor, textos y estado, sin contraseñas, PIN, tokens, claves ni secretos. Las escrituras de dominio y sus auditorías usan la misma conexión y transacción; una falla de auditoría revierte también Producto, Vaccine, timestamps y disponibilidad de la clave idempotente.

El alta bloquea al actor para serializar reintentos del mismo usuario. Los uniques de Productos y Vacunas resuelven carreras por SKU, nombre, relación 1:1 y clave. Las pruebas de concurrencia verifican replay simultáneo, asociación concurrente del mismo SKU, baja frente a movimiento y primer movimiento frente a cambio de unidad.

## Datos demo

[VaccineDemoSeeder](database/seeders/SuppliersAndCatalogs/VaccineDemoSeeder.php) se ejecuta sólo en `local`, después de crear los Productos demo. Adopta `VAC-001`, lo relaciona con «Agroinsumos del Sur» y registra la ficha a través de `CreateVaccineAction`.

El seeder de Vacunas no crea saldos ni movimientos. El Product `VAC-001`, el saldo `120` y las líneas demo `+150/-30` pertenecían previamente a `LocalDemoDataSeeder`; la nueva carga conserva esos registros. Repetir `VaccineDemoSeeder` no duplica ficha, auditoría, saldo ni movimientos, y una ficha preexistente con otra clave se conserva sin reescritura.

## Activación local

Aplicar la migración aditiva:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan migrate --no-interaction
```

En una instalación local ya sembrada, cargar únicamente la ficha demo:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\SuppliersAndCatalogs\VaccineDemoSeeder' --no-interaction
```

La especificación ya está incluida en el selector de Swagger UI de `compose.dev.yaml`. Si el contenedor conserva una configuración anterior, recrear únicamente `swagger-ui`; no eliminar volúmenes ni ejecutar `docker compose down -v`.

## Verificación automatizada

La cobertura está en:

- `tests/Feature/SuppliersAndCatalogs/VaccineEndpointTest.php`;
- `tests/Feature/SuppliersAndCatalogs/VaccineContractTest.php`;
- `tests/Feature/SuppliersAndCatalogs/VaccineConcurrencyTest.php`;
- `tests/Feature/SuppliersAndCatalogs/VaccineDemoSeederTest.php`.

Resultados verificados el 2026-09-08:

- 16 pruebas específicas de Vacunas aprobadas, con 107 aserciones.
- Suite completa con un `APP_KEY` y pepper de prueba válidos: 271 pruebas y 1803 aserciones aprobadas.
- Larastan nivel 5 mediante `.phpstan-vaccines.neon`: sin errores.
- Pint en modo de comprobación: aprobado.
- `git diff --check`: aprobado.
- Migración aplicada en una base existente y validada desde cero por la suite PostgreSQL.

La receta literal actual del README fuerza `IDENTITY_PIN_PEPPER` vacío y no exporta el `APP_KEY` utilizado por subprocesos; por eso no reproduce por sí sola la suite completa. Esta diferencia de configuración es transversal y no corresponde al catálogo de Vacunas.

## Observaciones técnicas pendientes

La revisión técnica identificó dos endurecimientos transversales antes de considerar cerrada la aceptación:

1. Al promover a ADMIN un usuario que conserva un PAT emitido desde una sesión compartida, la fila de sesión se revoca pero el token puede seguir autenticando rutas protegidas sólo con `auth:sanctum`. La revocación de tokens y la validación de canal deben resolverse en Identidad y Acceso para todos los módulos administrativos.
2. `UpdateProductAction` debe preservar internamente el nombre no vacío de un Product vinculado, además de la validación que ya realizan los FormRequests HTTP.

También falta ampliar las pruebas específicas de 401/403 por endpoint, misma clave con payload distinto, cambio de proveedor y baja con movimientos históricos en saldo cero. Estas observaciones no cambian la identidad ni el modelo de inventario implementados, pero deben resolverse antes de la aceptación final del módulo.

## Do Test — validación manual pendiente

1. Autenticar un ADMIN, un usuario no ADMIN y una sesión no autenticada. Confirmar acceso del ADMIN y respuestas 403/401 para los demás en todos los endpoints.
2. Crear una vacuna con `Idempotency-Key`; comprobar Product `vaccine`, unidad `dose`, proveedor, auditorías y ausencia de movimientos.
3. Repetir la misma clave con el mismo payload y luego con otro contenido. Confirmar una sola ficha, respuesta 200 en el replay y 409 en el conflicto.
4. Listar, buscar por nombre/SKU, filtrar por proveedor/estado y consultar el detalle por ULID.
5. Actualizar nombre, descripción, detalles y proveedor. Comprobar identidad estable, snapshot, timestamps y auditoría.
6. Intentar cambiar SKU, tipo y seguimiento mediante Productos. Confirmar 409 y ausencia de cambios parciales.
7. Desactivar sin stock, repetir la baja y reactivar. Agregar stock, intentar desactivar y confirmar 409; dejar saldo cero con historial y comprobar que la baja conserva movimientos.
8. Registrar el primer movimiento e intentar cambiar unidad. Confirmar que se rechaza y que saldo, unidad y auditoría permanecen coherentes.
9. Ejecutar `VaccineDemoSeeder` dos veces en local y comprobar una sola ficha `VAC-001`, saldo 120 y movimientos sin duplicados. Verificar que fuera de local no genera datos.
10. Verificar la revocación de una sesión compartida promovida a ADMIN después de aplicar el endurecimiento pendiente de Identidad y Acceso.

Registrar ambiente, commit, actor, requests anonimizados, claves de idempotencia, respuestas, saldos, movimientos y entradas de auditoría. Las pruebas automatizadas no sustituyen esta aceptación manual.

## Fuera de alcance

Frontend, Plan Maestro o Plan de Manejo, asignación de vacunas a planes, aplicación a lotes, historial de vacunaciones, próximas aplicaciones, calendarios, notificaciones, consumo automático de stock, variantes, dosis por especie o peso, protocolos clínicos y sincronización directa con Notion.
