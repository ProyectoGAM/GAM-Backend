# Pesajes diarios por lote — implementación

La API agrupa ingresos individuales y grupales en una jornada identificada por lote y fecha local de `America/Montevideo`. Complementa los recursos independientes de `POST /pesajes`; no cambia sus correcciones históricas ni su distribución. El contrato público está en [weighings.yaml](../../contracts/openapi/weighings.yaml).

## Jornada y persistencia

La migración [create_daily_weighing_tables](../../database/migrations/2026_10_07_142233_create_daily_weighing_tables.php) crea:

- `daily_weighings`: una fila única por `flock_id` y `local_date`, con ULID público, etapa, rango en gramos, versión global y procedencia de la referencia capturados, versión de concurrencia y agregados.
- `daily_weighing_entries`: ingresos con ULID público, modalidad, aves, pesos, promedio, marca de anomalía, hora UTC y actor. Una eliminación marca `deleted_at`, `deleted_by` y `deletion_reason`; conserva la fila histórica.

La jornada se crea con el primer ingreso aceptado. Si todavía no existen rangos globales, `expected_range` es `null` y no se marca ningún ingreso como anómalo. Su estado se obtiene de la fecha local: `in_progress` durante ese día y `closed` al comenzar el siguiente, sin comando de cierre. La jornada permanece consultable aunque se eliminen todos sus ingresos.

## Contrato HTTP

Todas las rutas son relativas a `/api/v1` y usan la autenticación personal o compartida del proyecto. Las operaciones de escritura con cookie requieren CSRF; las sesiones compartidas exigen la credencial del dispositivo y `X-GAM-Session`.

| Método y ruta | Permiso | Resultado |
| --- | --- | --- |
| `GET /pesajes-diarios?flock_id={ULID}` | `weighings.view` | Historial por fecha descendente, con `date_from`, `date_to`, `per_page` y `cursor`. Una lista vacía indica que no hay jornadas. |
| `GET /lotes/{lote}/pesajes-diarios/{fecha}` | `weighings.view` | Jornada de una fecha local; `404` si no existe. |
| `GET /pesajes-diarios/{jornada}` | `weighings.view` | Jornada e ingresos ordenados por hora e ID, paginados con `per_page` y `cursor`. |
| `POST /lotes/{lote}/pesajes-diarios/ingresos` | `weighings.manage` | Agrega un ingreso a la fecha local actual; devuelve la jornada y el ingreso. |
| `DELETE /pesajes-diarios/{jornada}/ingresos/{ingreso}` | `weighings.manage` | Elimina una medición concreta y devuelve la jornada recalculada. |
| `GET /pesajes-diarios/{jornada}/distribucion` | `weighings.view` | Histograma y curva de los pesos individuales activos. |
| `GET/PUT /configuracion-pesajes` | `weighing-settings.manage` | Consulta o cambia los rangos globales. |

Las páginas devuelven `next_cursor`; `null` indica que no hay más resultados. El listado de jornadas se ordena por fecha e ID descendentes, y los ingresos por hora e ID ascendentes. `per_page` admite de 1 a 100; el detalle usa 50 y el historial 20 por defecto. Así, los ingresos nuevos no desplazan elementos entre páginas.

`401` significa que falta autenticación y `403` que el usuario no tiene permiso. Una jornada inexistente devuelve `404`; una existente pero cerrada devuelve `200` con `status=closed`. Las consultas no crean jornadas vacías.

## Registro, cálculos y anomalías

El cuerpo de un ingreso contiene `mode=individual` con `weight`, o `mode=group` con `bird_count` y `total_weight`. Los pesos son strings decimales en **gramos**, con hasta una décima. No se envía la fecha: el servidor toma el instante UTC y determina la jornada según `America/Montevideo`. Las modalidades pueden mezclarse en el mismo día.

```json
{"mode":"individual","weight":"20.0"}
```

```json
{"mode":"group","bird_count":3,"total_weight":"90.0"}
```

Para un grupo, `average_weight_g = total_weight_g / bird_count`. El promedio de la jornada usa todas las aves representadas:

```text
represented_bird_count = cantidad de ingresos individuales + suma de bird_count grupales
total_weight_g = suma de pesos individuales + suma de pesos totales grupales
average_weight_g = total_weight_g / represented_bird_count
```

No se permite que la muestra acumulada supere la población viva proyectada del lote. Cada peso individual o promedio grupal se compara con el rango capturado de la jornada. Un grupo fuera de rango cuenta como **un ingreso anómalo**, con independencia de su cantidad de aves.

Un ingreso fuera de rango sin `confirm_out_of_range=true` devuelve `409` con código `DAILY_WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED`, promedio y límites. La transacción se revierte: no crea jornada, ingreso, operación ni auditoría. Si el usuario confirma, reintenta con el mismo `Idempotency-Key` y el campo de confirmación. Cancelar la confirmación no requiere ninguna llamada adicional ni cambia datos.

## Eliminación, versión y auditoría

`POST` y `DELETE` requieren `Idempotency-Key` UUID. La misma clave y el mismo comando de un actor devuelven el resultado guardado; reutilizarla con datos distintos produce conflicto. La inserción bloquea la configuración y el lote, y la unicidad de `(flock_id, local_date)` impide jornadas duplicadas. Dos actores pueden registrar ingresos en la misma jornada sin sobrescribirlos.

