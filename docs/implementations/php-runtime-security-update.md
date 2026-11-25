# Actualización de seguridad de PHP en Docker

PHP 8.5.12 corrigió CVE-2026-109618 y CVE-2026-109619, dos vulnerabilidades críticas que pueden permitir ejecución remota de código sin autenticación. La base actual de desarrollo y producción es la imagen oficial `php:8.5.13-fpm-alpine3.24`, fijada por digest en el `Dockerfile`. [Aviso oficial de PHP 8.5.12](https://www.php.net/releases/8_5_12.php)

CI y el despliegue toman la versión del mismo `Dockerfile`. La actualización se aplica al reconstruir y recrear los servicios PHP. El cambio no requiere migraciones ni modifica el contrato HTTP.

La actualización a 8.5.12 se verificó en Compose local en la API, las colas, el planificador y el monitor. El endpoint de salud respondió a través del gateway y pasaron 30 pruebas de autenticación, PIN y clientes en PostgreSQL aislado. La imagen de producción se construyó localmente y pasó `php-fpm -t`.

## Instalación de PhpRedis

La etapa compartida `php-base` compila PhpRedis 6.3.0 desde el commit publicado por el proyecto en GitHub. Así, una compilación limpia puede obtener la extensión aunque PECL devuelva un error al servir su archivo. El commit fija la misma versión que intentaba descargar la CI. [Release oficial de PhpRedis](https://github.com/phpredis/phpredis/releases/tag/6.3.0)
