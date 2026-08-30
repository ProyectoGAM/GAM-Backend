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

Las correcciones y cancelaciones exigen `version` y `reason`. La Action bloquea la fila y verifica la versión antes de escribir; una versión obsoleta devuelve `409`. Cada operación confirmada incrementa la versión.

### Contrato de integración

| Método | Ruta bajo /api/v1 | Uso |
|---|---|---|
| GET | /galpones/{poultryHouse}/mantenimientos | Histórico paginado |
| POST | /galpones/{poultryHouse}/mantenimientos | Registrar un hecho realizado |
| GET | /galpones/{poultryHouse}/mantenimientos/ultimo | Último vigente o data null |
| GET | /mantenimientos/{maintenance} | Detalle, incluidos cancelados |
| PATCH | /mantenimientos/{maintenance} | Corrección auditada |
| POST | /mantenimientos/{maintenance}/cancelacion | Cancelación con motivo |

El alta recibe `maintenance_date`, `description`, `cost_amount`, `cost_currency` y `responsible_user_id`. No admite cambiar el galpón desde el cuerpo, editar directamente el estado ni programar una fecha futura.

El costo de salida tiene forma `{"amount": "1250.50", "currency": "UYU"}`. La moneda siempre debe enviarse explícitamente; el ejemplo no establece una moneda única para el sistema.

El histórico permite `status`, `date_from`, `date_to`, `per_page` de 1 a 100 (por defecto 50) y `page` de 1 a 100000. Los errores de autenticación, autorización, validación, inexistencia y conflicto siguen `application/problem+json`.

La auditoría utiliza los eventos `maintenance_created`, `maintenance_corrected` y `maintenance_cancelled`, con actor, sujeto, UP, resultado, cambios y snapshot permitido, además de `operation_id` y `trace_id`. Si falla la auditoría se revierte toda la operación. Sus lecturas continúan requiriendo `audit.view` en el endpoint existente de auditoría.

### Fuera de alcance

- Programación o planes futuros de mantenimiento.
- Alertas y notificaciones preventivas.
- Mantenimiento vehicular.
- Órdenes de trabajo, adjuntos o movimientos contables automáticos.
- Interfaz Angular: esta entrega implementa el backend y su contrato.

### Validación automatizada

- PostgreSQL 18: migraciones de prueba desde cero; nueva migración aplicada correctamente en desarrollo.
- PHPUnit, suite completa: **134 tests, 497 aserciones, 0 fallos y 0 advertencias**.
- Se agregaron **51 casos** de prueba de mantenimientos y Money, incluidos validación, permisos, historial, precisión monetaria, reintentos, versiones obsoletas, cancelación y rollback de las tres escrituras cuando falla auditoría.
- PHPStan con Larastan, nivel 5, sobre el código nuevo del módulo y sus Requests: **0 errores**.
- Pint: correcto sobre los 27 archivos PHP del cambio. Se intentó `--dirty --format agent`; como la imagen no incluye Git, se utilizó la lista exacta obtenida desde el repositorio del host.
- OpenAPI: sintaxis YAML correcta y seis operaciones verificadas con `route:list`.
- Validación de diff: correcta.

Las pruebas se ejecutaron sobre la base aislada `gam_maintenance_test`, nunca mediante un borrado de la base de desarrollo. La configuración del contenedor proviene de `compose.dev.yaml`; se creó un `.env` local ignorado, sin credenciales, para evitar advertencias de carga del entorno.

### Despliegue

La migración es aditiva y no cambia tablas existentes. En otros entornos se aplica con `php artisan migrate --no-interaction`, siguiendo el procedimiento de despliegue del proyecto.

El rollback de esta migración elimina la tabla y su histórico: no debe utilizarse como corrección de negocio ni como procedimiento de recuperación de datos en producción. Para corregir registros se utilizan las Actions y la auditoría.
