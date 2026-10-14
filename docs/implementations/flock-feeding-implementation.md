# Alimentación de lotes, consumos diarios y rectificaciones

## Alcance y decisiones

El tercer bloque consume exclusivamente alimento elaborado desde `FeedBalance`, por receta y UP del lote. No descuenta ingredientes, modifica elaboraciones ni aplica sus restricciones de cancelación. Permite saldos cero o negativos sin elaboración previa, con aritmética exacta y límites comprobados.

Incluye etapas de alimentación del plan, inicialización automática al admitir aves, configuración manual fija, regreso explícito a automático, integración con mortalidad y redistribuciones, recuperación de días pendientes, consultas y rectificaciones con compensación. No incorpora frontend, comidas individuales, pausa, alertas, notificaciones ni eliminación/cancelación de consumos.

Se confirmó con el usuario que el total manual debe ser positivo; una rectificación puede establecer cero. También se confirmó que revisar el plan del lote aplica sus nuevas etapas desde el día de la revisión, preservando días anteriores y cualquier configuración manual vigente. Las transiciones automáticas evalúan la disponibilidad al inicio del día: si la receta estaba activa a medianoche, la etapa queda vigente aunque se desactive durante esa jornada.

## Planes y calendario

Cada `ration_change` referencia una receta global por ID estable y declara `grams_per_bird`, en gramos enteros positivos hasta 999999999999. La referencia `catalog_ref` de estas actividades pasa de productos de alimento terminado a recetas; su tipo copiado es `feed_recipe`. Admite día, semana o tramo de semanas, sin condición. Debe existir exactamente una alimentación inicial en día 1 o semana 1, incluyendo la prohibición de expresar ambas a la vez. No existe día/semana cero. Se rechazan dos etapas con el mismo comienzo; los tramos conservan su fin descriptivo, y la alimentación vigente continúa hasta un comienzo posterior disponible.

El lote obtiene actividades y estimaciones copiadas de la versión publicada. Editar o publicar otra versión de la plantilla no modifica su copia. Una revisión explícita del plan propio crea otra revisión y otra configuración de alimentación con fecha efectiva de hoy; conserva historia y valores manuales.

El ingreso ordinario debe ser el día del registro en America/Montevideo. Se rechazan tanto días anteriores como futuros. La edad de alimentación es inclusiva: ingreso = día 1; semana 10 comienza en día 64. La edad se conserva al fraccionar mediante la fecha de referencia del plan copiado.

El consumo automático utiliza la etapa correspondiente a la fecha y las aves sobrevivientes al cierre: gramos por ave × máximo entre cero y población. Por ejemplo, mil aves con cien bajas el lunes y estimación de 40 g producen un consumo del lunes de 36000 g, registrado el martes a las 00:00. Censo cero o negativo conserva un consumo de cero, sin movimiento de stock.

Una etapa cuya receta está desactivada al inicio del día de comienzo se omite por completo: permanecen receta y gramos de la etapa anterior. Una receta que ya estaba vigente sigue consumiéndose si después se desactiva, incluso dentro de la misma jornada del comienzo. Reactivar una etapa omitida no provoca por sí solo una transición; se espera una etapa posterior, un cambio manual o el regreso explícito a automático. Para recuperar días pendientes se consulta en bloque la historia auditada de estados de recetas, no solamente su estado actual. El alta, una revisión explícita del plan y el regreso a automático seleccionan su ancla con disponibilidad en el instante de esa operación; luego las etapas futuras vuelven a evaluarse a medianoche.

## Manual, automático y movimientos

Cambiar receta, total diario o ambos deja toda la configuración en modo manual. Una receta recién seleccionada debe estar activa. Cambiar solamente cantidad conserva la receta, incluso desactivada; cambiar sólo receta conserva el total vigente calculado en ese momento. Si ese total es cero, debe indicarse además un total manual positivo. El total y la receta permanecen fijos pese a edad, bajas, población cero, redistribución o agrupación. La configuración afecta toda la jornada del cambio y no jornadas anteriores pendientes.

