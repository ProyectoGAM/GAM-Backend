---
paths:
  - 'app/**/Inventory/*Recipe*.php'
  - 'app/**/Inventory/*Feed*.php'
  - 'app/**/Inventory/*.php'
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

## Mínimos de alimento por receta y UP
FeedBalance.minimum_quantity_grams es nullable; null significa sin configurar, cero es válido y el rango es 0..999999999999 gramos enteros. El mínimo pertenece a receta/UP, nunca a planta. Configurarlo requiere inventory.manage o admin y auditoría síncrona en la misma transacción; no modifica quantity_grams y puede materializar una cuenta en cero sin movimientos. Estado y atención se calculan con FeedBalanceStatus: atención sólo con mínimo configurado y saldo <= mínimo. FeedBalanceEvaluated se publica después del commit de elaboraciones, ajustes, cancelaciones, consumos, rectificaciones y mínimos; Alertas será responsable de notificaciones y deduplicación.

## Compatibilidad de productos en movimientos
La consulta productos-permitidos y el registro transaccional comparten StockProductCompatibility para categoria y unidad por ubicacion. El filtro loss exige saldo local positivo solo para el selector: no cambia los negativos permitidos de materias primas ni reserva stock. Al cambiar ubicacion u operacion, el cliente debe invalidar selecciones y respuestas anteriores; una pagina o busqueda parcial no demuestra incompatibilidad.

## Tipos de almacenamiento y sanidad por UP
StockLocation.type define warehouse (supply/other), feed_plant (raw_material en g), egg_account (egg protegido), health (medicine/vaccine) y unknown (ninguno). Sanidad es unica por UP. Consultas y escritura usan StockProductCompatibility; nuevos movimientos requieren ubicaciones y UP activas y plantas operativas. Preparar metadatos es explicito y auditado; no reclasificar ni trasladar cantidades automaticamente. Regularizacion solo reduce un saldo incompatible hacia cero y anexa historial. Proveedor interno usa suppliers.system_key=internal_supplier; resolverlo en ingresos no crea ni reactiva proveedores. El contador clinico global de medicamentos no es existencia fisica por UP.
