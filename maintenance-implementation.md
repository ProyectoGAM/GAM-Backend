# Mantenimientos de instalaciones — FarmStructure

**Descripción:**

Registrar y consultar el histórico de mantenimientos realizados en los galpones de la granja.

Incluye:

- fecha de realización y descripción;
- costo exacto con moneda explícita;
- galpón y responsable;
- histórico cronológico paginado;
- consulta del último mantenimiento vigente;
- correcciones con motivo y auditoría;
- cancelación sin borrado físico.

Es funcionalidad nueva incorporada a `FarmStructure`. En esta entrega, la instalación mantenida es un `PoultryHouse`, vinculado a su unidad productiva. No se crea una entidad genérica de instalaciones ni se modifica el mantenimiento vehicular.

**Dependencias:**

- Estructura de la granja: galpones y unidades productivas existentes.
- Usuarios y permisos funcionales.
- Auditoría y trazabilidad mediante `AuditRecorder` y `AuditEntryData`.
- `App\Shared\Money`, basado en la dependencia existente `brick/money`.

No depende de Lotes, Alertas ni Notificaciones para esta etapa.

[Contrato OpenAPI — Mantenimientos](contracts/openapi/maintenance.yaml)

## Implementación realizada — 30/08/2026

- Se agregó `Maintenance`, su factory y la tabla `maintenances` con claves foráneas restrictivas e índices para histórico y último mantenimiento.
- Se implementaron seis operaciones HTTP: registro, histórico, detalle, último mantenimiento, corrección y cancelación.
- Las escrituras usan FormRequests, Policies, Actions, transacciones PostgreSQL y auditoría síncrona. Las lecturas usan Queries y Resources sin relaciones implícitas en la respuesta.
- Se creó `Money` como valor inmutable compartido. El costo se almacena en `DECIMAL(19,4)`, se recibe como texto decimal y se normaliza según la moneda sin utilizar float ni redondear silenciosamente.
- Se agregó idempotencia al alta y control de versión para correcciones y cancelaciones.
- Se integraron las convenciones de `main`: campos y filtros HTTP en español mediante `PublicInputMapper`, conservando los identificadores internos en inglés.
- Se agregó `MaintenanceDemoSeeder`, invocado por `LocalDemoDataSeeder` sólo en ambiente local. Crea cuatro mantenimientos pasados para los tres galpones demo mediante la Action y su auditoría, identificada con origen `seeder`. Repetirlo no duplica hechos ni sobrescribe fechas o correcciones existentes.
- Se actualizaron el README y la documentación de arquitectura. No se agregaron dependencias.

### Decisiones de histórico

El mantenimiento representa un hecho realizado. La fecha es obligatoria, con formato `YYYY-MM-DD`, y no puede ser posterior a la fecha actual de la aplicación.

Los estados son `completed` y `cancelled`. La única transición es de realizado a cancelado, mediante su Action. Un cancelado no se edita ni se reactiva. Para rectificar una cancelación errónea debe registrarse un nuevo mantenimiento con una descripción que explique la relación con el anterior.

La fila del mantenimiento conserva su versión actual. Cada corrección agrega una entrada de auditoría con valores anteriores, nuevos y motivo; las entradas anteriores nunca se modifican. La cancelación conserva fecha, costo, descripción y responsable y agrega motivo y momento de cancelación. No existen endpoints DELETE ni `isDeleted` ni SoftDeletes para este recurso.

El histórico incluye cancelados por defecto y se ordena por `maintenance_date DESC, id DESC`. La consulta operacional de último mantenimiento excluye cancelados. Si no existe un mantenimiento vigente, responde `200` con `{"data": null}`.

El responsable es un usuario activo al asignarlo. Se conserva una copia de su nombre, para que una modificación o baja posterior del usuario no reescriba la historia. Su baja no impide corregir otros datos del mantenimiento.

Se pueden registrar hechos pasados de galpones o UP actualmente inactivos. Registrar o corregir un mantenimiento no modifica el estado, la ocupación ni la capacidad física del galpón.

### Acceso y concurrencia

Se reutilizan `poultry-houses.view` para lecturas y `poultry-houses.manage` para escrituras; el administrador también tiene acceso. Los usuarios desactivados no reciben acceso.

Los permisos son globales en la empresa. No hay asignaciones usuario–UP, filtros por propietario ni permisos que contengan IDs.

