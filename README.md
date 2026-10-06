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

[weighing-implementation.md](weighing-implementation.md) => implementación de pesajes individuales y grupales, configuración de referencia, correcciones, distribución y evolución; avance parcial del módulo 06

[medication-implementation-plan.md](medication-implementation-plan.md) => catálogo de medicamentos implementado: alta y consulta sólo para administradores; avance parcial del módulo 06

[contracts/openapi/medication.yaml](contracts/openapi/medication.yaml) => contrato API del catálogo de medicamentos

[contracts/openapi/weighings.yaml](contracts/openapi/weighings.yaml) => contrato API de pesajes y configuración global de referencia

[contracts/openapi/management-plans.yaml](contracts/openapi/management-plans.yaml) => contrato API de plantillas, planes de lote, ejecuciones e historial de manejos

[contracts/openapi/authentication.yaml](contracts/openapi/authentication.yaml) => contrato API de identidad y acceso

[contracts/openapi/reference-data.yaml](contracts/openapi/reference-data.yaml) => catálogos dinámicos para formularios y filtros

[feed-stock-implementation.md](feed-stock-implementation.md) => plantas de ración y stock de ingredientes en gramos

[contracts/openapi/feed-stock.yaml](contracts/openapi/feed-stock.yaml) => contrato API de plantas de ración y stock de ingredientes

