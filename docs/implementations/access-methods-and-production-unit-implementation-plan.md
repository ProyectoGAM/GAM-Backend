# Acceso, registro y dispositivos por UP — plan de implementación

Estado: plan aprobado e implementado en esta rama; la guía de [comportamiento entregado](access-methods-and-production-unit-implementation.md) documenta las decisiones finales, la migración y la verificación. Fecha del plan: 02/11/2026. Se conserva debajo el diseño y sus propuestas históricas para trazabilidad.

## Alcance confirmado

- Conservar dos formas de acceso: correo y contraseña; dispositivo vinculado y PIN.
- Permitir registrar cuentas sin correo ni contraseña, exigiendo PIN de acceso y una UP.
- Si se informa correo, exigir contraseña. PIN de acceso y UP no son requisitos para esa modalidad.
- La forma de acceso de una cuenta se conserva durante toda su vida. Una cuenta de acceso PIN no puede convertirse en cuenta de correo.
- El usuario puede cambiar sus credenciales propias, según su modalidad; no puede modificar su correo ni su forma de acceso.
- Vincular cada dispositivo compartido a una única UP. Cada usuario de acceso PIN pertenece a una única UP.
- La sesión PIN sólo puede consultar y operar en la UP vinculada al dispositivo. La pertenencia del usuario y la UP del dispositivo deben coincidir también al autenticar, no únicamente al construir el selector.
- Denegar operaciones fuera de la UP en la que está registrado el usuario. La asignación a una UP es un límite obligatorio del backend, aunque el rol tenga el permiso funcional. Incluye consultas, mutaciones y operaciones con origen o destino en otra UP; no se admite cambiar el alcance mediante IDs, parámetros o cambios de rol.
- Todos los usuarios siguen teniendo exactamente un rol. Crear el rol de sistema **“Sin rol”**, sin permisos y sin posibilidad de iniciar sesión por ninguna modalidad. Asignarlo revoca las sesiones existentes. No se admiten usuarios sin asignación ni permisos individuales.
- Las cuentas con correo pueden utilizar un PIN adicional dentro de la aplicación para confirmar operaciones, como ocurre actualmente al iniciar y cerrar repartos. Ese PIN no habilita el acceso mediante dispositivo vinculado.
- Admin puede establecer un PIN operativo propio para confirmar tareas dentro de la aplicación, conservando el login por correo y contraseña. El usuario proporcionará en otra instancia el listado de tareas de Admin que exigirán esa confirmación; esta entrega no selecciona tareas nuevas ni activa confirmaciones adicionales por suposición.
- Cambiar o restablecer la contraseña, el PIN de acceso o el PIN operativo cierra todas las sesiones del usuario afectado, incluida la sesión actual cuando el cambio es propio. Aplica también a Admin y a restablecimientos administrativos; se exige un nuevo login por la modalidad original.
- El desarrollo del perfil queda fuera de este plan. No se agregan pantallas, edición de datos personales ni otras funciones de perfil.

Las respuestas del usuario confirmaron expresamente el aislamiento completo de la sesión PIN, una sola UP por usuario y la denegación de operaciones fuera de la UP de registro. En este alcance, las cuentas PIN requieren esa asignación; las cuentas con correo sin UP mantienen el alcance empresarial indicado debajo. El PIN adicional de los usuarios con correo es una confirmación operativa, no una tercera forma de login.

## Modelo funcional propuesto

| Aspecto | Cuenta con correo | Cuenta con acceso PIN |
| --- | --- | --- |
| Login | Correo y contraseña, en web o aplicación nativa. | Dispositivo vinculado, selección del usuario y PIN. |
| Correo | Obligatorio. | Ausente; guardar `null`, sin direcciones ficticias. |
| Contraseña | Obligatoria. | Ausente; guardar `null`, sin contraseñas de relleno. |
| PIN de acceso | No se configura. | Obligatorio, string de cuatro dígitos. |
| UP de acceso | No es requisito. La sesión personal mantiene alcance empresarial, sujeto a permisos. | Una UP obligatoria, coincidente con la del dispositivo. |
| PIN operativo | Adicional, obligatorio sólo para ejecutar operaciones que lo exijan. | Propuesta: usar su propio PIN de acceso para esas confirmaciones, sin obligarlo a recordar otro. |
| Rol | Exactamente uno; “Sin rol” impide iniciar sesión. | Exactamente uno; “Sin rol” impide iniciar sesión y no aparece en el selector. |
| Cambio de modalidad | No disponible. | No disponible, incluso después de cambiar rol o UP. |

