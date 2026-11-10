# Informe de integración de auditoría para frontend

Fecha: 31/10/2026. Rama: historyDetails.
Contrato de referencia: [audit.yaml](../../contracts/openapi/audit.yaml).
Implementación y decisiones: [guía técnica](audit-presentation-implementation.md).

## Qué cambia

Se implementó la opción 2: resumen en el listado y detalle bajo demanda.
El frontend deja de traducir claves arbitrarias o reconstruir nombres mediante
el directorio de usuarios. El historial sigue conservando eventos desconocidos,
paginación y totales calculados por backend.

Todas las rutas siguientes son relativas a /api/v1.

| Consulta | Ruta | Permiso |
| --- | --- | --- |
| Listado con resumen | GET /audit/entries | can_view_audit |
| Detalle de una entrada | GET /auditoria/entradas/{id} | can_view_audit |
| Búsqueda de responsables | GET /auditoria/responsables | can_view_audit |
| Etiquetas de eventos | GET /reference/options | Bloque audit con can_view_audit |

No hay endpoint nuevo de mutación de auditoría. El catálogo no agrega audit.actors:
el selector debe usar la búsqueda paginada de responsables.

## Secuencia de pantalla

1. Cargar eventos desde reference/options.data.audit.events y respetar value/label.
2. Cargar audit/entries con filtros y per_page. Renderizar presentation.title,
   presentation.summary, actor y created_at. No solicitar un detalle por fila.
3. Para el responsable, buscar en auditoria/responsables con buscar, por_pagina y
   page. La respuesta usa data, links y meta, igual que el listado.
4. Al abrir una entrada, solicitar auditoria/entradas/{id}. Mostrar facts, changes,
   history.missing, reversibility.reason y actions.
5. Al elegir una acción disponible, abrir la confirmación/formulario del módulo
   dueño de esa operación. Crear un UUID de idempotencia al confirmar.
6. Tras una mutación exitosa, volver a consultar detalle y listado. Una página o
   un detalle anterior no prueba que la acción siga disponible.

Si se cambia de usuario o sesión, invalidar detalles que contienen disponibilidad
de acciones y facultades de gestión. Evitar caché compartida entre usuarios para
estas respuestas. No usar innerHTML ni interpretar contenido como Markdown/HTML.

## Resumen del listado

Ejemplo de un elemento de data; se omiten aquí los campos técnicos anteriores:

```json
{
  "id": 901,
  "event": "feed_consumption_recorded",
  "created_at": "2026-10-31T03:00:00+00:00",
  "actor": {
    "type": null,
    "id": null,
    "kind": "system",
    "name": "Sistema",
    "current_name": null,
    "exists": false,
    "status": "system",
    "can_manage": false
  },
  "presentation": {
    "title": "Consumo diario de ración registrado",
    "summary": "Se registraron 36 kg para 600 aves del lote INICIAL.",
    "subject": {
      "label": "INICIAL",
      "resource": "flock",
      "id": "01K8Y2N7AA2XQY0YQEBMWVFJ9F"
    },
    "production_unit": { "id": 1, "name": "Granja El Ombú" }
  }
}
```

Este resumen no contiene facts, changes, actions ni reversibility.
El listado conserva properties y attribute_changes por compatibilidad, pero la
pantalla nueva no debe mostrarlos ni deducir información a partir de claves opacas.

subject.resource o subject.id pueden ser null. La etiqueta histórica puede
mostrarse aunque el recurso haya cambiado después. Un sujeto es contexto
histórico: por sí solo no garantiza existencia actual, autorización o navegación.
Para acciones usar exclusivamente destinos de kinds reconocidos.

### Enlace al lote desde un pesaje

Los pesajes convencionales individuales y grupales, y los ingresos de pesaje
diario, entregan la relación en el mismo campo del listado y del detalle:

```json
{
  "subject": {
    "label": null,
    "resource": "flock",
    "id": "01K8Y2N7AA2XQY0YQEBMWVFJ9F"
  }
}
```

