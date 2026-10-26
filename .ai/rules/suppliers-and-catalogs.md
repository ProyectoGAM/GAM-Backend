---
paths:
  - 'app/**/SuppliersAndCatalogs/*.php'
---

# Suppliers And Catalogs

## Retirada operativa de finished_feed sin migración automática
finished_feed no admite altas ni conversiones nuevas: FormRequests devuelven 422 en kind y Actions bloquean bypasses internos. Conservar ProductKind::FinishedFeed, el cast y products_kind_check mientras puedan existir filas antiguas; edición de metadata y lectura históricas siguen disponibles. No reclasificar, borrar ni trasladar cantidades automáticamente a recetas o feed_balances. RecordFeedSupplyAction sólo reproduce operaciones históricas guardadas; claves nuevas responden 410, manteniendo consulta, anulación auditada y reportes de feed_supplies. ReportingDemoSeeder no crea productos ni suministros antiguos. FeedRecipe, ingredientes, FeedBalance con mínimos, RationChange, elaboraciones y consumos actuales se conservan íntegros. Retirada final del enum exige clasificación explícita por entorno y migración nueva del CHECK, nunca editar sólo la original.
