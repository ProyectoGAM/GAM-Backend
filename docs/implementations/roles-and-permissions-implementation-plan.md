# Roles y permisos — plan de implementación

Estado: plan aprobado para implementación. Fecha: 02/11/2026. Rama: `rolesyPermisos`. El comportamiento entregado, contrato y verificación se documentan en [roles y permisos configurables](roles-and-permissions-implementation.md); las secciones siguientes conservan el diseño y diagnóstico previos.

## Objetivo y alcance

Permitir que Admin configure roles como agrupaciones de permisos comprensibles, organizados por módulo y acompañados de una descripción breve. Utilizar las Policies, Gates y FormRequests de Laravel y la persistencia de Spatie ya instalada. El catálogo de operaciones pertenece al código; Admin configura qué permisos tiene cada rol, sin crear operaciones arbitrarias desde la interfaz.

Esta entrega de planificación sólo agrega este documento y su enlace en el índice. Las rutas, estructuras y nombres siguientes son propuestas, no capacidades disponibles actualmente. La construcción de la pantalla frontend queda para su entrega correspondiente, con el contrato definido aquí como base.

Decisiones confirmadas por el usuario: cada usuario tiene exactamente un rol y todos sus permisos provienen de los permisos activados en ese rol. Admin puede activar o desactivar permisos de cada rol. “Repartidor+” fue únicamente un ejemplo de un posible rol elegido por el cliente: no es un rol requerido ni define reemplazos automáticos. Los datos actuales son de prueba; se autoriza reconstruir el entorno Docker del proyecto y regenerarlos cuando sea necesario. No se requiere preservar esos datos ni diseñar una migración de sus asignaciones históricas.

## Diagnóstico del repositorio

- Versiones instaladas comprobadas con `composer show --direct` dentro de `api`: Laravel 13.29.0, Spatie Permission 8.3.0 y Sanctum 4.3.3. No hace falta agregar dependencias.
- `User` ya utiliza `HasRoles`. Existen las tablas estándar de Spatie y numerosas Policies por recurso. Se conservará el guard de permisos `web` para ambos transportes; no se duplicará el catálogo para Sanctum o sesiones compartidas.
- `IdentityPermissionSeeder` concentra los identificadores actuales, con formato `flocks.view`, `inventory.adjust`, etc. No aporta módulos, etiquetas ni descripciones.
- El seeder sincroniza todos los permisos de Admin, como exige `.ai/rules/seeders.md`, pero también vuelve a imponer los permisos de `employee` y `delivery`. Esto debe cambiar para conservar la configuración del cliente.
- `CreateManagedUserRequest` y `UpdateUserRolesRequest` sólo permiten los tres roles fijos actuales. `identity.users.manage` habilita crear usuarios con Admin y asignarlo: esa autorización es demasiado amplia para el nuevo requisito.
- `UserManagementController` asigna roles fuera de la Action de registro y cambia roles directamente, sin la transacción y auditoría propias que requiere esta operación.
- `UpdateUserPasswordRequest` autoriza incondicionalmente y depende del middleware `identity.users.manage`. Delegar ese permiso permitiría restablecer la contraseña de un Admin; también deben protegerse las cuentas privilegiadas frente a cambios de estado y otras operaciones administrativas.
- `FeedOperationRequest::authorize()` sólo exige autenticación y documenta que falta decidir el permiso funcional. La revisión debe cubrir estas operaciones, no limitarse a crear un editor de roles.
- `employee` y `delivery` aparecen en elegibilidad de sesiones y operaciones. No se pueden eliminar ni renombrar sin revisar esos flujos.
- `docs/libraries.md` conserva una referencia a alcance de acceso por UP que contradice las reglas actuales: el permiso funcional alcanza todas las UP de la empresa. Se corregirá esa referencia al implementar.

## Reglas propuestas