El alta exige `Idempotency-Key` como UUID. La clave se limita al actor autenticado: un reintento con igual contenido normalizado devuelve el mantenimiento existente sin duplicar la auditoría; si el contenido cambia, devuelve `409`. La repetición puede devolver el estado actual de un registro corregido o cancelado.

Las correcciones y cancelaciones exigen `version` y `motivo`. La Action bloquea la fila y verifica la versión antes de escribir; una versión obsoleta devuelve `409`. Cada operación confirmada incrementa la versión.

### Contrato de integración

| Método | Ruta bajo /api/v1 | Uso |
|---|---|---|
| GET | /galpones/{poultryHouse}/mantenimientos | Histórico paginado |
| POST | /galpones/{poultryHouse}/mantenimientos | Registrar un hecho realizado |
| GET | /galpones/{poultryHouse}/mantenimientos/ultimo | Último vigente o data null |
| GET | /mantenimientos/{maintenance} | Detalle, incluidos cancelados |
| PATCH | /mantenimientos/{maintenance} | Corrección auditada |
| POST | /mantenimientos/{maintenance}/cancelacion | Cancelación con motivo |

El alta recibe `fecha_mantenimiento`, `descripcion`, `costo_importe`, `costo_moneda` y `responsable_id`. No admite cambiar `galpon_id` desde el cuerpo, editar directamente `estado` ni enviar `programado_para`.

El campo `costo` de salida tiene forma `{"importe": "1250.50", "moneda": "UYU"}`. La moneda siempre debe enviarse explícitamente; el ejemplo no establece una moneda única para el sistema. El responsable se devuelve como `responsable: {id, nombre}`. Los estados estables siguen siendo `completed` y `cancelled`; las columnas y snapshots internos permanecen en inglés.

El histórico permite `estado`, `fecha_desde`, `fecha_hasta`, `por_pagina` de 1 a 100 (por defecto 50) y `pagina` de 1 a 100000. Los enlaces de paginación conservan esos filtros. Los errores de autenticación, autorización, validación, inexistencia y conflicto siguen `application/problem+json`.

La auditoría utiliza los eventos `maintenance_created`, `maintenance_corrected` y `maintenance_cancelled`, con actor, sujeto, UP, resultado, cambios y snapshot permitido, además de `operation_id` y `trace_id`. Si falla la auditoría se revierte toda la operación. Sus lecturas continúan requiriendo `audit.view` en el endpoint existente de auditoría.

### Fuera de alcance

- Programación o planes futuros de mantenimiento.
- Alertas y notificaciones preventivas.
- Mantenimiento vehicular.
- Órdenes de trabajo, adjuntos o movimientos contables automáticos.
- Interfaz Angular: esta entrega implementa el backend y su contrato.

### Validación automatizada

- PostgreSQL 18: migraciones de prueba desde cero; nueva migración aplicada correctamente en desarrollo.
- PHPUnit, suite completa después de integrar `origin/main` (`7587e82`): **141 tests, 537 aserciones, 0 fallos y 0 advertencias**.
- Se agregaron **55 casos** de prueba de mantenimientos, Money y datos demo, incluidos validación, permisos, historial, precisión monetaria, reintentos, versiones obsoletas, cancelación, rollback de las tres escrituras cuando falla auditoría, paginación con filtros y seeding local idempotente.
- PHPStan con Larastan, nivel 5, sobre los 29 archivos PHP de implementación e integración: **0 errores**. Se eliminó una clave duplicada del mapeador incorporado por `main` y se explicitó el tipo enum de la unidad del producto para analizar sus datos demo, sin cambiar el comportamiento existente.
- Pint: correcto sobre los 32 archivos PHP del cambio. Se intentó `--dirty --format agent`; como la imagen no incluye Git, se utilizó la lista exacta obtenida desde el repositorio del host.
- OpenAPI: sintaxis YAML correcta y seis operaciones verificadas con `route:list`.
- Validación de diff: correcta.

Las pruebas se ejecutaron sobre la base aislada `gam_maintenance_test`, nunca mediante un borrado de la base de desarrollo. La configuración del contenedor proviene de `compose.dev.yaml`; se creó un `.env` local ignorado, sin credenciales, para evitar advertencias de carga del entorno.

### Despliegue

La migración es aditiva y no cambia tablas existentes. En otros entornos se aplica con `php artisan migrate --no-interaction`, siguiendo el procedimiento de despliegue del proyecto.

El rollback de esta migración elimina la tabla y su histórico: no debe utilizarse como corrección de negocio ni como procedimiento de recuperación de datos en producción. Para corregir registros se utilizan las Actions y la auditoría.
