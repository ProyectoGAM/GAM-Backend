# Resumen operativo e integración de la API

Todas las rutas son relativas a `/api/v1`. Omitir el filtro de UP aplica el alcance de la sesión. Una cuenta personal autorizada consulta toda la empresa. Una sesión PIN consulta únicamente su UP y recibe `404` si intenta seleccionar otra. El selector de UP puede reutilizar `GET /production-units`. Las fechas son días inclusivos de `LOTS_TIMEZONE`, con `America/Montevideo` por defecto.

| Tarjeta | Consulta | Permiso |
| --- | --- | --- |
| Huevos recolectados | `GET /collections/metrics?date_from=2026-11-01&date_to=2026-11-07&comparar_anterior=1&production_unit_id=12` | `can_view_egg_collections` |
| Aves vivas | `GET /lotes/resumen?up_id=12` | `can_view_flocks` |
| Mortalidad | `GET /mortalidades/resumen?desde=2026-11-01&hasta=2026-11-07&up_id=12` | `can_view_mortality` |
| Alertas pendientes | `GET /alertas-operativas/resumen?up_id=12` | `can_view_notifications` y permiso de cada tipo |
| Valor entregado global | `POST /reportes/deliveries.deliveries/previsualizaciones` | `can_view_reports` y `can_view_delivery_history` |
| Gastos registrados | `GET /gastos/resumen?desde=2026-11-01&hasta=2026-11-07&up_id=12` | Permiso de lectura de al menos una fuente de gastos |

## Producción, aves y mortalidad

La comparación de huevos usa un período inmediatamente anterior de igual duración, con los mismos filtros y únicamente recolecciones vigentes. El bloque `data.comparacion` incluye `desde`, `hasta`, `total_huevos` y `variacion_porcentual`. Si la base anterior es cero, la variación es `null` y se puede mostrar «Sin base de comparación». Las series contienen sólo fechas con registros y corresponden al período solicitado. Omitir `comparar_anterior` conserva la respuesta original. También se puede pedir la comparación para un lote en `/flocks/{flock}/metrics`.

El total de aves vivas devuelve `data.up_id`, `data.aves_vivas` y `data.consultado_en`. Cuenta la ocupación de lotes activos y en cuarentena. Un saldo negativo aporta cero al total de su galpón. La tabla se puede abrir con `GET /flocks?production_unit_id=12` y `GET /production-units/12/poultry-houses`. Para ración usar `GET /saldos-alimento?up_id=12`.

El resumen de mortalidad suma `quantity` de registros vigentes, usando la UP y la fecha efectiva guardadas en cada registro. Las rectificaciones se reflejan en la siguiente consulta y las anulaciones quedan fuera. El detalle usa `GET /mortalities?production_unit_id=12&date_from=2026-11-01&date_to=2026-11-07&status=recorded`. El `meta.total` del detalle cuenta registros, mientras `data.bajas_registradas` suma aves.

`poblacion_referencia` suma las aves vivas inmediatamente antes de la apertura y las admisiones externas del período. Las redistribuciones entre galpones de una UP dejan constante ese denominador. La apertura toma el último movimiento de cada lote y reconcilia las rectificaciones posteriores que afectan a bajas anteriores a la fecha de corte. Un saldo negativo aporta cero aves al censo físico. `tasa_porcentual` devuelve `bajas ÷ población de referencia × 100`, con dos decimales. `estado_tasa` indica `calculada`, `sin_poblacion` o `historial_indeterminado`. En los últimos dos casos, la tasa es `null`. Si las bajas registradas superan una población positiva, se conserva la tasa calculada y se informa `bajas_superan_poblacion`.

## Alertas operativas

`GET /alertas-operativas` lista episodios abiertos y `GET /alertas-operativas/resumen` agrega `total` y `por_urgencia` con `alto`, `medio` y `bajo`. Ambos usan los filtros `up_id` y `urgencia`. El listado acepta `page` y `per_page`, se ordena por fecha e ID descendentes y devuelve `entidad` para abrir el origen. El filtro de urgencia devuelve cero para las otras claves del resumen. Una alerta general autorizada entra en la vista empresa. Al seleccionar una UP, el listado y el contador sólo devuelven episodios de esa unidad.

