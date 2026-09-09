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
| **06 — Manejo productivo y sanidad** | **Medicamentos, Vacunas y Pesajes implementados; módulo aún incompleto** | [medication-implementation-plan.md](medication-implementation-plan.md), [vaccination-implementation.md](vaccination-implementation.md) y [weighing-implementation.md](weighing-implementation.md) | [medication.yaml](contracts/openapi/medication.yaml), [vaccination.yaml](contracts/openapi/vaccination.yaml) y [weighings.yaml](contracts/openapi/weighings.yaml) | Completar Plan de Manejo, aplicaciones y las demás secciones; mantener la tarjeta en implementación |

## Módulo 06 — Manejo productivo y sanidad

**Medicamentos y Vacunas están implementados en su alcance actual de catálogo, y Pesajes está implementado como manejo productivo de lotes. El módulo completo todavía no está listo: faltan Plan de Manejo, aplicaciones y las demás secciones de manejo productivo y sanidad.** Este avance no debe registrarse como un módulo terminado pendiente de mover.

Disponible en código:

- Alta y consulta paginada de medicamentos mediante `/api/v1/medicines`, exclusivamente para administradores activos.
- Nombre, descripción y proveedor; se permiten nombres repetidos y se conserva la identidad individual de cada ficha.
- Idempotencia, auditoría transaccional, búsqueda, filtro por proveedor y datos demo locales.
- CRUD backend de vacunas mediante `/api/v1/vacunas`, exclusivamente para administradores activos.
- SKU y nombre único, descripción, detalles opcionales, proveedor y baja lógica con conflicto cuando existe stock positivo.
- Relación 1:1 con el Product de Inventario, unidad `dose` predeterminada, saldos y movimientos compartidos, idempotencia, auditoría atómica y locks de concurrencia.
- Configuración global versionada de rangos de peso para etapas `chick` y `adult`, administrable mediante `/api/v1/configuracion-pesajes`.
- Registro individual y grupal de pesajes mediante `/api/v1/pesajes`, con gramos normalizados, confirmación explícita de valores fuera de rango, proyección histórica del lote y correcciones auditadas.
- Listado, detalle, evolución por lote y distribución individual consumible por el frontend externo, con salida en gramos o kilogramos y límite de 1000 puntos de evolución.
- Permisos `weighings.view`, `weighings.manage` y `weighing-settings.manage`, idempotencia, control optimista, locks transaccionales y soporte para autenticación personal o compartida.

La asignación a un futuro Plan de Manejo relacionado con un lote, las fechas de aplicación y el consumo ocasionado por una vacunación quedan diferidos hasta definir ese dominio. Los catálogos no registran intervenciones ni modifican stock por sí mismos.

Las demás secciones, como Plan de Manejo, aplicaciones y otros manejos, quedan fuera de esta entrega y requieren su propia definición e implementación. La mortalidad ya pertenece a Lotes y no debe duplicarse aquí. La ubicación técnica de los catálogos es `SuppliersAndCatalogs`; Pesajes pertenece a `Lots` porque depende de la población, ubicación y ciclo de vida históricos del lote. Esta tarjeta registra el aporte de ambas áreas técnicas al módulo funcional.

Validación automatizada registrada el 2026-09-05: 52 pruebas aprobadas con 826 aserciones, incluyendo regresiones seleccionadas, y una prueba de concurrencia separada con 4 aserciones. La aceptación manual del catálogo sigue pendiente y puede documentarse por separado, sin cambiar el estado incompleto del módulo.

Validación de Vacunas registrada el 2026-09-08: 16 pruebas específicas aprobadas con 107 aserciones; suite completa aprobada con variables de testing válidas, 271 pruebas y 1803 aserciones; Larastan, Pint y `git diff --check` correctos. La guía [vaccination-implementation.md](vaccination-implementation.md) contiene el procedimiento de Do Test y las observaciones técnicas pendientes de autenticación compartida, validación interna y cobertura. La tarjeta debe permanecer en implementación hasta resolverlas y completar la aceptación manual.

Validación de Pesajes registrada el 2026-09-09: 37 pruebas focalizadas aprobadas con 441 aserciones; suite completa aprobada con `APP_KEY` y pepper temporales válidos, 305 pruebas y 2226 aserciones; contrato final aprobado con 5 pruebas y 197 aserciones; Larastan, Pint, rutas y `git diff --check` correctos. Las revisiones funcional y de seguridad cerraron sin bloqueadores. La guía [weighing-implementation.md](weighing-implementation.md) contiene fórmulas, contrato, datos demo y procedimiento de Do Test. Esta sección está lista para aceptación manual, pero la tarjeta del Módulo 06 debe permanecer en implementación.

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