La eliminación requiere el `version` actual de la jornada y un `reason` de 3 a 1000 caracteres. Una versión antigua devuelve `409` con `DAILY_WEIGHING_VERSION_CONFLICT`. **Se permite eliminar ingresos de jornadas cerradas**, con motivo y auditoría. La fila marcada como eliminada se excluye de las lecturas y cálculos activos. Se vuelven a calcular aves, peso, promedio, anomalías y último ingreso; al eliminar el único ingreso, el promedio y `last_entry` quedan en `null`.

Las Actions registran sincrónicamente, dentro de la misma transacción, `daily_weighing_entry_added` y `daily_weighing_entry_deleted` mediante `AuditRecorder` y `AuditEntryData`. El historial conserva actor, operación, traza, unidad productiva y snapshots permitidos; la eliminación guarda también el motivo y el ingreso anterior. Una falla de auditoría revierte el cambio de negocio.

## Cambios de rangos y distribución

`PUT /configuracion-pesajes` actualiza el rango y reclasifica **todas las jornadas abiertas** de la fecha local en la misma transacción. Para cada etapa toma el rango propio de la raza si existe o el global si se hereda. `PATCH /breeds/{breed}` reclasifica sólo las jornadas abiertas de lotes de esa raza cuando cambian sus límites. Ambos cambios recalculan las marcas y el contador de anomalías, incrementan la versión y auditan cada jornada afectada. Las jornadas cerradas conservan el rango capturado y sus resultados históricos. Los recursos independientes de `/pesajes` siguen conservando su propia referencia histórica.

La respuesta `expected_range` de cada jornada indica `source=global|breed`, `breed_id` y `breed_version` además de límites, etapa y versión global. Las jornadas anteriores a la ampliación de razas se presentan con origen global. Una raza sin límites propios continúa usando la configuración global; ambas parejas en `null` recuperan esa herencia.

La distribución diaria usa sólo `weight_g` de ingresos individuales activos. No reparte el peso total de un grupo entre aves imaginarias ni coloca su promedio en la curva. Devuelve `available`, `reason`, `n`, `bins` y `curve` con el esquema existente de distribución, siempre en gramos. Con menos de dos pesos individuales informa `insufficient_sample`; con varianza cero informa `zero_variance`. En ambos casos `curve` es `null`.

## Verificación

La cobertura está en [DailyWeighingEndpointTest](../../tests/Feature/Lots/DailyWeighingEndpointTest.php), [WeighingConcurrencyTest](../../tests/Feature/Lots/WeighingConcurrencyTest.php) y [WeighingContractTest](../../tests/Feature/Lots/WeighingContractTest.php). Incluye mezcla de modalidades, promedio ponderado, confirmación sin efectos, reintentos, medianoche local, borrado de jornadas cerradas, versión obsoleta, cursores, permisos, actualización de rangos, distribución y dos actores simultáneos.

La ejecución focalizada del 2026-10-07 aprobó 38 pruebas y 551 aserciones en PostgreSQL aislado. Tras ajustar las rutas públicas a `/lotes`, las pruebas afectadas y la prueba multiproceso volvieron a pasar. Pint y `git diff --check` finalizaron sin errores. Larastan no señaló problemas en este cambio; conserva dos avisos previos en `RecordInventoryMovementAction`.

## Fixture local para diseño del frontend

`WeighingDesignDemoSeeder` crea el lote `LOT-PESAJE-TEST` en **semana 60** al momento de la primera carga. Busca un galpón avícola operativo y desocupado en una UP activa; si no existe, crea el galpón y, si hace falta, la UP mediante sus Actions auditadas. Usa un plan publicado y una raza activa. Nunca reemplaza un lote existente con ese código.

El fixture carga seis jornadas durante los últimos 28 días, incluida la fecha local de la primera carga: **72 ingresos** (60 individuales y 12 grupales), con **7 ingresos anómalos confirmados**. La jornada más reciente contiene dos anomalías. Cada jornada representa 22 aves, conserva el rango capturado y ofrece suficientes pesos individuales distintos para el histograma y la curva. Los pesos se generan dentro o fuera del rango vigente según corresponda. Los ingresos pasan por `AddDailyWeighingEntryAction`, por lo que los agregados, la idempotencia y la auditoría son los mismos que en la API. La auditoría identifica el origen `seeder`.

Es un fixture **optativo y sólo local**; no forma parte del seeding general ni del reset demo de producción. Tras migrar y cargar los datos base locales, se ejecuta con:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\Lots\WeighingDesignDemoSeeder' --no-interaction
```

Una segunda ejecución no duplica jornadas ni ingresos. La fecha de referencia queda fijada por la fecha de ingreso del lote, así que el fixture envejece naturalmente si se conserva la base varios días. La cobertura focalizada está en [WeighingDesignDemoSeederTest](../../tests/Feature/Lots/WeighingDesignDemoSeederTest.php).

El 2026-10-07 pasaron sus tres pruebas (16 aserciones), incluido el escenario sin galpón disponible. La carga en la base local `gam` confirmó seis jornadas, 72 ingresos y siete anomalías; las 72 altas quedaron auditadas con origen `seeder`.
