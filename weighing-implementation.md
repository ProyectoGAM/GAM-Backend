# Pesajes de lotes — implementación

La sección de Pesajes pertenece funcionalmente a **06 — Manejo productivo y sanidad** y técnicamente a `Lots`, porque reconstruye población, galpón, unidad productiva, edad y ciclo de vida desde el historial del lote.

**Estado funcional: Pesajes implementado; Módulo 06 aún incompleto.** Esta entrega cubre configuración global de referencia, captura individual y grupal, confirmación de valores fuera de rango, corrección histórica, consultas, distribución, evolución, auditoría y datos demo. Plan de Manejo, aplicaciones sanitarias y los demás manejos continúan pendientes. El seguimiento en [notion.md](notion.md) conserva la tarjeta del módulo en implementación.

## Alcance y decisiones confirmadas

- Cada pesaje pertenece a un lote y conserva la ubicación y población reconstruidas para su fecha efectiva.
- Se admiten los modos `individual` y `group`; no se identifica permanentemente a cada ave.
- El cliente captura en `g` o `kg`. La persistencia normaliza pesos y totales a gramos con escala de una décima; promedios y métricas usan seis decimales.
- Un pesaje admite entre 1 y 1000 filas. Los valores se reciben como strings decimales positivos y no se aceptan campos propios del otro modo.
- En modo individual cada fila representa un ave y contiene `weight`.
- En modo grupal cada fila contiene `total_weight` y `bird_count`; la normalidad se evalúa sobre el promedio de esa tanda.
- La cantidad de aves representadas no puede superar la población viva del lote en la fecha indicada.
- Se puede crear sobre lotes activos o en cuarentena. Un lote finalizado conserva sus pesajes históricos, pero no admite capturas nuevas.
- No existen borrado, cancelación, campañas, curvas teóricas, percentiles, predicciones ni frontend dentro de este alcance.

## Persistencia y referencia histórica

La migración [create_weighing_tables](database/migrations/2026_09_08_231408_create_weighing_tables.php) agrega tres tablas con FKs `RESTRICT`, checks e índices:

- `weighing_reference_settings`: singleton de configuración, unidad administrativa y versión optimista.
- `weighings`: cabecera con ULID público, lote, ubicación histórica, modo, fecha, unidad capturada, agregados, referencia aplicada, creador y versión.
- `weighing_measurements`: filas ordenadas por `position`, con forma individual o grupal y marca de rango.

Los índices principales cubren lote/fecha, modo/fecha y posición única dentro del pesaje. La configuración usa `singleton_key=true` como restricción única. La creación inicial se serializa en PostgreSQL con un advisory transaction lock antes de consultar o insertar el singleton; las actualizaciones bloquean la fila y exigen su versión vigente.

Cada pesaje fotografía la configuración aplicada: semana inicial de adultez, rangos de ambas etapas, unidad administrativa y versión. Cambiar la configuración global no reetiqueta registros anteriores. Una corrección conserva esa referencia; sólo un pesaje creado cuando no existía configuración puede adoptar la vigente al corregirse.

## Unidades, precisión y fórmulas

Los cálculos exactos usan `Brick\Math\BigDecimal`; no se usa `float` para conversión, sumas, promedios, rangos, varianza ni bins. La evaluación de `exp()` de la densidad normal es la única parte necesariamente flotante.

Conversión canónica:

```text
weight_g = weight_kg × 1000
```

Promedio por ave para una tanda grupal:

```text
batch_average_g = total_weight_g / bird_count
```

Agregados de cabecera:

```text
represented_bird_count = cantidad de filas individuales
                         o suma de bird_count en modo grupal
total_weight_g = suma de los pesos o totales normalizados
average_weight_g = total_weight_g / represented_bird_count
```

Los gramos admiten hasta 13 dígitos enteros y una cifra decimal. Los kilogramos admiten hasta 10 dígitos enteros y cuatro cifras decimales, equivalentes a décimas de gramo. Los límites se validan antes de construir `BigDecimal`, evitando desbordes de `decimal(14,1)` y entradas decimales de tamaño no acotado. Las respuestas expresan decimales como strings e identifican la unidad de cada magnitud derivada.

## Configuración global de normalidad

La configuración contiene:

- `adult_from_week`, inclusive;
- `chick_min_weight` y `chick_max_weight`;
- `adult_min_weight` y `adult_max_weight`;
- `unit`, usada para capturar administrativamente los cuatro rangos;
- `version`, obligatoria al actualizar y omitible sólo en la creación inicial.

