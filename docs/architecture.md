# GAM — Backend Architecture

## 1. Objetivo

GAM utiliza un backend Laravel orientado a casos de uso, siguiendo una estructura simple y cercana a las convenciones del framework.

La arquitectura evita capas innecesarias, módulos DDD artificiales y abstracciones sin una necesidad concreta.

Flujo principal:

```text
Request
   ↓
FormRequest
   ↓
Controller
   ↓
Action
   ↓
Model / Service
   ↓
Resource
   ↓
Response
```

Principios:

* Laravel concentra las reglas de negocio.
* Controllers delgados.
* Casos de uso en Actions.
* Eloquent directo cuando sea suficiente.
* Services, Interfaces y DTOs sólo cuando resuelvan una necesidad concreta.
* PostgreSQL como fuente de verdad.
* Redis para colas, cache y rate limiting.
* Operaciones críticas dentro de transacciones.
* Locks cuando exista concurrencia real.
* Auditoría para operaciones sensibles.

## 2. Stack

* Laravel 13
* PHP 8.x
* PostgreSQL
* Redis
* Laravel Sanctum
* Laravel Horizon
* Docker Compose
* OpenAPI

## 3. Estructura

```text
app/
├── Actions/
│   ├── Auth/
│   ├── Installations/
│   ├── Flocks/
│   ├── Management/
│   ├── Health/
│   ├── Inventory/
│   ├── Deliveries/
│   ├── Sales/
│   └── Reports/
│
├── DTO/
├── Enums/
├── Exceptions/
│
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/
│
├── Interfaces/
├── Models/
├── Policies/
├── Providers/
├── Services/
└── Infrastructure/
```

No todas las carpetas deben existir desde el inicio.

Se crean cuando aparece una necesidad concreta.

`Auth`, `Inventory`, `Sales`, `Flocks`, etc. son agrupaciones funcionales dentro de las carpetas normales de Laravel, no módulos independientes.

No se utiliza:

```text
app/Modules/
```

ni estructuras del tipo:

```text
Application/
Domain/
Infrastructure/
Http/
```

por cada área funcional.

## 4. Controllers

Los Controllers adaptan HTTP al caso de uso.

Responsabilidades:

* recibir un `FormRequest`;
* obtener datos validados;
* ejecutar una Action;
* devolver un Resource o respuesta HTTP.

No deben contener reglas importantes de negocio.

```php
public function store(
    StoreFlockRequest $request,
    CreateFlockAction $action,
): FlockResource {
    $flock = $action($request->validated());

    return new FlockResource($flock);
}
```

Un Controller no debe calcular stock, decidir transiciones de estado, calcular saldos ni manipular varias entidades directamente para ejecutar lógica de negocio.

## 5. Actions

Las Actions representan casos de uso concretos.

```text
CreatePoultryHouseAction
UpdatePoultryHouseAction
CreateFlockAction
MoveFlockAction
RecordMortalityAction
RecordEggCollectionAction
AdjustStockAction
CreateDeliveryAction
CloseDeliveryAction
ConfirmSaleAction
RegisterPaymentAction
```

Una Action puede:

* consultar Models;
* validar invariantes;
* usar Services;
* iniciar transacciones;
* utilizar `lockForUpdate()`;
* registrar auditoría;
* despachar Events;
* despachar Jobs.

```php
final class RecordEggCollectionAction
{
    public function __invoke(array $data): EggCollection
    {
        return DB::transaction(function () use ($data) {
            // validar estado actual
            // registrar recolección
            // registrar movimiento de stock
            // auditar operación

            return $collection;
        });
    }
}
```

No se crea una Action automáticamente para cada operación CRUD trivial.

## 6. Models

Los Models son modelos Eloquent normales.

Contienen:

* relaciones;
* casts;
* scopes;
* atributos;
* comportamiento pequeño directamente relacionado con la entidad.

```text
Models/
├── User.php
├── ProductionUnit.php
├── PoultryHouse.php
├── Flock.php
├── MortalityRecord.php
├── EggCollection.php
├── Product.php
├── StockMovement.php
├── Customer.php
├── Sale.php
└── Payment.php
```

Los Models no deben convertirse en objetos gigantes que implementen casos de uso completos.

No se crean repositories genéricos únicamente para envolver Eloquent.

## 7. Form Requests

Los `FormRequest` validan la entrada HTTP.

Responsabilidades:

* campos requeridos;
* tipos;
* formatos;
* rangos;
* reglas estructurales.

Las invariantes dependientes del estado actual deben validarse dentro de la Action.

Ejemplos:

* stock disponible;
* capacidad de una instalación;
* estado de un lote;
* estado de una venta;
* saldo actual;
* disponibilidad dentro de un reparto.

## 8. Resources

Los API Resources definen la representación pública de los datos.

Se encargan de:

* seleccionar campos expuestos;
* transformar valores;
* serializar relaciones;
* mantener estable el contrato HTTP.

Los Resources no contienen reglas de negocio.

## 9. DTOs

Los DTOs son opcionales.

Se utilizan cuando simplifican inputs complejos.

```text
CreateSaleData
CloseDeliveryData
RegisterFlockMovementData
```