Cuando presentation.subject.resource sea flock y tenga id, usar ese identificador
público en la ruta de detalle de lote que ya maneja el frontend. No utilizar
data.subject.id, que identifica al pesaje, ni un ID interno de base de datos.
Si label es null, el enlace puede decir Ver lote; no se necesita buscar el nombre
actual para mostrarlo ni bloquear la navegación por falta de etiqueta histórica.

Se incluyen weighing_recorded, weighing_corrected, daily_weighing_entry_added y
daily_weighing_entry_deleted. Las correcciones apuntan al lote capturado después
de la corrección; el registro original conserva el suyo. La relación también se
recupera de entradas históricas sin modificarlas, siempre que contengan el ULID
público. No se consulta el pesaje vigente para reconstruir su relación pasada.

El recurso de negocio continúa validando acceso: GET /flocks/{id} requiere los
permisos del módulo y puede responder 403/404. El frontend debe manejar esos
estados al navegar. Los IDs ausentes, internos o inválidos no generan un enlace.
La respuesta nunca entrega una URL para ejecutar directamente.

## Presentación legible de pesajes

Los eventos de pesaje tienen un presentador específico. El frontend renderiza
title, summary, facts y changes sin interpretar measurements ni otros JSON técnicos.
La relación presentation.subject con el lote permanece disponible.

Ejemplo parcial de presentation para un registro grupal:

```json
{
  "title": "Pesaje grupal registrado",
  "summary": "5 aves · Peso total: 2.500 g · Promedio: 500 g por ave. Dentro del rango esperado.",
  "facts": [
    { "label": "Modalidad", "value": "Grupal" },
    { "label": "Cantidad de aves", "value": "5" },
    { "label": "Peso total", "value": "2.500 g" },
    { "label": "Promedio por ave", "value": "500 g por ave" },
    { "label": "Rango esperado por ave", "value": "400 g – 600 g por ave" },
    { "label": "Evaluación del rango", "value": "Dentro del rango esperado." }
  ],
  "changes_state": "presented"
}
```

El título distingue Individual/Grupal y registro/rectificación. En pesajes diarios
también distingue un ingreso registrado de uno eliminado. Las mediciones de una
muestra convencional se presentan con etiquetas Medición 1, Medición 2, etc.

Los pesos se expresan en gramos normalizados: punto de miles y coma decimal.
Por ejemplo, 166.666667 g almacenados se muestran como 166,666667 g; no se agrega
redondeo de presentación. Estos textos no son payloads editables.

La evaluación usa el resultado guardado para las mediciones, no el promedio
general: puede haber mediciones fuera del rango aunque ese promedio esté dentro.
Sin referencia se muestra Sin rango esperado disponible. Si la referencia existe
pero no se conservó el resultado, se indica Evaluación del rango no conservada.
No interpretar una referencia ausente como confirmación de normalidad.

### Cambios de la muestra diaria

En daily_weighing_entry_added y daily_weighing_entry_deleted los facts distinguen
Aves del ingreso, Peso total del ingreso y Promedio del ingreso de los totales
Cantidad de aves de la muestra diaria, Peso total de la muestra diaria y Promedio
de la muestra diaria. La evaluación del rango corresponde al ingreso registrado
o eliminado; Ingresos fuera del rango esperado expresa el contador conservado de
la jornada después de la operación.

Ejemplo parcial de changes al agregar tres aves a una muestra que ya tenía una:

```json
[
  { "label": "Cantidad de aves de la muestra diaria", "before": "1", "after": "4" },
  { "label": "Peso total de la muestra diaria", "before": "20 g", "after": "110 g" },
  { "label": "Promedio de la muestra diaria", "before": "20 g por ave", "after": "27,5 g por ave" }
]
```

Al eliminar un ingreso se muestran los cambios que consten en la instantánea y
su motivo. Si se elimina el último, el resumen indica La muestra diaria quedó
vacía y el promedio queda como Sin aves en la muestra, no como cero. Si falta el
estado anterior, before permanece null; no se inventa una muestra de cero aves.