Antes de `adult_from_week` se aplica la etapa `chick`; desde esa semana se aplica `adult`. Los límites mínimo y máximo son inclusivos, y cada mínimo debe ser positivo y menor que su máximo. La configuración es global para toda la empresa y no varía por raza, sexo, línea genética o unidad productiva.

El sistema puede operar sin configuración. En ese estado el pesaje se guarda sin etapa ni rango y devuelve la advertencia `WEIGHING_REFERENCE_UNAVAILABLE`. `GET /configuracion-pesajes` devuelve `data: null` hasta que un usuario autorizado cree el singleton.

## Proyección histórica y ciclo de vida

[WeighingFlockProjection](app/Services/Lots/WeighingFlockProjection.php) busca el último `FlockMovement` hasta `occurred_at`, usando fecha e ID como orden estable, y lee el snapshot `after` del lote. De allí obtiene:

- población viva;
- galpón;
- unidad productiva;
- fecha de entrada usada por `FlockAge`.

La fecha no puede ser futura ni anterior al alta del lote. La falta de snapshot o una proyección sin aves produce un conflicto controlado. Pesar no modifica población, capacidad física, ubicación ni versión del lote.

## Contrato HTTP

La fuente ejecutable es [weighings.yaml](contracts/openapi/weighings.yaml). Las rutas son relativas a `/api/v1`, usan `auth:sanctum`, FormRequests dedicados y Policies.

| Método y ruta | Permiso | Comportamiento |
| --- | --- | --- |
| `GET /pesajes` | `weighings.view` | Lista paginada por lote, modo y fechas; orden descendente estable. |
| `POST /pesajes` | `weighings.manage` | Registra un pesaje individual o grupal. |
| `GET /pesajes/evolucion` | `weighings.view` | Devuelve un punto por pesaje para un lote. |
| `GET /pesajes/{pesaje}` | `weighings.view` | Consulta el detalle y sus mediciones. |
| `PATCH /pesajes/{pesaje}` | `weighings.manage` | Corrige el estado vigente y conserva el anterior en auditoría. |
| `GET /pesajes/{pesaje}/distribucion` | `weighings.view` | Devuelve histograma y campana sólo para modo individual. |
| `GET /configuracion-pesajes` | `weighing-settings.manage` | Consulta el singleton o `null`. |
| `PUT /configuracion-pesajes` | `weighing-settings.manage` | Crea o actualiza rangos globales. |

`POST`, `PATCH` y `PUT` requieren `Idempotency-Key` con UUID. El contrato documenta las alternativas personales Bearer/cookie y las sesiones compartidas nativa/web. Las mutaciones con cookie incluyen `X-XSRF-TOKEN`; las sesiones compartidas incluyen `X-GAM-Session` y la credencial de dispositivo correspondiente al transporte.

Ejemplo individual:

```json
{
  "flock_id": "01K4...",
  "mode": "individual",
  "unit": "g",
  "occurred_at": "2026-09-09T12:00:00-03:00",
  "measurements": [
    { "weight": "20.0" },
    { "weight": "22.0" },
    { "weight": "19.5" }
  ]
}
```

Ejemplo grupal:

```json
{
  "flock_id": "01K4...",
  "mode": "group",
  "unit": "kg",
  "measurements": [
    { "total_weight": "0.2000", "bird_count": 10 },
    { "total_weight": "0.3150", "bird_count": 15 }
  ]
}
```

Una corrección puede enviar sólo los campos modificados, pero siempre exige `version` y `correction_reason`. Si cambia el modo, debe enviar todas las mediciones. Si omite `unit`, se interpreta la unidad capturada del pesaje vigente.

## Confirmación de valores fuera de rango

No se usa desviación de la propia muestra para decidir si un dato es anómalo. Cada peso individual o promedio de tanda grupal se compara con el rango histórico de la etapa.

Si una fila queda fuera del rango, el primer intento responde `409` con `WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED`. La metadata identifica posición, valor derivado en gramos, etapa y límites aplicados. Ese intento no persiste pesaje, operación, auditoría ni evento. El cliente reintenta la misma operación incluyendo:

```json
{
  "confirm_out_of_range": true
}
```

El segundo comando debe conservar el resto del payload y la misma intención idempotente. Al confirmarse, la fila y la cabecera guardan `outside_expected_range=true`; el dato participa sin alteraciones en promedio, distribución y evolución.

## Corrección, idempotencia y concurrencia

`PATCH` sobrescribe el estado vigente, incrementa `version` y reemplaza las mediciones atómicamente. Puede modificar lote, modo, fecha, unidad, notas y mediciones. Antes de persistir vuelve a construir población, ubicación, etapa, agregados y marcas.

