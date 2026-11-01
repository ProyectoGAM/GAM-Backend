# Auditoría: resumen y detalle bajo demanda

## Alcance

Implementación de la opción 2 acordada el 31/10/2026. El listado técnico existente
se amplía de forma aditiva con responsables y resumen. El detalle se consulta sólo
al abrir una entrada y devuelve hechos, cambios legibles y acciones calculadas
contra el estado actual. Contrato: [audit.yaml](../../contracts/openapi/audit.yaml).
La integración del cliente está en [el informe para frontend](audit-frontend-integration.md).

No se agregan dependencias ni migraciones. Se reutilizan los JSON de activity_log.
No se ejecutaron migraciones ni seeders sobre datos de trabajo.

## Rutas y autorización

| Método | Ruta bajo /api/v1 | Uso |
| --- | --- | --- |
| GET | /audit/entries | Listado anterior con actor ampliado y presentation resumida. |
| GET | /auditoria/entradas/{auditEntry} | Detalle bajo demanda, identificado por ID de auditoría. |
| GET | /auditoria/responsables | Selector paginado, con buscar, por_pagina y page. |
| GET | /reference/options | Catálogo existente con etiquetas de eventos legibles. |

Cada endpoint nuevo tiene su FormRequest y exige audit.view. El catálogo existente
sigue siendo accesible por usuarios autenticados, pero su bloque audit sólo se
completa para quienes pueden leer auditoría. No se agrega audit.actors ilimitado.

Los filtros originales del listado siguen disponibles. Se agrega actor_kind
(person, system, unavailable). Los límites son 1..100 por página y 1..1000000
para page; los responsables usan por_pagina=25 por defecto y buscar de 1..100
caracteres. El listado conserva per_page=50 por defecto. Fecha descendente y
desempate por ID descendente; los totales permanecen en servidor.

No se incorporan asignaciones de usuarios a unidades productivas ni restricciones
por UP adicionales a los permisos funcionales.

## Captura histórica

AuditEntryData::forSubject conserva un actor_snapshot mínimo con kind y name.
Sólo usuarios reconocidos se identifican como personas. Un proceso sin actor
requiere evidencia explícita de scheduler o automatic_process para identificarse
como sistema; null por sí solo no basta.

El DTO también captura presentation_subject para entidades reconocidas con nombre
o código y su identificador de ruta. Las claves UUID del sujeto siguen usando
subject_key por compatibilidad con subject_id bigint; esa clave técnica no se
vuelca arbitrariamente en la presentación.

SpatieAuditRecorder captura production_unit_snapshot con ID y nombre cuando hay
up_id. FlockFeedingHistory guarda lote público y código, receta y galpón para las
operaciones de alimentación. Las rectificaciones conservan el nombre anterior de
receta desde la entrada previa de ese consumo, cuando existe evidencia.

Todo esto se registra mediante el grabador compartido en la misma transacción de
las Actions existentes, sin colas. No se actualizan ni rellenan entradas anteriores.
La captura refleja los nombres al registrar la operación; una recuperación tardía
no promete reconstruir el nombre que tenía una entidad en la fecha de negocio.

Los nombres que el histórico anterior no conservó siguen siendo desconocidos.
El nombre actual del usuario se expone separadamente como current_name.
exists significa que existe su fila, incluso si tiene deleted_at; status=disabled
identifica ese caso. can_manage exige fila existente e identity.users.manage.
Una eliminación física conserva el nombre histórico y elimina la posibilidad de
gestión. No se devuelve el resto del directorio, correos, roles ni permisos.

## Representación

Controllers coordinan consultas y preparan las representaciones. Los Resources
serializan los datos preparados. Los nombres actuales de actores se resuelven en
una consulta por página; el listado no consulta recursos de negocio para calcular
acciones ni genera todos sus detalles.