Swagger UI (desarrollo): [http://localhost:8080/docs/](http://localhost:8080/docs/)

La documentación se sirve desde el servicio `swagger-ui` de Compose y permite
seleccionar los contratos de autenticación, Lotes/producción de huevos,
mantenimientos, medicamentos, vacunas, pesajes, planes de manejo, plantas de
ración y reporting. El
botón **Authorize** usa el token Bearer emitido por el login.

docker compose -f compose.dev.yaml up -d --build

## Tests y Artisan

La estrategia oficial de testing ejecuta PHPUnit dentro del contenedor `api`. PHPUnit usa PostgreSQL en `DB_HOST=postgres` y deriva la base aislada agregando `_testing` al `DB_DATABASE` normal del entorno. Con la configuración local actual, la base normal es `gam` y la de testing es `gam_testing`; las credenciales se heredan del servicio PostgreSQL definido en Compose.

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
  -e DB_DATABASE=gam_testing \
  api php artisan config:show database.default
docker compose -f compose.dev.yaml exec -T \
  -e APP_ENV=testing \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=gam_testing \
  api php artisan config:show database.connections.pgsql.database
```

La salida debe ser `pgsql` y `gam_testing` (la base normal local es `gam`; en otro entorno, sustituir ambos nombres por `<DB_DATABASE>` y `<DB_DATABASE>_testing`).

Para la comprobación adicional con un pepper temporal no persistido, reemplazá el valor vacío por `-e IDENTITY_PIN_PEPPER=test-only-temporary-value`.

No ejecutes `docker compose down -v`: elimina los volúmenes y los datos existentes.
## Datos de prueba locales

Cuando `APP_ENV=local`, `DatabaseSeeder` ejecuta también `LocalDemoDataSeeder` y carga datos ficticios pero coherentes de granjas, galpones avícolas y plantas de ración, proveedores, productos, medicamentos, inventario, reservas, reportes, una plantilla publicada de plan de manejo y lotes con redistribuciones, mortalidad, recolección y pesajes. La carga es idempotente y no se ejecuta en otros ambientes.

Para reconstruir la base local desde cero:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

Para una base local que conserva el demo anterior, esta reconstrucción reemplaza
los balances y movimientos de maíz y soja que estaban expresados en kg por su
representación canónica en gramos.

Las decisiones para corregir, regenerar o reconstruir datos de prueba del entorno local no requieren confirmación adicional: son datos ficticios, no pertenecen a producción y su reemplazo no afecta negativamente el desarrollo. Esta autorización se limita a `APP_ENV=local` y no aplica a datos reales ni a otros entornos.

Cada módulo nuevo debe incluir su seeder de datos demo y registrarlo en `LocalDemoDataSeeder` (o en un seeder del módulo invocado por este), para que sus datos estén disponibles automáticamente cuando el ambiente sea local.

## Reset temporal de la demo en VPS

El workflow de release conserva la creación de tags y despliega el SHA exacto de cada push a `main`. Sólo solicita el reset cuando GitHub encuentra un PR asociado a ese SHA que está mergeado, apunta a `main` y tiene ese mismo `merge_commit_sha`. Los pushes directos, PRs hacia otras ramas y `workflow_dispatch` despliegan con migraciones normales y no resetean la base.

El reset queda apagado hasta configurarlo explícitamente en el environment `production` de GitHub Actions. Antes de habilitarlo, definí allí estas variables (sin guardar contraseñas):

- `DEMO_DB_RESET_ENABLED=true`
- `DEMO_VPS_HOST`: el mismo host exacto que `VPS_HOST`, para fijar el destino de demo.
- `DEMO_DB_DATABASE`: el nombre real de la base de demo; no puede ser `gam_testing` ni terminar en `_testing`, sin importar mayúsculas.

En el archivo `.env.production` de esa VPS configurá los mismos valores `DEMO_DB_RESET_ENABLED=true`, `DEMO_VPS_HOST` y `DEMO_DB_DATABASE`, además de `ADMIN_PASSWORD` con la contraseña administrativa de la demo. `ADMIN_PASSWORD` se queda en esa VPS: no lo agregues a GitHub Actions, al repositorio ni a sus logs. Compose lo entrega al contenedor API junto con la opción del reset. Los valores esperados de host y base no están incluidos en el repositorio; el flujo falla cerrado si todavía no se configuraron.

Los workflows de backend y frontend se encolan por separado en GitHub, pero ambos toman en la VPS el mismo lock exclusivo `$HOME/.gam-vps-production.lock` con `flock` durante cada despliegue; así se serializan aunque estén en repositorios distintos. El backend usa el script remoto ya configurado en `VPS_BACKEND_DEPLOY_SCRIPT`; sólo continúa si terminó correctamente y los servicios `gam-prod` reportan la imagen API `gam-backend:<SHA>` correspondiente y salud correcta.

El orden de cada reset habilitado es: **merge a `main` → despliegue correcto del commit mergeado → respaldo previo → reset y seed → comprobación de salud**. Antes del comando destructivo se comparan el host SSH con `DEMO_VPS_HOST`, `APP_NAME=GAM`, `APP_ENV=production`, el servicio PostgreSQL `postgres`, la base efectiva de Laravel y `POSTGRES_DB` con `DEMO_DB_DATABASE`, y la opción de reset exacta `true` en el contenedor. Se rechazan bases de testing. El respaldo se escribe con permisos privados en `$HOME/gam-demo-db-backups`, en formato PostgreSQL custom, y se verifica con `pg_restore --list` y una lectura completa de validación con `pg_restore --file=/dev/null`; ni las credenciales, el SQL generado ni el contenido del respaldo se imprimen. Después corre en el contenedor API de esa imagen `php artisan migrate:fresh --seed --force --no-interaction`; se comprueba la cuenta admin activa con rol y permiso, un lote de demo y el endpoint de salud.

Para volver a migraciones normales sin borrar datos, cambiá `DEMO_DB_RESET_ENABLED` a `false` en el environment `production` de GitHub y en `.env.production` de la VPS. **Hacelo antes de trasladar GAM a la VPS del cliente**. Los seeders de demo en producción sólo se habilitan con la opción activa; en `local` siguen disponibles como antes.

## Autenticación multi-login

Define ADMIN_PASSWORD e IDENTITY_PIN_PEPPER en el entorno antes de sembrar datos. La web usa cookies stateful y CSRF; nativo usa PAT Bearer. El acceso compartido se vincula con un código de 10 caracteres y conserva una credencial de dispositivo independiente.

La guía operativa y las rutas están en identity-access-implementation.md. El contrato OpenAPI está en contracts/openapi/authentication.yaml.

Pruebas backend con Docker Compose:

    docker compose -f compose.dev.yaml exec -T api vendor/bin/phpunit --configuration phpunit.xml tests/Feature/IdentityAndAccess

Si se revoca un dispositivo, hay que vincularlo otra vez; si se deshabilita o cambia el PIN, el empleado vuelve al selector aunque la tablet siga vinculada.
