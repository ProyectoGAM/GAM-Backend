# Clientes y cuenta corriente — cards 13 y 15

Implementación conjunta del 29/10/2026. Referencia de alcance y decisiones: [plan unificado](customers-and-accounts-implementation-plan.md). Ambas cards se actualizaron en Notion a `Do tests` con la evidencia y el alcance. Disponible para pruebas funcionales; queda pendiente la aceptación del negocio y el recorrido en un dispositivo Android/iOS real.

## Comportamiento implementado

La ficha persistente admite datos generales y fiscales incompletos, razón social, contacto, dirección, coordenadas opcionales en pareja, localidad, frecuencia, condiciones comerciales y notas para reparto. Cada cliente tiene ULID público y una cuenta global en UYU. Nombre y documento no son obligatorios; no hay relaciones compartidas con Proveedores. Desactivar conserva cuenta, movimientos y visitas, permite cobrar y bloquea nuevas entregas.

Administración dispone de listado paginado con búsqueda y filtros, alta, edición y ficha con cuenta corriente, entregas/visitas y datos. El historial muestra actor, fechas, motivo, medio y referencia del pago, origen en reparto, saldos anterior/posterior y compensaciones. Los filtros de período tienen saldos inicial/final independientes de la página y del filtro de tipo. Los enlaces de origen abren el reparto solicitado, incluso fuera de la primera página del historial administrativo.

El repartidor usa la misma identidad persistente. La lista es la navegación inicial; incluye clientes sin ubicación para cobrar, notas, último reparto y saldo confirmado con fecha de consulta. El catálogo pagina de 50 en 50; la caché conserva únicamente los clientes consultados por esa persona. El mapa consulta hasta 200 marcadores dentro del viewport, con debounce, estados de visita y `has_more`; la lista sigue disponible ante fallos de mapa o tiles. La proyección operativa excluye documentos y notas administrativas.

## Cuenta y precisión

```text
saldo = cargos - pagos + ajustes firmados + compensaciones
deuda = máximo(saldo, 0)
saldo a favor = máximo(-saldo, 0)
```

Se permite saldo a favor por sobrepago o ajuste de disminución. Importes HTTP como cadenas, hasta tres decimales, moneda fija UYU, almacenamiento `numeric(22,3)` y Brick Money con `CustomContext(3)`. No se recorta el excedente ni se redondea a centésimos. Los precios unitarios del reparto siguen siendo pesos enteros; las cantidades fraccionarias deben equivaler a huevos enteros. La presentación usa milésimos con `bigint` y conserva los tres decimales significativos.

Una entrega confirmada contabiliza su valor exacto y, si existe, un pago separado en la misma transacción. «Fiado» exige confirmación explícita de cobro cero. Un cargo de valor cero no genera una fila financiera de importe cero. «Sólo cobro» no crea una entrega fallida ni modifica stock; se pueden registrar varios pagos del mismo cliente y reparto, antes o después de su entrega.

**Ajustar deuda** permite aumentar o disminuir mediante importe positivo y motivo obligatorio de hasta 2000 caracteres. Envía `expected_version`: un cambio concurrente devuelve `409` y exige consultar el saldo y confirmar otra vez. Sólo un administrador activo puede ajustar o revertir, también al invocar directamente una Action. El saldo no es un campo editable del cliente.

Los movimientos no tienen rutas de edición o eliminación. Un pago o ajuste incorrecto se compensa mediante una reversión exacta, vinculada y con motivo obligatorio, una sola vez. La corrección de mercadería no se implementa mediante una reversión financiera genérica.

## Contratos y puntos de entrada

Contratos: [clientes y cuentas](../../contracts/openapi/customers-and-accounts.yaml), [repartos](../../contracts/openapi/deliveries.yaml). El primero se añadió al selector Swagger de desarrollo. Todas las escrituras indicadas requieren una cabecera `Idempotency-Key` UUID nueva por intención de negocio, conservada para sus reintentos.

