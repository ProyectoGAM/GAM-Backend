# GAM — Ejemplo de estructura modular

## 1. Propósito

Este documento muestra cómo crear un módulo de GAM sin implementar su lógica. Define carpetas, archivos base, responsabilidades y reglas de colaboración para que todo el equipo siga el mismo criterio.

El ejemplo utiliza **M03 — Instalaciones** porque permite representar casos de uso, consultas, autorización, persistencia y un Value Object sin introducir todavía la complejidad transaccional de inventario o ventas.

## 2. Alcance del módulo

M03 es dueño de:

- unidades productivas;
- galpones;
- mantenimientos;
- capacidad operativa y estados de las instalaciones.

El módulo no administra usuarios, lotes, stock, ventas ni reportes. Esos módulos sólo pueden consultar Instalaciones mediante su API pública.

En código se utiliza `UnidadProductiva`; `UP` queda como abreviatura de negocio e interfaz.

## 3. Estructura base

```text
app/
├── Modules/Instalaciones/
│   ├── Application/
│   │   ├── Actions/
│   │   │   ├── UnidadProductiva/
│   │   │   │   ├── CrearUnidadProductivaAction.php
│   │   │   │   ├── ActualizarUnidadProductivaAction.php
│   │   │   │   └── DesactivarUnidadProductivaAction.php
│   │   │   ├── Galpon/
│   │   │   │   ├── CrearGalponAction.php
│   │   │   │   ├── ActualizarGalponAction.php
│   │   │   │   └── CambiarEstadoGalponAction.php
│   │   │   └── Mantenimiento/
│   │   │       ├── RegistrarMantenimientoAction.php
│   │   │       └── CompletarMantenimientoAction.php
│   │   └── Queries/
│   │       ├── ListarUnidadesProductivasQuery.php
│   │       ├── ObtenerUnidadProductivaQuery.php
│   │       ├── ListarGalponesQuery.php
│   │       └── ObtenerGalponQuery.php
│   ├── Domain/
│   │   ├── Enums/
│   │   │   ├── EstadoUnidadProductiva.php
│   │   │   ├── EstadoGalpon.php
│   │   │   └── EstadoMantenimiento.php
│   │   ├── ValueObjects/
│   │   │   └── CapacidadAves.php
│   │   ├── Rules/
│   │   │   └── CapacidadOperativaRule.php
│   │   ├── Events/
│   │   │   ├── GalponCreado.php
│   │   │   ├── EstadoGalponCambiado.php
│   │   │   └── MantenimientoRegistrado.php
│   │   └── Exceptions/
│   │       ├── UnidadProductivaInactiva.php
│   │       ├── GalponNoOperativo.php
│   │       └── CapacidadOperativaInvalida.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── UnidadProductivaController.php
│   │   │   ├── GalponController.php
│   │   │   ├── DesactivarUnidadProductivaController.php
│   │   │   ├── CambiarEstadoGalponController.php
│   │   │   ├── RegistrarMantenimientoController.php
│   │   │   └── CompletarMantenimientoController.php
│   │   ├── Requests/
│   │   │   ├── CrearUnidadProductivaRequest.php
│   │   │   ├── ActualizarUnidadProductivaRequest.php
│   │   │   ├── CrearGalponRequest.php
│   │   │   ├── ActualizarGalponRequest.php
│   │   │   ├── CambiarEstadoGalponRequest.php
│   │   │   ├── RegistrarMantenimientoRequest.php
│   │   │   └── CompletarMantenimientoRequest.php
│   │   ├── Resources/
│   │   │   ├── UnidadProductivaResource.php
│   │   │   ├── GalponResource.php
│   │   │   └── MantenimientoResource.php
│   │   └── Routes/api.php
├── Models/Instalaciones/
│   ├── UnidadProductiva.php
│   ├── Galpon.php
│   └── Mantenimiento.php
├── Casts/Instalaciones/
│   └── CapacidadAvesCast.php
├── Policies/Instalaciones/
│   ├── UnidadProductivaPolicy.php
│   ├── GalponPolicy.php
│   └── MantenimientoPolicy.php
└── Providers/
    └── InstalacionesServiceProvider.php
```

`php artisan make:model Galpon` crea `app/Models/Galpon.php`, nunca `app/Http/Models`. Para conservar el contexto se usa `php artisan make:model Instalaciones/Galpon`, que crea `app/Models/Instalaciones/Galpon.php`.