1. Sólo un usuario activo con el rol estable `admin` puede crear, modificar, eliminar roles o cambiar sus permisos. La Policy verifica ese rol explícitamente. No existirá un permiso delegable equivalente a administrar roles.
2. Como medida adicional propuesta, la asignación y retirada de roles a usuarios también será exclusiva de Admin. Los permisos de gestión de usuarios podrán delegar operaciones sobre usuarios no administradores, pero no asignar roles ni crear, desactivar o tomar control de cuentas Admin.
3. `admin` es un rol de sistema: no se elimina, no se cambia su clave y no se recortan sus permisos desde la API. Recibe el catálogo completo al incorporar permisos nuevos. Se puede mostrar con la etiqueta “Administrador”.
4. No se permite desactivar ni retirar el rol al último Admin activo. Todas las operaciones que alteren esa condición deben compartir un bloqueo transaccional, para cubrir solicitudes simultáneas.
5. Cada usuario tiene exactamente un rol; no se acumulan roles ni se permite dejar una cuenta sin rol. No hay jerarquías ni suma de permisos entre roles.
6. No se permiten permisos directos por usuario. Admin configura cada rol activando y desactivando los permisos del catálogo. Guardar esa configuración mantiene el mismo rol y sus usuarios asignados; el cambio alcanza a todos sus usuarios, sin crear otro rol ni reasignarlos. Si el cliente necesita una agrupación distinta para otras personas, puede crear un rol independiente con el nombre que decida.
7. Los roles existentes `employee` y `delivery` mantienen permisos funcionales configurables. Revisar las comprobaciones y selectores que hoy dependen literalmente de esos nombres, para que las operaciones delegables se autoricen por los permisos activados en el rol, conservando las reglas de canal y de negocio. La administración exclusiva de Admin se comprueba por su rol estable, como excepción explícita. No se incorpora un sistema de roles base, variantes o reemplazos.
8. Las restricciones exclusivas de Admin ya existentes en medicamentos, vacunas, presentaciones de huevos y ajustes/reversiones de cuentas se conservan. El catálogo debe indicar esas restricciones y evitar ofrecer una casilla que sugiera delegarlas. Cambiarlas requiere una decisión de negocio independiente.
9. Las Policies conservan estados, propiedad cuando corresponda y restricciones del dominio. No se agregará un `Gate::before` global que permita a Admin saltar esas validaciones.
10. Los permisos son globales dentro de la empresa: sin relación usuario–UP, filtros por asignación ni identificadores de registros en las claves.

## Granularidad y catálogo

Usar normalmente dos permisos por área funcional: **Ver** y **Gestionar**. Gestionar agrupa alta, edición y cambios de estado ordinarios cuando tienen un riesgo equivalente. Separar operaciones con impacto distinto: ajustes de stock, redistribución, cierre de lote, pagos, anulaciones o exportación.

No crear un permiso por endpoint, campo, botón, galpón o UP. Tampoco agrupar todo inventario bajo un único permiso si eso permite ajustar saldos a quien sólo necesita registrar producción.

Propuesta de nombres técnicos: `can_<action>_<resource>`, en inglés y con `snake_case`, como solicita el usuario. Los rótulos y descripciones serán españoles. Ejemplos candidatos:

| Módulo | Identificador | Etiqueta | Descripción breve |
| --- | --- | --- | --- |
| `lots` — Lotes | `can_view_flocks` | Ver lotes | Consultar los lotes y su información general. |
| `lots` — Lotes | `can_manage_flocks` | Gestionar lotes | Crear lotes, editar sus datos y gestionar sus estados permitidos. |
| `lots` — Lotes | `can_redistribute_flocks` | Redistribuir aves | Registrar redistribuciones de aves entre lotes y galpones. |
| `lots` — Lotes | `can_finalize_flocks` | Cerrar lotes | Finalizar un lote conforme a las validaciones del módulo. |
| `poultry_houses` — Galpones | `can_view_poultry_houses` | Ver galpones | Consultar galpones, capacidad y ocupación. |
| `poultry_houses` — Galpones | `can_manage_poultry_houses` | Gestionar galpones | Crear galpones, editar datos y cambiar estados permitidos. |
| `feed_stock` — Alimento | `can_view_feed_stock` | Ver stock de alimento | Consultar saldos e historial de alimento por receta y UP. |
| `feed_stock` — Alimento | `can_manufacture_feed` | Elaborar alimento | Registrar elaboraciones con consumo de ingredientes. |
| `feed_stock` — Alimento | `can_adjust_feed_stock` | Ajustar stock de alimento | Corregir manualmente el saldo de alimento indicando el motivo. |
| `feed_stock` — Alimento | `can_cancel_feed_manufacturing` | Anular elaboraciones | Compensar una elaboración según las reglas de devolución de ingredientes. |
| `audit` — Auditoría | `can_view_audit` | Consultar auditoría | Consultar el historial paginado de operaciones y responsables. |