AuditPresentation usa una lista explícita de campos, incluidas rutas anidadas
conocidas de etapas, censos y aplicaciones. No recorre JSON arbitrario para
convertirlo en etiquetas. Los saldos de inventario presentan producto y ubicación
históricos desde sus líneas auditadas. Los nombres actuales no se usan para
rellenar referencias históricas ausentes.

Para pesajes convencionales y diarios, presentation.subject identifica el lote
del evento con resource=flock e id público. Se lee exclusivamente la instantánea
capturada: flock_id en weighing_recorded/weighing_corrected y
daily_weighing.flock_id en daily_weighing_entry_added/daily_weighing_entry_deleted.
Esto incluye registros anteriores a esta entrega, sin modificar auditorías ni
consultar el lote o el pesaje vigente. Se validan ULID públicos para no convertir
IDs internos o datos opacos en destinos. label permanece null cuando no hay un
nombre histórico; el frontend puede mostrar Ver lote y construir su ruta conocida.
La navegación conserva los permisos del recurso y sus respuestas 403/404.

Los gramos se convierten con Brick Math, moviendo el punto decimal exactamente;
el formato usa coma decimal y unidad. Las fechas de negocio se muestran DD/MM/YYYY
sin conversión de zona. Los instantes reconocidos conservan ISO con offset.
No se retorna HTML dentro de presentation.

### Presentador específico de pesajes

WeighingAuditPresentation interpreta las instantáneas de weighing_recorded,
weighing_corrected, daily_weighing_entry_added y daily_weighing_entry_deleted.
El resumen distingue modalidad y operación, y presenta aves, peso total, promedio
y evaluación de referencia. El detalle agrega mediciones y cambios legibles.

Los pesos se expresan en gramos normalizados con agrupación española y todos los
decimales almacenados. El formato usa Brick Math sin float ni redondeo adicional.
Se conserva la evaluación histórica por medición; no se deduce normalidad desde
un promedio que podría ocultar valores fuera del rango. Una referencia ausente
se explica como Sin rango esperado disponible.

Los ingresos diarios separan los datos del ingreso registrado/eliminado de los
totales diarios. Para los cambios se reconoce tanto el before plano que guarda
el alta como el daily_weighing anidado que guarda la eliminación. Se muestran
cantidad, total, promedio y anomalías antes/después. Al vaciarse una jornada, su
promedio nulo se presenta como Sin aves en la muestra; un before no conservado
continúa siendo null.

Este presentador aplica una lista de negocio conocida e ignora metadatos como
IDs, versiones o referencias de configuración, sin mostrarlos como JSON ni
generar el aviso genérico de cambios no presentables. Si faltan datos de negocio
requeridos, declara la limitación específica. Los avisos de nombres históricos
ausentes permanecen separados. No consulta tablas vigentes ni modifica auditorías,
acciones de pesaje o autorización. El listado sólo prepara su resumen; las
mediciones y comparaciones completas se preparan en el detalle bajo demanda.

changes_state distingue presented, partial, unavailable y not_recorded. Un null
en before/after no equivale a Sin registrar. history.missing explica límites sin
exponer nombres de claves internas. La detección de campos no presentados es
conservadora: también puede incluir metadatos que se omiten intencionalmente.

El endpoint de detalle no entrega properties, attribute_changes, operation_id,
trace_id ni tipos de modelos como sujeto. El listado conserva sus campos técnicos
anteriores por compatibilidad; el cliente nuevo debe usar presentation.

## Acciones actuales y reglas de negocio

Se integran dos acciones:

- reverse_inventory_movement: exige la política reverse y verifica compensación
  anterior, operación de origen, productos, ubicaciones/UP operativas,
  compatibilidad, precisión y saldo actual. Las regularizaciones, elaboraciones
  y aplicaciones sanitarias no anuncian una reversión genérica.
- correct_feed_consumption: dirige al consumo y al identificador público de su
  lote. La entrada original y las revisiones reemplazadas quedan indisponibles
  para evitar trabajar sobre una versión histórica. La última revisión permite
  registrar otra rectificación.