Los modelos continúan siendo propiedad de M03 aunque sigan la ubicación convencional de Laravel. Ningún otro módulo puede importarlos directamente.

Archivos externos propiedad del módulo:

```text
tests/
├── Feature/Instalaciones/
│   └── GalponControllerTest.php
└── Unit/Instalaciones/
    ├── CapacidadAvesTest.php
    └── CrearGalponActionTest.php

database/
├── factories/Instalaciones/
│   ├── UnidadProductivaFactory.php
│   ├── GalponFactory.php
│   └── MantenimientoFactory.php
├── migrations/instalaciones/
│   ├── create_unidades_productivas_table.php
│   ├── create_galpones_table.php
│   └── create_mantenimientos_table.php
└── seeders/
    └── InstalacionesSeeder.php

contracts/openapi/v1/
├── paths/instalaciones.yaml
└── schemas/instalaciones.yaml
```

## 4. Responsabilidad de cada capa

| Capa | Responsabilidad | No debe contener |
|---|---|---|
| `Application/Actions` | Casos de uso, transacciones y coordinación | HTTP, respuestas JSON o lógica visual |
| `Application/Queries` | Lecturas y filtros sin modificar estado | Mutaciones o efectos secundarios |
| `Domain` | Invariantes, estados, Value Objects y eventos | Controllers, Resources o acceso HTTP |
| `Http/Controllers` | Adaptar HTTP y delegar | Reglas, cálculos o transacciones |
| `Http/Requests` | Validar estructura, tipos y formato | Reglas transaccionales o consultas complejas |
| `Http/Resources` | Definir la respuesta del contrato API | Validación de entrada o reglas de negocio |
| `Models` | Entidades Eloquent, relaciones, fillable y casts | HTTP o reglas entre casos de uso |
| `Casts`, `Policies`, `Providers` | Integración con Laravel | Reglas residuales o respuestas JSON |
| `Tests` | Verificar contrato, casos de uso e invariantes | Pruebas de otros módulos |

## 5. Contratos base de los casos de uso

Los archivos se crean inicialmente con su namespace, dependencias y firma pública, pero sin lógica hasta implementar el caso de uso.

```text
CrearUnidadProductivaAction
└── execute(array $attributes, User $actor): UnidadProductiva

CrearGalponAction
└── execute(array $attributes, User $actor): Galpon

CambiarEstadoGalponAction
└── execute(Galpon $galpon, EstadoGalpon $estado, User $actor): Galpon

RegistrarMantenimientoAction
└── execute(array $attributes, User $actor): Mantenimiento

ListarGalponesQuery
└── execute(UnidadProductiva $unidad, array $filters, User $actor): LengthAwarePaginator
```

Las firmas pueden refinarse durante la implementación, pero el Request nunca se entrega completo a Application.

## 6. Un ejemplo por tipo de archivo

Los siguientes fragmentos muestran la forma esperada de cada pieza. Son ejemplos mínimos para orientar al equipo, no una implementación completa del módulo.

### Action: `CrearGalponAction`

```php
namespace App\Modules\Instalaciones\Application\Actions\Galpon;

use App\Modules\Instalaciones\Domain\Enums\EstadoGalpon;
use App\Modules\Instalaciones\Domain\Events\GalponCreado;
use App\Modules\Instalaciones\Domain\ValueObjects\CapacidadAves;
use App\Models\Instalaciones\Galpon;
use App\Models\Instalaciones\UnidadProductiva;
use Illuminate\Support\Facades\DB;

final readonly class CrearGalponAction
{
    public function execute(
        UnidadProductiva $unidad,
        array $attributes,
        int $actorId,
    ): Galpon {
        return DB::transaction(function () use ($unidad, $attributes, $actorId) {
            $capacidad = CapacidadAves::desdeEntero($attributes['capacidad_aves']);

            $galpon = $unidad->galpones()->create([
                'nombre' => $attributes['nombre'],
                'capacidad_aves' => $capacidad,
                'estado' => EstadoGalpon::OPERATIVO,
            ]);

            GalponCreado::dispatch((int) $galpon->getKey(), (int) $unidad->getKey(), $actorId);

            return $galpon;
        });
    }
}
```

