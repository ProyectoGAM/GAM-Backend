# GAM — Librerías del backend

## 1. Objetivo

Este documento registra las librerías aprobadas para el backend Laravel de GAM, el problema que resuelve cada una y un ejemplo mínimo de uso.

Las librerías complementan la arquitectura, pero no reemplazan las reglas del dominio:

- los permisos también deben validar el alcance por Unidad Productiva mediante Policies;
- Activitylog no reemplaza los movimientos de inventario o cuenta corriente;
- Horizon y Pulse no reemplazan logs, health checks ni manejo correcto de errores;
- Brick Money será utilizado detrás del Value Object `Money` propio de GAM;
- los filtros de Query Builder deben coincidir con el contrato OpenAPI.

Las versiones definitivas se resolverán según las versiones de PHP y Laravel del proyecto y quedarán fijadas en `composer.lock`.

## 2. Resumen

| Paquete | Entorno | Uso principal |
|---|---|---|
| `laravel/pulse` | Producción | Rendimiento y actividad de la aplicación |
| `laravel/horizon` | Producción | Administración de workers y colas Redis |
| `laravel/sanctum` | Producción | Autenticación de la SPA y futuros clientes móviles |
| `spatie/laravel-medialibrary` | Producción | Archivos asociados a modelos Eloquent |
| `spatie/laravel-permission` | Producción | Roles y permisos |
| `spatie/laravel-backup` | Producción | Backups de base de datos y archivos |
| `spatie/laravel-health` | Producción | Comprobaciones de salud de la aplicación |
| `spatie/simple-excel` | Producción | Importación y exportación CSV/XLSX |
| `spatie/laravel-activitylog` | Producción | Auditoría de acciones relevantes |
| `spatie/laravel-query-builder` | Producción | Filtros y ordenamiento controlados en endpoints API |
| `brick/money` | Producción | Cálculos monetarios exactos |
| `larastan/larastan` | Desarrollo | Análisis estático de código Laravel |

## 3. Librerías de producción

### 3.1 Laravel Pulse

- **Paquete:** `laravel/pulse`
- **Repositorio:** [laravel/pulse](https://github.com/laravel/pulse)
- **Uso en GAM:** observar consultas lentas, endpoints, excepciones, jobs y comportamiento general de la aplicación.

El dashboard debe ser privado. Por ejemplo, el acceso puede limitarse mediante el gate esperado por Pulse:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewPulse', function (User $user): bool {
    return $user->can('monitoreo.ver');
});
```

Pulse sirve para observar la aplicación; no reemplaza Horizon, Health ni una plataforma externa de logs.

### 3.2 Laravel Horizon

- **Paquete:** `laravel/horizon`
- **Repositorio:** [laravel/horizon](https://github.com/laravel/horizon)
- **Uso en GAM:** administrar workers Redis, supervisar jobs y separar las colas `critical`, `default` y `reporting`.

Una exportación de reporte puede enviarse a su cola correspondiente:

```php
GenerarReporteProduccion::dispatch($reporteId)
    ->onQueue('reporting')
    ->afterCommit();
```

El dashboard de Horizon también debe protegerse mediante autorización. Los jobs continúan necesitando idempotencia, timeout, reintentos y backoff.

### 3.3 Laravel Sanctum

- **Paquete:** `laravel/sanctum`
- **Repositorio:** [laravel/sanctum](https://github.com/laravel/sanctum)
- **Uso en GAM:** autenticar Angular mediante cookies stateful y emitir tokens limitados para futuros clientes móviles.

Ejemplo de una ruta privada:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/v1/me', function (Request $request) {
    return $request->user();
});
```

Para la web se utilizarán cookies `HttpOnly`, `Secure`, CSRF y orígenes CORS explícitos. Los permisos se validarán después de autenticar al usuario.

### 3.4 Spatie Laravel Media Library

