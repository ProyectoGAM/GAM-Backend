# Seguimiento de módulos en Notion

Registro local de funcionalidades implementadas que todavía deben reflejarse en el Kanban de Notion. Este archivo permite preparar la actualización sin afirmar que Notion fue modificado.

## Estado general

No se consultó ni modificó Notion durante esta entrega por falta de acceso al tablero. Cuando haya acceso, actualizar las tarjetas correspondientes y moverlas a `Do Test` únicamente si la validación manual aún no fue realizada. No marcar una tarjeta como probada sólo porque la suite automatizada haya pasado.

## Módulos implementados pendientes de reflejar

| Módulo / tarjeta Notion | Estado en código | Documentación fuente | Contrato | Pendiente |
|---|---|---|---|---|
| **07 — Mantenimiento de instalaciones** | Implementado en `develop` | [maintenance-implementation.md](maintenance-implementation.md) | [maintenance.yaml](contracts/openapi/maintenance.yaml) | Sincronizar la tarjeta y ejecutar Do Test |
| **05 — Lotes y cría** | Implementado en `Lotes` | [lots-implementation.md](lots-implementation.md) | [lots.yaml](contracts/openapi/lots.yaml) | Sincronizar la tarjeta y ejecutar Do Test manual |
| **09 — Producción y stock de huevos** | Implementado en `ProduccionStockHuevos` | [egg-production-implementation.md](egg-production-implementation.md) | [lots.yaml](contracts/openapi/lots.yaml) | Pendiente: actualizar la tarjeta y ejecutar Do Test manual |

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
5. Mover la tarjeta a `Do Test` si corresponde. La aceptación manual debe ejecutarse en un ambiente local o QA, nunca en producción.
6. Registrar responsable, fecha, rama o commit, ambiente, casos ejecutados, respuestas HTTP, auditoría y cualquier bloqueo.
7. Después de completar Do Test, actualizar este archivo con la fecha y el enlace a la tarjeta de Notion.

## Criterio de mantenimiento

Cada módulo nuevo debe agregarse a la tabla cuando su implementación esté disponible en código pero todavía no exista evidencia de sincronización en el Kanban. Al completarse Notion y Do Test, conservar el enlace histórico y actualizar el estado en esta página.