Volver a automático crea una operación auditada y una nueva fecha efectiva. Retoma las etapas copiadas según edad y disponibilidad de recetas y vuelve a calcular cada jornada con su propia población. No reescribe consumos previos.

Todas las variantes de redistribución y su reversión rechazan cambiar de UP. Dentro de una misma UP:

- Fraccionar en automático copia todas las etapas de alimentación, incluyendo la vigente, preservando edad. El origen consume por las aves restantes y el destino por sus aves.
- Fraccionar en manual copia exactamente receta y total, sin repartirlo ni escalarlo.
- Incorporar aves a un receptor existente conserva el plan y modo del receptor. Automático usa su nueva población; manual mantiene su total.
- Un traslado total conserva identidad, alimentación y UP.
- Una unión total deja el origen con censo cero por el movimiento real de aves. En manual conserva su total para la jornada terminal, según la regla de jornada completa.

No se agregan asignaciones de usuarios ni filtros de acceso por UP. Los permisos vigentes de lotes, planes y otros manejos permanecen.

## Proceso diario y recuperación

`alimentacion:consumir` se programa a las 00:00, explícitamente en America/Montevideo, con `withoutOverlapping()` y `onOneServer()`. Debe estar funcionando el scheduler normal de Laravel y su cache compartida.

```powershell
docker compose -f compose.dev.yaml exec -T api php artisan alimentacion:consumir
docker compose -f compose.dev.yaml exec -T api php artisan alimentacion:consumir --lote=ULID_DEL_LOTE
```

Recupera todas las jornadas pendientes hasta ayer, incluyendo lotes ya finalizados. Cada fecha selecciona su configuración efectiva, sus etapas copiadas, edad y censo histórico. No aplica el manual actual indiscriminadamente al pasado. Cada jornada confirma su propia transacción; una falla deja esa fecha pendiente, devuelve código distinto de cero y permite continuar otros lotes. Un reintento reconoce registros anteriores.

Los días de creación y finalización cuentan completos aunque la operación ocurra durante el día. La población del día final se reconstruye desde los movimientos inmutables, excluyendo el cero administrativo del movimiento `departure`; no se pierde cuando `FinalizeFlockAction` pone `current_quantity` a cero. Las transferencias reales sí afectan la población al cierre. Sólo la terminalidad detiene fechas posteriores; cuarentena continúa consumiendo. Las compensaciones de mortalidad participan desde su fecha de movimiento y no alteran consumos ya registrados; cualquier corrección del alimento histórico requiere rectificación explícita.

Una restricción única `(flock_id, consumption_date)` y un lock del lote serializan reintentos, procesos concurrentes y recuperación. Los consumos automáticos crean operaciones `flock.feeding.daily` sin actor humano, para mantener la FK del journal de lotes. Los consumos cuentan como actividad posterior al evaluar una reversión de redistribución.

## HTTP

[Contrato OpenAPI](../../contracts/openapi/flock-feeding.yaml). Las rutas están bajo `/api/v1`:

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | `/lotes/{flock}/alimentacion` | Receta, modo, total diario, estimación, censo actual y etapa. |
| GET | `/lotes/{flock}/alimentacion/historial` | Configuraciones con fechas y planes copiados, paginadas. |
| POST | `/lotes/{flock}/alimentacion/manual` | Cambia receta, total o ambos. |
| POST | `/lotes/{flock}/alimentacion/automatico` | Restablece automático. |
| GET | `/lotes/{flock}/consumos-alimento` | Consumos efectivos y acumulado exacto. |
| GET | `/lotes/{flock}/consumos-alimento/{consumption}` | Original, último efectivo, censo, etapa, rectificaciones y movimientos. |
| POST | `/lotes/{flock}/consumos-alimento/{consumption}/rectificaciones` | Compensación con motivo. |

Los nuevos endpoints requieren la sesión autenticada existente y los middleware de dispositivo/sesión compartidos. En nativo compartido se exigen `X-Shared-Device-Token`, `X-GAM-Session`, sesión actual y generación vigente; en web, la credencial HttpOnly y CSRF existentes. No agregan permisos funcionales. Una consulta de estos historiales de negocio no expone la API genérica de auditoría, que conserva `audit.view`.