```json
{
  "data": {
    "up_id": 12,
    "consultado_en": "2026-11-07T14:00:00-03:00",
    "total": 3,
    "por_urgencia": {"alto": 1, "medio": 2, "bajo": 0}
  }
}
```

La proyección guarda una fila por condición abierta, incluso cuando carece de destinatarios. El módulo de origen actualiza la urgencia o resuelve el episodio. La reapertura obtiene otro ID. La lectura personal se gestiona en `/notificaciones`. Se incluyen las condiciones con resolución automática, las anomalías de pesaje pendientes de revisión y los repartos con saldo negativo detectados en sus operaciones. Repartos permite consultar la alerta con `can_view_delivery_history` o `can_monitor_deliveries`, además de `can_view_notifications`. Un reparto con varias UP usa alcance general.

Un reparto activo con saldo negativo permanece abierto. El cierre responde `409` hasta regularizar la diferencia. La alerta operativa y sus avisos pendientes se resuelven al alcanzar un saldo de cero o positivo. Para cerrar, indicar `return_production_unit_id` siempre que se devuelvan huevos. El saldo después de devolver todo el sobrante debe ser cero. El aviso de sobrantes es informativo e identifica la UP que recibió el stock. [Contrato de Repartos](../../contracts/openapi/deliveries.yaml)

### Rectificar una entrega y su cargo

Con sesión personal de administrador, consultar `GET /repartos/{reparto}` para obtener el `id`, `version` e `items` de la entrega. Enviar `POST /repartos/{reparto}/entregas/{entrega}/rectificaciones` con `Idempotency-Key` y el motivo de la corrección. Este ejemplo reduce la primera línea a una unidad y conserva las demás.

```json
{
  "version": 1,
  "motivo": "Se registró más mercadería de la entregada",
  "lineas": [{"indice": 0, "cantidad": "1"}]
}
```

La operación reutiliza las equivalencias y precios guardados. Devuelve `antes`, `despues` y el identificador del crédito si cambia el cargo. Puede corregir un reparto activo o ya cerrado con saldo negativo. Si quedan otras diferencias, el episodio sigue abierto. Para un reparto activo, el sobrante se descarga en el cierre. Para uno cerrado, indicar `return_production_unit_id` en la rectificación para confirmar el ingreso del sobrante. Si ya hubo devolución, se usa la misma UP. La rectificación conserva el resumen de cierre original y el detalle del reparto expone `rectificaciones_entregas`. En registros históricos sin líneas se puede informar `cantidad_entregada` y, si corresponde, `importe`. Si el cargo original nunca se contabilizó, la corrección deja la cuenta corriente sin movimientos.

Ante una respuesta incierta, reintentar con la misma clave y el mismo cuerpo. Ante `409` por versión, volver a consultar el detalle para obtener las cantidades y la versión vigentes. Después de confirmar, refrescar el detalle del reparto y el contador de alertas. Usar `data.despues.saldo_huevos` para mostrar si queda una diferencia pendiente.

Aplicar las migraciones antes de habilitar la API. La proyección inicial recupera anomalías de pesaje con aviso abierto verificable y repartos activos o cerrados con aviso abierto y faltante comprobado. Las condiciones periódicas se inicializan al completar sus evaluaciones programadas. Antes de habilitar la tarjeta en un despliegue, comprobar que terminaron los ciclos de cobertura y revisión de alimentación, sanidad y tareas, recolección, capacidad, inventario y vehículos. La migración y los evaluadores preservan los avisos personales y sus estados. Las lecturas del resumen no ejecutan evaluaciones ni escriben auditoría. La proyección se actualiza dentro de la transacción del emisor y usa una restricción única para cada identidad abierta.

