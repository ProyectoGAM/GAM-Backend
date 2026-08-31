# Ejemplo de estructura modular

Este documento usa el módulo de ubicaciones y estructura de la granja como referencia para módulos nuevos. Los identificadores del código están en inglés; los comentarios y bloques PHPDoc se escriben en español.

## Límites del módulo

La funcionalidad se divide en dos límites coordinados:

- `Geography` es propietario de `Department` y `Locality`.
- `FarmStructure` es propietario de `ProductionUnit`, `PoultryHouse`, sus estados y capacidad máxima.

Otros módulos no importan estos modelos Eloquent directamente. La colaboración se realiza mediante clases dentro de `Application/PublicApi`.

## Estructura real

```text
app/
├── Models/
│   ├── Geography/
│   │   ├── Department.php
│   │   └── Locality.php
│   └── FarmStructure/
│       ├── ProductionUnit.php
│       └── PoultryHouse.php
├── Modules/
│   ├── Geography/
│   │   ├── Application/
│   │   │   ├── Actions/
│   │   │   └── Queries/
│   │   └── Http/
│   │       ├── Controllers/
│   │       ├── Requests/
│   │       └── Resources/
│   └── FarmStructure/
│       ├── Application/
│       │   ├── Actions/
│       │   ├── Queries/
│       │   └── PublicApi/
│       │       ├── Contracts/
│       │       ├── Data/
│       │       └── Queries/
│       ├── Domain/
│       │   ├── Enums/
│       │   ├── Exceptions/
│       │   └── ValueObjects/
│       ├── Http/
│       │   ├── Controllers/
│       │   ├── Requests/
│       │   └── Resources/
│       └── Infrastructure/
│           └── Occupancy/
├── Policies/
│   ├── Geography/
│   └── FarmStructure/
└── Providers/
    └── FarmStructureServiceProvider.php

database/
├── factories/
│   ├── Geography/
│   └── FarmStructure/
├── migrations/
└── seeders/
    └── GeographySeeder.php

tests/
├── Feature/
│   ├── Geography/
│   └── FarmStructure/
└── Unit/
    └── FarmStructure/
```

## Responsabilidad de las capas

| Capa | Responsabilidad | No contiene |
|---|---|---|
| `Application/Actions` | Caso de uso, transacción, locks y auditoría | HTTP o respuestas JSON |
| `Application/Queries` | Lecturas paginadas y filtros | Mutaciones |
| `Application/PublicApi` | Contratos estables para otros módulos | Modelos Eloquent expuestos |
| `Domain` | Estados, transiciones, errores y Value Objects | Controllers o acceso HTTP |
| `Http/Controllers` | Adaptación entre HTTP, Action/Query y Resource | Reglas de negocio |
| `Http/Requests` | Autorización, validación y filtros acotados | Transacciones |
| `Http/Resources` | Contrato de salida | Consultas ocultas |
| `Models` | Persistencia, relaciones, casts y queries propias | Requests o respuestas HTTP |
| `Policies` | Permisos funcionales | Mutaciones |

## Flujo de escritura

```text
StorePoultryHouseRequest
→ PoultryHouseController
→ CreatePoultryHouseAction
→ BirdCapacity
→ PoultryHouse
→ AuditRecorder
→ PoultryHouseResource
```

El `FormRequest` se resuelve, autoriza y valida automáticamente antes de ejecutar el controller. El controller entrega solamente datos validados a la Action. La Action bloquea las filas necesarias, modifica el negocio y registra auditoría dentro de una única transacción.

## Contratos de casos de uso

```text
CreateProductionUnitAction
└── execute(array $attributes, User $actor): ProductionUnit

ChangeProductionUnitStatusAction
└── execute(ProductionUnit $productionUnit, ProductionUnitStatus $status, User $actor): ProductionUnit

CreatePoultryHouseAction
└── execute(ProductionUnit $productionUnit, array $attributes, User $actor): PoultryHouse

ChangePoultryHouseStatusAction
└── execute(PoultryHouse $poultryHouse, PoultryHouseStatus $status, User $actor): PoultryHouse

ListPoultryHousesQuery
└── execute(ProductionUnit $productionUnit, array $filters): LengthAwarePaginator
```

## Capacidad y ocupación

`BirdCapacity` protege una capacidad máxima estrictamente positiva. Ese valor no cambia cuando entran o salen aves.

```text
bird_capacity = capacidad física máxima
occupancy = valor calculado por Lots
available_capacity = bird_capacity - occupancy
```

`PoultryHouseOccupancyProvider` es el contrato público implementado por `LotsPoultryHouseOccupancyProvider`: suma aves vivas, incluida cuarentena. `EmptyPoultryHouseOccupancyProvider` queda como fallback del módulo de Instalaciones cuando Lotes no está registrado. Las Actions consultan el contrato al reducir capacidad o desactivar un galpón. Lotes obtiene referencias bloqueadas y capacidad disponible mediante `LockPoultryHousesQuery`, sin importar los modelos internos de Instalaciones.

## Autorización

Los permisos expresan capacidades funcionales estables:

- `geography.view` y `geography.manage`.
- `production-units.view` y `production-units.manage`.
- `poultry-houses.view` y `poultry-houses.manage`.

Los permisos son globales dentro de la empresa: un usuario con el permiso funcional correspondiente puede consultar u operar sobre todas las unidades productivas. No existe una tabla pivote entre usuarios y unidades, no se filtran consultas por usuario y no se crean permisos como `up_view_123`. Esta decisión debe revisarse únicamente si la organización incorpora una necesidad real de aislamiento por unidad.

## Persistencia PostgreSQL

- Los nombres normalizados protegen la unicidad sin depender de mayúsculas.
- Coordenadas, capacidad y estados tienen restricciones `CHECK`.
- Las FKs de geografía e instalaciones usan `RESTRICT` para preservar referencias históricas.
- No existen `isDeleted`, cascadas destructivas de instalaciones ni endpoints de borrado.
- Los estados se modifican mediante Actions explícitas y enums.

## API de referencia

```text
GET    /api/v1/departamentos
POST   /api/v1/departamentos
PATCH  /api/v1/departamentos/{department}
GET    /api/v1/departamentos/{department}/localidades
POST   /api/v1/departamentos/{department}/localidades
PATCH  /api/v1/localidades/{locality}

GET    /api/v1/unidades-productivas
POST   /api/v1/unidades-productivas
GET    /api/v1/unidades-productivas/{productionUnit}
PATCH  /api/v1/unidades-productivas/{productionUnit}
PATCH  /api/v1/unidades-productivas/{productionUnit}/estado

GET    /api/v1/unidades-productivas/{productionUnit}/galpones
POST   /api/v1/unidades-productivas/{productionUnit}/galpones
GET    /api/v1/galpones/{poultryHouse}
PATCH  /api/v1/galpones/{poultryHouse}
PATCH  /api/v1/galpones/{poultryHouse}/estado
```

## Reglas para módulos nuevos

- Usar identificadores y nombres de clases en inglés.
- Escribir comentarios y PHPDoc en español.
- Crear una Action por operación de negocio y una Query por lectura reutilizable.
- Usar un `FormRequest` custom en todo parámetro `$request` de controllers.
- Mantener transacción y auditoría sincronizada dentro de la Action propietaria.
- No pasar Requests a Application ni modelos Eloquent a otros módulos.
- No crear DTOs, repositorios o interfaces sin una frontera o invariante concreta.
- Publicar efectos secundarios solamente después del commit.
- Probar contrato HTTP, permisos funcionales globales, validación, conflictos, restricciones PostgreSQL y rollback de auditoría.