- **Paquete:** `spatie/laravel-medialibrary`
- **Repositorio:** [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary)
- **Uso en GAM:** asociar fotos, comprobantes o documentos sanitarios con modelos Eloquent.

Ejemplo de una evidencia adjunta a un mantenimiento:

```php
$mantenimiento
    ->addMedia($request->file('evidencia'))
    ->toMediaCollection('evidencias');
```

El modelo propietario implementará `HasMedia` y usará `InteractsWithMedia`. El backend debe validar tamaño, MIME, permisos y acceso al archivo; la librería no hace pública una colección automáticamente.

### 3.5 Spatie Laravel Permission

- **Paquete:** `spatie/laravel-permission`
- **Repositorio:** [spatie/laravel-permission](https://github.com/spatie/laravel-permission)
- **Uso en GAM:** administrar roles y permisos del módulo M01.

Ejemplo de asignación:

```php
$usuario->assignRole('administrador');
$usuario->givePermissionTo('repartos.iniciar');
```

Ejemplo de autorización desde una Policy o Action:

```php
if (! $usuario->can('repartos.iniciar')) {
    throw new AuthorizationException();
}
```

El paquete resuelve RBAC, pero no garantiza que el usuario tenga acceso a la UP del reparto. Ese alcance se comprobará con Policies y relaciones propias de GAM.

### 3.6 Spatie Laravel Backup

- **Paquete:** `spatie/laravel-backup`
- **Repositorio:** [spatie/laravel-backup](https://github.com/spatie/laravel-backup)
- **Uso en GAM:** generar backups de MySQL y archivos relevantes y enviarlos a almacenamiento externo.

Ejemplo de planificación en `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run --only-db')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('backup:clean')
    ->dailyAt('03:00');
```

El contenedor deberá tener `mysqldump` y la extensión ZIP. Los backups deben almacenarse fuera del servidor principal y probarse mediante restauraciones periódicas.

### 3.7 Spatie Laravel Health

- **Paquete:** `spatie/laravel-health`
- **Repositorio:** [spatie/laravel-health](https://github.com/spatie/laravel-health)
- **Uso en GAM:** verificar MySQL, Redis, espacio en disco, backups, colas y scheduler.

Ejemplo de registro de checks:

```php
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

Health::checks([
    DatabaseCheck::new(),
    RedisCheck::new(),
    UsedDiskSpaceCheck::new()
        ->warnWhenUsedSpaceIsAbovePercentage(70),
]);
```

La readiness puede comprobar dependencias externas. La liveness debe ser una ruta mínima que no provoque reinicios por una caída temporal de MySQL o Redis.

### 3.8 Spatie Simple Excel

- **Paquete:** `spatie/simple-excel`
- **Repositorio:** [spatie/simple-excel](https://github.com/spatie/simple-excel)
- **Uso en GAM:** importar o exportar archivos CSV y XLSX simples, especialmente desde M10.

Ejemplo de exportación:

```php
use Spatie\SimpleExcel\SimpleExcelWriter;

return SimpleExcelWriter::streamDownload('produccion.xlsx')
    ->addRows($filas)
    ->toBrowser();
```

Los reportes grandes se generarán en la cola `reporting`. Los datos y permisos se validarán en el backend antes de producir el archivo.

### 3.9 Spatie Laravel Activitylog

- **Paquete:** `spatie/laravel-activitylog`
- **Repositorio:** [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog)
- **Uso en GAM:** auditar cambios sensibles de permisos, stock, repartos, ventas y cobros.

Ejemplo de auditoría explícita al confirmar una venta:

```php
activity('ventas')
    ->performedOn($venta)
    ->causedBy($usuario)
    ->event('confirmada')
    ->withProperties([
        'up_id' => $venta->up_id,
        'total' => (string) $venta->total,
    ])
    ->log('Venta confirmada');
```

No deben registrarse contraseñas, tokens, cookies ni datos sensibles innecesarios. El activity log no sustituye los movimientos inmutables ni los contramovimientos del dominio.

### 3.10 Spatie Laravel Query Builder

- **Paquete:** `spatie/laravel-query-builder`
- **Repositorio:** [spatie/laravel-query-builder](https://github.com/spatie/laravel-query-builder)
- **Uso en GAM:** implementar filtros, ordenamiento e inclusiones permitidas en listados API sin aceptar parámetros arbitrarios.

Ejemplo de una Query de ventas:

```php
use App\Models\Ventas\Venta;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

$ventas = QueryBuilder::for(Venta::query())
    ->allowedFilters([
        AllowedFilter::exact('estado'),
        AllowedFilter::exact('up_id'),
    ])
    ->allowedSorts(['fecha', 'total'])
    ->defaultSort('-fecha')
    ->paginate();
```

Los filtros e includes permitidos deben estar documentados en OpenAPI. El alcance por UP debe aplicarse antes o dentro de la Query y nunca confiarse a parámetros enviados por el cliente.

### 3.11 Brick Money

- **Paquete:** `brick/money`
- **Repositorio:** [brick/money](https://github.com/brick/money)
- **Uso en GAM:** realizar cálculos monetarios exactos sin `float` ni `double`.

Ejemplo conceptual de cálculo:

```php
use Brick\Money\Money as BrickMoney;

$precioUnitario = BrickMoney::of('125.50', 'UYU');
$total = $precioUnitario->multipliedBy(12);

$importeDecimal = (string) $total->getAmount(); // 1506.00
```

El resto de GAM no debería depender directamente de `BrickMoney`. El Value Object `App\Shared\Domain\ValueObjects\Money` encapsulará la librería, impondrá la moneda y definirá las reglas de redondeo.

## 4. Librería de desarrollo

### 4.1 Larastan

- **Paquete:** `larastan/larastan`
- **Repositorio:** [larastan/larastan](https://github.com/larastan/larastan)
- **Uso en GAM:** detectar errores de tipos, relaciones Eloquent, propiedades inexistentes y retornos incompatibles sin ejecutar la aplicación.

Configuración mínima en `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
    level: 6
```

Ejecución:

```bash
./vendor/bin/phpstan analyse
```

El nivel puede aumentarse gradualmente. Larastan se instalará con `composer require --dev` y deberá ejecutarse en CI.

## 5. Paquetes no incluidos

Los siguientes paquetes fueron evaluados y no forman parte del conjunto actual:

| Paquete | Motivo |
|---|---|
| `spatie/data-transfer-object` | Está discontinuado y archivado |
| `spatie/laravel-data` | FormRequests, Resources y objetos tipados puntuales son suficientes por ahora |
| `spatie/laravel-settings` | La configuración actual se mantendrá en los archivos de `config/` |
| `spatie/laravel-ciphersweet` | No existe actualmente un caso que necesite cifrado consultable |
| `spatie/laravel-image-optimizer` | El volumen previsto de imágenes no lo justifica |
| `spatie/laravel-prometheus` | No se utilizará Prometheus |
| `deptrac/deptrac` | No se incorporará por ahora |
| `sentry/sentry-laravel` | Se evaluará más adelante si se necesita monitoreo externo de errores |

## 6. Reglas de integración

- Se utilizará el auto-discovery de Laravel cuando el paquete lo soporte.
- Sólo se publicarán configuraciones y migraciones que realmente necesitemos modificar.
- Los modelos propios continuarán en `app/Models/<Modulo>`.
- Los tests propios continuarán en `tests/Feature/<Modulo>` y `tests/Unit/<Modulo>`.
- Los dashboards de Pulse y Horizon y los detalles de Health nunca serán públicos.
- Toda migración publicada será revisada antes de ejecutarse.
- No se agregarán paquetes con `dev-main`; Composer resolverá una versión estable compatible.
- `composer.lock` debe mantenerse versionado.
