# Catálogo global de recetas y cálculo de cantidades

## Alcance y decisiones de negocio

Esta entrega incorpora el catálogo global de recetas y un cálculo exacto reutilizable para la futura elaboración. Las recetas tienen ID interno estable y admiten nombres repetidos. No pertenecen a una UP ni requieren asignaciones de usuarios.

El alta usa productos existentes de tipo `raw_material` del catálogo de Inventario, con unidad base `g` y control de stock. No crea productos, ubicaciones, saldos ni movimientos. No exige existencias o estado activo del ingrediente: el catálogo y el cálculo no ejecutan una elaboración.

El nombre, la nota, el rendimiento y los productos que integran la composición quedan establecidos al crear. Después sólo cambian las cantidades de esos productos y el estado de la receta. Cada ingrediente debe aparecer una sola vez y tener cantidad positiva; la composición nunca queda vacía.

Todas las cantidades del dominio son **gramos enteros positivos**, sin redondeo. Se aceptan enteros JSON o cadenas de dígitos; se rechazan floats, cadenas decimales y notación científica. El rango es 1–9223372036854775807, compatible con PHP de 64 bits y PostgreSQL `bigint`. Las respuestas usan cadenas canónicas para preservar números superiores al rango seguro de JavaScript. El frontend convierte otras unidades antes de enviar datos.

El rendimiento de salida es independiente de las cantidades de entrada. No se suman ingredientes para validar el rendimiento ni se impone igualdad entre entrada y salida.

La edición recibe la lista completa de ingredientes originales con sus cantidades. Enviar un conjunto distinto devuelve 409; los campos ajenos a la edición devuelven 422. Esto impide agregar, quitar o sustituir ingredientes. Se permite editar recetas activas e inactivas. Las nuevas cantidades afectan únicamente los cálculos y elaboraciones posteriores; ningún movimiento histórico se recalcula.

Desactivar conserva receta, composición e historial. La receta inactiva permanece consultable, pero su cálculo para una nueva elaboración devuelve 409. Reactivar habilita nuevamente el cálculo. No se publica eliminación física.

Para preservar los ingredientes existentes, una materia prima vinculada a cualquier receta no puede cambiar de tipo, unidad base ni control de stock desde el catálogo de productos, aunque todavía no tenga movimientos. Puede conservar las operaciones existentes de nombre, SKU y estado; cada auditoría de receta preserva su propia instantánea histórica.

## API pública

Contrato: [feed-recipes.yaml](../../contracts/openapi/feed-recipes.yaml). Disponible en el selector de Swagger UI de desarrollo después de actualizar el servicio con la configuración de Compose.

| Método | Ruta | Permiso |
| --- | --- | --- |
| GET | `/api/v1/recetas` | `feed-recipes.view` |
| POST | `/api/v1/recetas` | `feed-recipes.manage` |
| GET | `/api/v1/recetas/{recipe}` | `feed-recipes.view` |
| PATCH | `/api/v1/recetas/{recipe}/cantidades` | `feed-recipes.manage` |
| PATCH | `/api/v1/recetas/{recipe}/estado` | `feed-recipes.manage` |
| POST | `/api/v1/recetas/{recipe}/calculo` | `feed-recipes.view` |

El permiso de gestión no implica el de consulta: asignar ambos cuando el usuario deba administrar y leer. El rol `admin` sigue la excepción administrativa existente en los catálogos; usuarios eliminados no están autorizados. `IdentityPermissionSeeder` registra los dos permisos y los incluye en el rol administrativo. Consultar el catálogo de materias primas mediante `/api/v1/products?kind=raw_material` mantiene su permiso existente `products.view`.

Las rutas conservan el middleware autenticado y de sesión compartida del resto de la API. Las consultas de recetas son globales. No se aceptan filtros, atributos ni asignaciones por UP.

El listado incluye ambos estados por defecto y ordena por ID descendente. Admite `buscar` (hasta 120 caracteres), `estado` (`active` o `inactive`), `por_pagina` (1–100; por defecto 25) y `page` (1–1000000). Devuelve `data`, `links` y `meta`. El límite técnico de composición es 1000 ingredientes; nombre hasta 160 caracteres y nota hasta 5000.

Ejemplo de alta, con un UUID en el encabezado `Idempotency-Key`:

```json
{
  "nombre": "Ración BB",
  "rendimiento_g": "13000",
  "nota": "Combinación base",
  "ingredientes": [
    {"producto_id": 1, "cantidad_g": "2000"},
    {"producto_id": 2, "cantidad_g": "5000"},
    {"producto_id": 3, "cantidad_g": "6000"}
  ]
}
```

Los IDs del ejemplo deben sustituirse por materias primas reales del catálogo. El alta devuelve 201 con la receta en `data`; el detalle incluye `id`, `nombre`, `nota`, `rendimiento_g`, `estado`, `ingredientes`, `creado_en` y `actualizado_en`. Cada ingrediente expone ID de producto, nombre, SKU y cantidad actual en gramos.

