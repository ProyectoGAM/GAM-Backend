# Roles y permisos configurables

## Comportamiento entregado

Cada usuario tiene exactamente un rol. Sus permisos efectivos son los permisos activados en ese rol, sin permisos individuales, herencia ni acumulación de roles. Admin puede activar y desactivar permisos del mismo rol: se conservan su identificador y sus usuarios asignados, y la autorización cambia desde la siguiente solicitud posterior al commit.

El nombre “Repartidor+” fue sólo un ejemplo del usuario. No se agrega ese rol, un sistema de variantes ni reemplazos automáticos. Los roles personalizados se crean con el nombre visible que el cliente decida.

Sólo usuarios activos con el rol técnico `admin` administran roles, sus permisos y las asignaciones de usuarios. No existe un permiso delegable para administrar roles. Los permisos de gestión de usuarios, PIN o sesiones no permiten tomar control de cuentas Admin. El alta de usuarios también es exclusiva de Admin porque incluye su asignación inicial de rol.

`admin` no admite edición ni eliminación; mantiene todos los permisos registrados. `employee` y `delivery` admiten configuración de sus permisos y metadatos visibles, pero sus claves técnicas y existencia están protegidas. No se puede desactivar ni retirar el rol al último Admin activo, incluso mediante solicitudes concurrentes.

La actualización de acceso agrega `unassigned`, visible como **Sin rol**, protegido, siempre vacío y sin login. Asignarlo revoca sesiones existentes. Su descripción indica que la cuenta no tiene permisos ni acceso. Ver [modalidades y UP](access-methods-and-production-unit-implementation.md).

## Catálogo y autorización

`config/permission-catalog.php` define 84 permisos en 23 módulos con claves estables `can_*`, etiqueta española, descripción breve, dependencias y marca de exclusividad Admin. Las agrupaciones ayudan al editor; no conceden permisos por sí mismas. Generalmente se separa consultar de gestionar, y se distinguen operaciones sensibles como ajustes, redistribuciones, anulaciones y exportaciones.

La persistencia sigue utilizando Spatie Permission 8.3 y la autorización utiliza Policies de Laravel 13. Las Policies de dominio mantienen sus validaciones de estado y negocio. `RolePolicy` exige el rol Admin explícitamente; `UserPolicy` protege las cuentas y asignaciones. No hay un bypass global mediante `Gate::before`.

Las claves de permisos siguen siendo funcionales y no contienen IDs. Las cuentas con correo sin UP conservan alcance empresarial; las cuentas PIN pertenecen a una única UP y sus permisos nunca amplían ese límite. Un control previo de autorización puede denegar un recurso fuera de la UP antes de evaluar su Policy funcional.

Las operaciones que ya eran exclusivas de Admin, como gestión de medicamentos/vacunas, presentaciones de huevos y ajustes/reversiones de cuentas corrientes, mantienen esa restricción. El catálogo las identifica como no asignables a otros roles. El stock físico sanitario sigue deshabilitado.

El backend rechaza una selección si falta una dependencia: por ejemplo, `can_manage_flocks` requiere `can_view_flocks`. También rechaza claves inexistentes, permisos exclusivos de Admin, duplicados y campos que no pertenecen al contrato. Una lista vacía desactiva todos los permisos del rol editable.

## API de administración

Contrato completo: `contracts/openapi/authentication.yaml`, versión 2.0. Todas estas rutas requieren una sesión personal autenticada de Admin. La web conserva cookies stateful y CSRF; el transporte nativo conserva PAT con vencimiento. Las sesiones compartidas no permiten administrar roles.

| Método y ruta | Resultado |
| --- | --- |
| `GET /api/v1/permisos` | Módulos con sus permisos, descripciones, dependencias y asignabilidad. |
| `GET /api/v1/roles` | Página de roles, permisos activos y cantidad de usuarios asignados. |
| `POST /api/v1/roles` | Crea un rol personalizado con clave técnica generada por el servidor. |
| `GET /api/v1/roles/{role}` | Detalle y versión del rol. |
| `PATCH /api/v1/roles/{role}` | Edita nombre visible y descripción con control de versión. |
| `PUT /api/v1/roles/{role}/permisos` | Guarda los permisos activados del mismo rol. |
| `DELETE /api/v1/roles/{role}` | Elimina un rol personalizado sin usuarios, contando también los deshabilitados. |
| `PUT /api/v1/usuarios/{user}/rol` | Configura el único rol del usuario mediante `rol_id`. |

El listado acepta `buscar` de hasta 120 caracteres, `por_pagina` entre 1 y 100 y `page` positivo acotado. Ordena por nombre visible e ID. El catálogo no acepta filtros. Los cambios del rol requieren su `version`; una versión obsoleta devuelve 409 y obliga a recargar. No se acepta del cliente `guard_name`, clave técnica ni banderas de protección.

