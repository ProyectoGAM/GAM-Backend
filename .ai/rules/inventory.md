---
paths:
  - 'app/**/Inventory/*Recipe*.php'
  - 'app/**/Inventory/*Feed*.php'
---

# Inventory

## Recetas y elaboracion futura
El catalogo de recetas es global y usa ingredientes existentes de Inventario. La futura elaboracion descontara ingredientes de una planta de racion y guardara alimento por receta y UP sin separar saldos por versiones; los stocks podran ser negativos. Desactivar una receta impide nuevas elaboraciones pero NO detiene el consumo de los lotes: continuan con su receta y cantidad aun con saldo cero o negativo hasta cambiar alimentacion o desactivar el lote. Elaboracion, consumo, herencia del plan y alertas requieren decisiones adicionales y no deben deducirse del calculo de recetas.

## Elaboración, compensación y ajustes de alimento
El segundo bloque ya implementa elaboración inmediata: ingredientes activos, plantas exclusivamente operativas de la misma UP, seleccionada primero y luego saldo ascendente por ingrediente con empate por ID de planta. Adquirir locks en orden técnico fijo antes de distribuir; elaborar y ajustar ingredientes nunca deja nuevos negativos. Alimento mantiene saldo entero firmado por receta/UP. Cancelar agrega historia, devuelve las líneas originales aun con ingredientes inactivos sin reactivarlos y exige receptores operativos y alimento final no negativo. Sumar/restar requieren magnitud positiva; establecer ingredientes admite cero/positivos y alimento enteros firmados. Los nuevos endpoints usan sesión autenticada y contexto compartido; permiso específico pendiente por decisión del usuario. Esta entrega sustituye la mención anterior a elaboración pendiente; el consumo de lotes sigue fuera de alcance.

## Alimentación diaria y rectificaciones de lotes
El tercer bloque consume FeedBalance por receta/UP sin tocar ingredientes ni elaboraciones. El manual es positivo, fijo e indefinido; rectificaciones admiten cero e inactivas y anexan compensaciones desde el último efectivo sin cambiar configuración. Automático calcula etapa copiada × censo histórico al cierre; cero no mueve stock. Recuperación y configuración usan fechas efectivas America/Montevideo, y recetas disponibles según historia, nunca sólo estado actual. Consumos únicos lote/día, locks ordenados, auditoría síncrona sin actor humano para el scheduler y journal vinculado a flock.feeding.daily.

## Disponibilidad al comenzar etapas automáticas
Las etapas automáticas evalúan receta disponible a las 00:00 de su día de comienzo en America/Montevideo. Si estaba activa, queda vigente aunque se desactive durante esa misma jornada; recuperar consumos usa esa disponibilidad histórica. El alta, una revisión explícita y el regreso a automático seleccionan su ancla en el instante de la operación. Esta entrega sustituye las notas anteriores que dejaban el consumo de lotes pendiente o fuera de alcance.
