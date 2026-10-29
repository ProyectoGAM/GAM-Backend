# Identidad y acceso multi-login

## Decisión implementada

GAM tiene dos transportes y tres contextos de sesión:

- web personal: cookie de sesión Laravel HttpOnly, con CSRF y auth_sessions.kind=personal;
- nativo personal: PAT Bearer de 90 días, almacenado únicamente en secure storage;
- dispositivo compartido: credencial separada del usuario, válida 365 días, y una sesión de empleado de 8 horas con 120 segundos de inactividad.

El registro público está retirado. Las cuentas se crean mediante POST /api/v1/users y no se emite un token para la persona creada.

## Invariantes

- IDENTITY_PIN_PEPPER es obligatorio en ambientes reales y nunca se entrega al cliente.
- El PIN es un string exacto de cuatro dígitos. Se conserva 0007, se hashea con Argon2id sobre un HMAC-SHA256 con pepper y se guarda separado de password.
- Administradores no pueden tener PIN operativo.
- El listado compartido sólo devuelve usuarios activos con rol employee, PIN habilitado y permiso identity.shared.login.
- El dispositivo se identifica con X-Shared-Device-Token en nativo o cookie gam_shared_device en web. Nunca se sustituye por un PAT personal.
- Las operaciones de empleado requieren X-GAM-Session y la generación actual del dispositivo.
- El backend revoca y audita en transacciones; la expiración también se comprueba en cada request y no depende del scheduler.

## Permisos completos de Admin

El rol estable `admin` tiene acceso funcional a todos los módulos, incluyendo consulta, gestión y ejecución de planes de manejo, recetas, inventario, reportes y reparto. `IdentityPermissionSeeder` centraliza el catálogo completo y asigna al rol todos los permisos registrados del guard `web`, incluidos los añadidos por otros módulos. `AdminUserSeeder` reutiliza esa misma carga y sólo asigna el rol a la cuenta administradora configurada: ya no sustituye sus permisos por una segunda lista parcial. Los nuevos permisos deben registrarse en el catálogo central o antes de esta sincronización.

La reparación alcanza a todos los usuarios con rol `admin`. Login web, login nativo y `GET /api/v1/me` mantienen su formato y exponen los permisos efectivos mediante `UserResource`; el frontend puede habilitar sus pantallas con esa lista. Los roles `employee` y `delivery` conservan sus permisos específicos. Se mantienen las validaciones de negocio, estados, sesiones, canales, CSRF y la prohibición de PIN operativo para administradores.

Para actualizar permisos de una instalación existente sin cambiar cuentas ni contraseñas:

```powershell
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\IdentityPermissionSeeder' --no-interaction
docker compose -f compose.dev.yaml exec -T api php artisan permission:cache-reset
```

Si el cliente conserva una copia anterior del perfil, debe volver a consultar `/api/v1/me` o cerrar e iniciar sesión. No se requiere migración ni cambio de dependencias. Las pruebas de `AdminSeederTest` cubren ambas entradas de seed, recuperación de permisos con caché consultada previamente, permisos adicionales, múltiples administradores y exposición del catálogo en la sesión personal y el perfil.

Verificación de esta corrección: **29 pruebas y 218 aserciones** aprobadas entre `AdminSeederTest`, `AuthorizationTest` y `AuthenticationTest`, incluida la prueba adicional de sesión web, usando PostgreSQL aislado `gam_admin_access_testing`. Pint y PHPStan nivel 5 aprobaron los seeders modificados. Pint usó rutas explícitas por ausencia de Git en el contenedor y `sys_temp_dir=/dev/shm` para evitar el espacio temporal limitado. La carga de permisos se aplicó en la base local sin ejecutar `AdminUserSeeder` ni cambiar credenciales; se limpió la caché y Laravel Boost confirmó **55 permisos registrados, 55 asignados a admin y 0 faltantes**. No se verificó navegación visual en un navegador ni se modificó el frontend.

## Configuración local

Copiar .env.example y definir al menos:

    ADMIN_PASSWORD=una-clave-local-segura
    IDENTITY_PIN_PEPPER=un-secreto-estable-separado-del-backup

El Compose de desarrollo usa un pepper explícitamente local sólo para permitir levantar el entorno sin .env; debe reemplazarse antes de crear datos reales.

    docker compose -f compose.dev.yaml up -d --build
    docker compose -f compose.dev.yaml exec api php artisan migrate --force
    docker compose -f compose.dev.yaml exec api php artisan db:seed --force

## Flujo operativo

1. Un administrador inicia sesión por web o PAT nativo.
2. Confirma nuevamente su contraseña para generar un código.
3. El código alfanumérico de 10 caracteres vence en 10 minutos y sólo puede canjearse una vez.
4. El canje nativo devuelve device_token una sola vez; el canje web establece la cookie HttpOnly.
5. La instalación consulta el estado del dispositivo, obtiene empleados elegibles y solicita PIN.
6. Un login PIN revoca la sesión compartida anterior del mismo dispositivo. Terminar revoca sólo la sesión actual y conserva la vinculación.
7. Revocar la vinculación invalida todas las sesiones del dispositivo. El cambio o deshabilitado del PIN invalida las sesiones de ese empleado.

## Rutas principales

El contrato completo está en contracts/openapi/authentication.yaml.

| Caso | Web | Nativo |
|---|---|---|
| Login personal | POST /api/v1/auth/web/login | POST /api/v1/auth/login |
| Vincular dispositivo | POST /api/v1/auth/web/pairing | POST /api/v1/shared-devices/pairing |
| Estado/lista/PIN | rutas /shared-device/web/* con cookies | rutas /shared-device/* con headers |
| Finalizar empleado | POST /.../web/finalize | POST /.../finalize |

Todas las mutaciones web pasan por el middleware web y CSRF. Las rutas nativas no aceptan cookies como sustituto de sus headers.

## Verificación

Backend:

    docker compose -f compose.dev.yaml exec -T api vendor/bin/phpunit --configuration phpunit.xml tests/Feature/IdentityAndAccess
    docker compose -f compose.dev.yaml exec -T api php artisan route:list --path=api/v1

Frontend:

    npm run lint
    npm run test:ci
    npm run build
    npx cap sync

Las pruebas de navegador real, expiración con reloj avanzado, dos pestañas y builds Android/iOS firmados deben ejecutarse en sus entornos correspondientes; las pruebas PHPUnit/Vitest no las sustituyen.
