---
paths:
  - 'app/**/Inventory/*Recipe*.php'
---

# Inventory

## Recetas y elaboracion futura
El catalogo de recetas es global y usa ingredientes existentes de Inventario. La futura elaboracion descontara ingredientes de una planta de racion y guardara alimento por receta y UP sin separar saldos por versiones; los stocks podran ser negativos. Desactivar una receta impide nuevas elaboraciones pero NO detiene el consumo de los lotes: continuan con su receta y cantidad aun con saldo cero o negativo hasta cambiar alimentacion o desactivar el lote. Elaboracion, consumo, herencia del plan y alertas requieren decisiones adicionales y no deben deducirse del calculo de recetas.