La Action bloquea:

1. la configuración global;
2. el pesaje vigente;
3. los lotes de origen y destino en orden determinista.

Una versión desactualizada devuelve `WEIGHING_VERSION_CONFLICT`. Cambiar a otro lote vuelve a validar la proyección histórica y la cantidad representada. Si aparecen outliers nuevos o modificados, se repite la confirmación 409.

`RunLotsCommand` serializa al actor y conserva `FlockOperation` para idempotencia. Mismo actor, UUID y comando normalizado devuelven la operación existente; reutilizar la clave con datos distintos responde `409`. Pesaje, mediciones, operación, auditoría y evento se confirman en una única transacción.

## Auditoría y eventos

La implementación reutiliza `AuditRecorder`, `AuditEntryData`, `LotsHistory` y el log `lots`.

- `weighing_reference_saved`: configuración anterior y nueva.
- `weighing_recorded`: snapshot completo del pesaje y sus mediciones.
- `weighing_corrected`: snapshots allowlist antes/después y `correction_reason`.

Las entradas incluyen actor, operación, traza, sujeto y unidad productiva cuando corresponde. La falla de auditoría revierte la operación y deja libre la clave para reintentar. No se registran contraseñas, PIN, peppers, tokens, cookies, claves idempotentes ni secretos de dispositivo.

Los eventos de dominio `WeighingReferenceSettingsSaved`, `WeighingRecorded` y `WeighingCorrected` se emiten dentro del flujo transaccional confirmado.

## Distribución individual

`GET /pesajes/{pesaje}/distribucion?unit=g|kg` devuelve `n`, media, desviación estándar muestral, bins y curva. Para pesos `xᵢ`:

```text
mean = Σxᵢ / n
sample_variance = Σ(xᵢ - mean)² / (n - 1)
sample_stddev = √sample_variance
```

La cantidad de bins usa Sturges, limitada a 20:

```text
bin_count = min(20, ceil(log₂(n) + 1))
```

La curva normal usa 81 puntos sobre `mean ± 4 × sample_stddev`, ampliando el dominio para incluir los extremos observados:

```text
y = exp(-0.5 × z²) / (sample_stddev × √(2π))
z = (x - mean) / sample_stddev
```

`x`, media, desviación y bins respetan la unidad solicitada. Cada punto declara `y_unit` como `1/g` o `1/kg`; la densidad también se escala con la unidad. Para `n=1`, desviación y curva son `null` con `reason=insufficient_sample`. Para varianza cero, la curva es `null` con `reason=zero_variance`. Un pesaje grupal devuelve `available=false` y `reason=group_mode` porque no existe dispersión individual inferible.

## Evolución

`GET /pesajes/evolucion` exige `flock_id` y acepta `mode`, `unit`, `date_from` y `date_to`. Devuelve un punto por pesaje vigente, ordenado por `occurred_at` y ULID. Cada punto contiene identidad, fecha, modo, aves representadas, promedio por ave, unidad y marca de fuera de rango.

No agrega por día, no suaviza, no interpola ni muestra una curva teórica. Las correcciones aparecen inmediatamente porque la consulta lee el estado vigente. La consulta carga como máximo 1001 filas y, si encuentra más de 1000, devuelve `422` solicitando `date_from` y `date_to`; nunca recorta el historial silenciosamente.

## Seguridad y autorización

Los permisos son company-wide y no crean asignaciones por unidad productiva:

- `weighings.view` para listado, detalle, distribución y evolución;
- `weighings.manage` para alta y corrección;
- `weighing-settings.manage` para lectura y escritura de configuración.

El administrador inicial recibe los tres permisos. Las Policies se vuelven a comprobar dentro de las Actions críticas. En sesiones compartidas, web acepta únicamente `gam_shared_device` y nativo únicamente `X-Shared-Device-Token`; ambos exigen la sesión de empleado vigente, `X-GAM-Session`, generación del dispositivo y permiso funcional. No se usa el rol ni el modo local del frontend como evidencia de autorización.

## Datos demo locales

[WeighingDemoSeeder](database/seeders/Lots/WeighingDemoSeeder.php) se ejecuta sólo en `APP_ENV=local`, después de los lotes demo. Cuando no existe configuración:

- crea rangos explícitamente demo;
- registra una serie individual para evolución;
- registra el caso `20, 22, 19.5, 221` con confirmación del outlier;
- registra un pesaje grupal con dos tandas.

Las claves idempotentes son estables y las escrituras atraviesan las Actions públicas. Repetir el seeder no duplica configuración, pesajes, operaciones ni auditorías. Si existe cualquier configuración previa, el seeder omite toda la sección demo de Pesajes para no modificar datos del usuario ni depender de rangos ajenos.