InventoryReversalAvailability comparte las restricciones del origen con
ReverseInventoryMovementAction. La comprobación final sigue dentro del comando,
con autorización, transacción y locks; la evaluación de lectura no reserva stock.

En alimentación, el permiso específico sigue pendiente por una decisión previa
del proyecto: el comando existente admite sesión autenticada y valida el contexto
de dispositivo compartido cuando corresponde. Esta entrega no crea un permiso
nuevo. También conserva la regla existente de admitir rectificaciones de lotes
cerrados y recetas inactivas. No se declara unavailable sólo porque un lote cerró.

available es una evaluación orientativa al consultar. unavailable no implica
irreversibilidad. irreversible se limita a hechos sin operación de reversión
integrada, como cierre de sesión o expiración de una exportación. El resto de los
módulos devuelve unknown hasta integrar su elegibilidad específica; siguen
mostrando sus campos reconocidos y su historial.

Los comandos conservan Idempotency-Key, validación, permisos y compensaciones.
Abrir un detalle no ejecuta mutaciones. Los destinos son objetos semánticos, sin
URLs o verbos HTTP proporcionados al cliente.

## Compatibilidad, límites y despliegue

La incorporación es aditiva para el listado y el catálogo. Las rutas nuevas están
en español; no se renombran rutas existentes. El catálogo deriva etiquetas
específicas o usa la descripción legible del evento; si sólo hay un código opaco,
muestra Actividad registrada. Los eventos desconocidos conservan totales y páginas.

No existe una reconstrucción universal del pasado ni se promete una acción de
corrección para todos los eventos. Los presentadores específicos actuales cubren
alimentación, inventario y pesajes; otras áreas reciben presentación permitida de campos
reconocidos y una explicación de alcance.

Swagger de desarrollo agrega Auditoría e historial en compose.dev.yaml. Un servicio
Swagger ya iniciado necesita recrearse para mostrar la nueva opción del menú;
el archivo OpenAPI está disponible en el repositorio desde este cambio.

## Verificación

La cobertura nueva verifica autorización, compatibilidad del listado, eventos
desconocidos, responsables históricos y dados de baja, búsqueda literal paginada,
filtros de sistema/desconocido, exclusión de secretos anidados, precisión, fechas,
referencias históricas reales, rectificaciones, restricciones por operación,
revalidación de stock al confirmar y consultas de responsables en bloque.
También contrasta las formas públicas con OpenAPI.

Resultado de la verificación inicial: 308 casos distintos aprobados en PostgreSQL aislado de testing:

- 26 de auditoría y opciones de referencia, incluidos 18 del contrato nuevo.
- 148 de inventario, alimentación, almacenamiento, elaboración y compensaciones sanitarias.
- 134 de medicamentos, vacunas, geografía, instalaciones, mantenimientos,
  clientes y autenticación, que también utilizan el DTO de auditoría compartido.

Ampliación de navegación de pesajes: 11 casos nuevos aprobados y regresión completa
de AuditPresentationTest aprobada con 29 casos y 212 aserciones. Se verificaron
pesajes individuales y grupales reales en ambas APIs, lectura del lote mediante
su ID público, referencias históricas de altas/correcciones/eliminaciones y
rechazo de IDs internos, ausentes o inválidos. El cambio del presentador aprobó
PHPStan nivel 5 y Pint; OpenAPI se validó dentro de la regresión.

Ampliación de presentación legible de pesajes: la suite de auditoría completa
aprobó 41 casos y 346 aserciones. Incluye ejemplos individuales/grupales y
rectificaciones, precisión de seis decimales, ausencia de referencia, detección
de mediciones fuera del rango aunque el promedio esté dentro, y alta/eliminación
real de ingresos diarios hasta vaciar una jornada. Se verificó que IDs, versiones
y secretos anidados no se conviertan en hechos visibles. PHPStan nivel 5 aprobó
las tres clases de aplicación modificadas; Pint aprobó esas clases y la prueba
actualizada.