La Action coordina la transacción, utiliza el VO, persiste mediante Eloquent y publica el evento. La validación HTTP permanece en el FormRequest.

### Query: `ObtenerGalponQuery`

```php
namespace App\Modules\Instalaciones\Application\Queries;

use App\Models\Instalaciones\Galpon;

final readonly class ObtenerGalponQuery
{
    public function execute(int $galponId, int $unidadProductivaId): Galpon
    {
        return Galpon::query()
            ->where('unidad_productiva_id', $unidadProductivaId)
            ->with('unidadProductiva')
            ->findOrFail($galponId);
    }
}
```

La Query sólo lee. El alcance recibido debe provenir del contexto autorizado del usuario.

### Enum: `EstadoGalpon`

```php
namespace App\Modules\Instalaciones\Domain\Enums;

enum EstadoGalpon: string
{
    case OPERATIVO = 'operativo';
    case MANTENIMIENTO = 'mantenimiento';
    case FUERA_DE_SERVICIO = 'fuera_de_servicio';
    case INACTIVO = 'inactivo';
}
```

El enum evita estados escritos libremente. Las transiciones permitidas se validan en Domain/Application.

### Value Object: `CapacidadAves`

```php
namespace App\Modules\Instalaciones\Domain\ValueObjects;

use App\Modules\Instalaciones\Domain\Exceptions\CapacidadOperativaInvalida;

final readonly class CapacidadAves
{
    private function __construct(private int $valor) {}

    public static function desdeEntero(int $valor): self
    {
        if ($valor < 0) {
            throw new CapacidadOperativaInvalida();
        }

        return new self($valor);
    }

    public function valor(): int
    {
        return $this->valor;
    }

    public function admiteOcupacion(int $cantidadAves): bool
    {
        return $cantidadAves >= 0 && $cantidadAves <= $this->valor;
    }
}
```

El VO no tiene identidad ni persistencia propia; protege el significado y las reglas de la capacidad.

### Rule: `CapacidadOperativaRule`

```php
namespace App\Modules\Instalaciones\Domain\Rules;

use App\Modules\Instalaciones\Domain\Exceptions\CapacidadOperativaInvalida;
use App\Modules\Instalaciones\Domain\ValueObjects\CapacidadAves;

final readonly class CapacidadOperativaRule
{
    public function validar(
        CapacidadAves $capacidad,
        int $ocupacionActual,
        int $avesAAgregar,
    ): void {
        $ocupacionResultante = $ocupacionActual + $avesAAgregar;

        if (!$capacidad->admiteOcupacion($ocupacionResultante)) {
            throw new CapacidadOperativaInvalida();
        }
    }
}
```

Una Rule combina varios valores para verificar una invariante que no pertenece a un único campo.

### Event: `GalponCreado`

```php
namespace App\Modules\Instalaciones\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class GalponCreado implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $galponId,
        public readonly int $unidadProductivaId,
        public readonly int $actorId,
    ) {}
}
```

El evento transporta identificadores y se publica después del commit; no contiene modelos Eloquent ni ejecuta efectos secundarios.

### Exception: `CapacidadOperativaInvalida`

```php
namespace App\Modules\Instalaciones\Domain\Exceptions;

use DomainException;

final class CapacidadOperativaInvalida extends DomainException
{
    public function __construct()
    {
        parent::__construct('La capacidad operativa indicada no es válida.');
    }
}
```

La excepción expresa un error del dominio. La capa HTTP la transforma después en un `409` o `422` homogéneo.

### Request: `CrearGalponRequest`

```php
namespace App\Modules\Instalaciones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CrearGalponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'capacidad_aves' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

El Request valida forma y tipos. Devuelve `true` en `authorize()` porque la Policy se ejecuta explícitamente en el controller.

### Controller: `GalponController`

```php
namespace App\Modules\Instalaciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Instalaciones\Galpon;
use App\Models\Instalaciones\UnidadProductiva;
use App\Modules\Instalaciones\Application\Actions\Galpon\CrearGalponAction;
use App\Modules\Instalaciones\Http\Requests\CrearGalponRequest;
use App\Modules\Instalaciones\Http\Resources\GalponResource;
use Illuminate\Support\Facades\Gate;

