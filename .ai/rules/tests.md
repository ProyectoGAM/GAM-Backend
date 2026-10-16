---
paths:
  - 'tests/**'
---

# Tests

## Comprobar guardas de seeders sin confirmación interactiva
Cuando una prueba simula production para verificar la guarda de entorno de un seeder, invocar run mediante el contenedor ($this->app->call([new SeederClass, 'run'])). No usar $this->seed() para esa comprobación: db:seed pide confirmación en producción y puede fallar por el estado global de los prompts de otras pruebas. Reservar Artisan para pruebas del comando; esas pruebas deben declarar explícitamente --force o su interacción esperada.