Las mutaciones requieren UUID en `Idempotency-Key`, devuelven 201 y la misma respuesta al repetir clave/contenido. La clave no se admite en el cuerpo. Otro contenido con esa clave devuelve 409. Un consumo ajeno al lote devuelve 404.

Entrada manual:

```json
{"receta_id": 1, "cantidad_diaria_g": "6500"}
```

Entrada de rectificación:

```json
{"receta_id": 2, "cantidad_g": "6000", "motivo": "Recuento de la jornada"}
```

El regreso a automático admite un cuerpo vacío. Cantidades recibidas admiten entero JSON o cadena entera; se rechazan punto flotante, fracciones, notación científica y redondeos. Las cantidades de respuestas se entregan como cadenas para preservar precisión. Los listados admiten `desde`, `hasta`, `por_pagina` (1–100) y `page` acotada; ordenan fecha e ID descendente. `acumulado_g` suma cantidades efectivas de todo el filtro antes de paginar y usa suma decimal exacta de PostgreSQL.

El endpoint existente `POST /flocks/{flock}/cambios-racion` conserva `management-plans.execute` y su registro de manejo, pero ahora cambia la configuración manual del día mediante la misma Action. Sustituye `finished_feed_product_id` por `feed_recipe_id` y `daily_quantity_grams` (uno o ambos obligatorios), con `occurred_at` del día de registro. No registra comidas ni descuenta stock inmediatamente. Los antiguos registros con snapshots de productos siguen consultables; corregir su metadata no revierte la alimentación vigente.

## Persistencia, auditoría y límites

La nueva migración agrega receta/estimación a actividades de plantilla y lote y amplía `ration_changes` con referencias y snapshot de receta y total diario. No transforma automáticamente planes o lotes antiguos sin alimentación. Los datos demo nuevos sí son coherentes; no se requiere una migración de negocio para datos reales.

Tablas nuevas:

- `flock_feeding_changes`: historial de configuraciones, modo, referencia del plan, fechas, etapas copiadas, ancla automática y cantidad manual.
- `feed_consumptions`: original diario inmutable, configuración aplicada, edad, población, censo con movimientos usados, receta y etapa.
- `feed_consumption_corrections`: rectificaciones anexadas con resultado efectivo, motivo e información anterior.
- `feed_consumption_movements`: débitos y compensaciones anexados sobre los mismos `feed_balances`.

Las FKs restrictivas conservan referencias aunque recetas se desactiven. La migración permite `created_by=null` en `flock_operations` únicamente para el comando automático, sin retirar la FK de usuario ni el vínculo del journal. Su rollback rechaza eliminar etapas con recetas o historia existente: después de configurar u operar debe usarse una migración correctiva. No se ejecutaron migraciones ni reconstrucciones sobre bases normales durante la verificación.

Una rectificación devuelve la última cantidad efectiva a su receta anterior y descuenta la nueva en la UP histórica. Para la misma receta registra la diferencia neta; diferencias cero no generan movimientos. Acepta recetas desactivadas, preserva originales y auditorías, y no modifica configuración del lote, manual ni plan.

Los locks siguen actor cuando corresponde, lote, consumo y recetas/saldos en orden técnico de ID. `FeedOperationRunner::balance()` reutiliza creación segura y bloqueo de `FeedBalance`. Saldo y cantidad diaria respetan el rango real -999999999999 a 999999999999 g, con cantidades de consumo no negativas; multiplicación usa `BigInteger`. No se relajan los gramos positivos de recetas ni elaboraciones.

Las Actions escriben con `AuditRecorder` y `AuditEntryData` dentro de la misma transacción que el cambio, con operación, traza, actor o proceso automático, UP, snapshots permitidos y cambios old/new. Falla de auditoría revierte configuración, consumo, compensación y stock. No se registran secretos ni se sobrescribe historia.

## Datos demo