final class GalponController extends Controller
{
    public function store(
        CrearGalponRequest $request,
        UnidadProductiva $unidadProductiva,
        CrearGalponAction $action,
    ): GalponResource {
        Gate::authorize('create', [Galpon::class, $unidadProductiva]);

        $galpon = $action->execute(
            $unidadProductiva,
            $request->validated(),
            (int) $request->user()->getAuthIdentifier(),
        );

        return new GalponResource($galpon);
    }
}
```

El controller autoriza, adapta la entrada, invoca la Action y selecciona el Resource. No abre transacciones ni calcula capacidad.

### Resource: `GalponResource`

```php
namespace App\Modules\Instalaciones\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GalponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'unidad_productiva_id' => (int) $this->unidad_productiva_id,
            'nombre' => $this->nombre,
            'capacidad_aves' => $this->capacidad_aves->valor(),
            'estado' => $this->estado->value,
        ];
    }
}
```

El Resource convierte Eloquent, enums y Value Objects a primitivas definidas por OpenAPI.

### Model: `Galpon`

```php
namespace App\Models\Instalaciones;

use App\Casts\Instalaciones\CapacidadAvesCast;
use App\Modules\Instalaciones\Domain\Enums\EstadoGalpon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Galpon extends Model
{
    protected $table = 'galpones';

    protected $fillable = [
        'unidad_productiva_id',
        'nombre',
        'capacidad_aves',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'capacidad_aves' => CapacidadAvesCast::class,
            'estado' => EstadoGalpon::class,
        ];
    }

    public function unidadProductiva(): BelongsTo
    {
        return $this->belongsTo(UnidadProductiva::class);
    }
}
```

El modelo representa persistencia, relaciones y casts. No se ubica dentro de `Http`.

### Cast: `CapacidadAvesCast`

```php
namespace App\Casts\Instalaciones;

use App\Modules\Instalaciones\Domain\ValueObjects\CapacidadAves;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class CapacidadAvesCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): CapacidadAves
    {
        return CapacidadAves::desdeEntero((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        $capacidad = $value instanceof CapacidadAves
            ? $value
            : CapacidadAves::desdeEntero((int) $value);

        return $capacidad->valor();
    }
}
```

El Cast adapta MySQL y Eloquent al VO; no decide reglas de un caso de uso.

### Policy: `GalponPolicy`

```php
namespace App\Policies\Instalaciones;

use App\Models\Instalaciones\UnidadProductiva;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final readonly class GalponPolicy
{
    public function create(Authenticatable $actor, UnidadProductiva $unidad): bool
    {
        return Gate::forUser($actor)->allows('instalaciones.galpones.crear')
            && Gate::forUser($actor)->allows(
                'acceder-unidad-productiva',
                (int) $unidad->getKey(),
            );
    }
}
```

La Policy combina el permiso funcional y el alcance por UP. Las abilities utilizadas son registradas por M01.

### Provider: `InstalacionesServiceProvider`

```php
namespace App\Providers;

use App\Models\Instalaciones\Galpon;
use App\Policies\Instalaciones\GalponPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class InstalacionesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Galpon::class, GalponPolicy::class);

        $this->loadMigrationsFrom(
            database_path('migrations/instalaciones'),
        );
    }
}
```

El Provider registra la integración del módulo con Laravel. Se agrega una sola vez en `bootstrap/providers.php`.

### Test: `CapacidadAvesTest`

```php
namespace Tests\Unit\Instalaciones;

use App\Modules\Instalaciones\Domain\Exceptions\CapacidadOperativaInvalida;
use App\Modules\Instalaciones\Domain\ValueObjects\CapacidadAves;
use PHPUnit\Framework\TestCase;