Estos eventos dejan de usar el aviso genérico de cambios no presentables cuando
los datos de negocio están conservados. Pueden seguir mostrando history.partial
por falta de nombre histórico de lote, responsable o UP, o por datos de pesaje
ausentes. Eso no significa que se hayan perdido las mediciones. No se agregaron
acciones nuevas de mutación o permisos de pesaje desde auditoría.

## Responsables: nombres, gestión y filtros


actor.name es histórico; null significa que no se conservó. current_name es el
nombre vigente, también disponible para cuentas deshabilitadas lógicamente.
Si name falta, puede mostrarse current_name con la aclaración Nombre actual.
Si ambos faltan, mostrar Responsable no disponible. Nunca sustituir un nombre por
el ID. Los procesos identificados muestran Sistema.

| Campo | Interpretación |
| --- | --- |
| kind=person | Actor de usuario reconocido; conserva su ID aunque la cuenta ya no exista. |
| kind=system | Proceso automático identificado explícitamente. |
| kind=unavailable | No hay identidad humana o automática que pueda afirmarse. |
| exists=true | La fila existe, incluso si está deshabilitada. |
| status=disabled | Cuenta con baja lógica; conserva su fila. |
| can_manage=true | La fila existe y quien consulta tiene can_manage_users. |

La búsqueda devuelve sólo actores con actividad, una opción por identidad. Cada
opción contiene value, label, type, kind, status, can_manage, exists, name y
current_name. No contiene correo, roles ni permisos completos.

```http
GET /auditoria/responsables?buscar=Ana&por_pagina=25&page=1
```

buscar tiene hasta 100 caracteres y busca literalmente en el nombre histórico
de la entrada representativa más reciente o en el vigente. Admite Sistema.
Una búsqueda no recorre todas las variantes históricas de un nombre.

Para una persona seleccionada, enviar al listado:

```text
actor_kind=person
actor_type=<type devuelto, codificado como parámetro URL>
actor_id=<value devuelto>
```

Para Sistema enviar actor_kind=system; para Responsable no disponible enviar
actor_kind=unavailable. No enviar actor_id=null. Usar una clave de opción compuesta
por kind, type y value, porque las opciones especiales no tienen ID.

Una entrada sin responsable no es automáticamente una entrada del sistema.
No ofrecer gestión con can_manage=false. La auditoría no entrega un endpoint nuevo
de ficha de usuario: reutilizar la pantalla de gestión existente sólo cuando
corresponda. Las cuentas deshabilitadas permiten gestionar su reactivación; las
eliminadas físicamente no ofrecen ese enlace.

## Contrato de detalle

La respuesta tiene data con exactamente id, event, created_at, actor y presentation.
presentation conserva el resumen y agrega:

```ts
type HistoryState = 'complete' | 'partial';
type ChangesState = 'presented' | 'partial' | 'unavailable' | 'not_recorded';
type ReversibilityState = 'available' | 'irreversible' | 'unavailable' | 'unknown';

interface AuditFact {
  label: string;
  value: string;
}

interface AuditChange {
  label: string;
  before: string | null;
  after: string | null;
}

interface AuditAction {
  kind: 'reverse_inventory_movement' | 'correct_feed_consumption' | 'undo_mortality'
    | 'reverse_flock_status' | 'reverse_flock_redistribution';
  label: string;
  available: boolean;
  reason: string | null;
  target: {
    resource: 'inventory_movement' | 'feed_consumption' | 'mortality_record'
      | 'flock' | 'flock_redistribution';
    id: number | string;
    flock_id?: string;
    production_unit_id?: number;
    status?: 'active' | 'quarantined';
    version?: number;
    destination_version?: number;
  };
}

interface AuditDetailFields {
  facts: AuditFact[];
  changes: AuditChange[];
  changes_state: ChangesState;
  history: { state: HistoryState; missing: string[] };
  reversibility: { state: ReversibilityState; reason: string };
  actions: AuditAction[];
}
```