Crear un rol:

```json
{
  "nombre": "Operador de alimento",
  "descripcion": "Consulta y ajustes de alimento elaborado",
  "permisos": ["can_view_feed_stock", "can_adjust_feed_stock"]
}
```

Desactivar el ajuste conservando consulta y el mismo rol:

```json
{
  "version": 1,
  "permisos": ["can_view_feed_stock"]
}
```

Los permisos omitidos quedan desactivados al guardar. El formulario debe enviar toda la selección vigente, incluidas sus dependencias. Guardar una selección idéntica con la versión actual no incrementa la versión ni duplica auditoría. Un cambio real incrementa la versión; repetir la versión anterior devuelve conflicto.

La respuesta del rol contiene `id`, `clave`, `nombre`, `descripcion`, `version`, `protegido`, `editable` y `permisos`. Listado y detalle incluyen `usuarios_count`. `protegido` indica que no se puede eliminar; `editable` es falso para Admin.

Respuestas: 401 sin sesión válida, 403 sin autorización o ante una operación sobre un rol protegido, 404 para recursos inexistentes, 422 por datos o selección inválida y 409 por versión obsoleta, rol asignado o protección del último Admin. Los conflictos incorporan `ROLE_VERSION_CONFLICT`, `ROLE_IN_USE` o `LAST_ADMIN`.

## Cambios del contrato de usuarios

- `POST /api/v1/users` recibe los campos de cuenta existentes y un `rol_id` escalar obligatorio, en lugar de `role` con tres nombres fijos.
- `/api/v1/users/{user}/roles` se conserva como ruta anterior, usando la misma Policy y Action que la ruta española. Ahora recibe un único `rol_id`; no acepta arrays `roles` ni permisos individuales.
- Login y `/api/v1/me` exponen `rol` singular con `id`, `clave` y `nombre`, junto con `permissions` como lista de claves `can_*`.
- La gestión de contraseñas, estados, PIN y sesiones utiliza la Policy de usuario y revalida la autorización en las Actions sensibles.

Los consumidores del contrato anterior deben actualizar tanto el campo del rol como los identificadores de permisos. Este repositorio entrega la API y sus contratos; la pantalla de configuración corresponde a la entrega frontend prevista en el plan.

## Alimento y alimentación de lotes

Se retira la autorización provisional de sólo autenticación. La Policy de operaciones de alimento distingue:

| Capacidad | Permiso |
| --- | --- |
| Consultar saldos de alimento y operaciones | `can_view_feed_stock` |
| Elaborar alimento | `can_manufacture_feed` |
| Anular elaboraciones | `can_cancel_feed_manufacturing` |
| Ajustar alimento elaborado | `can_adjust_feed_stock` |
| Ajustar ingredientes de una planta | `can_adjust_feed_ingredients` |
| Configurar mínimos de alimento | `can_manage_feed_minimums` |
| Consultar alimentación y consumos de lotes | `can_view_flock_feeding` |
| Configurar modo manual y automático | `can_manage_flock_feeding` |
| Rectificar consumos históricos | `can_correct_feed_consumption` |

Los efectos internos de una elaboración autorizada no exigen además permisos de movimientos manuales de inventario. Se conservan las transacciones, compensaciones, idempotencia y auditoría de esas operaciones. El scheduler de consumo diario conserva su contexto de sistema.

Las comprobaciones operativas que dependían del nombre `delivery` o `employee` se basan en permisos, conservando los requisitos de cada flujo. El listado compartido exige usuario activo, PIN habilitado, permiso `can_login_shared` y ausencia del rol Admin.

Registrar combustible de reparto conserva el alcance al uso propio activo aunque el cliente desactive el permiso de iniciar repartos. La autorización no amplía su alcance por dejar de pertenecer a un nombre de rol fijo. La presentación de auditoría también consulta el permiso de rectificación antes de ofrecer esa acción.

## Persistencia, auditoría y concurrencia

La migración nueva añade `label`, `description` y `version` a `roles`, una restricción única de asignación por sujeto en `model_has_roles` y una restricción que impide permisos directos de User en `model_has_permissions`. Conserva los pivotes al renombrar las claves anteriores cuando existen. No modifica las migraciones históricas.

Alta de usuario y asignación inicial ocurren en una misma transacción. El cambio explícito de asignación bloquea al usuario y mantiene una sola asignación. Las mutaciones de roles y de acceso toman primero un bloqueo sobre el rol Admin para serializar las decisiones sobre cuentas privilegiadas. El control de versión evita que dos administradores sobrescriban la configuración del mismo rol.