La comprobación de exclusión de secretos se repitió después del último ajuste
de destinos e instantáneas y aprobó sus 14 aserciones. El análisis PHPStan nivel 5
con la configuración Larastan existente no encontró errores en los archivos de
aplicación cambiados; los últimos ajustes del presentador y DTO se comprobaron
también por separado. route:list confirmó las dos rutas nuevas y git diff --check
no encontró errores.

Se intentó el Pint obligatorio con --dirty, pero el contenedor no reconoce Git.
Se ejecutó Pint con --format agent sobre la lista explícita de todos los PHP
cambiados y nuevos, y sobre los últimos ajustes: formato aprobado.

No se ejecutó toda la suite del repositorio ni se midió rendimiento con volúmenes
de producción. Se comprobó que la resolución de responsables no agrega consultas
por fila. No se verificó una interfaz frontend: este repositorio entrega el
backend y el informe de integración.


## Clasificación de planes de manejo

Los eventos plan_template_created/revised/published/retired/activated y
flock_plan_assigned/inherited/revised pertenecen a Manejo y sanidad
(management_plans), también cuando el registro original tiene log_name=lots.
La consulta aplica esta clasificación antes de contar y paginar; el listado de
Todo el sistema presenta el mismo módulo. Los demás filtros mantienen su alcance.
La alimentación (flock_feeding_plan_revised) continúa en Inventario y raciones.
Los eventos de Lotes sin código o desconocidos no se eliminan del historial.

Se conservan intactos los registros append-only: no hay migración, reescritura,
seeder ni cambios en permisos, operaciones de negocio o acciones de reversión.
La prueba de AuditEntryTest verifica historial antiguo/actual, módulos, filtros,
paginación, totales, ausencia de duplicados y persistencia del módulo original.
Verificación: 35 pruebas de auditoría aprobadas (273 aserciones) en gam_audit_modules_20261031_testing; Pint aprobado con las cuatro rutas PHP explícitas porque el contenedor no dispone del modo Git --dirty. Navegador local: Lotes pasó de 570 a 526 entradas y Manejo y sanidad presenta 49; las plantillas y asignaciones aparecen en su módulo correcto.

## Mortalidad: lote y deshacer

Mortalidad registrada, rectificada y cancelada enlaza al lote con el flock_id
público conservado en su instantánea. Los eventos de ajuste del censo asociados
usan public_id. No se usan IDs internos como rutas de navegación.

El detalle incorpora undo_mortality con target.resource=mortality_record,
target.id=ULID público del registro y target.flock_id=ULID público del lote.
Las entradas del registro y del censo se resuelven mediante la misma operación
auditada, comprobando que el registro pertenezca al lote del evento.
La disponibilidad exige el registro vigente, su versión histórica coincidente
y permisos de consulta del lote/mortalidad y gestión de mortalidad. Una entrada
ya cancelada o rectificada conserva su explicación y no habilita otro deshacer.

La acción navega a la confirmación existente del módulo de Mortandad. Consultar
la auditoría o abrir la confirmación no modifica datos. La cancelación conserva
POST mortalities/{id}/cancellation, Idempotency-Key, version, flock_version y
reason (máximo 500), además de confirm_reactivation cuando corresponda.
El comando continúa validando concurrencia, ocupación y reactivación del lote;
no se duplican esas reglas ni se modifica el historial append-only.
Verificación de mortalidad: 68 pruebas de auditoría/mortalidad aprobadas (575 aserciones) en la base aislada; los dos casos nuevos se repitieron después del ajuste final (37 aserciones). PHPStan sin errores y Pint aprobado con rutas explícitas, al no disponer el contenedor del modo Git --dirty. Navegador local: enlace al lote, Deshacer, apertura del registro correcto con motivo obligatorio y cancelación sin modificar datos reales; escritorio y teléfono claro/oscuro.

## Integración con develop del 01/11/2026