final class CapacidadAvesTest extends TestCase
{
    public function test_rechaza_una_capacidad_negativa(): void
    {
        $this->expectException(CapacidadOperativaInvalida::class);

        CapacidadAves::desdeEntero(-1);
    }
}
```

El test Unit verifica una invariante sin levantar Laravel ni acceder a MySQL.

## 7. Flujo esperado

### Crear un galpón

```text
CrearGalponRequest
→ GalponController
→ CrearGalponAction
→ CapacidadAves
→ Galpon de Eloquent
→ GalponResource
```

### Cambiar el estado de un galpón

```text
CambiarEstadoGalponRequest
→ CambiarEstadoGalponController
→ CambiarEstadoGalponAction
→ EstadoGalponCambiado
→ GalponResource
```

El evento se publica después del commit. El controller no decide si una transición está permitida.

## 8. Uso del Value Object

`CapacidadAves` se justifica porque representa una cantidad no negativa, permite comparar capacidad máxima con ocupación y evita repetir esa validación.

```text
CapacidadAves
├── valor entero no negativo
├── admiteOcupacion(int $cantidadAves)
├── disponibleRespectoA(int $cantidadAves)
└── se compara por valor
```

`CapacidadAvesCast` transforma la columna de MySQL al Value Object y viceversa. El modelo conserva identidad y persistencia; el Value Object protege el significado de la capacidad.

No se crean Value Objects para `nombre`, `descripcion` u `observaciones` mientras no tengan reglas reales.

## 9. DTOs: sólo cuando aplican

Crear una unidad productiva o un galpón no necesita DTO inicialmente. El controller puede entregar `$request->validated()` o parámetros tipados a la Action.

Cuando M05 necesite consultar un galpón, se agrega una API pública explícita:

```text
app/Modules/Instalaciones/Application/PublicApi/
├── Data/
│   └── GalponOperativoData.php
└── Queries/
    └── ObtenerGalponOperativoQuery.php
```

`GalponOperativoData` contiene únicamente el contrato permitido:

```text
galponId
unidadProductivaId
estado
capacidadMaxima
capacidadDisponible
```

M05 puede importar `PublicApi`, pero nunca `App\Models\Instalaciones\Galpon`.

Otro caso donde sí aplica un DTO es una venta con líneas anidadas:

```text
Application/Actions/Venta/Registrar/
├── RegistrarVentaAction.php
├── RegistrarVentaData.php
└── RegistrarVentaLineaData.php
```

El DTO se agrega por la complejidad del comando, no como requisito general de la arquitectura.

## 10. Rutas iniciales

```text
GET    /api/v1/unidades-productivas
POST   /api/v1/unidades-productivas
GET    /api/v1/unidades-productivas/{unidadProductiva}
PATCH  /api/v1/unidades-productivas/{unidadProductiva}
POST   /api/v1/unidades-productivas/{unidadProductiva}/desactivar

GET    /api/v1/unidades-productivas/{unidadProductiva}/galpones
POST   /api/v1/unidades-productivas/{unidadProductiva}/galpones
GET    /api/v1/galpones/{galpon}
PATCH  /api/v1/galpones/{galpon}
POST   /api/v1/galpones/{galpon}/cambiar-estado

POST   /api/v1/galpones/{galpon}/mantenimientos
POST   /api/v1/mantenimientos/{mantenimiento}/completar
```

Las transiciones del negocio usan comandos explícitos. No se utiliza `DELETE` para desactivar instalaciones ni corregir registros históricos.

## 11. Registro en Laravel

| Archivo | Preparación necesaria |
|---|---|
| `bootstrap/app.php` | Registrar `routes/api.php` |
| `routes/api.php` | Aplicar `/api/v1` e importar las rutas del módulo |
| `bootstrap/providers.php` | Registrar `InstalacionesServiceProvider` |
| `InstalacionesServiceProvider` | Cargar migraciones, Policies, listeners y bindings |

`composer.json` no requiere un namespace adicional porque `App\` ya cubre `app/Modules`.

## 12. Reglas para el equipo

- Usar nombres del negocio en español y sufijos técnicos consistentes: `Action`, `Query`, `Request`, `Resource` y `Policy`.
- Crear una Action por caso de uso, no por cada operación genérica de tabla.
- Mantener Controllers y Resources sin reglas de negocio.
- No pasar Requests a Application ni modelos Eloquent a otros módulos.
- No crear DTOs, Value Objects, repositorios o interfaces sin una necesidad concreta.
- No usar carpetas genéricas como `Helpers`, `Utils` o `Services` para acumular lógica.
- Usar transacciones y locks dentro de la Action que modifica estado crítico.
- Publicar eventos únicamente después del commit.
- Mantener las pruebas en `tests/Feature/<Modulo>` y `tests/Unit/<Modulo>`.
- Agregar pruebas Feature para HTTP y Unit para Actions, reglas y Value Objects.
- Actualizar OpenAPI cuando cambie una entrada o respuesta pública.

Esta estructura funciona como plantilla. Los demás módulos copian sus límites y convenciones, pero sólo crean los archivos necesarios para sus propios casos de uso.
