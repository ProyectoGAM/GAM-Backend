---
paths:
  - 'app/Services/Inventory/*ReportSource.php'
---

# Services Inventory

## Reportes de alimentación diaria y suministros históricos
management.feeding consulta feed_consumptions con la última feed_consumption_corrections por ID; COALESCE debe conservar el cero. No sumar originales, compensaciones ni estimaciones legadas. Galpón se reconstruye desde movimientos al cierre local de consumption_date en America/Montevideo, nunca desde el galpón actual; UP es la del consumo y un galpón incompatible queda nulo. Requiere can_view_flock_feeding. management.feed-supply conserva sus presets, permisos y estimaciones como Alimentación histórica.