Son apropiados cuando existen estructuras anidadas, múltiples líneas, muchos parámetros o reutilización fuera de HTTP.

Para inputs simples puede utilizarse directamente:

```php
$request->validated()
```

No se crea un DTO para duplicar cada `FormRequest`.

## 10. Services

Los Services contienen lógica reutilizable que no constituye por sí sola un caso de uso.

```text
StockService
PricingService
ReportService
FileStorageService
```

Una lógica utilizada por una única Action puede permanecer en esa Action.

No se crean Services únicamente para mover código de lugar.

## 11. Interfaces e Infrastructure

Las Interfaces se utilizan cuando existe una dependencia que realmente conviene desacoplar.

Ejemplos:

* proveedor meteorológico;
* almacenamiento externo;
* servicio externo;
* integración con terceros.

```text
Interfaces/
└── WeatherProvider.php

Infrastructure/
└── Weather/
    └── OpenWeatherProvider.php
```

No se crea una Interface delante de cada Model, Action o Service.

Eloquent puede utilizarse directamente desde las Actions.

## 12. Transacciones

Toda operación que deba ejecutarse atómicamente utiliza:

```php
DB::transaction(...)
```

Ejemplos:

* mover un lote;
* registrar mortalidad;
* registrar producción y aumentar inventario;
* ajustar stock;
* cerrar un reparto;
* confirmar una venta;
* registrar un cobro;
* anular una operación mediante contramovimientos.

Si una parte falla, toda la operación se revierte.

## 13. Concurrencia

Cuando una operación depende de datos que pueden modificarse concurrentemente se utilizan locks.

```php
$stock = StockBalance::query()
    ->where(...)
    ->lockForUpdate()
    ->firstOrFail();
```

Aplica especialmente a:

* stock;
* repartos;
* saldos;
* cantidades vivas;
* operaciones financieras;
* transiciones críticas.

La validación importante se realiza después de adquirir el lock y dentro de la misma transacción.

## 14. Inventario

El inventario se basa en movimientos.

```text
PRODUCTION
ENTRY
EXIT
SALE
RETURN
ADJUSTMENT
```

El stock no se modifica silenciosamente sin registrar el movimiento correspondiente.

Las correcciones relevantes utilizan movimientos compensatorios cuando corresponde.

PostgreSQL mantiene el estado autoritativo.

Redis nunca sustituye la base transaccional.

## 15. Dinero y cantidades

Los valores que requieran precisión no utilizan `float`.

Ejemplos:

* precios;
* totales;
* saldos;
* pesos;
* dosis;
* cantidades decimales.

Se utiliza `DECIMAL` en PostgreSQL.

Cuando una regla lo justifique pueden utilizarse casts, Enums o Value Objects específicos.

No se crea un Value Object para cada string, entero o decimal.

## 16. Auditoría

Las operaciones sensibles deben dejar trazabilidad.

Debe poder reconstruirse:

* actor;
* acción;
* entidad;
* fecha;
* resultado;
* valores relevantes anteriores;
* valores relevantes nuevos;
* `trace_id` cuando corresponda.

Ejemplos:

* cambios de permisos;
* movimientos de stock;
* movimientos de lotes;
* mortalidad;
* ventas;
* cobros;
* anulaciones;
* cierres de reparto;
* ajustes manuales.

La auditoría nunca guarda contraseñas, tokens ni secretos.

La auditoría es transversal y no necesita convertirse en un módulo arquitectónico independiente.

## 17. Autenticación

La API utiliza Laravel Sanctum con canales de autenticación separados según el tipo de cliente.

Las sesiones personales web usan cookies stateful y protección CSRF. Los clientes personales nativos usan Personal Access Tokens Bearer con expiración y revocación:

```http
Authorization: Bearer <token>
```

Los dispositivos compartidos usan una credencial independiente de la sesión personal. En clientes nativos se presenta mediante `X-Shared-Device-Token`; en web se conserva en la cookie HttpOnly `gam_shared_device`. Una sesión de empleado compartida exige además su `auth_sessions` vigente, `X-GAM-Session`, la generación actual del dispositivo y los permisos funcionales del actor. El transporte de la credencial debe coincidir con el transporte de la sesión.

Los tokens y sesiones deben poder:

* expirar;
* revocarse;
* asociarse al usuario;
* limitarse mediante abilities cuando sea necesario.

El rol o modo conservado por un frontend nunca constituye evidencia de autorización. Las credenciales, contraseñas, PIN, peppers, tokens y secretos de dispositivo no se registran en logs ni auditoría.

## 18. Autorización

La autorización se ejecuta siempre en Laravel.

Se utilizan:

* Policies;
* middleware;
* roles;
* permisos;
* abilities cuando corresponda.

Las Actions sensibles no deben asumir autorización únicamente por restricciones existentes del lado del consumidor de la API.

## 19. API

La API se versiona bajo:

```text
/api/v1
```

Para recursos normales se utiliza REST.

```text
GET    /api/v1/flocks
POST   /api/v1/flocks
GET    /api/v1/flocks/{flock}
PATCH  /api/v1/flocks/{flock}
```