| Método y ruta bajo `/api/v1` | Función |
| --- | --- |
| `GET/POST /clientes` | Listar/crear fichas; la creación abre una cuenta en cero. |
| `GET /clientes/mapa` | Marcadores acotados por `west/east/south/north`, límite y contexto de visita. |
| `GET/PATCH /clientes/{cliente}` | Consultar/editar datos generales. |
| `PATCH /clientes/{cliente}/estado` | Activar/desactivar, sin borrar historia. |
| `GET /clientes/{cliente}/entregas` | Visitas y entregas históricas paginadas. |
| `GET /clientes/{cliente}/cuenta-corriente` | Saldo, deuda, crédito, versión y fecha de consulta. |
| `GET /clientes/{cliente}/movimientos` | Historial y estado de cuenta por período. |
| `POST /clientes/{cliente}/pagos` | Pago administrativo. |
| `POST /clientes/{cliente}/ajustes` | Aumentar/disminuir deuda con versión y motivo. |
| `POST /clientes/{cliente}/movimientos/{movimiento}/reversion` | Compensar pago/ajuste. |
| `GET /repartos/clientes` | Proyección operativa paginada y última entrega. |
| `POST /repartos/{reparto}/entregas` | Entrega/visita e integración contable atómica. |
| `POST /repartos/{reparto}/cobros` | Cobro independiente en reparto propio activo. |

Ejemplo de disminución: el saldo consultado es `1000.125`, versión 7.

```json
{"direction":"decrease","amount":"150.125","reason":"Corrección acordada con el cliente","expected_version":7}
```

El movimiento será `-150.125`, con saldo posterior `850.000`; no se clasifica como cobro.

Ejemplo de entrega fiada; `client_event_id` debe coincidir con su `Idempotency-Key`:

```json
{
  "client_reference":"01J00000000000000000000001",
  "client_event_id":"cb6eaf51-a59c-45fe-aae9-1cbe2dca2c9b",
  "accounting_version":2,
  "status":"delivered",
  "items":[{"unit":"maple","amount":"0.5","eggs_per_unit":30,"unit_price":101}],
  "payment":{"amount":"0.000"}
}
```

Ejemplo de cobro independiente de mercadería:

```json
{
  "client_reference":"01J00000000000000000000001",
  "client_event_id":"eb2e0abc-60ac-4758-a05e-d45dcd5e07da",
  "amount":"150.125",
  "payment_method":"transfer",
  "payment_reference":"Referencia opcional"
}
```

Los medios son `cash`, `transfer` y `other`, presentados como Efectivo, Transferencia y Otro. El cobro administrativo utiliza esos mismos campos de pago sin `client_reference` ni `client_event_id`; el cliente pertenece a la ruta.

## Autoridad, transacciones y auditoría

La capacidad `CustomersAndAccounts` usa FormRequests, Controllers, Actions/Queries, Eloquent y Resources. La UI usa rutas lazy, signals, formularios reactivos, servicios de feature y el `ApiClient` existente. Se mantienen los shells, los patrones de Proveedores y los tokens semánticos en ambos temas. No se añadieron dependencias.

`CustomerOperationRunner` identifica actor, clave, contenido canónico y resultado, incluyendo operaciones sin movimientos. El replay autorizado se resuelve antes de validar una versión o un estado que ya pudo cambiar. Misma clave con contenido diferente devuelve `409`.

El orden de locks es usuario, reparto cuando corresponda, cliente y cuenta. `CustomerAccountLedger` valida importes, anexa movimientos y actualiza saldo/versiones. El cargo tiene origen único en la entrega; los pagos mantienen identidad propia. Las restricciones SQL protegen moneda, signos, motivos, secuencia, aritmética del saldo y reversión única. Las FKs conservan referencias financieras. El journal y la proyección se comprueban en las pruebas de concurrencia.

Las Actions registran auditoría síncrona mediante `AuditRecorder` en la misma transacción. Si la auditoría falla se revierte la operación completa, incluida la entrega. Los snapshots financieros conservan nombre del cliente y actor sin copiar documentos, credenciales ni PIN. No se reescribe la auditoría para corregir operaciones.