Se integró develop (c8843e9) en historyDetails, conservando sus cambios de
vehículos, repartos y autenticación junto con la presentación de auditoría.
El único conflicto estaba en el selector de contratos de Swagger en
compose.dev.yaml: se conservaron Auditoría e historial y Vehículos.
Las rutas de ambos módulos se integraron sin conflictos. Esta resolución no
agrega cambios al contrato de auditoría, permisos ni persistencia.

La verificación detectó dos fallos en el código recibido de develop. El alta de
vehículos ahora recarga los valores persistidos antes de registrar el historial
inicial: el estado predeterminado active queda disponible y se evita un error
500 por to_status nulo. Se conserva la transacción con auditoría síncrona y no
se agregan migraciones ni cambios de permisos. El contrato de vehículos aclara
el estado inicial. La prueba de repartos ahora declara el vehículo enviado para
que su hash esperado incluya todos los datos del comando; no cambia el cálculo
de idempotencia del backend ni se incorpora el PIN al hash.
PHPStan también detectó una comparación redundante después de isset en la
validación del conductor habitual; se simplificó sin cambiar su comportamiento.

Verificación de la integración: Compose válido, JSON del selector de Swagger
válido y todos sus contratos presentes; las tres rutas de auditoría están
registradas. La ejecución conjunta de auditoría, vehículos, repartos, finanzas
de reparto y salud tuvo 72 casos aprobados y los dos fallos descritos (712
aserciones). Tras los ajustes, los dos casos afectados pasaron con 23 aserciones,
incluida la comprobación del estado active en la respuesta y el historial inicial.
Pint aprobó los PHP ajustados con rutas explícitas, porque el contenedor no
dispone del modo Git --dirty. No se repitió toda la selección ni la suite completa
después de esas dos correcciones.
La repetición final de los dos casos volvió a pasar (23 aserciones), Pint pasó
y PHPStan no encontró errores en SaveVehicleAction con nivel 5.

## Regresiones detectadas por CI tras la integración

La suite completa de CI del 01/11/2026 detectó tres problemas fuera del
formateador de auditoría. La descripción de odometer_km en el contrato de
repartos ahora lleva comillas: su coma invalidaba el YAML y la resolución de
referencias desde el contrato de clientes. El texto y el esquema no cambian.

La prueba web de pesajes utiliza http://localhost:4200 desde el login hasta
las consultas, como las demás pruebas web y la configuración de Sanctum.
localhost sin puerto quedó reservado para el origen nativo de Capacitor.
Se conserva la comprobación de rechazo de credenciales nativas en una sesión
web, sin ampliar orígenes confiables ni modificar middleware o autenticación.

La prueba de reportes conserva diez fuentes cuando faltan los permisos de
vehículos y comprueba 403 para esas tres fuentes. Después concede vehicles.view,
vehicles.fuel.view y vehicles.maintenance.view y ejecuta las trece fuentes y
sus consultas estándar. La guía de reportes incorpora esas fuentes existentes.
No se cambian permisos de producción, reglas de negocio, datos ni migraciones.

La verificación completa también agotó el tmpfs de /tmp (64 MB) al generar la
caché de PHPStan. CI utiliza ahora .phpstan-ci.neon para guardar esa caché en
storage/framework/cache/phpstan-ci, conservando el nivel 0 y el alcance app del
comando anterior. No se amplían recursos de los servicios ni se cambian paquetes.

Los tres casos originales se reprodujeron antes del ajuste y pasaron después
con 524 aserciones en gam_testing. Pint aprobó los dos archivos PHP modificados
y PHPStan aprobó los 785 archivos de app con la configuración de CI corregida.
La suite completa pasó con las variables de CI y LOG_CHANNEL=null para omitir
los mensajes esperados de fallos simulados de auditoría: 875 pruebas y 8295
aserciones, sin errores ni fallos. Se ejecutó en el contenedor local con PHP
8.5.10, PHPUnit 12.5.34 y PostgreSQL aislado gam_testing. La ejecución de GitHub
adjunta utilizaba PHP 8.5.12; esta comprobación local no sustituye su nueva
ejecución remota.