La tabla ilustra la granularidad; no sustituye el inventario completo de permisos. El permiso de mantenimiento de galpones se evaluará por separado si el cliente necesita distinguir instalaciones de tareas de mantenimiento. Cada descripción final debe enumerar el alcance real que autoriza su Policy.

El catálogo completo abarcará las funcionalidades existentes: identidad y canales, geografía, UP, galpones y mantenimiento, lotes, razas, mortalidad, huevos, pesajes, planes de manejo, alimentación, recetas, ingredientes, inventario, proveedores/productos, sanidad, clientes/cuentas, vehículos, repartos, reportes y auditoría. Módulos y subgrupos son de presentación; un permiso tiene una identidad y no se duplica por aparecer en varias pantallas.

### Persistencia y metadatos

- Mantener las relaciones estándar de Spatie para persistir asignaciones y permisos, restringiendo usuario–rol a una sola asignación. Añadir una restricción de unicidad compatible con las claves polimórficas reales para impedir dos roles por usuario, además de la validación y la Action. La obligatoriedad de asignar uno se garantiza en el alta y en cada sustitución transaccional.
- Proponer `config/permission-catalog.php` como fuente única versionada de metadatos y sincronización. Cada entrada define `name`, `module`, `label`, `description`, orden, categoría funcional/canal y restricciones de asignación. Los módulos tienen identificador estable y rótulo español.
- Mantener en `permissions` la identidad que utiliza Spatie. La API combina esos registros con el catálogo; no hace falta duplicar metadatos en la tabla ni extender el modelo Permission sólo para presentarlos.
- Añadir a `roles` los campos necesarios para etiqueta y descripción, y una versión para evitar sobrescrituras concurrentes. Utilizar un modelo Role propio que extienda el de Spatie y configurarlo en `config/permission.php`; actualizar los usos necesarios de la clase base, incluyendo seeders y factories.
- Las claves reservadas de sistema se deciden en código. El cliente no puede convertir un rol personalizado en `admin` cambiando un nombre, una bandera o un campo adicional del payload.
- Antes de preparar migraciones, inspeccionar el esquema real con `database-schema` si está disponible; de lo contrario, inspeccionar el esquema con las herramientas del framework. No modificar migraciones ya desplegadas.

### Dependencias comprensibles

Si “Gestionar” requiere “Ver”, declararlo como dependencia del catálogo y mostrarlo en el editor. No confiar sólo en la interfaz: el backend rechaza combinaciones incompletas con un error español que identifique el permiso faltante. Un permiso sensible de ajuste no incorpora silenciosamente otros permisos de gestión.

Las dependencias y las restricciones Admin se validan tanto al guardar roles como al calcular el acceso efectivo. Los roles vacíos pueden existir como borrador; no se permite dejar a una cuenta operativa sin ningún rol por accidente.

## Integración con Laravel

