# Modalidades de acceso y dispositivos por UP

## Comportamiento entregado

Cada cuenta conserva una modalidad permanente y exactamente un rol. El alta sigue siendo administrativa, exclusiva de Admin: no hay registro público ni emisión de sesión para el usuario recién creado. El backend decide la modalidad al registrar y la persiste; una restricción y un trigger PostgreSQL impiden combinaciones incompatibles y cambios posteriores de modalidad.

| Modalidad | Datos del alta | Acceso y alcance |
| --- | --- | --- |
| `email_password` | Nombre, correo, contraseña confirmada y rol | Login personal por correo; sin UP asignada, alcance empresarial sujeto a permisos. No admite PIN de acceso. |
| `device_pin` | Nombre, PIN confirmado de cuatro dígitos, una UP activa y rol | Sólo dispositivo vinculado a la misma UP. Correo y contraseña quedan en `null`; no se generan valores ficticios. |

`unassigned`, visible como **Sin rol**, es un rol protegido, sin permisos y sin acceso por ninguna modalidad. No aparece en el selector compartido. Asignarlo revoca las sesiones existentes; asignar después otro rol habilitado exige un nuevo login. Admin no puede editarlo, eliminarlo ni activarle permisos. Se conservan los permisos de canal `can_login_web`, `can_login_native` y `can_login_shared`: un permiso de canal nunca sustituye la modalidad ni los permisos de negocio.

Una cuenta PIN no puede promoverse a Admin, porque Admin conserva el acceso personal por correo. Los cambios de rol no borran PIN ni convierten modalidades. El perfil y la edición de correo quedan fuera de esta entrega.

## Registro y credenciales propias

`POST /api/v1/users` recibe una de estas variantes:

```json
{"name":"Operario","pin":"0007","pin_confirmation":"0007","up_id":1,"rol_id":2}
```

```json
{"name":"Usuario personal","email":"persona@example.test","password":"clave-de-ejemplo","password_confirmation":"clave-de-ejemplo","rol_id":3}
```

Los IDs son ilustrativos: consultar el catálogo de roles y las UP activas. Con correo, no enviar PIN de acceso ni UP; sin correo, no enviar contraseña. El servidor rechaza campos ajenos a esta operación. La respuesta conserva `rol` y `permissions` y agrega `modalidad_acceso`, `up_id` y `pin_operativo_configurado`; nunca incluye hashes o secretos.

| Endpoint propio | Datos requeridos | Modalidad |
| --- | --- | --- |
| `PATCH /api/v1/auth/contrasena` | `current_password`, `password`, `password_confirmation` | Correo |
| `PATCH /api/v1/auth/pin` | `current_pin`, `pin`, `pin_confirmation` | Dispositivo PIN, sesión compartida válida |
| `PUT /api/v1/auth/pin-operativo` | `current_password`, `pin`, `pin_confirmation` | Correo, incluido Admin |

Estas operaciones actúan sobre el usuario de la sesión, sin aceptar otro ID ni cambios de correo, rol, UP o modalidad. No requieren permisos administrativos. Sin rol no puede utilizarlas porque no puede mantener una sesión válida.

Cambiar o restablecer contraseña, PIN de acceso o PIN operativo revoca **todas las sesiones del usuario afectado**, incluida la actual, cookies web y PAT nativos. No se emite una sesión sustituta. Los cambios propios responden `requiere_reingreso: true`; el cliente vuelve al acceso correspondiente. Si Admin restablece la credencial de otra persona, conserva su propia sesión. La vinculación física del dispositivo y las sesiones de otras personas permanecen.

Credencial, revocaciones y auditoría se guardan en una transacción: un error no deja credenciales parcialmente modificadas ni aplica un cierre de sesiones por cambio exitoso. Los restablecimientos administrativos existentes mantienen esta regla. Restablecer contraseña a una cuenta PIN se rechaza. Deshabilitar su PIN conserva el hash obligatorio y bloquea su uso hasta la habilitación administrativa posterior.

## PIN de confirmación

Cada cuenta con correo, incluido cada Admin, puede configurar su propio PIN operativo dentro de la app demostrando su contraseña actual. No se exige al registrar la cuenta. El PIN operativo utiliza un hash independiente del PIN de acceso y nunca habilita el login compartido ni amplía permisos.

Las cuentas que ingresan por PIN reutilizan ese PIN al confirmar operaciones existentes. Repartos conserva su confirmación al iniciar y cerrar, mediante un verificador operativo separado del login. La ausencia de PIN operativo detiene la tarea antes de producir efectos. Los intentos operativos de cuentas con correo usan límites independientes del login PIN; nunca se registran credenciales en auditoría.