Permisos registrados centralmente: `customers.view`, `customers.manage`, `customer-accounts.view`, `customer-payments.record`, `customer-accounts.adjust`, `customer-accounts.reverse`. Ajustes/reversiones exigen además rol admin. Repartos conserva sus permisos existentes, reparto propio, sesión válida y PIN de inicio/cierre. Las sesiones compartidas siguen los middleware actuales.

## Offline y compatibilidad

IndexedDB `gam-deliveries` se actualiza de v2 a v3 conservando stores y eventos. Cargas, visitas y cobros se guardan **antes de enviarlos**, esperando `IDBTransaction.oncomplete`. No existe aceptación en memoria para una operación de negocio. Una falla de almacenamiento conserva el formulario y no envía la operación.

Cada evento nuevo guarda actor, versión, orden transaccional, clave, payload y estado. Sólo su propietario puede leerlo, enviarlo o modificarlo. Referencias y saldos cacheados también pertenecen al actor. El importe pendiente se muestra separado del saldo confirmado. Una respuesta incierta mantiene exactamente el mismo evento y clave; un rechazo permanente conserva el payload y requiere revisión antes de continuar el envío ordenado. El cierre se bloquea mientras haya negocio pendiente.

Los eventos v2 sin actor sólo se atribuyen después de verificar en el servidor que su reparto activo pertenece a la sesión. Primero se reproduce el comando original: una entrega antigua ya confirmada retorna su resultado sin crear deuda. Una entrega no confirmada que carece de protocolo contable devuelve `409`; no se infiere fiado. La pantalla de pendientes permite indicar el cliente real, revisar cantidades/precios y decidir el cobro. La sustitución se guarda atómicamente con nueva clave y vínculo local al original, que queda `superseded` sin borrar su payload. El reintento del mismo evento rechazado es una acción explícita. Un cierre v2 conserva el registro hasta el cierre real verificado por PIN.

Las colas antiguas de otros actores o repartos que ya no son el activo no se atribuyen al usuario actual ni se eliminan. Su conciliación requiere verificar ese origen; no se incorporan automáticamente al reparto nuevo. No se borran stores para actualizar la app.

Los ajustes administrativos se confirman en línea. Mientras la sesión y la ejecución de la app continúan, un resultado incierto conserva comando y clave al cerrar/reabrir el diálogo; no se implementó una cola offline administrativa ni persistencia de esos comandos después de recargar toda la app. Después de una recarga debe consultarse el historial antes de formular una nueva corrección.

Las entregas preexistentes quedan `legacy_unreconciled`, sin cargos ni cobros deducidos. No hay asociación automática entre referencias demo, nombres o documentos y clientes reales. `CuentaCorriente`/`Movimiento` del sistema antiguo no estaban disponibles: no se importaron ni se inventaron deudas previas.

## Cierre y resumen

`financial_summary` distingue mercadería, cobrado, variación de cuenta y subtotales por medio; incluye cobros sin mercadería, pagos adicionales y compensaciones. Las cuentas históricas por cliente muestran el saldo de sus operaciones, que puede diferir del saldo vigente por movimientos externos al reparto. Si hay entregas antiguas sin conciliar, el total cobrado desconocido es `null`; `recorded_collected_amount` identifica sólo lo registrado.

Cerrar conserva PIN, descarga total de sobrantes, una UP de destino y sincronización previa. Guarda `closed_financial_summary` sin volver a contabilizar. Una reversión posterior modifica el resumen vigente, conserva el de cierre y aparece en el historial de la cuenta. Administración muestra ambos y enlaza las fichas de clientes. Un pago administrativo general no altera el efectivo cobrado por el repartidor.

## Instalación y datos locales

Aplicar migraciones con el procedimiento habitual y después `IdentityPermissionSeeder` para actualizar el catálogo sin cambiar contraseñas. La migración propia es `2026_10_29_195003_create_customers_and_accounts_tables.php`. No requiere reconstruir repartos antiguos ni ejecutar seeds generales sobre datos reales.

`Database\Seeders\CustomersAndAccounts\CustomersDemoSeeder` crea seis fichas ficticias, una deuda inicial y un saldo a favor mediante las Actions, con claves estables. Se integró en `LocalDemoDataSeeder`; sólo opera en `local/testing`, es idempotente y no se ejecuta en producción.

