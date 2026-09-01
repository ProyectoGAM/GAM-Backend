GAM: http://localhost:8080

Estado de la aplicación: http://localhost:8080/estado

Mailpit: http://localhost:8025

Horizon: http://localhost:8080/horizon

Pulse: http://localhost:8080/pulse


[notion.md](notion.md) => módulos implementados pendientes de reflejar en el Kanban de Notion

architecture.md => arquitectura

libraries.md => paquetes/librerias usadas

module-structure-example.md => ejemplo de estructura y alguna que otra aplicacion minima

[maintenance-implementation.md](maintenance-implementation.md) => implementación e histórico de mantenimientos de galpones

[lots-implementation.md](lots-implementation.md) => implementación e histórico de lotes y crías

[egg-production-implementation.md](egg-production-implementation.md) => implementación e histórico de producción y stock de huevos

[contracts/openapi/authentication.yaml](contracts/openapi/authentication.yaml) => contrato API de identidad y acceso

[contracts/openapi/reference-data.yaml](contracts/openapi/reference-data.yaml) => catálogos dinámicos para formularios y filtros

Swagger UI (desarrollo): [http://localhost:8080/docs/](http://localhost:8080/docs/)

La documentación se sirve desde el servicio `swagger-ui` de Compose y permite
seleccionar los contratos de autenticación, mantenimientos y reporting. El
botón **Authorize** usa el token Bearer emitido por el login.

docker compose -f compose.dev.yaml up -d --build

## Tests y Artisan

La estrategia oficial de testing ejecuta PHPUnit dentro del contenedor `api`. PHPUnit usa `DB_HOST=postgres` y la base aislada `gam_test`; las credenciales se heredan del servicio PostgreSQL definido en Compose.

```bash
docker compose -f compose.dev.yaml exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=gam_test \
  -e CACHE_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  -e MAIL_MAILER=array \
  -e BROADCAST_CONNECTION=null \
  -e PULSE_ENABLED=false \
  -e TELESCOPE_ENABLED=false \
  -e NIGHTWATCH_ENABLED=false \
  api vendor/bin/phpunit --configuration phpunit.xml
docker compose -f compose.dev.yaml exec api php artisan optimize:clear
```

Las variables de testing se inyectan antes de iniciar PHP para que el bootstrap de Laravel no pueda tomar la base de desarrollo del contenedor.

El init script de PostgreSQL crea `gam_test` únicamente cuando se inicializa el volumen por primera vez. Si el volumen ya existe y la base aún no fue creada, ejecutá una vez:

```bash
docker compose -f compose.dev.yaml exec -T postgres sh -lc 'psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE gam_test;"'
```

No ejecutes `docker compose down -v`: elimina los volúmenes y los datos existentes.
## Datos de prueba locales

Cuando `APP_ENV=local`, `DatabaseSeeder` ejecuta también `LocalDemoDataSeeder` y carga datos ficticios pero coherentes de granjas, galpones, proveedores, productos, inventario, reservas, reportes y lotes con redistribuciones, mortalidad y recolección. La carga es idempotente y no se ejecuta en otros ambientes.

Para reconstruir la base local desde cero:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

Cada módulo nuevo debe incluir su seeder de datos demo y registrarlo en `LocalDemoDataSeeder` (o en un seeder del módulo invocado por este), para que sus datos estén disponibles automáticamente cuando el ambiente sea local.