Ejemplo de edición exclusiva de cantidades:

```json
{
  "ingredientes": [
    {"producto_id": 1, "cantidad_g": "2500"},
    {"producto_id": 2, "cantidad_g": "5000"},
    {"producto_id": 3, "cantidad_g": "6000"}
  ]
}
```

Para desactivar, enviar `{"estado":"inactive"}` al endpoint de estado; para reactivar, `{"estado":"active"}`. Los valores del enum son identificadores estables; las rutas, descripciones y errores son españoles.

## Regla de cálculo reutilizable

`FeedRecipeCalculator` concentra la aritmética y no depende de Eloquent, inventario ni HTTP. `RecipeGrams` valida el rango antes de convertir a entero y comprueba el desbordamiento antes de multiplicar. La futura elaboración puede reutilizar ambas clases.

`CalculateFeedRecipeAction` autoriza al actor, lee la receta activa y una composición consistente bajo un lock compartido, ejecuta la regla y devuelve el resultado con `FeedRecipeCalculationResource`. No escribe stock ni auditoría y no comprueba disponibilidad. Un cálculo no es una reserva ni una garantía de que la receta siga igual cuando se elabore.

Para rendimiento R y solicitud Q:

1. R y Q son enteros positivos representables.
2. Se exige `Q % R === 0`.
3. El factor se obtiene con `intdiv(Q, R)`.
4. Antes de cada producto, se exige `cantidad_base <= intdiv(PHP_INT_MAX, factor)`.
5. Se multiplica cada cantidad base por el factor sin floats.

Con la composición 2000 g de maíz, 5000 g de soja y 6000 g de arroz, y rendimiento 13000 g:

| Solicitud | Factor | Cantidades requeridas |
| --- | --- | --- |
| 13000 g | ×1 | 2000, 5000 y 6000 g |
| 26000 g | ×2 | 4000, 10000 y 12000 g |
| 39000 g | ×3 | 6000, 15000 y 18000 g |
| 19500 g | Rechazado | Factor fraccionario ×1,5 |

Enviar `{"cantidad_g":"26000"}` al endpoint de cálculo. La respuesta es:

```json
{
  "data": {
    "receta_id": 1,
    "rendimiento_g": "13000",
    "cantidad_g": "26000",
    "factor": "2",
    "ingredientes": [
      {"producto_id": 1, "nombre": "Maíz", "cantidad_base_g": "2000", "cantidad_requerida_g": "4000"},
      {"producto_id": 2, "nombre": "Soja", "cantidad_base_g": "5000", "cantidad_requerida_g": "10000"},
      {"producto_id": 3, "nombre": "Arroz", "cantidad_base_g": "6000", "cantidad_requerida_g": "12000"}
    ]
  }
}
```

Sólo se admite `cantidad_g`. Los ajustes manuales de ingredientes, unidades alternativas y campos adicionales devuelven 422. El factor fraccionario y el desbordamiento se devuelven como errores de `cantidad_g`. Los conflictos de estado o composición usan 409, falta de sesión 401, falta de permiso 403 y receta inexistente 404.

## Persistencia, auditoría e idempotencia

La migración `2026_10_13_061455_create_feed_recipes_tables.php` crea:

- `feed_recipes`: ID, nombre sin unicidad, nota, rendimiento `bigint`, estado, creador, operación y datos de idempotencia del alta.
- `feed_recipe_ingredients`: referencia a receta y producto, cantidad `bigint`, timestamps y unicidad por receta/producto.

Las cantidades tienen CHECK positivo y el estado tiene CHECK de enum. Las referencias a productos, recetas y creador usan restricción de borrado. No se altera la precisión decimal de Inventario. No se crea entidad de alimento elaborado ni tabla de stock por versión.

Los Actions registran `feed_recipe_created`, `feed_recipe_quantities_updated` y `feed_recipe_status_changed` mediante `AuditRecorder` y `AuditEntryData`, síncronamente en la misma transacción. La auditoría contiene actor, sujeto, `operation_id`, `trace_id`, origen, resultado y valores anteriores/nuevos. El log es `inventory` y la UP es nula. La instantánea permitida incluye identificación, nombre, nota, rendimiento, estado y cada ingrediente con producto, SKU, nombre, unidad y gramos. No incluye secretos.

Editar o reactivar añade historia; nunca sobrescribe ni elimina las entradas anteriores. Si falla la auditoría, se revierte toda la mutación. La API transversal de auditoría conserva su permiso `audit.view`, filtros y paginación existentes.

El alta requiere UUID en el encabezado; el cuerpo no acepta `idempotency_key`. Se normalizan nombre, cantidades y orden de ingredientes antes de calcular el hash. La clave se acota por actor y tiene restricción única en base. La fila del actor se bloquea antes de buscar el reintento; los productos se leen con lock compartido y orden estable durante la creación.

El reintento de un alta devuelve la receta existente en su estado actual, incluso si sus cantidades cambiaron después. No genera otra receta, auditoría ni restablece valores originales. La misma clave con datos distintos devuelve 409.