El usuario definirá en otra instancia las tareas nuevas de Admin que requerirán confirmación. Esta entrega habilita la credencial y conserva las confirmaciones existentes; no agrega exigencias de PIN a otras tareas por suposición.

## Vinculación, selector y sesión

Admin confirma recientemente su contraseña y genera el código con `POST /api/v1/shared-devices/codes`, enviando `name` y `up_id`. La UP debe estar activa. El código conserva la UP en el servidor, vence y se consume una sola vez; el canje no admite cambiar esa UP y comprueba nuevamente su estado y el creador.

La respuesta del dispositivo incluye `up_id`. El selector sólo devuelve ID y nombre de cuentas PIN activas, PIN habilitado, permiso compartido y UP coincidente; excluye Admin y Sin rol. Enviar manualmente otro usuario no permite saltar el selector. Un intento con un usuario de otra UP no incrementa los fallos personales de ese usuario.

La sesión compartida conserva `production_unit_id`, expuesta como `up_id`. Cada request valida sesión vigente, modalidad, permiso, UP de usuario/dispositivo/sesión, estado de UP, credencial física, `X-GAM-Session`, transporte y generación. Se mantienen cookie HttpOnly y CSRF en web, PAT de empleado y `X-Shared-Device-Token` independiente en nativo. Un login personal en un equipo vinculado conserva su contexto personal.

`PATCH /api/v1/usuarios/{user}/unidad-productiva`, exclusivo de Admin, recibe `up_id` y revoca las sesiones del usuario PIN. Un reparto activo impide el traslado con 409. No traslada historia ni cambia modalidad. Para mover una tablet, revocar su vinculación y generar otra. Desactivar una UP revoca sus sesiones y vence los códigos pendientes; reactivarla no revive esas autorizaciones.

## Límite de datos por UP

La UP de registro es una restricción obligatoria del backend, adicional al permiso funcional. No se puede operar en otra UP manipulando IDs, payloads, parámetros o roles. Los scopes de Eloquent limitan bindings, consultas, relaciones, agregaciones y paginación; las Policies y Actions conservan la autorización y revalidan recursos. Las escrituras comprueban UP y relaciones antes de persistir. Los movimientos validan ambos extremos y rechazan cruces completos, sin cambios parciales.

El registro central de alcance cubre estructura, lotes, pesajes, postura, mortalidad, planes y ejecuciones, stock, alimento, reparto, movimientos relacionados, auditoría y las configuraciones/exportaciones e identidades de operaciones. Los resultados idempotentes conservan su UP para impedir reutilizarlos desde una asignación posterior distinta.

El historial SQL de lotes también excluye movimientos históricos entre UP que contengan instantáneas o identificadores ajenos. Las fuentes de inventario aplican los scopes de Eloquent antes de obtener su query SQL para reportes.

| Recursos | Sesión PIN |
| --- | --- |
| Registros de una UP y derivados | Sólo su UP. Los IDs ajenos no están disponibles; los listados y totales excluyen otras unidades. |
| Transferencias y redistribuciones | Ningún extremo puede pertenecer a otra UP. |
| Catálogos globales | Lecturas de referencia autorizadas. Mutaciones globales requieren sesión personal; incluye configuración global de pesajes y creación global de ingredientes. |
| Clientes y cuenta corriente | Sin acceso a gestión ni saldos globales. El selector operativo de reparto omite la cuenta; los cobros requieren un reparto autorizado de su UP. Sus respuestas omiten saldos globales y secuencia de cuenta. |
| Vehículos | Selector con datos mínimos de referencia para repartir y registro de combustible vinculado al uso propio de un reparto de su UP. La gestión global y otras consultas del vehículo requieren sesión personal. |
| Auditoría | Sólo registros de su UP; excluye auditoría global de cuentas corrientes que puede contener saldos empresariales. |
| Reportes | Las fuentes SQL con UP filtran antes de opciones y agregación. Fuentes sin alcance demostrable se deniegan. |
| Exportaciones | Persisten UP y el worker genera con ese contexto; rechaza cambio de UP del solicitante. La descarga privada requiere contexto válido. No se comparten exportaciones restringidas mediante enlaces públicos. |

Al iniciar reparto sin carga desde PIN se conserva la UP de contexto, sin inventar una carga ni mover stock. Cargas y devoluciones posteriores quedan dentro de esa misma UP.

