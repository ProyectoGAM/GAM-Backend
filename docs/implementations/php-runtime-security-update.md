# Actualización de seguridad de PHP en Docker

El `Dockerfile` fija la imagen oficial `php:8.5.12-fpm-trixie` como base de desarrollo y producción. PHP 8.5.12 corrige CVE-2026-109618 y CVE-2026-109619, dos vulnerabilidades críticas que pueden permitir ejecución remota de código sin autenticación. [Aviso oficial de PHP](https://www.php.net/releases/8_5_12.php)

CI y el despliegue toman la versión del mismo `Dockerfile`. La actualización se aplica al reconstruir y recrear los servicios PHP. El cambio no requiere migraciones ni modifica el contrato HTTP.

En el Compose local se comprobó PHP 8.5.12 en la API, las colas, el planificador y el monitor. El endpoint de salud respondió a través del gateway y pasaron 30 pruebas de autenticación, PIN y clientes en PostgreSQL aislado. La imagen de producción se construyó localmente y pasó `php-fpm -t`. La verificación de producción queda para el despliegue de la imagen.
