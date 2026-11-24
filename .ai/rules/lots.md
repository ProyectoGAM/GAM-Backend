---
paths:
  - 'app/Services/Lots/*ReportSource.php'
---

# Lots

## Período y contexto de Lotes completos
lots.summary usa una fila por lote. from/to filtra hechos de bajas y postura y selecciona lotes presentes en el período, mientras entry_date filtra el ingreso por separado. Alojamiento, capacidad física y estado son actuales; un lote finalizado conserva su último galpón. Agregar bajas y postura por separado antes del join y restringir ambos hechos a la UP del contexto PIN.
