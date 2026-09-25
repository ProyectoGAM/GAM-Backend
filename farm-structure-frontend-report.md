# Informe para frontend — unidades productivas y galpones

## Contrato de estados de unidades productivas

Las unidades productivas admiten únicamente `active` e `inactive`. El catálogo `GET /api/v1/reference/options` devuelve esos dos valores en `data.statuses.production_units`, con las etiquetas `Activo` e `Inactivo`. El valor anterior `archived` ya no es válido en altas, filtros ni cambios de estado y recibe `422`. No existe una acción de archivo ni un endpoint de borrado para unidades productivas.

| Operación | Comportamiento vigente |
| --- | --- |
| `POST /api/v1/production-units` | `status` es opcional; si se omite, la unidad se crea `active`. Puede enviarse `inactive`. |
| `PATCH /api/v1/production-units/{productionUnit}/status` | Permite cambiar entre `active` e `inactive`. Repetir el estado actual devuelve conflicto `409`. |
| `GET /api/v1/production-units` | Devuelve unidades activas e inactivas; no oculta las inactivas. |
| `GET /api/v1/production-units?status=inactive` | Filtra las unidades inactivas. El filtro `status` solo admite los dos valores vigentes. |
| `GET /api/v1/production-units/{productionUnit}` | Permite consultar explícitamente una unidad inactiva. |

La desactivación de una unidad devuelve `409` si alguno de sus galpones no está `inactive`. Para el flujo de interfaz, primero deben desactivarse los galpones correspondientes. La capacidad física de cada galpón (`bird_capacity`) no cambia al activar o desactivar la unidad.

## Ocupación actual de galpones

`GET /api/v1/production-units/{productionUnit}/poultry-houses` y `GET /api/v1/poultry-houses/{poultryHouse}` incluyen `current_occupancy` como entero. Es la suma de `current_quantity` de los lotes `active` o `quarantined` del galpón; vale `0` si no hay lotes abiertos. `bird_capacity` sigue siendo la capacidad física máxima, no la ocupación.

El recurso puede omitir `current_occupancy` en las respuestas de alta, edición y cambio de estado del galpón. Si la pantalla necesita el valor después de una escritura, debe consultar nuevamente el listado o el detalle.

## Decisiones de interfaz pendientes

1. Definir si la vista operativa oculta por defecto las unidades `inactive`. El backend las incluye y ofrece el filtro por estado.
2. Usar una acción de desactivación o reactivación para unidades productivas, sin opción de archivo o eliminación.
3. Mostrar `bird_capacity` y `current_occupancy` como magnitudes distintas y actualizar la ocupación tras operaciones que cambian lotes.

El historial de auditoría es inmutable: un evento antiguo de archivo, si existiera en una base usada durante el desarrollo previo, puede seguir apareciendo como hecho histórico. No representa un estado vigente de la unidad.
