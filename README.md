GAM: http://localhost:8080

Estado de la aplicación: http://localhost:8080/estado

Mailpit: http://localhost:8025

Horizon: http://localhost:8080/horizon

Pulse: http://localhost:8080/pulse


architecture.md => arquitectura

libraries.md => paquetes/librerias usadas

module-structure-example.md => ejemplo de estructura y alguna que otra aplicacion minima

[maintenance-implementation.md](maintenance-implementation.md) => implementación e histórico de mantenimientos de galpones

[contracts/openapi/maintenance.yaml](contracts/openapi/maintenance.yaml) => contrato API de mantenimientos

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
## Seguimiento de mantenimientos en Notion

Usar [maintenance-implementation.md](maintenance-implementation.md) como fuente para actualizar la tarjeta de **Mantenimientos de instalaciones** en el Kanban Board de GAM.

1. Comprobar primero si la tarjeta ya contiene la implementación y las validaciones documentadas, para evitar duplicar contenido o repetir una actualización realizada por otro compañero.
2. Si está pendiente, actualizar la descripción y los resultados desde el documento y mover la tarjeta a **Do Test**. No marcarla como probada ni retroceder una tarjeta que ya haya completado esa etapa.
3. Después de verificar que Notion guardó el contenido y el estado, reemplazar la nota pendiente por **Actualizado en Notion**, con fecha y enlace a la tarjeta. Conservar el documento como referencia del proyecto; esta marca evita tener que borrarlo.
4. Si no hay acceso, falla la integración o no se encuentra la tarjeta, dejar el motivo y el pendiente aquí y **continuar con la implementación, integración de ramas, pruebas y pull request**. La actualización de Notion no debe bloquear el flujo de trabajo de ningún compañero.

**Estado de sincronización — 30/08/2026: pendiente.** La integración no devolvió resultados para GAM o mantenimientos y el enlace disponible del proyecto respondió `404 / sin acceso`. No se modificó ninguna tarjeta ni se pudo confirmar el estado `Do Test`. Reintentar cuando el tablero esté accesible, usando el documento indicado; este pendiente no bloquea el trabajo.

## Datos de prueba locales

Cuando `APP_ENV=local`, `DatabaseSeeder` ejecuta también `LocalDemoDataSeeder` y carga datos ficticios pero coherentes de granjas, galpones, mantenimientos, proveedores, productos, inventario y reportes. La carga es idempotente y no se ejecuta en otros ambientes.

Para reconstruir la base local desde cero:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

Cada módulo nuevo debe incluir su seeder de datos demo y registrarlo en `LocalDemoDataSeeder` (o en un seeder del módulo invocado por este), para que sus datos estén disponibles automáticamente cuando el ambiente sea local.