## Persistencia, auditoría y despliegue

La migración `2026_11_02_232521_add_access_methods_and_production_unit_context.php` agrega modalidad, correo/contraseña nullable, FK de UP y hash operativo independiente. Agrega UP a códigos, dispositivos, sesiones, reportes, configuraciones y operaciones idempotentes. Las FK preservan historia y se crean índices para elegibilidad y consultas por UP. No se agregan dependencias.

Las cuentas existentes con correo conservan esa modalidad y su contraseña; el PIN previo pasa al hash operativo. Las vinculaciones antiguas sin UP se revocan, sus códigos vencen y sus sesiones compartidas quedan inválidas. Deben vincularse nuevamente eligiendo UP. La migración no inventa asignaciones. El rollback se detiene si existen cuentas PIN que no pueden representarse en el esquema anterior.

Las mutaciones utilizan `AuditRecorder` y `AuditEntryData` dentro de su transacción. Se conservan actor, sujeto, operación, traza, contexto y cambios permitidos; nunca PIN, contraseñas, hashes, códigos o tokens. La auditoría recibe el contexto UP cuando corresponde. Los eventos propios son `user_credential_changed` y `user_production_unit_changed`, además de los eventos existentes de registro, PIN, roles y vinculación.

Actualizar con migraciones normales y el seeder de permisos. La demo de repartidor mantiene su PIN como operativo; repetir el seeder no lo restablece si ya existe. Los nuevos campos y flujos de integración están en [authentication.yaml](../../contracts/openapi/authentication.yaml). Los cambios visuales de una aplicación frontend independiente deben consumir ese contrato; esta entrega del repositorio backend no incluye pantallas de perfil.

### Usuarios de dispositivo compartido para la demo local

`SharedUserDemoSeeder` registra cuatro cuentas PIN con el rol **Operación de galpón**. La carga general lo ejecuta después de sembrar los roles y las UP. En un dispositivo vinculado a la UP correspondiente, elegí el usuario en el selector e ingresá el PIN de demo `1234`.

| UP | Cuentas |
| --- | --- |
| Granja El Ombú | Ana Duarte (demo) y Mateo Silva (demo) |
| Granja Santa Clara | Lucía Méndez (demo) y Bruno Castro (demo) |

El alta usa el flujo habitual, con hash Argon2id y el pepper del entorno, y registra la UP en la auditoría. Volver a ejecutar la carga conserva los cambios de PIN, rol, UP y estado de las cuentas existentes. Sólo se ejecuta en `local` o `testing`.

```bash
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\IdentityAndAccess\SharedUserDemoSeeder' --no-interaction
```

Se verificaron las cuatro cuentas en la base local. Pasaron 22 pruebas en PostgreSQL de testing, incluido el ingreso desde la UP asignada, el rechazo desde otra UP y la recarga después de cambiar el PIN.

## Verificación

Se verificó con PostgreSQL aislado (`gam_testing` y `gam_access_testing`), sin ejecutar pruebas sobre la base local:

- Identidad y acceso completos: **89 pruebas, 665 aserciones**; incluye el cambio de PIN concurrente con login, administración de roles, cookies, PAT, Sin rol, PIN operativo de Admin, UP, reportes y rollback de credenciales por fallo de auditoría.
- Regresiones de Repartos, cobros, vehículos, elaboración, alimentación y pesajes: **110 pruebas, 974 aserciones**.
- Reportes, estructura e inventario: **122 pruebas, 713 aserciones**.
- Caso adicional del historial SQL de lotes: **1 prueba, 2 aserciones**, para excluir instantáneas históricas de otras UP. La revalidación final de Repartos, cobros y reporte de stock aprobó **26 pruebas y 310 aserciones**, incluidas en las baterías anteriores.
- PHPStan: nivel 5 del área de identidad ampliada y nivel 0 de CI para aplicación, factories y seeders, sin errores. Pint ejecutado sobre los PHP modificados y `git diff --check` sin incidencias. El contenedor no incluye Git; se pasaron rutas explícitas. Para archivos bloqueados por el volumen Windows se utilizó el modo stdin del propio Pint y sustitución atómica del archivo.

La migración normal y `IdentityPermissionSeeder` se aplicaron en la base local, conservando sus datos; se emitió el reinicio de workers. No fue necesario reconstruir imágenes ni limpiar volúmenes. Las vinculaciones antiguas requieren elegir UP y vincular nuevamente. No se verificó navegación visual ni se modificó el frontend independiente.
