---
paths:
  - 'app/**/ReportingAndAnalytics/**'
---

# Reporting And Analytics

## Rango móvil en configuraciones y fechas congeladas en exportaciones
date_preset=last_month resuelve un mes móvil con subMonthNoOverflow hasta hoy en America/Montevideo sólo cuando ambos límites son nulos. ReportQueryData.toPresetArray conserva from/to nulos para recalcular y ReportPresetResource expone resolved_configuration para el front. toArray conserva las fechas concretas de la solicitud para que el worker no desplace el rango. Sin el marcador se mantiene el comportamiento previo; se omite o anula para guardar un rango fijo. DefaultReportPresetsSeeder crea Manejos, Repartos y Postura para auth.admin.email sin sobrescribir nombres normalizados existentes.

## Mes calendario anterior como rango predeterminado
Decisión posterior del usuario: sustituye la regla previa de rango móvil. date_preset=last_month significa el mes calendario anterior COMPLETO, del día 1 al último día inclusive, en America/Montevideo. Las tres configuraciones Manejos/Repartos/Postura ya guardadas usan ese mismo marcador y límites nulos, por lo que se actualizan al resolver sin reescribir personalizaciones. Mantener fechas explícitas prioritarias en consultas y fechas resueltas congeladas antes del worker. Guardar un rango fijo requiere omitir o anular date_preset.

## Módulos de fuentes y agregados monetarios
El catálogo expone module.key y module.label para agrupar fuentes sin cambiar sus claves guardadas. Mortandad y Medicación son fuentes de Lotes; los mantenimientos de galpones y vehicles.maintenance pertenecen a Gastos. Si una columna/métrica declara una unidad variable que es columna/dimensión (currency, cost_currency, base_unit), el normalizador incorpora esa unidad al detalle/agregado para no mezclar monedas. Cuenta corriente conserva originales y reversiones firmadas, no suma repetidamente el saldo actual y no admite PIN ni enlaces públicos. Stock de alimento es actual, sin fechas ni agrupaciones temporales; elaboraciones canceladas no suman producción efectiva y gastos vehiculares sólo suman completados.