- Incorporar `RolePolicy` para consultar y administrar roles, y `UserPolicy` para asignación de roles y protección de cuentas Admin. Registrar las Policies siguiendo los providers y namespaces existentes.
- Mantener las Policies de cada dominio y mapear sus métodos a permisos del catálogo: `viewAny`, `view`, `create`, `update` y acciones de negocio específicas.
- Cada endpoint tendrá un FormRequest reutilizable o dedicado con autorización en `authorize()` y validación acotada en `rules()`. Los controladores sólo orquestan Queries, Actions y Resources.
- Las mutaciones usan Actions, por ejemplo `CreateRoleAction`, `UpdateRoleAction`, `UpdateRolePermissionsAction`, `DeleteRoleAction` y `UpdateUserRoleAction`. El alta de una cuenta y la asignación de su único rol deben formar una única operación transaccional auditada. La sustitución bloquea al usuario para serializar cambios simultáneos.
- Revalidar actor y autorización dentro de la transacción en las operaciones sensibles, usando estado actual. Eliminar un rol asignado devuelve conflicto; Admin debe reasignar primero a sus usuarios. No hay borrado en cascada de asignaciones por una acción de interfaz.
- Utilizar las APIs de Spatie para asignar y sincronizar permisos, sin escrituras manuales de pivotes desde controladores.

