# Plan unificado — Clientes y cuenta corriente (cards 13 y 15)

Fecha de análisis: **29/10/2026**. Estado: **referencia de alcance; implementación integrada disponible para pruebas funcionales**.

El diagnóstico de este plan corresponde al comienzo del trabajo. El estado final, las rutas implementadas, las pruebas ejecutadas y sus limitaciones se documentan en la [guía de implementación](customers-and-accounts-implementation.md).

Este documento reúne las dos cards como una sola entrega funcional: **Clientes y cuenta corriente**, incluyendo pagos desde administración o reparto y el ajuste manual de deuda solicitado. Las fases son partes de esta entrega, no módulos que puedan darse por terminados aisladamente.

## 1. Fuentes y límites del análisis

- [13 — Clientes](https://app.notion.com/p/3cb032f1caff80bb8f1cc202974a8e7e), consultada con el MCP de Notion. Estado observado: `Not started`.
- [15 — Cuenta corriente, pagos y cobranzas](https://app.notion.com/p/3cb032f1caff8067b28af8a3e083c9d7), consultada con el MCP de Notion. Estado observado: `Not started`.
- [14 — Pedidos y ventas](https://app.notion.com/p/3cb032f1caff80159421eed17c2b911c) y [18 — Repartos](https://app.notion.com/p/3cb032f1caff8059b855c4834b930650), leídas como dependencias para delimitar la venta directa, los cobros y los reintentos offline.
- Código local: backend `b90305f`; frontend `2ef0d17`. Ambos submódulos estaban sin cambios de archivos al iniciar esta revisión; el repositorio raíz ya tenía cambios ajenos a este trabajo.
- Reglas de ambos `AGENTS.md`, `.ai/rules`, arquitectura, integración API, diseño, movilidad y pruebas del frontend. La estructura propuesta sigue esas reglas.

Se inspeccionaron implementaciones, migraciones, contratos y pruebas existentes. Se verificaron con Docker las dependencias instaladas y las 11 rutas actuales de Repartos. No se ejecutaron pruebas funcionales, suites ni recorridos de navegador durante esta planificación. Las pruebas mencionadas más adelante son trabajo de implementación pendiente.

La card 15 menciona clases antiguas `CuentaCorriente` y `Movimiento`. No aparecen en el workspace revisado; tampoco se encontró aquí un Excel o las notas originales `Notas3`. Los requisitos recuperados son los escritos en las cards. La lógica antigua deberá contrastarse si se incorpora su código; no se presenta como inspeccionada ni migrada.

## 2. Estado actual: qué existe y qué falta

Las referencias a clases PHP pertenecen al backend; las páginas, servicios TypeScript y componentes pertenecen al frontend.

| Área | Implementación observada | Trabajo necesario |
| --- | --- | --- |
| Clientes | No hay modelos, migraciones, permisos ni API de clientes reales. `app/Services/Deliveries/LocalDeliveryClientCatalog.php` devuelve seis clientes demo en `local/testing`. | Crear la ficha persistente y sustituir el proveedor demo por consultas reales. |
| Administración de clientes | La navegación ya contiene `Clientes` y `Nuevo cliente`; las rutas caen en `AdminPlaceholderPage`. | Conectar listado, alta, edición y detalle al shell existente. |
| Repartos | Cargas, entregas, visitas sin entrega, precios históricos, recargas, retornos y cierre en `app/Actions/Deliveries`. | Integrar identidad real del cliente, cargos y cobros sin duplicar movimientos de stock. |
| Identidad en entregas | `DeliveryStop` guarda `client_reference`, nombre, dirección y coordenadas como snapshot; no tiene FK a cliente. | Añadir relación real conservando los snapshots y las referencias antiguas. |
| Restricción de visitas | `delivery_stops` es único por reparto y `client_reference`; una visita ya confirmada no puede registrarse otra vez. | Permitir cobros independientes y posteriores sin quitar la protección contra entregas duplicadas. |
| Valor de mercadería | `DeliveryPricing` calcula líneas y totales en UYU con `CustomContext(3)`; `DeliveryStop.total_amount` usa tres decimales. | Reutilizar esos importes exactos como origen del cargo. No volver a calcular precios históricos. |
| Dinero compartido | `app/ValueObjects/Money.php` usa el contexto habitual de la moneda; `formatDeliveryMoney` sólo admite importes no negativos. | No pasar importes de tres decimales por el constructor actual de `Money`. Dar soporte preciso a saldos negativos en la presentación. |
| Cuenta y cobros | No hay registro de pagos, movimientos financieros ni saldos. Existen `TODO(M14/M15)` en backend y frontend. | Implementar un único registro de movimientos financieros con autoridad en Laravel. |
| Offline | `delivery-outbox.service.ts` usa IndexedDB v2. La caché de referencias pertenece al actor, pero las entradas del outbox no guardan actor; existe fallback en memoria y `enqueue` espera el éxito de la solicitud, no el commit de la transacción. | Para cobros, garantizar persistencia durable, aislamiento de identidad y reintento sin duplicación. |
| Clientes para el repartidor | `GET /repartos/clientes` tiene búsqueda y límite de 50; no tiene paginación ni bounding box. | Lista paginada independiente del mapa y consulta ligera del viewport. |
| Auditoría e identidad | Ya existen `AuditRecorder`, `AuditEntryData`, Spatie Permission, sesiones personales y compartidas. | Reutilizar autorización de servidor y auditoría dentro de la misma transacción financiera. |
| Geografía | Existen departamentos, localidades, validación territorial y un selector Mapbox especializado en UP. Repartos usa Leaflet. | Reutilizar datos geográficos y patrones; evitar acoplar clientes al servicio de UP o reemplazar el mapa de Repartos. |
| Reportes | Ya hay frontend real y motor de reportes. `DeliveriesReportSource` reporta mercadería, no dinero cobrado ni deuda. | Conservar esa distinción. El estado de cuenta de esta entrega se consulta en la ficha; no hace falta construir otro motor de reportes. |
| Vehículos | `LocalDeliveryClientCatalog` también resuelve un vehículo demo con `TODO(M16)`, bloqueado fuera de desarrollo/pruebas. | Retirar sólo su responsabilidad de clientes. Vehículos sigue siendo una dependencia independiente y no debe declararse resuelta. |

Versiones comprobadas: Laravel 13.29.0, Brick Money 0.14.1, Sanctum 4.3.3, Permission 8.3.0 y Activitylog 5.1.0. El frontend declara Angular 22.2.3, Ionic 9 y Capacitor 8.5.1. La entrega no necesita dependencias nuevas.

## 3. Resultado funcional y alcance

Un administrador podrá crear un cliente aunque falten sus datos fiscales, abrir su ficha, ver entregas y ventas directas registradas, consultar su deuda, registrar un pago y aumentar o disminuir la deuda dejando un motivo obligatorio. El repartidor trabajará con esos mismos clientes y registrará entregas fiadas, pagos parciales, pagos completos y pagos de deuda sin mercadería.

Incluye:

- Datos generales, razón social opcional, identificación flexible, contacto, dirección, ubicación, estado, frecuencia de visita, condiciones comerciales, observaciones y notas importantes para reparto.
- Listado móvil paginado y búsqueda para jornadas de cien clientes o más; mapa secundario con consulta acotada.
- Ficha central con saldo, deuda, pagos, movimientos, entregas, antecedentes y observaciones/incidentes ya registrados en las visitas.
- Cargo por venta directa durante reparto, cobro dentro de esa misma entrega o cobro independiente, referencia cruzada cliente/reparto y resumen de cierre.
- Ajuste administrativo de deuda con comentario, trazabilidad, idempotencia y corrección compensatoria.

El límite con la card 14 es explícito: se implementa el **cargo de la venta directa que ya nace de una entrega real**. No se construyen pedidos, reservas, preparación de pedidos, ventas de mostrador ni su ciclo completo de estados. Una futura venta de M14 utilizará la misma Action de registro contable; no volverá a cargar una entrega ya contabilizada. Hasta existir una entidad de venta independiente, la ficha mostrará el origen como «Venta en reparto» y enlazará la entrega real.

Tampoco se incorporan facturación fiscal, intereses, límites de crédito, tarifas por cliente, cuotas, aplicación FIFO de pagos a facturas, rutas obligatorias, asignaciones nuevas de clientes a repartidores, notificaciones, importación masiva o un módulo nuevo de incidentes. Las condiciones comerciales se conservan como texto informativo mientras no haya reglas acordadas para automatizarlas.

## 4. Reglas de negocio propuestas

Estas decisiones completan lo que las cards no detallan. Son propuestas del plan, no comportamiento actual.

### 4.1 Identidad, estado y ubicación del cliente

1. Toda ficha tendrá ID interno y ULID público estable, independiente de RUT, cédula o nombre. Nombre, razón social y documento podrán faltar. La etiqueta visible tomará nombre comercial, nombre o razón social disponible y, en último caso, «Cliente · ID».
2. Documento: tipo y número opcionales, coherentes entre sí cuando se informen. Sin unicidad por nombre ni exigencia de datos fiscales para cobrar o consultar una cuenta. La regla de duplicados de documento queda pendiente de definición funcional.
3. Contacto y datos comerciales serán opcionales. No habrá una relación ni una tabla compartida con proveedores.
4. Estados iniciales: activo/inactivo. Se permite desactivar con deuda: no borra la cuenta ni impide consultar y cobrar lo pendiente. Se bloquean nuevas entregas a inactivos; las correcciones administrativas siguen autorizadas y auditadas.
5. Una ficha puede guardarse incompleta. Para nuevas entregas en reparto se requieren dirección legible y coordenadas válidas, en pareja. Los clientes incompletos siguen visibles en administración y pueden recibir pagos; no se descartan del listado por no tener marcador.
6. Departamento/localidad se relacionan con Geografía cuando estén disponibles; su ausencia no invalida por sí sola una dirección y un punto válidos. Validar rangos y el criterio territorial ya usado por GAM en el servidor.
7. La frecuencia de visita se registra inicialmente como texto orientativo, sin generar turnos, rutas ni fechas automáticas. Las notas de reparto se distinguen de las observaciones administrativas y se exponen según el permiso de lectura.

### 4.2 Una sola cuenta y una sola regla de saldo

Cada cliente tiene una cuenta global en UYU, independiente de la UP. Cada movimiento conserva su origen, incluido reparto, entrega y UP cuando correspondan. No se divide la deuda por unidad productiva.

```text
saldo nuevo = saldo anterior + cargos - pagos + ajustes firmados + compensaciones
deuda = máximo(saldo, 0)
saldo a favor = máximo(-saldo, 0)
```

Saldo positivo significa que el cliente debe; saldo cero significa cuenta al día. **Confirmado por el usuario el 29/10/2026:** se permite saldo negativo como saldo a favor, conservando el importe íntegro de un pago o ajuste. No se recorta a cero ni se pierde el excedente.

Importes API como cadenas decimales, moneda fija UYU y persistencia `DECIMAL(22,3)` —ajuste al esquema real de Repartos para conservar su rango—. Importes de pagos y ajustes mayores a cero, con hasta tres decimales y dentro del rango permitido; cálculos con Brick Money y contexto de escala 3. Nunca `float` ni redondeo a centésimos. Los precios unitarios de entregas siguen siendo pesos enteros y las cantidades siguen la regla vigente de equivalencia a huevos enteros.

El saldo es una proyección actualizada por la misma Action que anexa el movimiento, con posibilidad de comprobarlo contra la suma del historial. No existe un `PATCH saldo` ni un saldo editable en el formulario del cliente.

### 4.3 Separación de mercadería y pagos

- Una entrega confirmada genera un cargo por su valor exacto. Si se cobra algo, se registra otro movimiento de pago dentro de la misma transacción. Una venta totalmente pagada conserva ambos movimientos, aunque el efecto neto sea cero.
- Cobrado cero es una decisión explícita en la nueva interfaz, etiquetada «Fiado»; la ausencia del campo en comandos viejos no equivale a fiado.
- Una visita puede cobrar deuda sin entregar huevos. Ese cobro no consume stock, no exige líneas de mercadería y no se registra como entrega fallida.
- Se permiten varios cobros de un cliente en el mismo reparto, incluso después de su entrega. No se modifica para ello la unicidad de `delivery_stops` ni se reabre una entrega confirmada.
- El saldo anterior o resultante mostrado en una operación es un snapshot histórico. El saldo vigente de la ficha se consulta aparte; no se recalcula el snapshot cuando alguien cobra después.
- No se supone que el valor entregado es igual a lo cobrado, ni que un pago cancela exclusivamente la compra de esa visita. El cobro reduce la cuenta global.

### 4.4 Ajuste manual de deuda solicitado

Desde la cuenta del cliente, el administrador abre **Ajustar deuda** y selecciona **Aumentar deuda** o **Disminuir deuda**, indica importe positivo y escribe un motivo obligatorio, recortado, de 1 a 2000 caracteres. La pantalla muestra saldo actual, operación y saldo previsto; confirmar crea un movimiento nuevo.

Ejemplo: deuda de $1.000, ajuste «Disminuir deuda», $150, motivo «Corrección de saldo inicial acordada con el cliente». El historial conserva saldo anterior $1.000, variación −$150, saldo posterior $850, administrador, fecha y motivo.

- El ajuste no se clasifica como pago ni como venta y no aumenta el dinero cobrado del reparto.
- Sólo administrador activo, incluso si otro usuario obtiene por error un permiso financiero general. La Action vuelve a comprobarlo para invocaciones internas.
- Se envía la versión de cuenta utilizada por la vista previa. Si cambió, se devuelve `409`, se actualiza la vista previa y se exige una nueva confirmación; no se aplica sobre una expectativa que ya quedó desactualizada.
- El mismo comando reintentado devuelve su operación original, aunque la versión actual haya avanzado. La idempotencia se resuelve antes del chequeo de versión mutable.
- El motivo se guarda con el movimiento y en su auditoría transaccional. No es un comentario descartable del frontend.
- Un ajuste confirmado no se edita ni se elimina. Si fue incorrecto, un administrador registra una reversión vinculada, con motivo obligatorio. La reversión exacta no puede repetirse.
- La carga de una deuda anterior podrá usar este mismo flujo, con motivo explícito; no requiere un campo de saldo inicial que eluda el historial.

## 5. Diseño técnico y persistencia

### 5.1 Propiedad y estructura

Backend bajo la capacidad `CustomersAndAccounts`, siguiendo las carpetas que ya existen:

```text
app/Models/CustomersAndAccounts/
app/Enums/CustomersAndAccounts/
app/Actions/CustomersAndAccounts/
app/Queries/CustomersAndAccounts/
app/Http/Controllers/CustomersAndAccounts/
app/Http/Requests/CustomersAndAccounts/
app/Http/Resources/CustomersAndAccounts/
app/Policies/CustomersAndAccounts/
```

Crear sólo las clases necesarias. Flujo `FormRequest → Controller → Action/Query → Eloquent → Resource`, sin `app/Modules`, repositories genéricos ni capas DDD. Las validaciones dependientes del estado y la autorización crítica permanecen también en las Actions.

Frontend como un feature `features/customers/`, con `pages`, `components`, `services`, `interfaces`, `types` y rutas lazy. Cuenta corriente pertenece a ese feature y se accede desde la ficha; no se añade otra aplicación ni otro shell. Las llamadas siguen `FeatureService → ApiClient`; autenticación e interceptores actuales se conservan.

### 5.2 Modelo mínimo

| Entidad/cambio | Datos y restricciones principales |
| --- | --- |
| `customers` | ID interno, ULID público único, datos generales/fiscales opcionales, contacto, localidad opcional, dirección, coordenadas opcionales en pareja, notas, condiciones, frecuencia, estado, creador y timestamps. Índices por estado/orden y los necesarios para búsqueda y mapa. |
| `customer_accounts` | FK única a cliente, saldo `DECIMAL(22,3)`, versión entera y timestamps. Se crea en la transacción de alta del cliente para tener una fila estable sobre la que bloquear. Moneda UYU fija. |
| `customer_account_movements` | Identidad pública, cuenta, tipo, importe firmado, saldo anterior/posterior, secuencia de cuenta, actor y snapshot permitido, motivo/observación, fecha efectiva y fecha de registro, `operation_id`, reparto y entrega opcionales, origen estable, reversión opcional, clave/hash de idempotencia. |
| `delivery_stops` | FK nullable a cliente para preservar filas viejas, snapshots actuales, estado explícito de contabilización para distinguir legado sin conciliar de entregas nuevas contabilizadas. |

Tipos mínimos de movimiento: cargo por venta en reparto, pago, ajuste de aumento, ajuste de disminución y reversión. El propio movimiento de pago guarda medio y referencia si se acuerdan; no hace falta otra tabla de pagos mientras no existan reglas de aplicación parcial a facturas o un ciclo propio de pagos.

Restricciones en PostgreSQL: FK con conservación de referencias financieras, ULID único, cuenta única por cliente, secuencia única por cuenta, signo válido según tipo, importe no nulo/no cero, moneda válida, motivo obligatorio para ajustes/reversiones y una única reversión de cada movimiento. `balance_after = balance_before + amount` se protege también donde corresponda. Validar rango tanto del movimiento como del saldo acumulado.

Un origen de cargo de entrega es único por `delivery_stop` y tipo de cargo. Un pago tiene identidad de operación propia: **no** se hace único por cliente/reparto. La combinación operación/tipo/origen permite cargo y pago en una misma operación sin duplicarlos. Los movimientos no se borran en cascada al corregir una ficha o un reparto.

Conservar la unicidad histórica por referencia y añadir la protección por reparto/cliente real para las filas vinculadas. Una correspondencia explícita con una referencia antigua no debe permitir una segunda entrega al mismo cliente usando su ULID nuevo.

Orden de escritura: tomar los locks de negocio en orden consistente —reparto cuando corresponda, cliente/cuenta y referencias necesarias—, revalidar estado, resolver el comando, anexar movimiento(s), actualizar proyección y versión, auditar y confirmar. Las rutas de cobro, entrega, ajuste y reversión deben compartir el mismo orden para evitar bloqueos cruzados. Los reintentos autorizados se reconocen antes de invalidarse por estados cambiados.

Actions previstas: guardar cliente, cambiar estado, registrar cargo desde entrega, registrar pago, ajustar cuenta y revertir movimiento. Cargo/pago/ajuste/reversión reutilizan una única rutina de registro financiero; extraerla sólo con la responsabilidad concreta de validar, bloquear y anexar movimientos. Queries específicas para listado, mapa, detalle, estado de cuenta e historial.

### 5.3 Auditoría e idempotencia

- Auditoría síncrona usando `AuditRecorder` y `AuditEntryData` dentro de la transacción propietaria. Fallar al auditar revierte también el movimiento, el saldo y la entrega si son parte de la misma operación.
- Registrar actor, entidad, evento estable en inglés, `operation_id`, `trace_id`, origen, importes, valores anterior/posterior y motivo. Snapshots allowlist para conservar la identidad histórica sin copiar documentos, teléfonos u otros datos innecesarios.
- Toda escritura crítica acepta `Idempotency-Key` UUID. Se conserva actor/operación, contenido normalizado y resultado; misma clave con otro cliente, importe o motivo da `409`. La entrega puede usar una clave de operación común para sus dos movimientos.
- Para comandos móviles, un `client_event_id` identifica de forma estable el evento local y se vincula 1:1 a la clave del comando. Reintentar o reinstanciar la vista no genera una nueva identidad de cobro.
- Contramovimientos conservan el original y su motivo. No se actualizan auditorías antiguas.

## 6. Contrato API propuesto

Rutas nuevas en español bajo `/api/v1`; campos técnicos estables en inglés, coherentes con el contrato actual de Repartos. Descripciones, mensajes de validación y conflictos en español. Ninguna ruta de esta tabla existe todavía, salvo las de Repartos indicadas como extensión.

| Método y ruta | Responsabilidad |
| --- | --- |
| `GET /clientes` | Listado paginado con búsqueda, estado y situación de cuenta, orden estable y resumen mínimo. |
| `POST /clientes` | Alta de ficha y cuenta en cero, atómica e idempotente. |
| `GET /clientes/mapa` | Marcadores por bounding box, búsqueda, estado y estado de visita contextual al reparto. Declarar antes de `/{cliente}`. |
| `GET /clientes/{cliente}` | Ficha y resúmenes autorizados; historiales separados para no descargar todo. |
| `PATCH /clientes/{cliente}` | Editar datos, nunca saldo ni historial. |
| `PATCH /clientes/{cliente}/estado` | Activar/desactivar preservando cuenta y referencias. |
| `GET /clientes/{cliente}/cuenta-corriente` | Saldo actual, deuda, saldo a favor, moneda y versión. |
| `GET /clientes/{cliente}/movimientos` | Historial paginado por período/tipo, con saldos de apertura y cierre del período completos. |
| `GET /clientes/{cliente}/entregas` | Historial real de reparto, últimas entregas, mercadería, observaciones y referencias financieras. |
| `POST /clientes/{cliente}/pagos` | Cobro administrativo independiente de una entrega. |
| `POST /clientes/{cliente}/ajustes` | Aumentar/disminuir deuda, motivo obligatorio y versión esperada. |
| `POST /clientes/{cliente}/movimientos/{movimiento}/reversion` | Corrección compensatoria autorizada de pagos/ajustes; valida pertenencia y motivo. |
| `GET /repartos/clientes` — existente | Sustituir demo por la proyección real y añadir paginación/metadatos; mantener `data`. |
| `POST /repartos/{reparto}/entregas` — existente | Entrega con cargo y cobro opcional explícito en una transacción; visita sin entrega conserva su comportamiento. |
| `POST /repartos/{reparto}/cobros` | Pago sin mercadería o adicional a una entrega, con reparto propio activo. No modifica `delivery_stops` ni stock. |
| `GET /repartos/{reparto}` y cierre — existentes | Añadir cobros, efectos sobre cuenta y resumen financiero, preservando campos anteriores. |

Paginación inicial: 50 por página, máximo 100; filtros acotados y desconocidos rechazados. Búsqueda literal sin distinguir mayúsculas, con desempate por ID. Los saldos y resúmenes se calculan en servidor; no mediante N+1 por ficha ni sumando sólo la página visible. Ajustar índices con evidencia de consultas, sin incorporar un motor externo de búsqueda.

Mapa: bounding box obligatoria, límite máximo inicial 200 y lectura de un elemento extra para `has_more`. Retornar ID, etiqueta corta, dirección breve, punto y estado de visita; no documentos ni historia financiera. Sin asignaciones implementadas, el contexto de visita se deriva de operaciones reales del reparto; no se inventa una relación cliente/repartidor. UI con debounce y descarte de respuestas obsoletas; si hay más resultados, indicar «Acercá el mapa para ver más clientes».

Historial: orden estable por fecha de registro y secuencia/ID; filtros de fechas se interpretan en `America/Montevideo`, timestamps de transporte en UTC. Separar fecha efectiva de registro. Para estado de cuenta de un período, saldo inicial más todos los movimientos filtrados por fechas da saldo final; filtros de tipo sólo cambian el detalle y no redefinen silenciosamente esos saldos.

Ejemplo de ajuste propuesto:

```http
POST /api/v1/clientes/{cliente}/ajustes
Idempotency-Key: <UUID>
Content-Type: application/json

{
  "direction": "decrease",
  "amount": "150.000",
  "reason": "Corrección de saldo inicial acordada con el cliente",
  "expected_version": 7
}
```

Respuesta: movimiento con ID, tipo, moneda, variación, saldo anterior/posterior, motivo, actor permitido, fecha y versión resultante. No exponer hashes internos ni permitir que el consumidor elija actor, saldo resultante u origen arbitrario. `401/403` para identidad/permisos, `404` para recurso, `409` para conflicto de estado/versión/idempotencia y `422` para entrada inválida, usando la representación de errores vigente de GAM.

Para los nuevos comandos de entrega se propone `accounting_version: 2`, `client_event_id` y un bloque `payment` obligatorio cuando `status` sea `delivered`: `{"amount":"0.000"}` expresa fiado y un importe positivo expresa lo efectivamente cobrado. Cero no crea un movimiento de pago. Las visitas `not_delivered` no llevan ese bloque; los pagos sin mercadería usan `/cobros`. El importe de la mercadería y el saldo resultante siempre los calcula el servidor.

En el endpoint operativo existente, `client_reference` pasa a contener el ULID público del cliente real. En `/repartos/{reparto}/cobros`, enviar esa misma referencia, `amount`, `client_event_id` y observación/medio/referencia según el contrato acordado. Las rutas administrativas resuelven `{cliente}` por su ULID; los IDs internos y el actor se resuelven en Laravel. El reparto viene de la ruta operativa y no puede elegirse libremente desde un pago administrativo.

No se ofrece reversión genérica de un cargo de mercadería: una devolución/anulación de venta requiere también sus reglas de inventario y pertenece a su operación de origen. Si sólo se corrige deuda por un acuerdo, se usa un ajuste explícito, sin alterar la entrega original.

## 7. Autorización

Registrar permisos nuevos en `IdentityPermissionSeeder`, manteniendo la sincronización de admin con el catálogo completo. Nombres propuestos: `customers.view`, `customers.manage`, `customer-accounts.view`, `customer-payments.record`, `customer-accounts.adjust` y `customer-accounts.reverse`.

| Actor | Lectura y escritura |
| --- | --- |
| Administrador activo | Gestionar fichas, consultar cuentas, registrar pagos, ajustar y revertir pagos/ajustes con motivo. |
| Repartidor | Proyección operativa de clientes, saldo y última entrega necesarios para visitar; cobros/entregas sólo dentro de su reparto activo. No obtiene edición de ficha ni ajuste/reversión administrativa. |
| Empleado/otro usuario | Ningún acceso nuevo automático. Sólo permisos explícitos para funciones que se habiliten; ajustes/reversiones conservan además la restricción administrativa. |
| Sin sesión, sesión revocada o dispositivo inválido | Sin acceso. El modo local y el cache no autorizan operaciones. |

Los endpoints administrativos y operativos exponen Resources diferentes cuando cambie la información permitida. No otorgar `customers.manage` al rol delivery para hacer funcionar su buscador. Tampoco conceder acceso a todas las cuentas por tener `delivery.view-own`.

Las comprobaciones del propietario del reparto y del usuario activo permanecen en Laravel, con sesión personal o compartida según el transporte existente. Guards y botones del frontend reflejan esos permisos; no los sustituyen. No crear permisos por cliente ni por UP.

## 8. Integración completa con Repartos y offline

### 8.1 Entrega, pago y cierre

1. `RecordDeliveryStopAction` resuelve el cliente real, conserva snapshot y usa `DeliveryUnitBalance` y `DeliveryPricing` tal como lo hace hoy.
2. En su misma transacción invoca el registro de cargo y, cuando haya pago, el registro de pago. Un error no deja entrega sin cuenta ni cuenta sin entrega. No vuelve a descontar inventario: la mercadería ya salió mediante la carga del reparto.
3. La interfaz pide cuánto se cobró o una decisión explícita de fiado; muestra mercadería, cobro y efecto sobre cuenta por separado. El backend determina el saldo real, aunque se envíe una vista previa.
4. «Sólo cobrar deuda» y «Registrar otro pago» usan el endpoint de cobros. Las entradas se vinculan al reparto y opcionalmente a una entrega del mismo cliente/reparto, validada en servidor.
5. El historial del repartidor y el administrativo incluyen cobros sin entrega. La lista distingue entrega pendiente, visitado y pago registrado; un cobro no marca mercadería como entregada ni impide una entrega posterior. Adaptar el actual `statusFor` y los contadores, sin inventar paradas de reparto asignadas.
6. Cerrar mantiene PIN, descarga de todos los sobrantes y sincronización previa. Añade resumen por cliente con valor entregado, cobrado, variación de cuenta y resumen general tipo mini factura. Es un resumen operativo, no un documento fiscal.
7. El cierre agrega movimientos ya confirmados; no los contabiliza otra vez. Los ajustes administrativos generales no se suman al dinero cobrado del repartidor. El comentario de diferencias no cambia automáticamente cuentas.
8. El resumen cerrado usa las operaciones del reparto y sus snapshots, nunca el saldo actual del cliente. Una reversión posterior muestra original, compensación y total neto, sin reescribir el historial del cierre. Abrir/editar repartos cerrados con cambios de mercadería requiere el flujo compensatorio de M18; no se implementa por un `PATCH` financiero genérico.

### 8.2 Persistencia y reintentos

- Ampliar el outbox actual para el comando de cobro y los nuevos datos de entrega. Conservar claves al reintentar, orden de operaciones y recuperación tras reiniciar la app.
- Guardar versión de payload e identidad propietaria de la entrada. Filtrar al leer/enviar y verificar el actor contra la sesión actual; no mezclar operaciones entre personas que usan la tablet. Las entradas antiguas sin propietario requieren resolución explícita a partir de su reparto y actor, no se atribuyen automáticamente a quien inicia sesión.
- Para eventos financieros esperar `IDBTransaction.oncomplete`. Si IndexedDB falla o no está disponible, no declarar un cobro guardado: no alcanza con el fallback en memoria. Tampoco informar éxito si falló el commit local. La misma protección cubre entrega más pago.
- Distinguir «guardado en este dispositivo, pendiente de sincronización» de «confirmado en servidor». El saldo cacheado lleva antigüedad y los importes pendientes no se presentan como deuda confirmada.
- Una respuesta perdida después del commit se resuelve por replay; nunca exige ingresar de nuevo el dinero. Reintentar no puede duplicar cargo, pago ni auditoría, tampoco desde dos conexiones concurrentes.
- Errores permanentes `403/409/422` conservan el evento y ofrecen revisión; no se reintentan sin fin ni se descartan. Bloquear el cierre mientras haya operaciones de negocio sin resolver.
- Los ajustes administrativos se confirman en línea; no se habilita una cola de ajustes offline. Un fallo de red con resultado incierto mantiene la clave y el contenido originales para consultar/reintentar, como los ajustes de stock actuales.

## 9. Experiencia visual y reutilización

### 9.1 Referencias concretas

| Referencia del frontend | Qué reutilizar |
| --- | --- |
| `features/admin/admin-navigation.ts`, `admin.routes.ts` | Grupo global Clientes, shell, título de sección, navegación y protección. Reemplazar placeholders sin duplicar menús. |
| `features/suppliers-catalogs/suppliers/suppliers-list.page.*` y `components/supplier-form` | Jerarquía del encabezado, CTA, búsqueda, tarjetas, paginación, formulario y estados de carga/error/vacío; reutilizar patrones visuales, no la entidad Proveedor. |
| `shared/ui/filter-dialog` y `searchable-select` | Filtros móviles y selectores existentes, con foco y retorno al disparador. El dominio de clientes queda en el feature. |
| `features/production-units/components/feed-stock-adjustment` | Presentación de operación, importe/cantidad, vista previa antes/después, motivo, bloqueo durante envío y conservación del reintento. No importar la lógica de stock en cuenta corriente. |
| `features/inventory/pages/egg-presentations` | Separación de estado de formularios y diálogos, feedback y tokens. No copiar sus reglas de bloqueo por reparto a la ficha del cliente. |
| `features/production-units/components/production-unit-location-picker` | Patrón de búsqueda, selección y confirmación de punto. Extraer sólo la parte visual neutral si se comparte con Clientes; mantener validación y consultas de UP fuera del componente compartido. |
| `features/deliveries/delivery.page.*` | Lista principal, hoja de cliente, navegación y mapa secundario actuales. Añadir cuenta/cobro en ese flujo. |
| `docs/design-colors.md` y `src/theme/variables.scss` | Todos los colores y estados en ambos temas, sin paleta local nueva. |

### 9.2 Pantallas y acciones

- **Listado de clientes:** encabezado «Clientes», «Nuevo cliente», búsqueda por identidad/contacto/dirección, filtros de estado y situación de cuenta, tarjetas o filas legibles con etiqueta, dirección breve y deuda autorizada. Paginación real. No precargar todas las fichas para filtrar en el teléfono.
- **Crear/editar:** formulario por secciones «Datos», «Contacto y ubicación», «Condiciones y notas». Mantener el acceso existente de Nuevo cliente y usar un mismo componente de formulario en alta/edición. Campos opcionales identificados; el mapa no obliga a completar datos fiscales ni bloquea guardar una ficha incompleta.
- **Detalle:** encabezado con identidad estable y estado; resumen de deuda/saldo a favor; secciones navegables de «Cuenta corriente», «Entregas» y «Datos y observaciones». La cuenta muestra los cargos por venta directa y sus enlaces. Cargar historiales por demanda, con paginación propia y filtros conservados al volver.
- **Cuenta corriente:** lista cronológica con tipo legible, cargo/pago/ajuste, importe, saldo histórico, motivo, responsable y origen. Acciones «Registrar pago» y «Ajustar deuda» según autorización. Mostrar deuda de manera textual, no sólo mediante color.
- **Ajuste:** diálogo con dos opciones inequívocas, importe UYU, motivo obligatorio y preview. Un solo submit «Aplicar ajuste», errores de campo y reintento con la misma operación. No ofrecer «Establecer saldo» como tercera operación no pedida.
- **Pago:** importe, medio/referencia si se acuerdan y observación. Preview del efecto sobre cuenta. Repartidor puede elegir sólo cobro sin entrar a cargar mercadería.
- **Reparto:** al abrir cliente, saldo confirmado con fecha, notas importantes y última entrega resumida con detalle expandible. Mantener entrega/no entrega; sumar cobro independiente. Si el mapa o los tiles fallan, lista, cobro y entrega continúan operables.
- **Cierre y consulta administrativa:** mercadería y pagos separados, detalle por cliente y resumen total, con enlaces a movimientos. No rotular «Dinero en mano» un total que incluye transferencias o medios no definidos.

Usar controles de al menos 44 px, `ion-content` como scroll principal donde corresponda y el espaciado inferior ya proporcionado por el shell. Formularios con labels, foco visible, Escape/cierre y devolución de foco, mensajes accesibles, safe areas y teclado móvil. Textos en español con el mismo tono directo de GAM.

Importes se muestran sin perder el tercer decimal y sin convertir el saldo a `number`. Se puede extraer el formateo preciso de UYU de `delivery-money.ts` a una utilidad neutral usada por ambos features, manteniendo sus salidas actuales y añadiendo saldos con signo. No se agrega una librería monetaria de frontend.

## 10. Datos existentes y activación

1. Migraciones aditivas; no editar las originales de Repartos ni reiniciar la base. Al agregar la FK de cliente a entregas, conservar filas sin correspondencia y todos sus snapshots.
2. Las entregas anteriores tienen valor de mercadería pero **no evidencia de cuánto fue cobrado**. Se marcan como legado pendiente de conciliación y no crean deuda automáticamente. Tampoco se interpreta un importe histórico nulo como cero.
3. Identificar clientes históricos sólo mediante una correspondencia explícita. No vincular por parecido de nombre, dirección o documento incompleto. Los `demo-*` no se convierten en clientes reales mediante heurísticas.
4. Antes de activar la nueva contabilización, inventariar repartos activos y colas pendientes. Replays de operaciones viejas ya confirmadas devuelven la operación vieja sin volver a contabilizarla.
5. Un comando viejo no confirmado que no incluye cuánto se cobró no se transforma silenciosamente en venta fiada: conservarlo y exigir conciliación/revisión explícita antes de generar deuda. Versionar el contrato y ofrecer resolución en la app; un despliegue no elimina operaciones pendientes.
6. Los nuevos comandos activados deben ser contabilizables de forma completa. Sólo el legado identificado queda sin conciliar; no se deja un adaptador provisional para nuevas entregas una vez finalizada esta entrega.
7. Clientes demo mediante seeder idempotente y modelos reales, con guarda propia `local/testing`. El seeder general actual también puede ejecutarse en una demo `production` mediante flag: no heredar accidentalmente esa habilitación para el proveedor transitorio prohibido por M13. Definir aparte la política de datos demo persistentes si esa instalación lo requiere.
8. Separar de `LocalDeliveryClientCatalog` la resolución pendiente de vehículo; no borrar `TODO(M16)` ni habilitar su fallback en producción. M13/M15 pueden completarse sin un CRUD de vehículos, pero no se declara listo el inicio productivo del reparto si todavía depende de esa referencia demo.
9. Documentar conciliación de saldos anteriores y conservar el movimiento de apertura/ajuste con motivo si se incorpora deuda real. Una importación masiva requiere otro alcance y una fuente verificable.

## 11. Secuencia de implementación

Cada fase deja una parte revisable del mismo módulo. No mover las cards a `Do tests` por completar sólo la ficha o sólo la API.

| Fase | Trabajo | Criterio de salida |
| --- | --- | --- |
| 0. Cerrar reglas y contrato | Resolver decisiones del apartado 13, escribir OpenAPI conjunto, matriz de permisos, formatos monetarios y política de legado. Verificar nuevamente los cambios concurrentes del workspace. | Ejemplos acordados de entrega fiada, cobro sin entrega, ajuste con motivo, exceso de pago y reintento viejo. Ningún supuesto financiero oculto. |
| 1. Ficha persistente | Migraciones, enums, modelos/factories, alta/edición/estado, queries, Resources, permisos, auditoría y datos locales. Crear cuenta en cero con cliente. | Cliente con datos fiscales incompletos se crea/edita, pagina y desactiva sin perder historia; no se toca Proveedores. |
| 2. Cuenta corriente | Registro único de movimientos, saldo/versiones, cargos, pagos, ajustes y reversiones con transacciones, locks e idempotencia. | Precisión exacta, mismo comando sin duplicados, auditoría atómica y concurrencia sin pérdida de saldo. |
| 3. Cliente real en reparto | Sustituir catálogo demo, identidad y snapshots, lista paginada, endpoint de mapa, notas y última entrega. | Más de cien clientes navegables; mapa acotado; sin mapa se mantiene la lista y la selección. Vehículo pendiente sigue aislado. |
| 4. Integración financiera del reparto | Entrega más cargo/pago, cobro independiente, proyecciones e historial por cliente/reparto, resumen de cierre. | Una sola transacción por operación; sólo cobro mueve dinero, no huevos; pagos posteriores permitidos; cerrar no duplica cargos. |
| 5. UI administrativa | Listado, formulario, ficha, cuenta, diálogos de pago/ajuste/reversión, rutas y guards. | Recorrido UI → API → movimiento → saldo → historial visible; ajustes restringidos a admin y motivo visible. |
| 6. UI móvil y offline | Formularios de cobro, indicadores de saldo, outbox durable/versionado/por actor, resolución de legado y errores. | Cobro sobrevive reinicio, sólo el dueño lo envía, replay no duplica y un fallo de almacenamiento no muestra éxito. |
| 7. Validación y documentación | Regresiones, pruebas de concurrencia, recorridos en navegador/dispositivo y actualización de guías/contratos. | Todos los criterios del apartado 12 con evidencia y limitaciones explícitas; entonces ambas cards pueden pasar a `Do tests`. |

## 12. Validación de aceptación

### 12.1 Casos financieros mínimos

| Escenario | Resultado esperado |
| --- | --- |
| Cliente nuevo sin documento ni nombre | Identidad interna válida, etiqueta alternativa, cuenta cero y ficha accesible. |
| Venta en reparto $1.000, cobrado $0 | Cargo +1.000, pago ausente, deuda aumenta $1.000 y queda fiado visible. |
| Venta $1.000, cobrado $600 | Cargo +1.000 y pago −600, variación +400; la ficha y el reparto coinciden. |
| Deuda anterior $400, entrega $100, cobrado $500 | Dos movimientos, saldo final cero; mercadería $100 y cobrado $500 se muestran separados. |
| Deuda $400, sólo cobro $150 | Pago −150, deuda $250; cero cambios de huevos y sin marcar entrega fallida. |
| Segundo pago del mismo cliente/reparto | Nuevo movimiento válido; no choca con la unicidad de entregas. |
| Aumento de deuda $50 con motivo | Saldo aumenta exactamente $50; historial y auditoría conservan actor y comentario. |
| Disminución de deuda $75 con motivo | Saldo disminuye exactamente $75; no se computa como cobro. |
| Ajuste cero, motivo vacío o whitespace | `422`, sin movimiento, auditoría ni cambio de saldo. |
| Otra persona intenta un ajuste | `403` tanto por HTTP como por invocación de Action. |
| Cambió la cuenta después del preview | `409`, sin ajuste; se muestra saldo actualizado antes de confirmar otra operación. |
| Reintento del ajuste ya confirmado | Devuelve el resultado original aunque cambie la versión; un solo movimiento/auditoría. |
| Reversión de pago/ajuste | Compensación exacta con motivo y vínculo; segundo intento no duplica. |
| Cliente inactivo con deuda | Cobro permitido con autorización; nueva entrega bloqueada. |
| Cantidad `0.125` a $101 por unidad | Cargo `12.625`, sin redondeo; usar equivalencia compatible, por ejemplo 24 huevos/unidad → 3 huevos. |
| Reparto anterior a la integración | Se conserva historial; ningún cargo de deuda creado por inferir cobro cero. |
| Pago que supera deuda | Resultado según política ratificada de saldo a favor; jamás pérdida silenciosa del excedente. |

### 12.2 Pruebas automatizadas y regresiones

- Backend: feature tests de ficha, cuenta, contrato OpenAPI, matriz de autorización personal/compartida, filtros y paginación, idempotencia y rollback por fallo de auditoría. Probar varias cuentas/clientes y referencias cruzadas inválidas.
- Concurrencia con PostgreSQL real y procesos separados: dos pagos distintos al mismo cliente, mismo pago reintentado simultáneamente y pago concurrente con ajuste/versionado. Comprobar suma, secuencia, saldo, auditoría y ausencia de duplicados. Un test secuencial no acredita esta condición.
- Regresión de `tests/Feature/Deliveries/DeliveryEndpointTest.php`: PIN, reparto propio, cargas, precios históricos, cantidades fraccionarias, retornos, visitas sin entrega e idempotencia. El reporte de mercadería conserva su significado y totales.
- Frontend: servicios usando `ApiClient`, payloads precisos y sin campos de saldo autoritativo, formularios opcionales, motivo requerido, señales de carga/error, diálogo y retorno de foco, versión obsoleta, pantalla de cuenta y vínculos cliente/reparto.
- Outbox con IndexedDB: commit abortado, indisponibilidad de almacenamiento, recarga/reinicio, cambio de actor, respuesta HTTP perdida, pago adicional y cola vieja. Usar un almacenamiento con transacciones en la prueba: un mock que siempre resuelve no prueba durabilidad.
- Listado/mapa: al menos 150 clientes, navegación más allá de los primeros 50, viewport/límite/`has_more`, respuestas fuera de orden, datos incompletos y falla del mapa sin bloquear lista/cobros.

Ejecutar PHPUnit en Docker con la receta del README, sobre `<DB_DATABASE>_testing`; nunca sobre la base local de trabajo. Primero suites nuevas focalizadas y regresiones de Repartos/Identidad/Reportes afectadas; la concurrencia se valida de forma separada. Aplicar Pint a los PHP modificados y análisis estático con la configuración vigente. Verificar rutas reales y contratos, no sólo YAML.

En el frontend ejecutar dentro de Docker `npm run lint`, `npm run test:ci` y `npm run build`. No instalar paquetes ni ejecutar herramientas npm en el host. `cap sync` sólo si cambian plugins/configuración nativa; este alcance no necesita un plugin nuevo.

### 12.3 Recorrido funcional pendiente

Validar en navegador contra API real: crear cliente incompleto, completar ubicación, entregar fiado, cobrar parcialmente, registrar pago sin mercadería, ajustar deuda en ambas direcciones con motivo, consultar ambos historiales, revertir un error y cerrar reparto. Comprobar persistencia después de recargar y resultado visible en los dos lados.

Revisar teléfono de 320/360 px, tablet y escritorio, temas claro/oscuro, teclado y foco. En Android 12+ o la tablet compartida, comprobar pérdida de red y reapertura real durante un cobro, cambio de identidad y rechazo de ajustes desde el repartidor. Registrar explícitamente qué plataforma no se pudo probar; Vitest no sustituye el recorrido de dispositivo.

Finalizada la implementación: mantener este documento como referencia de decisiones o convertirlo en guía de estado real, actualizar el índice de implementaciones, documentar contrato conjunto `customers-and-accounts.yaml`, ampliar `deliveries.yaml`, incorporarlo al selector Swagger y registrar evidencia en las dos cards. Sólo cambiar su estado cuando corresponda al alcance completo; `Done` requiere aceptación funcional, no sólo pruebas automáticas.

## 13. Decisiones a ratificar antes de las fases financieras

No impiden revisar el plan, pero deben cerrarse antes de fijar comportamiento de negocio:

1. **Saldo a favor: confirmado el 29/10/2026.** Se admite por sobrepago o disminución mayor a la deuda y se identifica por separado.
2. **Medios de pago: confirmados el 29/10/2026.** Efectivo, transferencia y otro, con referencia opcional. El cierre discrimina los medios y sólo llama efectivo al subtotal correspondiente.
3. **Deuda anterior y fuente antigua:** definir si habrá saldos reales que trasladar, fuente/correspondencia verificable y fecha de inicio contable. Revisar `CuentaCorriente`/`Movimiento` si se aporta el proyecto anterior. No inferir saldos desde entregas actuales.
4. **Datos fiscales duplicados y condiciones comerciales:** confirmar si un documento informado debe ser único y si las condiciones siguen siendo notas o necesitan reglas estructuradas. El plan base no obliga documentos ni automatiza crédito.

## 14. Condición de finalización del conjunto

Las cards 13 y 15 estarán implementadas cuando un cliente real tenga una única ficha/cuenta, una entrega fiada y cualquier cobro afecten esa cuenta una sola vez, el administrador pueda aumentar/disminuir deuda con motivo auditable y todo sea visible desde cliente y reparto con los patrones de GAM. El mapa, el legado y el modo offline deben tener un comportamiento explícito y probado; no pueden quedar clientes demo o saldos calculados sólo en el navegador para los nuevos flujos.
