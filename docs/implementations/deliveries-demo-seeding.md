# Seeders del reset de demo y datos de Repartos

## Carga en la VPS

El release con reset habilitado ejecuta `migrate:fresh --seed`. `DatabaseSeeder` carga permisos, administrador, departamentos y presets iniciales de reportes, y luego invoca `LocalDemoDataSeeder`. La carga incluye los roles y usuarios de dispositivo, los dos grupos de clientes, vehículos y la notificación de bienvenida, además de los datos de demo que ya se generaban. Con el reset deshabilitado, el release aplica las migraciones normales.

El stock sanitario conserva su configuración deshabilitada. `HealthStockDemoSeeder` sale sin crear movimientos y los catálogos de medicamentos y vacunas sí se cargan. `ManagementPlanExecutionDemoSeeder` sigue siendo una clase vacía. El reset verifica el permiso `can_view_admin_dashboard` del administrador, el lote `DEMO-LOT-A` y el reparto de demostración cerrado.

## Repartos de demostración

`DeliveriesDemoSeeder` se ejecuta después de los seeders de clientes, inventario y vehículos. Crea tres repartos cerrados del día para **Repartidor Demo**, cada uno con un vehículo propio.

| Matrícula | Zona | Paradas | Carga y devolución |
| --- | --- | --- | --- |
| `DEMO-2040` | Granja El Ombú, Las Piedras | 3 clientes, 2 entregas | 120 huevos cargados, 60 devueltos |
| `DEMO-2041` | Granja El Ombú, Las Piedras | 6 clientes, 5 entregas | 180 huevos cargados, 30 devueltos |
| `DEMO-2042` | Granja Santa Clara, Libertad | 12 clientes con coordenadas distintas, 10 entregas | 300 huevos cargados y entregados |

El reparto `DEMO-2042` crea sus doce clientes ficticios y permite revisar la distribución de marcadores en el mapa. Incluye entregas pagadas en efectivo o por transferencia, ventas fiadas y dos visitas sin entrega. El reparto original `DEMO-2040` conserva este detalle.

| Operación | Resultado |
| --- | --- |
| Carga inicial | 120 huevos |
| Almacén El Puente | 30 huevos, $300 UYU cobrados en efectivo |
| Comercio La Plaza | 30 huevos, $300 UYU fiados |
| Despensa Santa Rita | Visita sin entrega por comercio cerrado |
| Cierre | 60 huevos devueltos y vehículo liberado |

El seeder usa las acciones del módulo para registrar stock, paradas, ventas, cobros, cuenta corriente y auditoría. Reconoce cada reparto por su clave de inicio y agrega los escenarios faltantes sin duplicar los anteriores. En **Repartos → Historial**, filtrá por el día de la carga y elegí el reparto por su matrícula para ver las paradas en el mapa. Los vehículos quedan disponibles después del cierre.

El seeder registra un ingreso de huevos para abastecer cada carga. Las devoluciones de `DEMO-2040` y `DEMO-2041` dejan 90 huevos adicionales en el saldo de Granja El Ombú al terminar la carga general de la demo.

Para repetir únicamente este seeder en una base local ya preparada con los datos demo, ejecutá

```powershell
docker compose -f compose.dev.yaml exec -T api php artisan db:seed --class='Database\Seeders\Deliveries\DeliveriesDemoSeeder' --no-interaction
```

## Acceso de prueba en la VPS

Para entrar como repartidor, configurá `DEMO_DELIVERY_PASSWORD` y `DEMO_DELIVERY_PIN` en el `.env.production` de la VPS antes del reset. `DEMO_DELIVERY_EMAIL` permite elegir el correo, cuyo valor predeterminado es `repartidor.demo@gam.test`. Para probar los usuarios de dispositivo, configurá `DEMO_SHARED_USER_PIN`. Los PIN deben tener cuatro dígitos. Si se omiten estas credenciales, se generan valores aleatorios en producción y se pueden restablecer desde la gestión de usuarios. Los valores locales siguen disponibles en el Compose de desarrollo.

El seeder usa el contrato existente de [Repartos](../../contracts/openapi/deliveries.yaml).