## Valor entregado y gastos

Solicitar el valor de mercadería entregada para toda la empresa con este cuerpo en `POST /reportes/deliveries.deliveries/previsualizaciones`.

```json
{
  "from": "2026-11-01",
  "to": "2026-11-07",
  "metrics": ["merchandise_total", "delivery_count", "unpriced_delivery_count"]
}
```

Leer la primera fila de `data.rows`. Los importes y conteos se entregan como texto decimal. Si `delivery_count` es cero, mostrar cero entregas. Cuando todas las entregas carecen de importe, mostrar el valor como desconocido. Si sólo faltan algunos importes, mostrar el subtotal conocido e indicar cuántas entregas están sin precio. Una parada entregada cuenta una vez. El importe corresponde a mercadería entregada en UYU. Las ventas son globales y se mantienen independientes de la selección de UP.

`GET /gastos/resumen` usa `desde` y `hasta` obligatorios, con un rango máximo de 366 días, y `up_id` opcional. Devuelve un importe decimal por moneda y el desglose de cada una en `por_fuente`. `fuentes_incluidas` identifica las fuentes consultadas y `fuentes_sin_permiso` las fuentes aplicables que quedaron fuera por autorización. Una lista `totales` vacía representa ausencia de gastos para las fuentes incluidas y el período. Si hay fuentes sin permiso, presentar el resultado como parcial.

```json
{
  "data": {
    "up_id": 12,
    "desde": "2026-11-01",
    "hasta": "2026-11-07",
    "zona_horaria": "America/Montevideo",
    "fuentes_incluidas": ["mantenimiento_galpones", "combustible"],
    "fuentes_sin_permiso": [],
    "totales": [
      {"moneda": "USD", "importe": "10.5000", "por_fuente": [{"fuente": "mantenimiento_galpones", "importe": "10.5000"}]},
      {"moneda": "UYU", "importe": "11.125", "por_fuente": [{"fuente": "combustible", "importe": "11.125"}]}
    ]
  }
}
```

| Fuente | Fecha y condición | Atribución | Permiso |
| --- | --- | --- | --- |
| `mantenimiento_galpones` | Fecha de mantenimiento completado | UP del galpón | `can_view_poultry_houses` |
| `combustible` | Fecha del registro de combustible | En empresa incluye todos. En una UP sólo incluye cargas asociadas a un reparto atribuible íntegramente a esa UP | `can_view_vehicle_fuel` |
| `mantenimiento_vehiculos` | Fecha de finalización de la intervención completada | Empresa | `can_view_vehicle_maintenance` |

Mostrar los gastos por moneda. El valor entregado y los gastos registrados pueden consultarse en el mismo período. El resultado neto requiere acordar qué ingresos y costos se reconocen y cómo se comparan las monedas.

## Consumo desde el front

Al cambiar de UP, actualizar las tarjetas operativas y los gastos con los parámetros de cada API. Al cambiar el período, recargar huevos, mortalidad, valor global entregado y gastos. Aves y alertas muestran la situación al consultar. Mantener los estados de carga y error por tarjeta y descartar las respuestas de una selección anterior. Para abrir el detalle de alertas desde una urgencia, enviar el mismo `up_id` y `urgencia` a `/alertas-operativas`.

Los contratos están en [Lotes](../../contracts/openapi/lots.yaml), [Notificaciones](../../contracts/openapi/notifications.yaml) y [Reportes](../../contracts/openapi/reporting.yaml).

## Verificación

La primera entrega pasó 162 pruebas con 2.040 aserciones en PostgreSQL. La incorporación de la tasa y la rectificación de entregas pasó 120 pruebas con 2.158 aserciones. La segunda regresión cubrió la apertura histórica, la compensación del cargo, el destino del sobrante, los reintentos, los permisos, el historial y la recuperación de alertas de repartos legados. Pint y PHPStan pasaron en los archivos modificados. Las migraciones se verificaron en la base de pruebas.
