---
paths:
  - 'database/seeders/**'
---

# Seeders

## Admin conserva todos los permisos de la aplicación
IdentityPermissionSeeder centraliza el catálogo y sincroniza admin con todos los permisos registrados del guard web. AdminUserSeeder reutiliza esa carga; no mantener listas parciales independientes que recorten permisos. Al agregar módulos, registrar sus permisos en el catálogo central o antes de sincronizar el rol. Login y /me deben exponer todos los permisos efectivos para habilitar las pantallas del frontend.