Ejemplo de campos adicionales de una jornada:

```json
{
  "facts": [
    { "label": "Modo de alimentación", "value": "Automático" },
    { "label": "Edad en días", "value": "30" },
    { "label": "Cantidad de aves", "value": "600" },
    { "label": "Cantidad de ración", "value": "36 kg" },
    { "label": "Fecha de consumo", "value": "30/10/2026" },
    { "label": "Receta", "value": "Ración inicial" }
  ],
  "changes": [
    { "label": "Cantidad de ración", "before": null, "after": "36 kg" }
  ],
  "changes_state": "partial",
  "history": {
    "state": "partial",
    "missing": ["Se presentan los cambios reconocidos; otros datos del registro no se muestran."]
  },
  "reversibility": {
    "state": "available",
    "reason": "Puedes rectificar el consumo mediante una compensación. La configuración de alimentación no cambia."
  },
  "actions": [
    {
      "kind": "correct_feed_consumption",
      "label": "Rectificar consumo",
      "available": true,
      "reason": null,
      "target": {
        "resource": "feed_consumption",
        "id": 196,
        "flock_id": "01K8Y2N7AA2XQY0YQEBMWVFJ9F",
        "production_unit_id": 1
      }
    }
  ]
}
```

El orden y la cantidad de hechos dependen del evento y de la evidencia conservada;
renderizar las listas como vienen. Los ejemplos no prometen que todos los registros
tengan los mismos datos. Los IDs son ilustrativos.

before/after=null no significa Sin registrar. Mostrar Valor histórico no disponible
o una representación equivalente. changes=[] con unavailable indica cambios que
el presentador no puede explicar; con not_recorded indica que no se conservó un
diff. No mostrar No hubo cambios como conclusión de ninguno de esos dos estados.
presented significa que se muestran los campos reconocidos; partial avisa que
existen otros datos omitidos. history.missing se muestra como información,
sin impedir consultar el resto del registro.

Las fechas de negocio ya vienen DD/MM/YYYY: no pasarlas por new Date ni UTC.
Los instantes conservan ISO con offset y pueden convertirse para presentación.
Los valores de cantidades ya están formateados: 36001 g se presenta como
36,001 kg. No convertirlos a Number, redondearlos o usarlos como payload de edición.

## Mapeo de acciones a los comandos existentes

El servidor nunca proporciona URLs ejecutables ni verbos HTTP dentro de actions.
El cliente mantiene este mapeo explícito:

| kind | Comando bajo /api/v1 | Payload |
| --- | --- | --- |
| reverse_inventory_movement | POST /inventory/movements/{target.id}/reversals | reason, requerido, hasta 255 caracteres. |
| correct_feed_consumption | POST /lotes/{target.flock_id}/consumos-alimento/{target.id}/rectificaciones | motivo, hasta 2000 caracteres; receta_id, cantidad_g o ambos. |
| reverse_flock_status | PATCH /flocks/{target.id}/status | `audit_entry_id` de `data.id` del detalle, `status` y `version` del destino, y `reason` obligatorio de hasta 500 caracteres. |
| reverse_flock_redistribution | POST /redistributions/{target.id}/reversals | `version` del origen original, `destination_version` si aparece en el destino, y `reason` obligatorio de hasta 500 caracteres. |

Los comandos usan Idempotency-Key con UUID. En alimentación cantidad_g se envía
en gramos enteros, puede ser cero y admite hasta 999999999999; el texto de los
facts no sirve como valor editable. Para editar, consultar el recurso de negocio
vigente y reutilizar el formulario existente. No enviar idempotency_key en el body.

Mantener el mismo UUID si se reintenta la misma confirmación con idéntico payload.
Un nuevo intento de negocio o un payload distinto necesita otra clave.