`FlockFeedingDemoSeeder` está integrado en la entrada oficial. Incluye un lote `DEMO-FEEDING`, tres jornadas (automático 4000 g, manual 6500 g, automático 4000 g) y una rectificación del manual a 6000 g. Repetirlo conserva operaciones, saldos e historia.

Los seeders ordinarios de lotes usan ingreso del día y movimientos sólo dentro de la UP. Los fixtures optativos de historial de huevos y diseño de pesajes, y el lote histórico de alimentación, simulan cronológicamente el reloj del alta en su día y lo restablecen; no introducen una excepción al ingreso ni aceptan altas retroactivas en la API. Se preservan los datos existentes al repetir seeders.

## Verificación

Versiones verificadas: PHP 8.5.10, Laravel 13.29.0, PHPUnit 12.5.34, Brick Math 0.18.0 y Symfony YAML 8.1.5; documentación de transacciones, locks, validación y scheduler consultada mediante Laravel Boost. El esquema existente se consultó con `database-schema`. Referencia técnica: [documentación oficial de Laravel 13 sobre transacciones](https://laravel.com/framework/docs/13.x/database#database-transactions).

Las pruebas nuevas cubren inicial obligatorio/único, cero y negativos automáticos, etapas y disponibilidad histórica, independencia de plantilla, prioridad manual, regreso, revisión del plan, edad/modo al fraccionar, receptor, prohibición entre UP, jornadas inicial/final, cuarentena, recuperación, rectificaciones con cero y motivo, configuración/historia intactas, límites exactos, rollback y sesión compartida real. Las cuatro pruebas de concurrencia ejecutan procesos separados contra PostgreSQL: día duplicado, saldo compartido, rectificación duplicada y rectificaciones distintas.

La corrida final focalizada pasó **42 pruebas y 900 aserciones**, incluyendo las cuatro de concurrencia real, los datos demo oficiales y los contratos de planes y lotes. La comprobación adicional a la **01:00 y 12:00 locales** pasó con **2 casos y 16 aserciones**. La corrida amplia de Inventario, sus unidades, planes de manejo, Lotes y sus unidades pasó 330 casos; las dos fallas de sintaxis YAML de esa corrida se corrigieron y sus pruebas de contrato están incluidas en la corrida final aprobada.

```powershell
docker compose -f compose.dev.yaml exec -T -e APP_ENV=testing -e DB_HOST=postgres -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=null api php artisan test --compact tests/Feature/Inventory/FlockFeedingEndpointTest.php tests/Feature/Inventory/FlockFeedingConcurrencyTest.php tests/Feature/Inventory/FlockFeedingDemoSeederTest.php tests/Feature/ManagementPlans/ManagementPlanContractTest.php tests/Feature/Lots/LotsContractTest.php
docker compose -f compose.dev.yaml exec -T -e APP_ENV=testing -e DB_HOST=postgres -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=null api php artisan test --compact tests/Feature/Inventory/FlockFeedingEndpointTest.php --filter=test_recipe_deactivated_during_transition_day_remains_the_effective_stage_for_recovery
```

Pint pasó para todos los PHP cambiados; Larastan/PHPStan nivel 5 pasó sin errores para todos los archivos fuente modificados y nuevos, usando `.phpstan-vaccines.neon` con paths explícitos, sin baselines ni exclusiones nuevas. `git diff --check`, la comprobación de espacios finales de archivos nuevos, `route:list --path=lotes` y `schedule:list` pasaron. El scheduler muestra 03:00 UTC, equivalente a las 00:00 de America/Montevideo; su ejecución se comprobó con reloj controlado en las pruebas.

La base aislada termina en `_testing`; no se ejecutaron migraciones ni reconstrucciones destructivas sobre la base normal. El comando obligatorio `pint --dirty --format agent` se intentó, pero el contenedor no dispone de Git; se usó la lista explícita obtenida por Git en el host. No se verificó un despliegue ni la ejecución nocturna continua del scheduler. La migración debe aplicarse en el entorno que vaya a ejecutar la funcionalidad. No quedan decisiones de negocio pendientes. No hay dependencias nuevas.
