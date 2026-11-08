# Plan de implementación del resumen operativo

Este plan conserva el diagnóstico de `develop` en `75b25b5` y las propuestas iniciales. Consultá la [guía de implementación y consumo](operational-summary-implementation.md) para ver los contratos entregados, el alcance acordado de ventas globales y gastos por UP, y las decisiones que siguen abiertas.

## Qué estaba disponible antes de implementar

Todas las rutas de esta guía son relativas a `/api/v1`.

| Indicador | API actual y uso |
| --- | --- |
| Huevos recolectados | `GET /collections/metrics` acepta `date_from`, `date_to` y `production_unit_id`. Devuelve `total_eggs`, `daily_average`, `by_day`, `by_week` y `by_month`. La comparación requiere dos llamadas. Falta declarar el filtro por UP en el contrato. [Lotes](../../contracts/openapi/lots.yaml#L791) |
| Aves vivas, lotes y ración | `GET /production-units/{productionUnit}/poultry-houses` devuelve `current_occupancy` por galpón. `GET /flocks?production_unit_id=…` devuelve los lotes paginados. `GET /saldos-alimento?up_id=…` devuelve los saldos por receta. Falta el total de aves para todo el alcance. [Lotes](lots-implementation.md) · [Saldos](../../contracts/openapi/feed-recipes.yaml#L379) |
| Mortalidad | `GET /mortalities` lista registros por fecha, lote, galpón y estado. Falta el filtro por UP y un agregado de cantidades. La paginación cuenta registros. [Lotes](../../contracts/openapi/lots.yaml#L489) |
| Alertas | `GET /notificaciones/resumen` cuenta avisos personales sin leer. `GET /notificaciones?estado_resolucion=vigente` también devuelve informativos. `GET /configuracion-alertas` administra criterios de emisión. [Notificaciones](notifications-implementation.md) |
| Valor entregado | `POST /reportes/deliveries.deliveries/previsualizaciones` permite solicitar `merchandise_total` para un período. La suma ignora importes faltantes. La fuente carece de desglose por UP y de un cálculo de resultado neto. [Reportes](reporting-implementation.md) |

## Alcance y criterios comunes

- En rutas nuevas, usar `up_id`, `desde` y `hasta` cuando correspondan. Mantener los parámetros actuales de las rutas que se amplían. `GET /production-units` sirve para cargar el selector con las UP visibles para la sesión.
- La omisión de UP resuelve el alcance de la sesión. Para cuentas de correo con permisos funcionales, abarca la empresa. Para sesiones PIN, abarca exclusivamente su UP. Aplicar el alcance y los permisos antes de sumar, agrupar o paginar. Reutilizar `ProductionUnitAccess` y los scopes existentes. Las consultas SQL directas deben aplicar el alcance explícitamente.
- Los períodos usan fechas calendario inclusivas y la zona de Lotes, `America/Montevideo` por defecto. En SQL, usar desde el inicio de `desde` hasta el inicio del día siguiente a `hasta`, excluido. En los agregados nuevos por período, validar ambas fechas y acotar el rango a 366 días. Los indicadores actuales de aves y alertas llevan `consultado_en` y son independientes del selector de fechas.
- Los agregados nuevos responden en `data` y exponen el alcance efectivo mediante `up_id`, con `null` para toda la empresa. Usar cero para un conteo válido y vacío. Reservar `null` para valores indeterminados e informar su motivo cuando la API pueda distinguirlo. Mantener importes como decimales exactos en texto con moneda explícita.
- Usar `FormRequest` para autorización y filtros, consultas del módulo propietario y respuestas compatibles con el formato de errores vigente. Probar `401` sin sesión, `403` sin permiso, `404` para otra UP en sesión PIN y `422` para filtros inválidos en rutas nuevas. Los permisos se verifican también en los accesos al detalle.

## Entrega 1. Producción y población

### Recolección con comparación opcional

Ampliar `GET /collections/metrics` con `comparar_anterior=1` como opción y documentar `production_unit_id`. Reutilizar el cálculo de `GetEggProductionMetricsQuery` con los mismos filtros de lote, galpón y UP. Determinar el período anterior desde las fechas efectivas del actual, incluidos los valores por defecto. Termina el día anterior al inicio del actual y comprende la misma cantidad de días. Obtener sólo el total anterior y conservar las series actuales del período elegido. Las solicitudes sin la opción conservan su forma de respuesta.

Ejemplo del campo adicional para 950 huevos recolectados del 1 al 7 de noviembre y 1.000 del 25 al 31 de octubre.

```json
{
  "comparacion": {
    "desde": "2026-10-25",
    "hasta": "2026-10-31",
    "total_huevos": 1000,
    "variacion_porcentual": -5.0
  }
}
```

El backend calcula `(actual − anterior) × 100 ÷ anterior`, redondeado a dos decimales. Devuelve `null` si el anterior es cero y `0` si ambos totales son iguales y positivos. Mantiene el permiso `can_view_egg_collections` y documenta que las series actuales omiten los días sin registros. [Consulta actual](../../app/Queries/Lots/GetEggProductionMetricsQuery.php)

### Total de aves vivas

Agregar `GET /lotes/resumen?up_id=…` con `can_view_flocks`. La respuesta devuelve `data.up_id`, `data.aves_vivas` y `data.consultado_en`. Agrupar en PostgreSQL el saldo de lotes activos y en cuarentena por galpón. Sumar las ocupaciones después de aplicar un mínimo de cero por galpón. Reutilizar la regla del proveedor de ocupación de Lotes y conservar los saldos firmados en el detalle. Probar que un galpón con −2 y otro con 40 devuelven 40 aves. Excluir finalizados y devolver cero cuando no haya lotes abiertos en el alcance.

### Bajas por período y detalle por UP

Agregar `GET /mortalidades/resumen?desde=…&hasta=…&up_id=…` con `can_view_mortality`. Filtrar por `occurred_at`, la UP guardada en el registro y `status=recorded`, y sumar `quantity` en PostgreSQL. Una rectificación usa la fecha y la cantidad vigentes. Compartir con el listado las condiciones de fecha y UP. Extender `GET /mortalities` con `production_unit_id` para abrir el detalle con `date_from`, `date_to` y `status=recorded`. Documentar el filtro en OpenAPI y conservar la paginación existente.

```json
{
  "data": {
    "up_id": 12,
    "desde": "2026-11-01",
    "hasta": "2026-11-07",
    "zona_horaria": "America/Montevideo",
    "bajas_registradas": 8,
    "poblacion_referencia": null,
    "tasa_porcentual": null,
    "estado_tasa": "pendiente_definicion"
  }
}
```

## Entrega 2. Tasa de mortalidad y alertas operativas

### Población de referencia

Validar con el área de producción la propuesta `aves al inicio + ingresos externos del período`. Las redistribuciones actuales dentro de una UP no generan ingresos a ese alcance. Con 100 aves al inicio, 20 ingresos externos, 30 aves redistribuidas dentro de la UP y 6 bajas, la población de referencia sería 120 y la tasa sería 5 %.

Definir el tratamiento de saldos negativos, egresos y rectificaciones posteriores a la fecha de corte antes de publicar la tasa. La corrección registra su propio movimiento y conserva las fotografías históricas previas, por lo que sumar las bajas vigentes y tomar el último snapshot sin reconciliar la fecha efectiva puede mezclar criterios. Verificar si el historial permite reconstruir el censo de apertura para cada alcance. Con población cero o no reconstruible, devolver tasa `null` y distinguir el motivo. Si las bajas exceden la población de referencia, conservar las cantidades y acordar cómo señalar la inconsistencia. [Reglas de mortalidad e histórico](lots-implementation.md)

### Listado y conteo de alertas

Proponer `GET /alertas-operativas` y `GET /alertas-operativas/resumen`. Compartir la consulta de alertas abiertas y los filtros `up_id` y `urgencia=alto|medio|bajo`. El listado devuelve un ID público por alerta, tipo, urgencia, UP, fecha, título y referencia al detalle de origen cuando sea visible. El resumen devuelve `data.up_id`, `data.consultado_en`, `data.total` y `data.por_urgencia` con las tres claves. Con filtro de urgencia, contar el conjunto filtrado y devolver cero en las otras claves. Agregar antes de paginar y usar orden estable en el listado.

1. Inventariar emisores y decidir por tipo qué condición abre, actualiza y cierra una alerta. Validar si los avisos de reparto sin flujo de cierre quedan informativos o adquieren un cierre explícito. Definir el tratamiento de alertas generales y de repartos con varias UP. Las alertas generales visibles entran en el total de empresa y se excluyen del filtro de una UP.
2. Dar a cada alerta operativa una identidad independiente del destinatario, compuesta por tipo, alcance y clave estable de la condición o del hecho. Proyectar un registro operativo por identidad con apertura, urgencia y resolución, reutilizando los puntos de emisión y sincronización existentes. Definir cómo se identifica una reapertura. La existencia de la alerta debe poder consultarse aunque no tenga destinatarios. Leer un aviso no cierra su alerta.
3. Inicializar las condiciones periódicas mediante una evaluación controlada y recuperar alertas por evento sólo cuando el origen demuestre su identidad y estado. Validar la cobertura de los tipos acordados antes de publicar el contador. Probar reintentos, concurrencia, cambio de urgencia, reapertura y el caso de una alerta enviada a dos personas que suma uno.
4. Aplicar permisos de consulta por tipo antes del conteo y del listado. Validar `can_view_notifications` junto con el permiso de lectura del dominio emisor, respetando las alternativas legítimas en Repartos. El permiso de configuración sigue su contrato actual. La resolución operativa corresponde al módulo de origen y, si requiere una nueva mutación, se implementa en su Action con auditoría síncrona.

## Entrega 3. Valor entregado y atribución

El valor global puede avanzar en paralelo con la entrega 1. Ampliar `deliveries.deliveries` con métricas de entregas confirmadas y entregas sin importe, conservando `merchandise_total` y `can_view_delivery_history`. Conservar la fecha efectiva de la entrega y la moneda UYU. La vista previa sin agrupaciones devuelve los agregados solicitados en una fila de `data.rows`. Ejemplo de cuerpo propuesto para `POST /reportes/deliveries.deliveries/previsualizaciones`.

```json
{
  "from": "2026-11-01",
  "to": "2026-11-07",
  "metrics": ["merchandise_total", "delivery_count", "unpriced_delivery_count"]
}
```

Validar tres escenarios antes de habilitar la tarjeta. Un período sin entregas tiene conteo cero. Si todas carecen de importe, el valor es desconocido. Si sólo algunas carecen de importe, mostrar la suma disponible como parcial. Diferenciar de una entrega con importe explícito cero. La unidad de conteo es la entrega confirmada a un cliente, es decir, una parada entregada del reparto.

Para el desglose por UP, decidir cómo asignar cada ítem entregado cuando las cargas mezclan orígenes. Las líneas actuales guardan presentación, cantidad y precio, mientras que la UP se registra en las cargas. Definir si se conservará la atribución por línea al confirmar la entrega, cómo tratar devoluciones y qué históricos quedan sin atribuir. Verificar que los importes atribuidos y sin atribuir concilien con el global. Las sesiones PIN sólo podrán acceder a entregas cuyo alcance esté demostrado según las reglas de Repartos. Para el resultado neto, acordar primero ingresos, costos, fecha de reconocimiento, moneda y alcance de UP, y luego publicar ingresos, costos y resultado comparables en el mismo contrato. [Reporte](../../app/Services/Deliveries/DeliveriesReportSource.php) · [Asignación actual](../../app/Services/Deliveries/DeliveryUnitBalance.php)

## Guía de integración para el front

| Acción | Consumo esperado |
| --- | --- |
| Abrir el resumen o cambiar de UP | Cargar las tarjetas habilitadas para la sesión en paralelo. Enviar el mismo ID a cada API con el nombre que corresponda a su contrato. Descartar respuestas de una selección anterior. Mostrar errores por tarjeta para preservar los resultados de las demás. |
| Cambiar el período | Recargar huevos, mortalidad y, en vista empresa, valor entregado. La ocupación y las alertas muestran la fecha de consulta actual. |
| Abrir el detalle | Huevos conserva fechas y UP en la consulta de recolecciones. Mortalidad usa las fechas y `status=recorded`. Aves abre lotes y galpones de la UP. Alertas abre el listado operativo con la UP y la urgencia elegidas. El detalle conserva su paginación. |
| Mostrar estados | Mostrar `0` cuando la API informe cero. Para variación con base cero, usar «Sin base de comparación». Para tasa pendiente, mostrar las bajas y dejar la tasa «Por validar». En valor entregado, indicar cuántas entregas carecen de importe cuando el subtotal sea parcial. Mantener resultado neto «Por validar». |

Si las llamadas independientes generan un costo medible, evaluar `GET /resumen` como una composición de las mismas consultas. Debe preservar el alcance y los permisos de cada indicador, distinguir indisponibilidad de cero y mantener vigentes las rutas de detalle.

## Verificación y entrega

- Ejecutar pruebas HTTP de cada módulo sobre PostgreSQL con una cuenta personal y dos UP, y una sesión PIN válida. Comprobar omisión y selección de UP, acceso cruzado, permisos por indicador y coherencia entre total y detalle paginado. Incluir límites de medianoche, períodos contiguos, recolecciones y bajas anuladas, rectificaciones y población sin datos.
- Verificar los escenarios de reconciliación de alertas y de importes en sus respectivas entregas. Medir el plan de consultas con un volumen representativo antes de incorporar índices. Las lecturas iniciales no requieren migraciones ni escrituras de negocio.
- En cada entrega, actualizar OpenAPI y la guía del módulo, registrar las pruebas ejecutadas y adjuntar ejemplos de solicitudes y respuestas, permisos, campos disponibles y pendientes.