La base local estaba atrasada respecto del código de Inventario. Para el recorrido real se respaldó previamente y se aplicaron las dependencias existentes de recetas (`2026_10_13_061455`) y tipos de almacenamiento (`2026_10_28_062746`), además de la migración propia. Se ejecutó `inventario:preparar-almacenamiento --actor=1`: clasificación auditada de metadatos, sin regularizar saldos. No se aplicaron otras migraciones ajenas pendientes ni se reseteó la base. Se crearon datos locales de prueba por los flujos reales, sin tocar credenciales de cuentas existentes.

Vehículos sigue pendiente de M16. `LocalDeliveryVehicleReference` conserva exclusivamente el fallback de desarrollo/pruebas y bloquea su uso en producción. Se retiró `LocalDeliveryClientCatalog`; los clientes ya no dependen de datos en memoria. No se declara resuelto el inicio productivo de repartos sin una referencia de vehículo real.

## Evidencia del 29/10/2026

- PHPUnit en PostgreSQL aislado: **95 pruebas / 1282 aserciones** de Clientes/Cuentas, Repartos, Identidad y fuentes de reportes afectadas. Incluye precisión, rollback de auditoría, permisos, cliente incompleto, filtros, 151 clientes, mapa acotado y separación de mercadería/cobros.
- Concurrencia real con procesos PostgreSQL separados: tres casos de pagos distintos, replay simultáneo y pago frente a ajuste versionado. Incluida en el total anterior; también se ejecutó de forma focalizada.
- PHPStan nivel 5 sobre la capacidad y clases de Repartos afectadas: cero errores, con `--memory-limit=512M` y `.phpstan-customers.neon`. Pint sobre PHP modificados/nuevos; rutas explícitas porque el contenedor no incluye Git.
- Frontend en Docker: `npm run lint`, **136 archivos / 1051 pruebas** y `npm run build` aprobados. Los tests de formularios y payloads complementan la comprobación real de IndexedDB; los mocks unitarios no acreditan durabilidad.
- Chrome contra API real: alta sin datos fiscales, edición/ubicación, aumento/disminución con motivo, sobrepago, reversión, entrega fiada, cobros múltiples sin mercadería, tres medios de pago, corrección de evento v2 y cierre con 49 huevos de retorno.
- Caso de cierre real: mercadería `1101.000`, cobrado `1150.375`; efectivo `200.250`, transferencia `250.125`, otro `700.000`. Reversión posterior de `700.000`: cobrado vigente `450.375`, cierre conservado `1150.375`, saldo del cliente `599.750`.
- IndexedDB real: recarga con cobro pendiente conservando clave/importe, aislamiento de actor al leer cola/caché, abort después del éxito de una solicitud antes del commit sin envío HTTP, respuesta HTTP interrumpida después del commit del servidor y replay sin duplicados, original v2 conservado tras revisión. La recarga offline se simuló manteniendo el servidor que entrega la app disponible; no acredita caché de arranque web sin red.
- Lista y cuenta en 320, 360, 768 y 1280 px, temas claro/oscuro, sin desbordamiento horizontal. Recorrido de teclado dentro del diálogo, devolución de foco y fondo inert. Tiles bloqueados: mensaje legible y operación desde lista disponible.
- Selector Swagger y contrato servido verificados por HTTP; contrato servido idéntico al archivo local.

**Límites de validación:** no hay dispositivo conectado a ADB y no se ejecutó Android/iOS real, reapertura nativa, teclado de dispositivo ni expiración/revocación real de sesión compartida durante un cobro. No cambian plugins ni configuración nativa. Las pruebas automáticas de identidad cubren las reglas del servidor; la comprobación de aislamiento en navegador cubre el almacenamiento, no sustituye un recorrido completo de tablet compartida. Quedan aceptación del negocio, origen de deuda histórica y validación de hardware antes de `Done`.

Pedidos, ventas de mostrador, importación antigua, facturación fiscal, intereses, límites de crédito, asignaciones nuevas y reglas automáticas de condiciones comerciales permanecen fuera del alcance conjunto. Los incidentes visibles son motivos y notas de visitas existentes.
