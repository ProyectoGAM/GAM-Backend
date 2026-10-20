# Documentación del backend GAM

Este directorio reúne la documentación de arquitectura, operación y seguimiento del proyecto. Las decisiones y cambios funcionales del backend se documentan en [implementations](implementations/README.md).

## Guías generales

- [Arquitectura](architecture.md): estructura de la aplicación y responsabilidades de cada capa.
- [Bibliotecas](libraries.md): dependencias principales y motivos de uso.
- [Seguimiento de módulos](notion.md): estado local preparado para sincronizar con el tablero de Notion.
- [Auditoría de skills](skills-audit-report.md): evaluación histórica de las instrucciones de desarrollo.

## Contratos de API

Los contratos OpenAPI permanecen en `contracts/openapi` porque Swagger UI y las pruebas del repositorio los leen desde esa ubicación. Se consultan desde [el directorio de contratos](../contracts/openapi/) o desde Swagger UI en el entorno de desarrollo.

## Cambios y contribuciones

Antes de cada commit o PR que incluya una implementación, corrección o cambio de comportamiento del backend, se debe crear o actualizar la guía correspondiente en [docs/implementations](implementations/README.md) dentro del mismo cambio. El [README principal](../README.md) describe el contenido mínimo y cómo verificar que la documentación esté lista.