Las Actions registran `role_created`, `role_updated`, `role_permissions_updated`, `role_deleted`, `user_role_updated` y `user_status_updated` mediante `AuditRecorder` y `AuditEntryData`, dentro de la misma transacción. El registro contiene operación, traza, actor, sujeto, snapshot permitido y atributos anteriores/nuevos. La eliminación de un rol conserva su snapshot histórico. No se registran contraseñas, PIN, tokens ni secretos. Las correcciones producen entradas nuevas, sin editar historia.

La autorización consulta los permisos actuales del rol sin depender de una copia compartida en caché ni de los permisos directos de User. La invalidación de Spatie se solicita también después del commit. Al desactivar un permiso de canal se revocan síncronamente las sesiones afectadas; el middleware comprueba la elegibilidad y vigencia de la sesión en solicitudes posteriores. Promover a Admin deshabilita su PIN y revoca el contexto compartido. El estado local del frontend no participa en esas decisiones.

## Actualización del entorno y datos de prueba

No se añadieron dependencias. El usuario autorizó descartar los datos ficticios y reconstruir Docker si fuera necesario. No se requiere borrar imágenes o volúmenes para aplicar este cambio a una base compatible:

```powershell
docker compose -f compose.dev.yaml exec -T api php artisan migrate --no-interaction
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class=IdentityPermissionSeeder --no-interaction
docker compose -f compose.dev.yaml exec -T api php artisan permission:cache-reset
```

Si una base de prueba contiene múltiples roles por usuario o permisos directos, las nuevas restricciones rechazan esos datos. En ese entorno se puede reconstruir la base demo mediante el procedimiento autorizado del README. No se limpia Docker globalmente ni se afectan proyectos ajenos. Una futura instalación con datos reales necesita resolver esos casos antes de migrar.

`IdentityPermissionSeeder` registra el catálogo y actualiza Admin con todos los permisos registrados. Los permisos predeterminados de `employee` y `delivery` se aplican al crearlos o inicializar por primera vez sus metadatos; esto incluye el rol que ya crea una migración histórica. Reejecutarlo no deshace la configuración del cliente ni elimina roles personalizados. Las factories asignan un rol vacío independiente a cada usuario de prueba para no compartir permisos accidentalmente entre fixtures.

La reversión del esquema elimina columnas y restricciones nuevas, pero no vuelve a los nombres públicos anteriores de permisos. Revertir también el contrato requiere un despliegue coordinado, no únicamente ejecutar `migrate:rollback`.

## Verificación

Se ejecutaron pruebas de API, permisos por rol, protección de Admin, unicidad de asignación, auditoría y rollback, invalidación de sesiones, granularidad de alimento y concurrencia con procesos PostgreSQL:

- La primera ejecución completa recorrió 905 pruebas y 8400 aserciones: 877 aprobaron y 28 detectaron preparaciones antiguas, inicialización de reparto y cambios de autorización. Esos casos se corrigieron y se repitieron sus grupos; no se presenta esa ejecución inicial como una suite completa aprobada.
- Regresión posterior: 164 pruebas y 1384 aserciones de identidad, auditoría, cuentas, vehículos, pesajes y medicamentos; 105 pruebas y 761 aserciones de alimento e inventario. Ambos grupos aprobaron.
- Revisión final de roles, permisos de alimento, auditoría y vehículos: 76 pruebas y 635 aserciones aprobadas. Incluye caché anterior al cambio, factory de roles, dependencia de permisos y combustible propio sin permiso de inicio de reparto. Estos grupos se solapan; sus cantidades no se suman como pruebas únicas.
- PHPStan nivel 5 aprobó identidad y roles mediante `.phpstan-roles.neon`, y el análisis de CI del conjunto de `app` aprobó sin errores. Pint se ejecutó sobre los PHP modificados y `git diff --check` pasó. El contenedor no dispone de Git para `--dirty`; se utilizó la lista de archivos del diff y nuevos archivos del host.

Las pruebas utilizaron PostgreSQL aislado `gam_testing` y `gam_roles_validation_testing`. La ejecución completa requirió PHP CLI con memoria de 768 MB porque el proceso lanzado indirectamente con el límite original de 128 MB se agotaba. No se añadieron dependencias ni scripts de verificación.

En el entorno local se aplicaron las migraciones pendientes, incluida la de roles, se sincronizó el catálogo y se reinició su caché. Una consulta de comprobación confirmó 84 permisos registrados con claves `can_*` y los mismos 84 asignados a Admin. No fue necesario reconstruir imágenes ni borrar los datos de prueba. No se realizó una prueba visual del editor frontend, que corresponde a otra entrega.
