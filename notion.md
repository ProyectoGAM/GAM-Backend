# Entrega multi-login GAM

La entrega del plan de identidad y acceso quedó implementada en el backend y el frontend.

- Login personal web con cookie stateful y CSRF.
- Login personal nativo con PAT y sesiones revocables.
- Vinculación de dispositivos compartidos por código de un solo uso.
- Selector de empleados y PIN exacto de cuatro dígitos, con Argon2id y pepper de despliegue.
- Revocación local/remota, bloqueo y desbloqueo de PIN, sesiones personales y auditoría transaccional.
- Contrato OpenAPI en `contracts/openapi/authentication.yaml`.

La validación ejecutada con Docker Compose está registrada en la entrega del agente: suite PHPUnit, Pint, lint/tests/build Angular, auditoría de dependencias y `cap sync`. Los builds firmados Android/iOS y la validación de navegador físico quedan sujetos a sus toolchains/plataformas respectivas.
