GAM: http://localhost:8080

Estado de la aplicación: http://localhost:8080/status

Mailpit: http://localhost:8025

Horizon: http://localhost:8080/horizon

Pulse: http://localhost:8080/pulse


[notion.md](notion.md) => seguimiento de módulos completos y parcialmente implementados en el Kanban de Notion

architecture.md => arquitectura

libraries.md => paquetes/librerias usadas

module-structure-example.md => ejemplo de estructura y alguna que otra aplicacion minima

[maintenance-implementation.md](maintenance-implementation.md) => implementación e histórico de mantenimientos de galpones

[lots-implementation.md](lots-implementation.md) => implementación e histórico de lotes y crías

[egg-production-implementation.md](egg-production-implementation.md) => implementación e histórico de producción y stock de huevos

[medication-implementation-plan.md](medication-implementation-plan.md) => catálogo de medicamentos implementado: alta y consulta sólo para administradores; avance parcial del módulo 06

[contracts/openapi/medication.yaml](contracts/openapi/medication.yaml) => contrato API del catálogo de medicamentos

[contracts/openapi/authentication.yaml](contracts/openapi/authentication.yaml) => contrato API de identidad y acceso

[contracts/openapi/reference-data.yaml](contracts/openapi/reference-data.yaml) => catálogos dinámicos para formularios y filtros

Swagger UI (desarrollo): [http://localhost:8080/docs/](http://localhost:8080/docs/)

La documentación se sirve desde el servicio `swagger-ui` de Compose y permite
seleccionar los contratos de autenticación, Lotes/producción de huevos,
mantenimientos, medicamentos y reporting. El
botón **Authorize** usa el token Bearer emitido por el login.

docker compose -f compose.dev.yaml up -d --build

## Tests y Artisan

La estrategia oficial de testing ejecuta PHPUnit dentro del contenedor `api`. PHPUnit usa PostgreSQL en `DB_HOST=postgres` y deriva la base aislada agregando `_testing` al `DB_DATABASE` normal del entorno. Con la configuración local actual, la base normal es `sga_backend` y la de testing es `sga_backend_testing`; las credenciales se heredan del servicio PostgreSQL definido en Compose.

```bash
docker compose -f compose.dev.yaml exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e CACHE_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  -e MAIL_MAILER=array \
  -e BROADCAST_CONNECTION=null \
  -e PULSE_ENABLED=false \
  -e TELESCOPE_ENABLED=false \
  -e NIGHTWATCH_ENABLED=false \
  -e IDENTITY_PIN_PEPPER= \
  api php artisan test --compact
docker compose -f compose.dev.yaml exec api php artisan optimize:clear
```

El bootstrap de PHPUnit conserva la conexión PostgreSQL y transforma el nombre normal configurado en `<DB_DATABASE>_testing`. Además, `Tests\TestCase` aborta antes de los traits de base de datos si `APP_ENV` no es `testing`, la conexión no es PostgreSQL o la base efectiva no termina en `_testing`.

Compose crea esa base automáticamente dentro de la instancia PostgreSQL existente mediante `postgres-test-database`; el servicio es idempotente y también cubre volúmenes ya inicializados. Para verificar la conexión efectiva:

```bash
docker compose -f compose.dev.yaml exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=sga_backend_testing \
  api php artisan config:show database.default
docker compose -f compose.dev.yaml exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=sga_backend_testing \
  api php artisan config:show database.connections.pgsql.database
```

La salida debe ser `pgsql` y `sga_backend_testing` (la base normal local es `sga_backend`; en otro entorno, sustituir ambos nombres por `<DB_DATABASE>` y `<DB_DATABASE>_testing`).

Para la comprobación adicional con un pepper temporal no persistido, reemplazá el valor vacío por `-e IDENTITY_PIN_PEPPER=test-only-temporary-value`.

No ejecutes `docker compose down -v`: elimina los volúmenes y los datos existentes.
## Datos de prueba locales

Cuando `APP_ENV=local`, `DatabaseSeeder` ejecuta también `LocalDemoDataSeeder` y carga datos ficticios pero coherentes de granjas, galpones, proveedores, productos, medicamentos, inventario, reservas, reportes y lotes con redistribuciones, mortalidad y recolección. La carga es idempotente y no se ejecuta en otros ambientes.

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