Laravel recomienda Policies para organizar autorización por recurso; Spatie aporta las relaciones de roles y permisos y se integra con la autorización del framework. Referencias oficiales: [Laravel 13 — Authorization](https://laravel.com/docs/13.x/authorization), [Spatie 8 — permisos mediante roles](https://spatie.be/docs/laravel-permission/v8/basic-usage/role-permissions), [extensión de modelos](https://spatie.be/docs/laravel-permission/v8/advanced-usage/extending).

## API propuesta

Todas las rutas nuevas tendrán contrato español y prefijo `/api/v1`. Acceso personal autenticado, con CSRF para web y los requisitos de sesión vigentes; no se habilita administración de roles desde una sesión compartida.

| Método y ruta | Comportamiento |
| --- | --- |
| `GET /permisos` | Catálogo agrupado por módulo, con etiquetas, descripciones, dependencias y restricciones. Sólo Admin. |
| `GET /roles` | Listado paginado con búsqueda acotada, cantidad de usuarios y marca de rol protegido. |
| `GET /roles/{rol}` | Detalle de rol y permisos seleccionados. |
| `POST /roles` | Crear un rol con nombre visible, descripción y permisos válidos. |
| `PATCH /roles/{rol}` | Cambiar nombre visible o descripción, conservando la clave técnica. |
| `PUT /roles/{rol}/permisos` | Guardar de forma atómica los permisos activados y desactivados del mismo rol, sin modificar sus usuarios asignados. |
| `DELETE /roles/{rol}` | Eliminar un rol personalizado sin usuarios asignados. |
| `PUT /usuarios/{usuario}/rol` | Configurar el único rol de un usuario con las protecciones de Admin; recibe un `rol_id` escalar. Es una operación distinta de editar los permisos del rol. |

Todos los endpoints de esta tabla son exclusivos de Admin. Los filtros, tamaños de página y cantidades de permisos del payload tendrán límites explícitos. El alta y la actualización de usuarios reciben un único rol existente; se rechazan listas, valores nulos y permisos directos.

Ejemplo orientativo de una entrada del catálogo:

```json
{
  "nombre": "can_adjust_feed_stock",
  "modulo": "feed_stock",
  "etiqueta": "Ajustar stock de alimento",
  "descripcion": "Corregir manualmente el saldo de alimento indicando el motivo.",
  "requiere": ["can_view_feed_stock"],
  "asignable": true
}
```

Los identificadores siguen en inglés; los campos del nuevo contrato se presentarán en español. No se acepta `guard_name`, marca de sistema ni metadatos del catálogo desde el cliente. Los permisos inexistentes, reservados o incompatibles se rechazan, sin ignorarlos silenciosamente.

Sustituir el contrato anterior que recibe un array `roles` y adaptar sus consumidores. El perfil y login deben presentar un rol singular y los permisos de ese rol; documentar los campos finales en OpenAPI. Si algún consumidor necesita temporalmente una ruta anterior, ésta usa la misma Policy y Action, acepta únicamente una asignación y no puede conservar el comportamiento de múltiples roles. La transición de campos y claves se coordina con el frontend; no se agregan capas de compatibilidad sólo para conservar datos ficticios.

Respuestas esperadas: 401 sin sesión válida, 403 sin autorización, 422 por selección inválida y 409 por rol asignado, protección del último Admin o versión de edición desactualizada. Una operación idéntica no agrega una segunda auditoría de cambio si no modifica el estado.

## Auditoría, concurrencia y sesiones

Cada alta, edición, eliminación, cambio de permisos y sustitución del rol de un usuario registra auditoría síncrona mediante `AuditRecorder` y `AuditEntryData` en la misma transacción. Incluir actor, sujeto, `operation_id`, `trace_id`, resultado, atributos modificados y snapshot permitido del rol, con permisos anteriores/posteriores o rol anterior/nuevo cuando corresponda. Nunca contraseñas, PIN, tokens ni secretos. Historial append-only durante la operación normal de la aplicación.

La edición de los permisos de un rol requiere la versión que el editor leyó. Dos ediciones concurrentes no pueden sobrescribirse silenciosamente: una recibe 409 y debe recargar. La protección del último Admin necesita serializar también cambios de estado de usuarios, no sólo cambios de roles.

Los permisos se consultan en el servidor y se aplican desde la siguiente solicitud posterior al commit. No se considera suficiente actualizar `/me`: hay que comprobar invalidación de caché, relaciones cargadas y procesos de larga vida según el despliegue. Usar las funciones de Spatie y su invalidación documentada; no asumir que un evento o job encolado resuelve la revocación. Referencia: [Spatie 8 — caché](https://spatie.be/docs/laravel-permission/v8/advanced-usage/cache).

Al cambiar los permisos activados de un rol, evaluar a todos sus usuarios, manteniendo sus asignaciones. También evaluar al usuario cuando Admin cambie su asignación de rol explícitamente. Revocar las sesiones que pierdan elegibilidad de canal y comprobar el permiso de canal vigente en solicitudes posteriores; una sesión emitida antes no conserva acceso por sí misma. Al promover a Admin, conservar la deshabilitación de PIN y revocación de sesiones compartidas, dentro de la misma operación. Conservar cookies stateful y CSRF, PAT con vencimiento, `X-GAM-Session` y generación del dispositivo.

## Reconstrucción del entorno y actualización del contrato

La adopción de `can_*` modifica identificadores usados por backend, frontend, seeders, pruebas, reportes y contratos. No basta con cambiar la tabla de permisos.

1. Inventariar las claves registradas y todas sus comprobaciones. Preparar una matriz `ruta/operación → Policy → clave actual → clave propuesta → módulo`. El objetivo es cubrir las operaciones reales, sin conservar identificadores o pivotes de los datos de prueba.
2. Definir directamente el catálogo final `can_*`, la asignación única y la ausencia de permisos directos. Actualizar backend, frontend, pruebas, contratos y seeders de forma coordinada; evitar una migración histórica compleja para datos descartables.
3. Incorporar permisos que faltan, especialmente elaboración, ajustes y anulaciones de alimento/ingredientes y operaciones de alimentación. Son operaciones diferentes: no proteger todo con un único `can_adjust_feed_stock`. Definir los roles iniciales autorizados antes de cerrar el acceso que hoy sólo exige autenticación.
4. Cambiar `IdentityPermissionSeeder` para actualizar catálogo y Admin, y aplicar valores predeterminados a `employee` y `delivery` sólo al crearlos. Reejecutarlo en una base en uso no puede recuperar permisos que Admin retiró a esos roles ni borrar roles personalizados. El reseteo explícito de datos demo es una operación distinta.
5. Actualizar todos los seeders y factories de usuarios para asignar exactamente un rol y ningún permiso directo. No crear un rol llamado “Repartidor+” como requisito: el nombre fue sólo un ejemplo del usuario. Las pruebas de roles personalizados pueden usar datos arbitrarios y deben verificar permisos configurables, sin variantes o reemplazos automáticos.
6. Reconstruir imágenes/contenedores del proyecto y reinicializar su base de prueba cuando sea útil. El usuario autoriza descartar estos datos y, si hace falta, sus volúmenes de datos del proyecto. Limitar el alcance al Compose de GAM: no limpiar globalmente imágenes, contenedores o volúmenes ajenos. Reconstruir sólo una imagen no borra los datos persistidos en volúmenes.
7. Tras la reconstrucción, ejecutar migraciones y seeders, invalidar cachés y renovar sesiones/perfil. Las pruebas siguen usando PostgreSQL aislado según `README.md`.
8. Esta autorización se aplica al entorno con datos de prueba descrito por el usuario; no sustituye un procedimiento de conservación y migración para una futura instalación con datos reales. No se necesita reconstruir Docker durante esta entrega de planificación.

## Etapas y entregables

| Etapa | Entregable y condición para continuar |
| --- | --- |
| 1. Cerrar catálogo | Matriz completa de operaciones y permisos; módulos, textos y dependencias; un rol por usuario, permisos activables/desactivables y restricciones exclusivas. |
| 2. Persistencia y entorno de prueba | Metadatos de roles, catálogo central, unicidad usuario–rol, sincronización idempotente y seeders listos para reconstruir datos demo. |
| 3. Administración segura | Policies, FormRequests, Queries, Resources y Actions transaccionales; protección de cuentas Admin, último Admin y rutas antiguas. |
| 4. Cobertura funcional | Aplicar el catálogo a las Policies y cerrar operaciones pendientes, incluyendo alimento; revisar selectores, reportes y llamadas internas relacionadas. |
| 5. Contrato y experiencia | API agrupada, OpenAPI y ejemplo de integración; perfil con rol singular y permisos efectivos. La pantalla se implementa en su entrega frontend. |
| 6. Verificación y despliegue | Pruebas focalizadas, regresiones relevantes, Pint/PHPStan según alcance, guía operativa y procedimiento de actualización/recuperación. |

Actualizar `identity-access-implementation.md`, la guía funcional afectada y `contracts/openapi/authentication.yaml`; registrar las rutas nuevas en el contrato correspondiente y su navegación Swagger si se separan en otro archivo. Corregir la indicación obsoleta de acceso por UP en `docs/libraries.md`. Mantener índices de documentación actualizados.

## Criterios de aceptación y pruebas previstas

Aplicar `testing-best-practices`, las reglas de `tests/**` y PHPUnit 12 instalado. Pruebas de comportamiento por endpoint, con comentarios españoles y factories existentes. Ejecutar sobre PostgreSQL aislado según `README.md`.

- Admin activo administra roles. `employee`, `delivery` y un rol personalizado reciben 403, incluso con permisos de gestión de usuarios u otros permisos administrativos. Un invitado recibe 401 y un usuario inactivo no puede operar.
- Ninguna ruta de alta/asignación, cambio de contraseña o estado permite obtener o controlar Admin sin ser Admin. Las rutas antiguas aplican las mismas restricciones.
- No se elimina/renombra el rol Admin ni se recortan sus permisos. No se puede retirar/desactivar al último Admin, incluyendo operaciones concurrentes.
- Catálogo agrupado con claves únicas, descripción y módulo; selección inválida, guard ajeno, campos protegidos y dependencias incompletas se rechazan.
- Un rol vacío no concede capacidades. Crear o editar un usuario exige exactamente un rol; listas, asignaciones vacías y permisos directos se rechazan. Sustituir el rol retira todos los permisos del anterior y concede únicamente los del nuevo, incluso ante solicitudes concurrentes.
- Admin activa un permiso de un rol y sus usuarios obtienen esa capacidad; al desactivarlo dejan de tenerla desde la siguiente solicitud posterior al commit. El ID del rol y las asignaciones de sus usuarios permanecen iguales. Otro rol no se modifica y ningún permiso activado concede administración de roles a quien no es Admin.
- Ver permite consultar, pero no gestionar ni ajustar. Ajustar alimento no permite ajustar ingredientes ni anular elaboraciones. Las restricciones de estado y las exclusividades Admin existentes se mantienen.
- Las operaciones legítimas de producción no exigen permisos de movimientos manuales adicionales para sus efectos internos. Los efectos secundarios usan la autorización de su operación de negocio, sin forzar permisos de otras pantallas.
- Las rutas de alimento dejan de aceptar a cualquier autenticado: se prueban lecturas, elaboración, ajustes, mínimos, cancelaciones, configuración y rectificación según su permiso específico.
- Fallar la auditoría revierte rol, pivotes y efectos de sesión. Repetir una sustitución idéntica no duplica cambios; ediciones simultáneas detectan conflictos.
- La revocación se comprueba con una nueva solicitud y caché previamente utilizada. Cubrir web personal, PAT y sesión compartida; pérdida de canal y promoción a Admin invalidan los contextos correspondientes.
- Reejecutar seeders conserva cambios del cliente y roles personalizados, y concede permisos nuevos a Admin. Una reconstrucción de la base demo genera usuarios con un único rol, sin asignaciones directas, y un catálogo coherente con el contrato nuevo.
- Regresión de acceso global por UP: los permisos funcionales sirven en todas las UP, sin introducir asignaciones por unidad.

Ejecutar primero pruebas de identidad/roles y después las de los dominios modificados. Usar `php artisan test --compact` con archivos/filtros concretos; ejecutar Pint si se modifica PHP y los análisis exigidos por el proyecto. No crear scripts de verificación que dupliquen esas pruebas.

## Sugerencias de experiencia de configuración

- Mostrar etiquetas españolas y descripciones breves; dejar `can_adjust_feed_stock` como identificador secundario consultable.
- Agrupar por módulos plegables, con búsqueda y contador de permisos seleccionados.
- Distinguir “Ver”, “Gestionar” y acciones sensibles. “Seleccionar módulo” muestra cuáles se agregarán y excluye capacidades no delegables.
- Separar permisos de acceso web/nativo/compartido de las tareas del negocio y explicar el efecto de retirarlos.
- El editor de cada rol muestra casillas o interruptores de permisos agrupados por módulo. Activar o desactivar una casilla modifica ese rol al guardar, con efecto sobre todos sus usuarios.
- El formulario de usuario muestra un selector de rol único. La configuración de permisos pertenece al rol; no hay casillas de permisos individuales ni selección múltiple de roles.
- Antes de guardar, mostrar permisos agregados/retirados y cantidad de usuarios afectados. Mostrar dependencias y restricciones en el mismo editor.

## Decisiones aplicadas al aprobar la implementación

Las especificaciones dadas fijan Policies, roles como agrupaciones, administración exclusiva de Admin, módulos y descripciones. El usuario confirmó además un único rol por usuario, permisos activables/desactivables en cada rol y la posibilidad de reconstruir Docker y descartar los datos actuales de prueba. “Repartidor+” fue sólo un ejemplo, sin requerimiento de crearlo ni de implementar reemplazos. La solicitud de implementar el plan autoriza su propuesta principal:

1. Adoptar `can_*` para todo el catálogo y actualizar el contrato de sus consumidores.
2. Reservar también a Admin la asignación de roles y las operaciones administrativas sobre cuentas Admin.
3. Aplicar el catálogo central por módulos, conservando las restricciones exclusivas y los requisitos de acceso por canal.

El comportamiento y los resultados finales se describen en la guía de implementación enlazada al inicio.

## Verificación de esta entrega de planificación

Se revisaron las reglas del proyecto, seeders, modelo User, controladores y requests de identidad, ejemplos de Policies, autorización de alimento, recursos del perfil, middleware compartido y documentación relacionada. Se comprobaron versiones instaladas y rutas mediante Artisan (277 rutas bajo `api/v1`). Laravel Boost no está expuesto entre las herramientas disponibles de esta sesión; se consultaron fuentes oficiales compatibles con las versiones instaladas.

No se ejecutaron migraciones, seeders ni pruebas funcionales porque esta entrega sólo documenta una propuesta. No se modificaron permisos, usuarios, sesiones, contratos activos ni dependencias.