Propuesta de simplificación: no asignar una UP de acceso a las cuentas con correo en esta entrega. Si se desea permitir una UP informativa opcional para esas cuentas, debe definirse su significado antes de incluir el campo; no debe generar un alcance implícito ni incorporarlas al selector PIN.

La forma de acceso debe persistirse explícitamente, mediante un enum del módulo con identificadores internos ingleses, por ejemplo `EmailPassword` y `DevicePin`. No debe deducirse en cada request por la presencia de un hash, un rol o un permiso. El formulario de alta puede mostrar dos modalidades; el servidor rechaza combinaciones de campos contradictorias.

## Hallazgos e incompatibilidades con el código actual

| Situación comprobada | Cambio necesario |
| --- | --- |
| `users.email` y `users.password` son obligatorios. Registro y `LoginUserAction` presuponen ambos valores. | Hacerlos nullable, validar la modalidad y evitar verificar una contraseña nula. Revisar tipado, Resources, factories y consumidores del correo. |
| Login, middleware y revocación de sesiones dependen de `can_login_web`, `can_login_native` y `can_login_shared`. | Conservar los permisos de canal y añadir las validaciones de modalidad y contexto. “Sin rol” no tiene permisos de acceso y debe rechazarse también en sesiones existentes. |
| Dispositivo, código de vinculación y sesión no conservan una UP de acceso. | Guardar y validar esa identidad durante generación, canje, emisión de sesión y requests posteriores. |
| El selector compartido filtra PIN habilitado y permisos, sin pertenencia a UP. | Filtrar modalidad PIN, cuenta activa, PIN utilizable, permiso de canal y coincidencia de UP; excluir “Sin rol”. |
| El acceso funcional actual es empresarial, sin asignaciones usuario–UP. | La nueva indicación cambia esa regla para cuentas y sesiones PIN. Actualizar las reglas compartidas y la documentación al implementar, manteniendo el alcance personal con correo. |
| `SetUserPinAction` y `AccessControl::enforceSessions` prohíben o eliminan el PIN al detectar Admin. | Distinguir PIN de acceso de PIN operativo: permitir explícitamente que Admin configure y conserve su PIN operativo, sin habilitar login PIN. Evitar que una asignación de rol cambie la modalidad o deje una cuenta PIN sin credencial. |
| Repartos llama a `AuthenticateSharedPinAction::verifyPin` para confirmar inicio/cierre. | Extraer la confirmación operativa y utilizar el secreto correspondiente sin habilitar login PIN para una cuenta con correo. |
| La lista de usuarios en el dispositivo es previa a la autenticación personal. | No devolver correo, roles, hashes ni datos de otras UP; mantener paginación y límites. |
| Hay recursos globales, movimientos entre UP, repartos sin carga inicial y cuentas corrientes sin UP propia. | Preparar una matriz de alcance real por operación; añadir un `where` a unos listados no resuelve el aislamiento. |

