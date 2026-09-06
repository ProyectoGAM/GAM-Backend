GAM: http://localhost:8080

Estado de la aplicación: http://localhost:8080/estado

Mailpit: http://localhost:8025

Horizon: http://localhost:8080/horizon

Pulse: http://localhost:8080/pulse


architecture.md => arquitectura

libraries.md => paquetes/librerias usadas

module-structure-example.md => ejemplo de estructura y alguna que otra aplicacion minima

docker compose -f compose.dev.yaml up -d --build

## Datos de prueba locales

Cuando `APP_ENV=local`, `DatabaseSeeder` ejecuta también `LocalDemoDataSeeder` y carga datos ficticios pero coherentes de granjas, galpones, proveedores, productos, inventario, reservas y reportes. La carga es idempotente y no se ejecuta en otros ambientes.

Para reconstruir la base local desde cero:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

Cada módulo nuevo debe incluir su seeder de datos demo y registrarlo en `LocalDemoDataSeeder` (o en un seeder del módulo invocado por este), para que sus datos estén disponibles automáticamente cuando el ambiente sea local.

## Autenticación multi-login

Define ADMIN_PASSWORD e IDENTITY_PIN_PEPPER en el entorno antes de sembrar datos. La web usa cookies stateful y CSRF; nativo usa PAT Bearer. El acceso compartido se vincula con un código de 10 caracteres y conserva una credencial de dispositivo independiente.

La guía operativa y las rutas están en identity-access-implementation.md. El contrato OpenAPI está en contracts/openapi/authentication.yaml.

Pruebas backend con Docker Compose:

    docker compose -f compose.dev.yaml exec -T api vendor/bin/phpunit --configuration phpunit.xml tests/Feature/IdentityAndAccess

Si se revoca un dispositivo, hay que vincularlo otra vez; si se deshabilita o cambia el PIN, el empleado vuelve al selector aunque la tablet siga vinculada.
