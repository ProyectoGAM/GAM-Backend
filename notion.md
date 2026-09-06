# Seguimiento de módulos en Notion

## Entrega multi-login GAM

Implementado en backend y frontend: login web con cookie stateful/CSRF, login nativo con PAT, vinculación de dispositivos compartidos, PIN Argon2id con pepper, sesiones revocables y auditoría transaccional. El contrato está en `contracts/openapi/authentication.yaml`.

La validación automatizada se ejecutó con Docker Compose. Los builds firmados Android/iOS y la validación de navegador físico requieren sus plataformas/toolchains respectivas.

Registro local del avance de los módulos, tanto completos como parcialmente implementados. Este archivo permite preparar la actualización del Kanban sin afirmar que Notion fue modificado.

## Estado general

No se consultó ni modificó Notion durante esta entrega. Al sincronizar, mover una tarjeta de módulo a `Do Test` únicamente cuando su alcance esté implementado y falte la validación manual. Una funcionalidad terminada dentro de un módulo incompleto se registra como avance parcial, sin mover la tarjeta del módulo a `Do Test` ni darla por terminada. Las pruebas automatizadas no sustituyen la aceptación manual.

## Módulos implementados pendientes de reflejar

| Módulo / tarjeta Notion | Estado en código | Documentación fuente | Contrato | Pendiente |
|---|---|---|---|---|
| **07 — Mantenimiento de instalaciones** | Implementado en `develop` | [maintenance-implementation.md](maintenance-implementation.md) | [maintenance.yaml](contracts/openapi/maintenance.yaml) | Sincronizar la tarjeta y ejecutar Do Test |
| **05 — Lotes y cría** | Implementado en `Lotes` | [lots-implementation.md](lots-implementation.md) | [lots.yaml](contracts/openapi/lots.yaml) | Sincronizar la tarjeta y ejecutar Do Test manual |
| **09 — Producción y stock de huevos** | Implementado en `ProduccionStockHuevos` | [egg-production-implementation.md](egg-production-implementation.md) | [lots.yaml](contracts/openapi/lots.yaml) | Pendiente: actualizar la tarjeta y ejecutar Do Test manual |

## Módulos parcialmente implementados

| Módulo / tarjeta Notion | Estado en código | Documentación fuente | Contrato | Trabajo restante |
|---|---|---|---|---|
| **06 — Manejo productivo y sanidad** | **Medicamentos implementados; módulo aún incompleto** | [medication-implementation-plan.md](medication-implementation-plan.md) | [medication.yaml](contracts/openapi/medication.yaml) | Completar las demás secciones del módulo; mantener la tarjeta en implementación |

## Módulo 06 — Manejo productivo y sanidad

**Medicamentos está implementado en su alcance actual de catálogo. El módulo completo todavía no está listo: faltan las demás secciones de manejo productivo y sanidad.** Este avance no debe registrarse como un módulo terminado pendiente de mover.

Disponible en código:

- Alta y consulta paginada de medicamentos mediante `/api/v1/medicamentos`, exclusivamente para administradores activos.
- Nombre, descripción y proveedor; se permiten nombres repetidos y se conserva la identidad individual de cada ficha.
- Idempotencia, auditoría transaccional, búsqueda, filtro por proveedor y datos demo locales.

La asignación a un futuro plan de manejo relacionado con un lote y las fechas de aplicación quedan diferidas hasta definir ese dominio. Las dosis son una posible ampliación, todavía no confirmada. El catálogo no registra intervenciones ni modifica stock.

Las demás secciones, como vacunación, pesajes y otros manejos, quedan fuera de esta entrega y requieren su propia definición e implementación. La mortalidad ya pertenece a Lotes y no debe duplicarse aquí. La ubicación técnica del catálogo es `SuppliersAndCatalogs`; esta tarjeta registra su aporte al área funcional de la hoja de ruta.

Validación automatizada registrada el 2026-09-05: 52 pruebas aprobadas con 826 aserciones, incluyendo regresiones seleccionadas, y una prueba de concurrencia separada con 4 aserciones. La aceptación manual del catálogo sigue pendiente y puede documentarse por separado, sin cambiar el estado incompleto del módulo.

## Módulo 05 — Lotes y cría

Incluye el ciclo de vida de lotes, altas, estados, semana actual, capacidad derivada por ocupación, redistribución parcial hacia lotes nuevos o existentes, traslado total conservando identidad, finalización, historial, auditoría, mortalidad y recolección de huevos integrada atómicamente con Inventario.

Decisiones relevantes:

- La redistribución parcial no crea genealogía padre-hijo; registra un movimiento histórico con origen y destino.
- Agregar aves a un lote existente se realiza mediante redistribución parcial, exige misma raza, lotes activos y versiones válidas.
- El traslado total conserva el lote y su identidad; no se borra ni se fusiona.
- Las correcciones son compensaciones auditadas y no modifican el histórico original.
- El soporte offline implementado cubre idempotencia, ULID público y conflictos por versión; el almacenamiento y la sincronización del dispositivo quedan fuera de este backend.

Validación automatizada registrada: 151 pruebas aprobadas y 981 aserciones antes de integrar cambios posteriores de `develop`. La guía [lots-implementation.md](lots-implementation.md) contiene el procedimiento completo de Do Test, incluyendo evidencia requerida, escenarios de redistribución, mortalidad, huevos, permisos, auditoría y concurrencia.

## Módulo 09 — Producción y stock de huevos

Implementado en `ProduccionStockHuevos`. La documentación [egg-production-implementation.md](egg-production-implementation.md) describe el registro por lote de huevo genérico, la cuenta corriente por UP, ingresos manuales, preparaciones de reparto, pérdidas, correcciones append-only, integración atómica con Inventario, métricas y seeder demo.

La tarjeta de Notion con el título exacto **`09 — Producción y stock de huevos`** queda pendiente de actualización. La aceptación manual **Do Test** también queda pendiente; la validación automatizada no sustituye esa revisión. Al sincronizar la tarjeta, adjuntar el contrato [lots.yaml](contracts/openapi/lots.yaml), la evidencia de saldos y auditoría, y el resultado de cada escenario manual.

## Instrucciones para sincronizar en Notion

1. Abrir el tablero y localizar la tarjeta con el título exacto del módulo.
2. Comprobar si otra persona ya actualizó la tarjeta; evitar duplicar contenido o retroceder su estado.
3. Copiar el resumen y las decisiones desde el documento fuente correspondiente.
4. Adjuntar o enlazar el contrato OpenAPI y registrar las pruebas automatizadas.
5. Para módulos completos, mover la tarjeta a `Do Test` si falta aceptación manual. Para módulos parciales, actualizar las secciones terminadas y el trabajo restante manteniendo el módulo en implementación. La aceptación manual debe ejecutarse en un ambiente local o QA, nunca en producción.
6. Registrar responsable, fecha, rama o commit, ambiente, casos ejecutados, respuestas HTTP, auditoría y cualquier bloqueo.
7. Después de completar Do Test, actualizar este archivo con la fecha y el enlace a la tarjeta de Notion.

## Criterio de mantenimiento

Registrar cada módulo en la tabla correspondiente a su avance real. Una entrega parcial debe indicar qué sección está implementada y qué falta para completar el módulo; no debe aparecer como módulo terminado pendiente de mover. Al sincronizar Notion y completar la aceptación correspondiente, conservar el enlace histórico y actualizar el estado en esta página.