Versiones verificadas: Laravel 13.29.0, Sanctum 4.3.3, Spatie Permission 8.3.0 y PHPUnit 12.5.34. No se propone agregar dependencias. Laravel distingue autenticación de autorización y ofrece Policies para la segunda; se conserva esa separación y el transporte stateful de Sanctum para web. Fuentes oficiales: [autenticación de Laravel 13](https://github.com/laravel/docs/blob/13.x/authentication.md), [Sanctum en Laravel 13](https://github.com/laravel/docs/blob/13.x/sanctum.md), [Policies](https://laravel.com/docs/13.x/authorization).

## Rol “Sin rol” y autenticación

Propuesta: clave técnica estable `unassigned`, nombre visible “Sin rol” y descripción “Sin permisos ni acceso a la aplicación”. Es un rol de sistema protegido: no se elimina, no cambia su clave ni admite activar permisos, incluso desde Admin. Su configuración es siempre una lista vacía.

El alta sigue recibiendo un `rol_id` existente y obligatorio; la interfaz puede preseleccionar “Sin rol”, indicando que la cuenta no podrá ingresar hasta que Admin le asigne un rol habilitado. Cambiar a ese rol conserva la identidad, la modalidad y las credenciales, revoca todas las sesiones de usuario y bloquea los requests posteriores. No se agregan excepciones que otorguen permisos individuales al usuario. La vinculación física de un dispositivo compartido se conserva para los demás usuarios.

Se conservan `can_login_web`, `can_login_native` y `can_login_shared` en el catálogo y en las decisiones de acceso. Para autenticarse se requiere cuenta activa, un rol distinto de “Sin rol”, permiso del canal, modalidad compatible y contexto válido. Tener el permiso de canal no permite cambiar de modalidad. La desactivación de una cuenta o la retirada del permiso de canal continúa bloqueando y revocando las sesiones correspondientes.

Finalizar sesión y cambiar la credencial propia no requieren permisos de administración de usuarios, pero sí una sesión habilitada según las reglas anteriores. Se conserva la respuesta mínima de identidad y sesión que ya consume la aplicación, sin diseñar el perfil futuro.

Un usuario “Sin rol” tiene cero permisos y no recibe una sesión autenticada, ni por correo ni por PIN. Si conservaba una cookie o token anterior a la asignación, no puede utilizarlos para consultar identidad, cambiar credenciales propias ni acceder al negocio. Reasignarle un rol habilitado permite un nuevo login, sin reactivar sesiones antiguas. También deben revisarse endpoints que hoy sólo comprueban autenticación o exponen opciones genéricas: un permiso de canal por sí solo no concede permisos de negocio. No basta con ocultar menús en el frontend.

## Vinculación del dispositivo y acceso PIN

1. Admin, mediante sesión personal y confirmación reciente de contraseña, selecciona una UP activa al generar el código de vinculación.
2. El código conserva esa UP en el servidor, junto con vencimiento, creador y estado de consumo. El dispositivo no puede seleccionar otra UP al canjearlo.
3. El canje valida nuevamente la UP y la habilitación del creador. Crea el dispositivo con esa UP, consume el código una sola vez y audita dentro de la misma transacción.
4. El estado del dispositivo expone la UP asignada para que el usuario reconozca dónde está trabajando. La credencial del dispositivo sigue siendo independiente de las credenciales personales.
5. El selector muestra sólo usuarios activos de modalidad PIN, PIN habilitado, permiso `can_login_shared` y UP coincidente. “Sin rol” queda excluido.
6. El login valida en el servidor usuario, rol habilitado, permiso de canal, modalidad, PIN, UP de usuario y dispositivo, estado de UP, vigencia de credencial y generación. Enviar manualmente otro `user_id` no permite saltar el filtro del selector ni ingresar con “Sin rol”.
7. La sesión guarda la UP con la que fue emitida. Los requests posteriores comprueban que sigue coincidiendo con el dispositivo y el usuario; no toman la UP del storage, un header arbitrario o el payload como autoridad.
8. Se conserva una sesión compartida vigente por dispositivo, con la generación actual. Las sesiones anteriores no recuperan validez al cambiar de usuario, repetir un código o volver a activar una UP.

Mantener los transportes actuales: cookie HttpOnly y CSRF en web; `X-Shared-Device-Token` en nativo; `X-GAM-Session`, sesión de empleado y generación vigentes para operaciones compartidas. Una sesión personal con correo no se convierte en sesión PIN por ejecutarse en un equipo que también esté vinculado.

Un intento de login con un usuario de otra UP debe responder de forma genérica y consumir los límites del dispositivo, sin revelar si el PIN habría sido correcto ni bloquear por intentos al usuario ajeno. La selección del usuario utiliza su ID; los PIN no tienen que ser únicos entre personas y los nombres repetidos deben poder distinguirse en el selector.

## Aislamiento de datos por UP

Regla confirmada: el usuario registrado en la UP A no puede consultar ni operar sobre la UP B, aunque conozca sus IDs o su rol permita ejecutar esa clase de operación. La UP registrada en el servidor determina el límite; la coincidencia con el dispositivo es una comprobación adicional, nunca una forma de sustituirla. Toda operación que afecte otra UP se rechaza completa, sin cambios parciales de negocio.

Introducir un contexto de acceso reutilizable, resuelto desde la sesión autenticada: personal empresarial o compartido restringido a una UP. Las Policies comprueban permiso funcional y alcance; las Queries filtran antes de paginar, agregar o generar opciones. Las Actions revalidan los recursos relevantes dentro de su transacción.

La denegación de operaciones entre UP deja de ser una propuesta pendiente. La clasificación de recursos globales de la tabla debe respetar ese límite: no se habilita una mutación global desde un usuario restringido si puede afectar a otras UP o no puede demostrarse su alcance.

| Área | Regla propuesta para sesiones PIN |
| --- | --- |
| UP, galpones, lotes y registros derivados | Sólo la UP de contexto y sus recursos; resolver pertenencia por relaciones, no por un ID enviado por el cliente. |
| Stock, mínimos, alimento, ingredientes y consumos | Consultas y movimientos limitados a ubicaciones, cuentas y lotes de la misma UP. |
| Transferencias, redistribuciones y donaciones | Validar origen y destino. Una operación entre dos UP queda denegada para PIN; puede realizarla una cuenta personal con los permisos requeridos. |
| Métricas, búsqueda y opciones | Aplicar el alcance antes de calcular resultados o devolver nombres/identificadores. |
| Reportes, exportaciones y auditoría | Filtrar los hechos por UP y revalidar el contexto al generar y descargar. Los registros globales de seguridad no se exponen a un actor PIN sólo porque tenga permiso de auditoría. |
| Catálogos globales | Permitir únicamente las lecturas de referencia necesarias y autorizadas. No mostrar saldos globales. Las mutaciones globales que afecten a otras UP requieren sesión personal. |
| Clientes y cuentas corrientes | No inventar una UP para el cliente o la cuenta. Las operaciones financieras globales no se habilitan en PIN sin una relación verificable con la operación de su UP. |
| Repartos y vehículos | Conservar propiedad del conductor y permisos. Verificar además el contexto UP de reparto, cargas, devoluciones y efectos financieros. |

Las claves de permisos siguen siendo funcionales y generales, sin IDs de UP. El alcance es una restricción contextual adicional; cambiar de rol nunca amplía una sesión PIN a otra UP.

Casos que la matriz de implementación debe resolver explícitamente:

- **Reparto sin carga:** actualmente puede carecer de UP. Si lo inicia una sesión PIN, conservar la UP de contexto desde el inicio, aunque todavía no exista un movimiento de stock. No confundir contexto con una carga efectiva.
- **Carga o devolución posterior:** impedir elegir una UP distinta de la sesión, aunque el reparto sea del usuario.
- **Cobros durante reparto:** sólo mediante el reparto autorizado de su UP; la consulta del saldo completo de una cuenta global requiere una decisión independiente y no se habilita por defecto.
- **Enlaces públicos de reportes:** revisar si permiten eludir el contexto; no habilitar nuevas comparticiones públicas desde PIN hasta garantizar ese límite. Una exportación privada no debe reutilizar datos de otra UP ni autorizarse sólo por ser del mismo usuario.
- **Jobs, reintentos y operaciones históricas:** conservar el contexto original y comprobar la autorización aplicable al acceder al resultado. No reinterpretar un trabajo antiguo con la UP nueva del usuario.

Una URL a un registro de otra UP debe comportarse como recurso no disponible, normalmente 404. Una acción conocida pero incompatible con la modalidad o el alcance puede devolver 403. Las listas excluyen datos ajenos, sin incluirlos en contadores o totales.

## PIN operativo y cambios de credenciales propias

Separar responsabilidades actuales:

- PIN de acceso: autentica exclusivamente cuentas de modalidad PIN en dispositivos válidos de su UP.
- PIN operativo: confirma acciones de una cuenta con correo ya autenticada. Su existencia nunca habilita login PIN ni la incorpora al selector compartido.
- Propuesta para cuentas PIN: su PIN de acceso también confirma las operaciones que lo exijan. No agregar un segundo PIN obligatorio para esas cuentas.

Decisión confirmada para Admin: puede establecer, cambiar y restablecer su propio PIN operativo mediante una sesión personal válida y prueba de su contraseña actual. Su rol no debe provocar el borrado de ese PIN. El PIN es personal de cada cuenta Admin, no un secreto compartido por todas las cuentas con ese rol. No sustituye los permisos funcionales ni habilita acceso por dispositivo.

El listado de tareas de Admin que pedirán PIN se definirá en una instancia posterior. El alcance actual prepara la configuración y verificación de la credencial; no añade exigencias de PIN a la gestión de roles, vinculación de dispositivos u otras tareas sin esa definición. Las confirmaciones existentes de Repartos se tratan como compatibilidad del flujo actual, no como un listado nuevo aprobado para Admin.

Propuesta de persistencia: conservar `pin_hash` para acceso y agregar un hash independiente, por ejemplo `operation_pin_hash`, para cuentas con correo. Revisar los nombres definitivos al implementar. Ambos conservan el formato de cuatro dígitos, Argon2id y HMAC con pepper; no usar el PIN como contraseña ni guardarlo en texto plano.

El PIN operativo no es obligatorio al registrar una cuenta con correo. Puede establecerse dentro de la app, confirmando primero la contraseña actual. Si falta cuando se intenta iniciar/cerrar un reparto, la operación debe detenerse con una indicación clara de configuración pendiente, sin crear efectos parciales.

| Operación propia | Verificación propuesta |
| --- | --- |
| Cambiar contraseña de cuenta con correo | Contraseña actual, nueva contraseña y confirmación; conservar la política de longitud del proyecto. |
| Cambiar PIN de acceso | Sesión compartida válida de su UP, PIN actual, nuevo PIN y confirmación. |
| Establecer o restablecer PIN operativo de cuenta con correo | Contraseña actual, nuevo PIN operativo y confirmación. No exigir conocer un PIN operativo olvidado si ya prueba su contraseña. |

Los endpoints propios obtienen el usuario de una sesión habilitada: no aceptan un ID arbitrario ni requieren permisos de administrar usuarios o PIN. “Sin rol” no puede utilizarlos porque no puede mantener una sesión válida; sus credenciales sólo pueden restablecerse mediante la administración autorizada. Rechazan cambios de correo, modalidad, UP o rol en esos payloads.

Regla de sesiones confirmada: cambiar o restablecer contraseña, PIN de acceso o PIN operativo revoca todas las sesiones del usuario afectado, tanto personales como compartidas, incluidas la actual, las cookies web y los PAT nativos. También invalida confirmaciones operativas previas. Aplica a cambios propios y administrativos, incluido el PIN operativo de Admin. No se conserva ni se emite automáticamente una sesión de reemplazo: el usuario debe autenticarse nuevamente por su modalidad original.

La actualización de la credencial, la revocación y su auditoría deben ser atómicas. Un fallo de validación o de persistencia no debe cerrar sesiones como si el cambio hubiera tenido éxito. Se conserva la vinculación física de los dispositivos y las sesiones de otros usuarios; si el cambio lo realiza un administrador sobre otra cuenta, sólo se cierran las sesiones de la cuenta modificada.

Mantener límites de intentos por usuario, dispositivo/sesión e IP. Distinguir intentos de acceso de intentos de confirmación operativa para que una operación no reinicie contadores del login ni permita evadir límites. El PIN sigue sin ser visible o recuperable, incluso para Admin.

## Administración y casos de ciclo de vida

- **Registro:** conservar alta administrativa autenticada y transaccional, sin registro público. Cuenta, credenciales, UP cuando corresponda y único rol se guardan juntos y se auditan. Si falla una parte, no queda una cuenta incompleta.
- **Olvido de PIN sin correo:** recuperación mediante administración, sin convertir la cuenta a correo. Nunca enviar la credencial anterior ni exigir un email ficticio.
- **Deshabilitar PIN:** no borrar el hash obligatorio de una cuenta PIN dejándola con una modalidad inválida. Bloquear la credencial o la cuenta y permitir un restablecimiento administrativo posterior del mismo tipo.
- **Cambio de rol:** no cambia modalidad, correo, UP ni credenciales. Asignar “Sin rol” revoca todas las sesiones y bloquea nuevos ingresos. Una cuenta PIN no puede promoverse a Admin mientras se conserve la regla de administración mediante acceso personal; rechazar el cambio en vez de borrar su PIN automáticamente.
- **Transferir usuario PIN a otra UP:** propuesta: sólo Admin, mediante operación explícita y auditada, revocando sus sesiones. No cambia la modalidad ni traslada historia. Bloquearla si tiene un reparto u otra operación abierta que quedaría sin posibilidad de cierre; resolver primero ese trabajo.
- **Mover dispositivo a otra UP:** propuesta inicial: revocar y volver a vincular. La credencial anterior y sus sesiones quedan inválidas; no modificar silenciosamente la UP de una vinculación activa.
- **UP inactiva:** no emitir códigos ni sesiones PIN para ella. Al desactivarla, revocar los contextos correspondientes y comprobar el estado también en cada request. Reactivarla no resucita sesiones o códigos anteriores.
- **Cuenta inactiva o usuario eliminado lógicamente:** no aparece ni autentica, aunque conserve rol, UP y PIN histórico.
- **Sin usuarios elegibles en la UP:** responder lista vacía y explicar el estado en el flujo de acceso; no buscar automáticamente usuarios de otra UP.
- **Permisos del rol editados:** aplicar los cambios al negocio; retirar un permiso de canal invalida las sesiones de ese canal. Mantener el login sólo si cuenta, rol, permiso de canal, modalidad y contexto siguen habilitados. “Sin rol” permanece vacío y sin acceso.

## Persistencia y componentes afectados

Preparar migraciones nuevas, sin editar las ya desplegadas. Antes de generarlas, inspeccionar el esquema real mediante Boost si está disponible o las herramientas del framework.

1. `users`: modalidad explícita; correo y contraseña nullable; FK de una UP para modalidad PIN; soporte separado para PIN operativo de cuentas con correo. Sin tabla de múltiples pertenencias por usuario.
2. Restricciones de integridad para impedir cuenta PIN con correo/contraseña, cuenta PIN sin UP o hash de acceso, cuenta de correo sin contraseña y combinaciones contradictorias de modalidad y credenciales. El hash operativo opcional no viola esas reglas.
3. `shared_device_pairing_codes` y `shared_devices`: UP autorizada al generar el código y al vincular. No aceptar una sustitución arbitraria al canjear.
4. `auth_sessions`: UP de emisión para sesiones compartidas; conservar modalidad/transporte y generación. Sesiones personales sin un alcance UP implícito.
5. `roles`: registrar y proteger “Sin rol”, conservando la restricción vigente de una sola asignación. No cambiar a rol opcional.
6. Índices para usuarios elegibles por modalidad/UP/estado y consultas de sesiones por UP/dispositivo. Conservar FK restrictivas e historia de UP y credenciales revocadas.
7. Revisar campos nullable en `UserResource`, auditoría de usuarios, factories, seeders y cualquier uso de correo/contraseña. No reemplazar nulos por cadenas vacías para eludir validaciones.

Reutilizar y adaptar `RegisterUserAction`, Actions de vinculación, `AuthSessionService`, `SharedDeviceCredentialService`, `ValidateCurrentAccess`, validación compartida, `AccessControl`, Policies de usuario/rol y sus FormRequests. Incorporar Actions específicas para cambios propios de credencial y asignación administrativa de UP. Extraer un verificador de confirmación operativa para que Repartos no dependa de la Action de login compartido.

La UP y la modalidad deben verificarse también dentro de las transacciones sensibles. Establecer un orden común de bloqueo entre UP, dispositivo, usuario, sesión y las guardas de acceso existentes. Probar carreras de login contra transferencia, baja de UP, revocación de dispositivo y cambios de credencial.

Cada mutación registra auditoría síncrona con `AuditRecorder` y `AuditEntryData`: actor, sujeto, resultado, operación, traza, UP y snapshots permitidos. No incluir PIN, contraseñas, códigos de vinculación, tokens ni sus hashes. Conservar historia por compensaciones o entradas nuevas.

## Contrato y frontend afectados

Extender `contracts/openapi/authentication.yaml` y documentar:

- Alta administrativa con dos variantes de datos y `rol_id` obligatorio; campos contradictorios rechazados.
- Generación de código con `up_id` y exposición segura de la UP de vinculación.
- Selector y login compartidos limitados por UP.
- Endpoints propios propuestos `PATCH /api/v1/auth/contrasena`, `PATCH /api/v1/auth/pin` y `PUT /api/v1/auth/pin-operativo`, con Requests y payloads exclusivos por propósito. Son operaciones de credenciales, sin desarrollo de perfil.
- Administración explícita de UP de usuario y revinculación del dispositivo, con respuestas 409 cuando haya trabajo abierto o un conflicto de estado.
- Correo nullable, modalidad y contexto UP en la respuesta de identidad/sesión necesaria para el acceso. No devolver material secreto ni hashes.
- Errores genéricos de credenciales, sesión/contexto expirados, modalidad incompatible y falta de PIN operativo.
- Respuesta de cambio/restablecimiento de credencial que indique la necesidad de reingreso. En cambios propios, el frontend limpia la sesión de usuario y vuelve al acceso correspondiente; la vinculación del dispositivo permanece.

En frontend: dos variantes de registro, selector de rol con “Sin rol”, UP obligatoria sólo para acceso PIN, UP elegida por Admin al vincular, selector limitado y confirmación operativa con PIN. La modalidad viene del backend y no es editable. Una sesión PIN no ofrece selector para cambiar su UP.

Actualizar las guías de identidad, roles, estructura, repartos y contratos de los módulos afectados. Al implementar, sustituir la regla anterior de acceso empresarial para el contexto PIN en las reglas compartidas del proyecto, usando `record-rule` si Boost está disponible. Mantener empresarial el contexto personal con correo. No introducir permisos con IDs de UP.

## Etapas y condiciones de aceptación

| Etapa | Entregable |
| --- | --- |
| 1. Cerrar decisiones | Modalidades, rol “Sin rol”, distinción de PIN y matriz de operaciones por UP; resolver los puntos finales indicados debajo. |
| 2. Datos y registro | Migraciones, enum, restricciones y seeders; altas válidas de ambas modalidades y ninguna cuenta sin rol. |
| 3. Autenticación | Conservar `can_login_*`, bloquear “Sin rol”, vincular códigos/dispositivos a UP y validar elegibilidad completa al emitir sesiones. |
| 4. Aislamiento real | Policies, Queries y Actions de todos los módulos accesibles desde PIN; incluir referencias, reportes, operaciones entre unidades y reparto sin carga. |
| 5. Credenciales propias y confirmación | Cambio y recuperación del mismo tipo, sesiones revocadas correctamente y PIN operativo separado de login. |
| 6. Integración y verificación | Contratos, frontend de acceso/registro, pruebas focalizadas y regresiones; documentación del comportamiento entregado. |

Pruebas previstas, con PHPUnit y PostgreSQL aislado:

- Matriz completa de altas válidas e inválidas: combinaciones incompletas, contradicciones de modalidad, correo duplicado, UP ausente/inactiva, PIN con cero inicial y rol ausente.
- “Sin rol” con ambas modalidades: no autentica, no aparece en el selector PIN y no obtiene cookies, tokens ni sesiones nuevas. Tampoco ingresa enviando manualmente su ID.
- Asignar “Sin rol” revoca las sesiones web, nativas y compartidas existentes e impide consultas de identidad, cambios propios de credencial y acceso al negocio. Conserva la vinculación del dispositivo para otros usuarios. Reasignar un rol habilitado no revive sesiones anteriores.
- Los permisos de canal siguen siendo necesarios y no sustituyen la modalidad: retirarlos bloquea el ingreso correspondiente y revoca sus sesiones.
- La cuenta con correo nunca accede por PIN, aunque tenga PIN operativo; la cuenta PIN nunca obtiene login personal mediante campos manipulados, reset de contraseña o cambio de rol.
- Admin puede configurar, cambiar y restablecer su PIN operativo probando su contraseña actual; se almacena de forma segura y no se elimina por su rol. Ese PIN no habilita login compartido ni amplía permisos. No se agregan tareas nuevas con confirmación hasta recibir el listado del usuario.
- Un código de UP A no vincula a UP B; vencimiento, consumo único, creador deshabilitado y UP inactiva se validan durante el canje.
- Dispositivo A lista sólo usuarios PIN de A. Enviar manualmente un usuario de B no autentica ni bloquea sus contadores personales.
- Para cada operación expuesta a PIN: lectura directa, búsqueda, totales, mutación y relaciones anidadas de otra UP quedan inaccesibles. Validar ambas puntas de movimientos y recursos globales.
- Un usuario registrado en A con permiso funcional no puede operar en B manipulando rutas, payloads, filtros o relaciones. Si origen o destino pertenece a B, se rechaza toda la operación sin cambios parciales, movimientos de stock ni asientos de negocio.
- Reportes/exportaciones no revelan otra UP; comprobar generación, consulta, descarga y cambios de contexto posteriores.
- Repartos confirma con el PIN correspondiente, respeta conductor y UP en carga/devolución y no deja efectos si falta el PIN operativo.
- Cambios propios requieren credencial actual y confirmación; no permiten elegir otro usuario, modificar correo/modalidad/UP ni otorgar permisos.
- Revocación y cambio de credenciales funcionan en cookie web y PAT nativo, incluso con caché o relaciones cargadas. Las credenciales de dispositivo y de empleado siguen separadas.
- Cambiar o restablecer contraseña, PIN de acceso o PIN operativo revoca todas las sesiones del usuario afectado, incluida la actual y las de otros dispositivos. Cubrir cambios propios, administrativos y PIN operativo de Admin; no emitir una sesión sustituta ni revocar sesiones ajenas o la vinculación física. Los intentos fallidos no aplican el cierre de sesiones por cambio exitoso.
- Se conservan los bloqueos por intentos; la confirmación operativa no evade ni reinicia límites de acceso.
- Falla de auditoría revierte registro, asignación, vinculación, credenciales y revocaciones.
- Concurrencia: doble canje, login contra cambio de UP o PIN, baja de UP y revocación del dispositivo. No emitir sesiones válidas con contexto obsoleto.
- Seeders idempotentes: Admin mantiene su alcance, “Sin rol” permanece vacío y las preferencias de otros roles se conservan.

Ejecutar primero pruebas de identidad y autorización, después las de cada dominio modificado y sus regresiones relevantes. Pint y PHPStan según el alcance. Los datos actuales son ficticios y el usuario autorizó reconstruir el entorno de prueba si hace falta; no es necesario hacerlo durante la planificación. La transición de cuentas actuales con correo y PIN debe clasificarse explícitamente: no inferir que pasan a modalidad PIN por tener un hash existente.

## Decisiones finales y trabajo diferido

1. **UP en cuentas con correo:** se implementa sin asignación de UP de acceso en esta entrega. Una relación informativa opcional requiere una definición futura independiente.
2. **PIN de confirmación para cuentas PIN:** se reutiliza el mismo PIN de acceso; las cuentas con correo usan un hash operativo independiente.
3. **Listado de tareas de Admin con confirmación:** el usuario lo proporcionará en otra instancia. La posibilidad de configurar un PIN operativo para Admin ya está confirmada; sólo queda diferida su aplicación a tareas nuevas.
4. **Correo mal registrado:** no se agrega un endpoint de corrección en esta entrega. Una excepción administrativa requiere definición posterior, conservando siempre la modalidad.
5. **Recursos globales:** se aplica la matriz documentada en la guía de implementación; las lecturas operativas omiten saldos empresariales y las mutaciones globales o entre UP quedan denegadas a cuentas restringidas.

Estos puntos distinguen propuestas de diseño pendientes y definiciones diferidas; no describen capacidades implementadas. El PIN operativo para Admin, el cierre de todas las sesiones al cambiar contraseña o cualquier PIN y la denegación de operaciones fuera de la UP de registro ya están confirmados. El perfil futuro está excluido y no requiere resolver su diseño para implementar este alcance.

## Verificación original de planificación

Se revisaron el esquema versionado de usuarios, dispositivos, códigos y sesiones; Actions de registro y vinculación; validadores y transporte de sesión; catálogo y Policies de roles; cambio administrativo de PIN; confirmación de Repartos; modelos de cuentas y UP; documentación y versiones instaladas. Laravel Boost no está expuesto entre las herramientas de esta sesión, por lo que se utilizaron las herramientas CLI y fuentes oficiales compatibles.

La entrega original de planificación sólo agregó este documento y su enlace en el índice. La implementación posterior, sus migraciones, contratos y pruebas se registran en la guía de comportamiento entregado enlazada al inicio.