## Activación local

Aplicar la migración aditiva:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan migrate --no-interaction
```

En una instalación local ya sembrada y sin configuración de Pesajes, cargar sólo esta sección:

```bash
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\Lots\WeighingDemoSeeder' --no-interaction
```

La especificación está incluida en el selector de Swagger UI de `compose.dev.yaml`. Si el contenedor conserva la lista anterior, recrear únicamente `swagger-ui`. No eliminar volúmenes ni ejecutar `docker compose down -v`.

## Verificación automatizada

La cobertura principal está en:

- `tests/Unit/Lots/WeighingMathTest.php`;
- `tests/Feature/Lots/WeighingEndpointTest.php`;
- `tests/Feature/Lots/WeighingContractTest.php`;
- `tests/Feature/Lots/WeighingConcurrencyTest.php`;
- `tests/Feature/Lots/WeighingDemoSeederTest.php`.

Resultados verificados el 2026-09-09 dentro del servicio `api`, PostgreSQL y la base aislada `sga_backend_testing`:

- 37 pruebas focalizadas aprobadas con 441 aserciones, incluyendo regresión del seeder de Lotes.
- Suite completa aprobada con 305 pruebas y 2226 aserciones usando el `APP_KEY` de PHPUnit y un pepper temporal no persistido.
- Contrato final aprobado con 5 pruebas y 197 aserciones después de condicionar precisión, unidad, CSRF y transportes en OpenAPI.
- Larastan nivel 5: dominio y capa HTTP sin errores.
- Pint aplicado a los 53 archivos PHP modificados o nuevos.
- Ocho rutas confirmadas, YAML válido y `git diff --check` sin errores.
- Revisiones finales funcional y de seguridad sin bloqueadores.

La receta oficial de ejecución y las variables completas están en [README.md](README.md). Para la suite focalizada, usar esa misma receta agregando los cinco archivos anteriores y `tests/Feature/Lots/LotsDemoSeederTest.php` al comando `php artisan test --compact`.

## Do Test — aceptación manual pendiente

1. Probar las ocho rutas sin autenticación, con permisos insuficientes, con un usuario personal autorizado y con sesiones compartidas web/nativa. Confirmar `401`, `403` y separación estricta de cookie/header.
2. Consultar configuración inexistente; crearla en gramos, actualizarla en kilogramos con `version`, repetir con versión anterior y omitir versión. Confirmar `null`, éxito, `409` y `422` respectivamente.
3. Registrar un pesaje individual normal y luego `20, 22, 19.5, 21, 221`. Confirmar que el primer outlier devuelve `409` sin efectos y que el reintento confirmado conserva `221` y sus marcas.
4. Registrar un pesaje grupal y verificar que cada rango se compara contra `total_weight / bird_count`, no contra el total.
5. Intentar exceder la población histórica, usar una fecha futura, anterior al alta y una fecha sin aves. Confirmar conflictos sin cambios parciales.
6. Listar por lote, modo y fechas; consultar detalle y verificar orden, paginación, snapshots, strings decimales y unidades.
7. Corregir notas, mediciones, modo, unidad, fecha y lote con razón y versión. Probar replay, payload distinto con la misma clave y versión obsoleta; revisar auditoría antes/después.
8. Consultar distribución individual en gramos y kilogramos, incluyendo `n=1`, varianza cero y outlier confirmado. Confirmar `y_unit`; comprobar `group_mode` para un pesaje grupal.
9. Consultar evolución por lote y filtros; confirmar orden estable, corrección inmediata y error `422` al superar 1000 puntos sin acotar fechas.
10. Ejecutar `WeighingDemoSeeder` dos veces y comprobar idempotencia. Repetir sobre una instalación con configuración propia incompatible y verificar que no modifica ni crea datos de Pesajes.

Registrar ambiente, commit, actor, requests anonimizados, claves idempotentes de prueba, respuestas, operaciones y entradas de auditoría. Las pruebas automatizadas no sustituyen esta aceptación manual ni habilitan mover el Módulo 06 completo a `Do Test`.

## Fuera de alcance

Frontend y biblioteca de gráficos, Plan de Manejo, aplicaciones sanitarias, historial de vacunaciones o medicaciones, consumo automático de stock, rangos por raza o sexo, curvas teóricas, mediana, percentiles, coeficiente de variación, intervalos de confianza, predicciones, sublotes, identificación individual permanente, exportaciones, campañas, cancelaciones, borrado y sincronización directa con Notion.
