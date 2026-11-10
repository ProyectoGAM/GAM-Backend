# Catálogo de presentaciones de huevos

Administración gestiona el catálogo común a todas las UP desde Inventario:
puede crear, editar y eliminar presentaciones. Los endpoints requieren el rol
`admin`; el backend mantiene la autoridad sobre equivalencias y precios.

## Contrato

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | `/api/v1/inventario/presentaciones-huevos` | Catálogo disponible y `meta.locked` |
| POST | `/api/v1/inventario/presentaciones-huevos` | Creación: 201 |
| PATCH | `/api/v1/inventario/presentaciones-huevos/{presentation}` | Modificación: 200 |
| DELETE | `/api/v1/inventario/presentaciones-huevos/{presentation}` | Eliminación: 204 sin cuerpo |

El identificador público es `code`, expuesto como `id`. Crear requiere `name`,
`eggs_per_unit` entero positivo y `default_unit_price` entero no negativo en UYU.
Ejemplo: `{"name":"Caja de 45 huevos","eggs_per_unit":45,"default_unit_price":100}`.
Los precios nulos de las definiciones importadas siguen significando «sin configurar».

Todas las escrituras responden 409 mientras exista cualquier reparto activo.
La eliminación exige confirmación en la pantalla; el servidor valida el rol y el
bloqueo en su propia transacción. Una presentación inexistente o eliminada responde
404; repetir DELETE no crea otra auditoría. No se requiere clave de idempotencia.

## Persistencia e historial

La migración `2026_10_10_025700_add_deleted_at_to_egg_presentations_table` añade
`deleted_at` nullable con zona horaria. `SoftDeletes` oculta las presentaciones
eliminadas tanto en Administración como en el catálogo de cargas y sus consultas
de validación. Los códigos no se reutilizan y los registros previos se conservan.
Las cargas, entregas y devoluciones históricas mantienen sus instantáneas de nombre,
equivalencia, cantidad y precio aplicado.

El catálogo conserva el bloqueo de su primera fila incluyendo las eliminadas.
Así se mantiene la serialización con el inicio de repartos incluso después de
eliminar la última presentación, y se puede crear una nueva en un catálogo vacío.
La reversión de la migración elimina las marcas de borrado y vuelve a mostrar los
registros retirados; para evitarlo, preferir una corrección hacia adelante.

`DeleteEggPresentationAction` registra `egg_presentation_deleted` mediante
`AuditRecorder` en la misma transacción del borrado lógico. Conserva actor,
identificadores de operación y traza, instantánea permitida y cambio de `deleted_at`.
Una falla de auditoría revierte el borrado. No modifica auditorías anteriores.

## Verificación

Las pruebas de `EggPresentationEndpointTest` cubren creación y edición, eliminación
con conservación de historial, ausencia en ambos catálogos, catálogo vacío seguido
de nueva creación, autenticación, rol administrativo, bloqueo por reparto activo,
404 después del borrado y reversión ante falla de auditoría.

Las pruebas de la pantalla ejercitan el formulario visible, nueva presentación,
confirmación y cancelación, refresco tras borrado, conflicto del servidor y prevención
de solicitudes simultáneas.

Verificación ejecutada: 49 pruebas de Inventario y Repartos con 448 aserciones;
8 pruebas específicas de la pantalla y suite completa del frontend; lint, build,
Pint, parsing del contrato OpenAPI y revisión de diferencias. El build finaliza
correctamente con advertencias de presupuestos de estilos y dependencias CommonJS.

En Chrome con sesión administrativa local se comprobó la pantalla en escritorio
oscuro y teléfono claro de 390 px, sin desbordamiento horizontal; apertura y
cancelación del diálogo con foco dentro de él; y habilitación del botón de crear
al completar el formulario. Esta comprobación no creó ni eliminó presentaciones
reales; las escrituras se verificaron en la base aislada de pruebas.