Para transiciones de negocio se utilizan endpoints explícitos.

```text
POST /api/v1/flocks/{flock}/move
POST /api/v1/deliveries/{delivery}/start
POST /api/v1/deliveries/{delivery}/close
POST /api/v1/sales/{sale}/cancel
```

OpenAPI documenta el contrato público de la API.

OpenAPI no determina la estructura interna de Laravel.

## 20. Events

Los Events se utilizan cuando aportan desacoplamiento real.

```text
EggCollectionRecorded
SaleConfirmed
PaymentRegistered
DeliveryClosed
```

No se utilizan Events únicamente para evitar una llamada directa.

Cuando dependen de información confirmada deben ejecutarse después del commit.

## 21. Jobs y Horizon

Los Jobs se utilizan para trabajo asíncrono.

Ejemplos:

* reportes;
* exportaciones;
* notificaciones;
* procesamiento pesado;
* integraciones externas.

Cuando puedan reintentarse deben ser idempotentes.

Deben definir cuando corresponda:

* timeout;
* retries;
* backoff;
* manejo de fallos.

Horizon administra las queues basadas en Redis.

Al mover un Job se debe drenar o reintentar la cola antes de desplegar el nuevo namespace. Los Jobs ya serializados conservan su FQCN; esta reorganización no agrega alias permanentes ni cambia el payload de los Jobs.

## 22. PostgreSQL

PostgreSQL es la fuente de verdad.

Las migraciones utilizan correctamente:

* foreign keys;
* unique constraints;
* indexes;
* check constraints;
* tipos de datos adecuados.

Las invariantes que puedan protegerse también a nivel de base deben protegerse.

## 23. Redis

Redis se utiliza para:

* queues;
* Horizon;
* cache;
* rate limiting;
* locks distribuidos cuando sean necesarios.

Redis no almacena el estado transaccional autoritativo del negocio.

## 24. Idempotencia

Las operaciones críticas que puedan reintentarse deben soportar idempotencia cuando sea necesario.

Ejemplos:

* registrar producción;
* cerrar un reparto;
* confirmar una venta;
* registrar un cobro.

Una misma operación reintentada no debe producir movimientos duplicados.

## 25. Errores

Los errores utilizan una estructura homogénea.

Estados principales:

```text
400 Bad Request
401 Unauthorized
403 Forbidden
404 Not Found
409 Conflict
422 Unprocessable Entity
429 Too Many Requests
500 Internal Server Error
```

Los conflictos de estado utilizan preferentemente `409`.

Las validaciones de entrada utilizan `422`.

## 26. Logging y trazabilidad

Los logs deben ser estructurados.

Cuando corresponda incluyen:

```text
trace_id
user_id
action
entity_type
entity_id
result
```

Nunca incluyen secretos ni tokens completos.

Middleware como `AssignTraceContext` puede generar y propagar el contexto de trazabilidad de cada request.

## 27. Tests

```text
tests/
├── Feature/
│   ├── Auth/
│   ├── Installations/
│   ├── Flocks/
│   ├── Inventory/
│   ├── Deliveries/
│   └── Sales/
│
└── Unit/
    ├── Actions/
    ├── Services/
    └── ...
```

Los Feature Tests cubren principalmente:

* endpoints;
* autenticación;
* autorización;
* validación;
* persistencia;
* transacciones;
* respuestas JSON;
* invariantes críticas.

Los Unit Tests se utilizan cuando existe lógica suficientemente aislable.

No se mockea Eloquent por sistema.

## 28. Reglas de arquitectura

1. No utilizar `app/Modules`.
2. No implementar DDD por cada área funcional.
3. No crear capas sólo por simetría.
4. Mantener Controllers delgados.
5. Colocar casos de uso importantes en Actions.
6. Utilizar Eloquent directamente cuando sea suficiente.
7. Crear Services sólo cuando exista una responsabilidad reutilizable.
8. Crear Interfaces sólo cuando exista una dependencia que convenga desacoplar.
9. Crear DTOs sólo cuando simplifiquen inputs complejos.
10. Utilizar Form Requests para validación HTTP.
11. Utilizar Resources para respuestas públicas.
12. Mantener las reglas críticas en el backend.
13. Utilizar transacciones para operaciones atómicas.
14. Utilizar locks cuando exista concurrencia real.
15. Mantener históricos para movimientos importantes.
16. Auditar operaciones sensibles.
17. No guardar secretos en logs.
18. Evitar Helpers y Services genéricos sin responsabilidad clara.
19. Preferir nombres relacionados con el negocio.
20. No crear repositories genéricos para envolver Eloquent.
21. No anticipar microservicios sin una necesidad real.
22. No agregar una abstracción sin poder explicar qué problema concreto resuelve.

## 29. Regla general

La arquitectura preferida para GAM es:

```text
FormRequest
     ↓
Controller
     ↓
Action
     ↓
Eloquent / Service
     ↓
Resource
```

La solución más simple que preserve correctamente las reglas de negocio es la opción preferida.

Si una nueva capa no mejora de forma tangible mantenibilidad, seguridad, testabilidad o integración, no se agrega.