Las ediciones y transiciones de estado bloquean la misma fila de receta antes de consultar y mutar. Son asignaciones del estado actual: repetir inmediatamente valores iguales no escribe otra auditoría. Siguen el patrón de los catálogos existentes, sin encabezado de idempotencia ni control optimista por versión. Actualizaciones distintas concurrentes se serializan y prevalece la última ejecutada; la API no pretende detectar ediciones sobre una vista antigua. El cálculo mantiene un lock compartido de receta para evitar una composición parcial durante la edición.

## Datos demo y aplicación

`FeedRecipeDemoSeeder` está registrado en `LocalDemoDataSeeder` después de la carga de materias primas. Crea idempotentemente “Ración demo inicial” usando maíz y soja ya existentes: 5000 g + 7000 g con rendimiento declarado de 13000 g. Este ejemplo ejercita la independencia entre entrada y salida. No cambia stock ni sobrescribe una receta demo ya editada.

Se ejecuta sólo en local o en la demo de producción habilitada explícitamente por la configuración existente. No se ejecuta en testing ni en una producción normal.

Para aplicar el cambio en otro entorno existente, ejecutar migraciones normales y registrar los permisos mediante el flujo de seeding del proyecto. No hace falta reconstruir la base ni borrar inventario. La verificación automatizada aplica la migración en la base PostgreSQL aislada `gam_testing`. En esta entrega se confirmó además `APP_ENV=local`, se aplicó únicamente la nueva migración y se ejecutaron `IdentityPermissionSeeder` y `FeedRecipeDemoSeeder` para dejar disponible la API local con su receta demo. No se ejecutó una reconstrucción de base ni una elaboración. No se modificó producción ni se realizó ningún push o despliegue.

## Verificación

Pruebas focalizadas nuevas:

- `tests/Feature/Inventory/FeedRecipeEndpointTest.php`: operaciones, nombres repetidos, gramos, rendimiento independiente, composición fija, estados, cálculo, permisos, filtros, idempotencia, auditoría atómica y protección de materias primas vinculadas.
- `tests/Unit/Inventory/FeedRecipeCalculatorTest.php`: regla aislada, rendimiento independiente, límite máximo, floats, composición vacía y rendimiento cero.
- `tests/Feature/Inventory/FeedRecipeContractTest.php`: rutas documentadas y referencias OpenAPI.
- `tests/Feature/Inventory/FeedRecipeDemoSeederTest.php`: demo oficial, reintentos y protección por ambiente.

También se ejecutan las regresiones de `ProductIngredientRulesTest`, `ProductResourceCapabilitiesTest`, `FeedStockEndpointTest` y `FeedStockDemoSeederTest`.

Comando focalizado, dentro del contenedor API con la configuración aislada del README:

```powershell
docker compose -f compose.dev.yaml exec -T -e APP_ENV=testing api php artisan test --compact tests/Feature/Inventory/FeedRecipeEndpointTest.php tests/Unit/Inventory/FeedRecipeCalculatorTest.php tests/Feature/Inventory/FeedRecipeContractTest.php tests/Feature/Inventory/FeedRecipeDemoSeederTest.php tests/Feature/SuppliersAndCatalogs/ProductIngredientRulesTest.php tests/Feature/SuppliersAndCatalogs/ProductResourceCapabilitiesTest.php tests/Feature/Inventory/FeedStockEndpointTest.php tests/Feature/Inventory/FeedStockDemoSeederTest.php
```

Resultado de la ejecución integrada del 13/10/2026: **58 pruebas aprobadas, 805 aserciones**. Pint pasó sobre los archivos PHP modificados; el contenedor no detecta Git para `--dirty`, por lo que se utilizó la lista explícita del host. PHPStan/Larastan nivel 5 pasó sin errores usando la configuración existente con los paths del cambio; no se agregaron dependencias ni se relajaron comprobaciones. `git diff --check` también pasó.

Los tests prueban exactitud, reversión transaccional y ausencia de efectos de stock. No sustituyen la aceptación manual ni una prueba multiproceso de carreras concurrentes. No se ejecuta la suite completa del repositorio.

## Integraciones pendientes que no forman parte de esta entrega

Continúan pendientes la elaboración que descuenta ingredientes y acredita alimento, el stock de alimento por receta y UP, la alimentación inicial heredada del plan, el consumo automático o manual en lotes, alertas, notificaciones y pantallas frontend.

Las futuras elaboraciones descontarán ingredientes de una planta de ración y guardarán alimento por receta y UP, sin separar saldos por versiones. Los stocks de ingredientes y alimento podrán ser negativos. Desactivar una receta no detendrá el consumo de los lotes: el lote seguirá con su receta y cantidad configuradas aunque el saldo sea cero o negativo, hasta cambiar su alimentación o desactivar el lote. Estas condiciones se preservan como contexto; no se implementan operaciones, restricciones o alertas para resolverlas ahora.

Volver al [índice de implementaciones](README.md).