Las reversiones de inventario requieren can_adjust_inventory. Las rectificaciones de
alimentación conservan la autorización actual del módulo: sesión autenticada,
sin permiso funcional específico nuevo. Las sesiones compartidas necesitan sus
credenciales, X-GAM-Session y generación vigentes en el comando; las sesiones web
mantienen cookies y CSRF según los contratos existentes.

Los destinos de las dos reversiones de Lotes usan ULID. Las entradas del origen
y del destino de una redistribución apuntan al mismo movimiento original. Una
acción bloqueada con referencia verificable entrega `available: false`, el motivo
y un destino con `resource` e `id`. Cuando falta una referencia verificable y
accesible, se muestra `reversibility.reason`. La confirmación de redistribución pide
el motivo. El sistema de alertas evalúa la capacidad después de devolver las aves.

available=true es orientativo y puede caducar por concurrencia. Ante 409 mostrar
`detail` del comando y refrescar el detalle y el listado. Una nueva confirmación
requiere una acción del operador. Tras una reversión exitosa también se refrescan
el detalle y el listado. Ante 401 o 403 actualizar el estado de sesión y permisos.

## Reversibilidad y cobertura

| state | Tratamiento de UI |
| --- | --- |
| available | Hay una acción integrada disponible al consultar; mostrar su confirmación habitual. |
| unavailable | Mostrar la explicación y deshabilitar la acción cuando venga en actions. |
| irreversible | Mostrar la explicación del hecho histórico; no inferirla sólo por el evento. |
| unknown | El presentador no integra una corrección para ese caso; no afirmar irreversibilidad. |

Se integra elegibilidad específica de movimientos de inventario y consumos diarios
de alimentación. Para otros eventos hay título y campos de negocio reconocidos,
pero no se anuncia una corrección genérica. Esto permite extender la cobertura
sin que el cliente interprete modelos o JSON internos.

Una elaboración y una aplicación sanitaria se corrigen desde su operación dueña.
Un movimiento ya revertido devuelve unavailable. Un consumo con una rectificación
posterior bloquea la acción sobre esa entrada antigua; abrir la revisión vigente
o el consumo de negocio para continuar.

El negocio actual permite rectificar consumos de lotes cerrados y recetas
inactivas. El frontend no debe añadir un bloqueo por cierre o inactividad que
contradiga el detalle. Las rectificaciones son compensaciones y conservan historia.

## Compatibilidad y aceptación

- Los eventos no conocidos por el cliente usan el label y title entregados por backend.
- Sin descripción legible se muestra Actividad registrada; el registro sigue visible.
- No hay recuperación inventada de nombres históricos ni backfill del pasado.
- Las etiquetas nuevas se capturan para operaciones posteriores al despliegue.
- La captura de nombres se realiza al registrar, también para consumos recuperados.
- No se ocultan filas para simular filtros, ni se recalculan totales en cliente.
- No se ofrecen enlaces de cuenta cuando can_manage=false.
- No se ejecuta ninguna mutación al abrir el detalle.
- No se presentan secrets, hashes, IDs internos como nombres ni JSON opaco.
- El endpoint de detalle no contiene los campos técnicos del listado anterior.

## Estado de verificación

El backend verificó 308 casos distintos, incluidas 26 pruebas de auditoría y
referencias, precisión y flujos reales de consumo, rectificación y reversión con
cambio concurrente de stock. PHPStan y Pint aprobaron. La guía técnica registra
las ejecuciones y los límites de verificación. El frontend todavía debe integrar
esta pantalla contra las rutas descritas.

La ampliación del enlace al lote añadió 11 casos de pesajes y aprobó la regresión
de presentación completa: 29 pruebas y 212 aserciones, además de PHPStan y Pint.

La presentación legible de pesajes se verificó después con la suite de auditoría
completa: 41 pruebas y 346 aserciones, más PHPStan y Pint. Cubre ambos modos,
referencias ausentes, precisión, valores fuera del rango y cambios reales de la
muestra diaria al agregar o eliminar ingresos.
