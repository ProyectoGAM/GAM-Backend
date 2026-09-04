# Auditoría de skills de Codex — GAM-Backend

## Propósito

Informe de auditoría estática para que otro LLM pueda revisar la calidad, activación, alcance, duplicación, dependencias y cobertura de las skills del repositorio.

La auditoría fue de solo lectura. No se ejecutaron scripts de skills, PHPUnit, migraciones, seeders, Docker Compose, Artisan, Pint ni PHPStan. No se instalaron dependencias ni se modificó configuración.

## 1. Veredicto ejecutivo

El repositorio contiene 6 skills y 39 archivos por árbol. Todas tienen frontmatter básico válido, nombres únicos y referencias Markdown internas resolubles.

Conclusión por skill:

- configuring-horizon: útil, pero contiene un error técnico sobre balance false y necesita adaptación al servicio Compose existente.
- infer-conventions: útil como concepto, pero mezcla descubrimiento, confirmación, persistencia y coordinación de subagentes. Debe dividirse y su fase de escritura debe deshabilitarse para activación implícita.
- laravel-best-practices: útil como skill base, pero contradice reglas obligatorias del repositorio al permitir validación inline y al no diferenciar revisión de implementación.
- pulse-development: técnicamente válida, pero demasiado monolítica y comienza por instalación aunque Pulse ya está instalado.
- tailwindcss-development: técnicamente coherente con Tailwind 4, pero se activa con demasiados falsos positivos.
- testing-best-practices: valiosa, pero tiene referencias PHPUnit 13.3 para un proyecto con PHPUnit 12.5, prescribe paralelismo sin ParaTest instalado y contiene supuestos incompatibles con la arquitectura modular y el acceso company-wide.

No se recomienda dejar ninguna skill completamente sin cambios. No hay una candidata inmediata a retirar definitivamente.

El mayor riesgo transversal es Boost MCP: .mcp.json intenta iniciar php artisan boost:mcp, pero en el entorno observado no existe PHP en PATH ni vendor/autoload.php. Por tanto search-docs y record-rule no están garantizados para futuros agentes.

Existe una carencia real para una futura gam-critical-mutation-review, siempre que sea una skill explícita y de solo lectura que integre transacciones, locks, idempotencia, auditoría, compensaciones, permisos, contratos y OpenAPI sin copiar AGENTS.md.

## 2. Fuentes y limitaciones

Fuentes inspeccionadas:

- AGENTS.md
- CLAUDE.md
- .agents/skills/**
- .claude/skills/**
- boost.json
- .mcp.json
- composer.json
- composer.lock
- package.json
- README.md
- architecture.md
- phpunit.xml
- .phpstan-lots.neon
- compose.dev.yaml
- config/horizon.php
- config/pulse.php
- routes/console.php
- app/Providers/HorizonServiceProvider.php
- referencias internas de cada skill

No existen AGENTS.md, AGENTS.override.md o CLAUDE.md anidados.

Los tres subagentes solicitados fueron lanzados, pero terminaron por límite de cuota antes de responder. El agente principal repitió localmente las tres áreas: inventario estructural, semántica e integración.

Documentación primaria consultada:

- https://learn.chatgpt.com/es-419/docs/build-skills
- https://learn.chatgpt.com/es-419/docs/customization/overview
- https://laravel.com/framework/docs/13.x/horizon
- https://laravel.com/framework/docs/13.x/pulse
- https://docs.phpunit.de/en/12.5/
- https://api.laravel.com/docs/13.x/Illuminate/Testing/TestResponse.html
- https://tailwindcss.com/docs/theme
- https://github.com/nunomaduro/collision/blob/v8.x/src/Adapters/Laravel/Commands/TestCommand.php

## 3. Inventario completo

| Skill | Archivos | Bytes totales | SKILL.md | Referencias | Scripts/assets/openai.yaml |
|---|---:|---:|---:|---:|---|
| configuring-horizon | 5 | 9.611 | 3.589 / 85 líneas | 4 | 0 / 0 / no |
| infer-conventions | 2 | 24.455 | 12.367 / 104 líneas | 1 | 0 / 0 / no |
| laravel-best-practices | 20 | 74.369 | 4.644 / 59 líneas | 19 rules | 0 / 0 / no |
| pulse-development | 1 | 6.932 | 6.932 / 204 líneas | 0 | 0 / 0 / no |
| tailwindcss-development | 1 | 3.462 | 3.462 / 96 líneas | 0 | 0 / 0 / no |
| testing-best-practices | 10 | 28.578 | 4.183 / 57 líneas | 9 rules | 0 / 0 / no |

Todos los SKILL.md tienen:

- frontmatter entre las líneas 1 y 7;
- name coincidente con la carpeta;
- description presente;
- license MIT;
- metadata.author laravel;
- nombres únicos.

Alcance probable:

- Horizon, Laravel best practices y testing son plantillas reutilizables, parcialmente adaptadas al repositorio.
- Pulse es reutilizable para proyectos Laravel con Pulse, pero su contenido asume instalación y operaciones.
- Tailwind está adaptada de facto a Tailwind 4.
- Infer conventions está adaptada a Laravel Boost y al sistema .ai/rules.

Referencias operativas principales:

- Horizon: config/horizon.php, HorizonServiceProvider, routes/console.php, /horizon, comandos horizon:*.
- Infer: composer.json, package.json, configuración Pint/PHPStan/Rector, .ai/rules/index.md y árbol app/.
- Laravel best practices: reglas de modelos, routing, validación, seguridad, migraciones, colas, cache, HTTP, excepciones, mail, scheduling, Blade y arquitectura.
- Pulse: config/pulse.php, AppServiceProvider, vista vendor/pulse, migración Pulse, /pulse y comandos pulse:*.
- Tailwind: resources/css/app.css, templates Blade y JavaScript.
- Testing: phpunit.xml, tests/, .env.testing, tests/Fixtures, PHPUnit, Collision, Mockery y ParaTest.

## 4. Comparación .agents/skills versus .claude/skills

Hechos:

- Ambos árboles contienen 39 archivos.
- Los 39 archivos son idénticos por SHA-256.
- Hay 0 archivos diferentes.
- Hay 0 archivos exclusivos de una ubicación.
- Cada árbol ocupa 147.407 bytes.
- La duplicación física total es 294.814 bytes.

Para Codex, .agents/skills es la ubicación efectiva de descubrimiento del repositorio. La documentación oficial de skills de Codex describe .agents/skills como ubicación de skills del repositorio.

No existe dentro del repositorio una fuente declarada que regenere ambas copias. Por commit común, metadata author laravel y boost.json, es razonable inferir que son proyecciones generadas por Laravel Boost, pero no es un hecho comprobable solo con estos archivos.

Riesgos:

- una edición futura puede cambiar una copia y no la otra;
- la revisión humana se duplica;
- un indexador que lea ambas puede duplicar contexto;
- Codex y Claude pueden divergir por instrucciones superiores distintas aunque las skills sean iguales.

Recomendación: declarar una fuente de verdad o mecanismo de regeneración. No mantener ambas copias manualmente.

## 5. Puntuación

Cada dimensión puntúa de 0 a 3: validez estructural, precisión de activación, responsabilidad única, claridad/ejecutabilidad, divulgación progresiva, dependencias/referencias, ausencia de duplicación innecesaria y validación del resultado.

| Skill | Estructura | Activación | Responsabilidad | Claridad | Divulgación | Dependencias | No duplicación | Validación | Total |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| configuring-horizon | 3 | 2 | 3 | 1 | 3 | 2 | 2 | 2 | 18 |
| infer-conventions | 3 | 1 | 1 | 2 | 1 | 1 | 1 | 2 | 12 |
| laravel-best-practices | 3 | 2 | 2 | 2 | 3 | 2 | 1 | 2 | 17 |
| pulse-development | 3 | 1 | 2 | 2 | 1 | 2 | 2 | 2 | 15 |
| tailwindcss-development | 3 | 1 | 3 | 2 | 2 | 2 | 3 | 1 | 17 |
| testing-best-practices | 3 | 2 | 3 | 1 | 3 | 1 | 1 | 2 | 16 |

Interpretación:

- 21–24: mantener.
- 17–20: mantener con correcciones.
- 12–16: revisión importante.
- 7–11: dividir, fusionar o deshabilitar.
- 0–6: retirar.

Excepción: infer-conventions debe deshabilitar su fase de escritura aunque obtenga 12, porque puede mutar reglas durables y contradice la autoridad de AGENTS.md.

## 6. Problemas de frontmatter y estructura

No hay frontmatter inválido, names duplicados ni enlaces Markdown internos rotos.

Problemas menores:

- Ninguna skill declara dependencias mediante agents/openai.yaml. Esto es opcional para la estructura, pero importante para declarar MCP requerido.
- infer-conventions anuncia aproximadamente 49 dimensiones, pero su checklist salta del ítem 30 al 32 y contiene 48 ítems numerados.
- infer-conventions usa hints basados en grep y ls. En Windows no son garantías de disponibilidad; deben existir alternativas con rg o PowerShell.
- Las carpetas rules/ son válidas, aunque references/ sería más convencional para contenido cargable.

No hay scripts, assets ni rutas absolutas dependientes de otra máquina.

## 7. Activación y descripciones

### configuring-horizon

Problemas:

- “Whenever the user mentions Horizon” es demasiado amplio.
- La descripción promete Sail, pero boost.json tiene sail false y el cuerpo no desarrolla Sail.
- El proyecto usa un servicio Compose llamado horizon.

Descripción recomendada:

Use for installing, configuring, operating, or troubleshooting Laravel Horizon in this repository, including /horizon, horizon:*, Redis supervisors, balancing, waits, metrics, tags, and dashboard authorization. Trigger on explicit Horizon terms or Horizon-specific symptoms. Do not use for generic queues, non-Redis drivers, standalone Redis, Pulse, Telescope, batches, or OS process supervision. Inspect the installed package and Compose horizon service before proposing installation or host commands.

### infer-conventions

Problemas:

- Activa análisis y escritura ante solicitudes amplias como “onboard agents”.
- Mezcla auditoría, confirmación, persistencia y coordinación.
- No trata AGENTS.md y architecture.md como autoridad superior.

Descripción recomendada:

Use only when the user explicitly asks to audit or infer recurring repository conventions and report evidence or conflicts. Treat AGENTS.md, architecture, OpenAPI contracts, and existing path rules as authoritative. Default to read-only analysis; do not record rules or edit files without a separate explicit request. Do not use for ordinary code review, formatting, or implementation.

### laravel-best-practices

Problemas:

- Activa con prácticamente cualquier PHP Laravel.
- Incluye pasos de edición y ejecución aunque la solicitud sea revisión.
- No explicita que las reglas del repositorio superan sus defaults genéricos.

Descripción recomendada:

Use when writing, refactoring, or reviewing Laravel backend PHP. On review or reporting tasks remain read-only. Project AGENTS.md, architecture, OpenAPI contracts, and path-scoped rules override observed convention and generic defaults. Do not use for tests-only work, Pulse/Horizon operations, frontend styling, or non-Laravel PHP.

### pulse-development

Problemas:

- “Application monitoring” puede activar la skill para Health, logs, Horizon u observabilidad genérica.
- Comienza por instalación aunque Pulse ya existe.

Descripción recomendada:

Use for explicit Laravel Pulse setup, dashboard authorization, recorder configuration, filtering, Redis ingest, custom Pulse cards, Pulse::record(), /pulse, or pulse:* commands. Do not use for generic monitoring, Laravel Health, logging, Horizon, Telescope, or unrelated infrastructure metrics. Inspect the existing installation and Compose pulse service first.

### tailwindcss-development

Problemas:

- Se activa ante grid, cards o forms aunque no se mencione Tailwind.
- La descripción dice v3/v4 y el cuerpo obliga v4.

Descripción recomendada:

Use when editing Tailwind CSS v4 utilities or CSS-first theme tokens in this repository’s Blade or JavaScript templates, or when the user explicitly requests Tailwind changes. Do not activate for generic layout discussion, vanilla CSS, backend/API work, CSS audits without utility changes, or build configuration alone.

### testing-best-practices

Problemas:

- Menciona Pest aunque Pest no está instalado.
- No distingue diseño/revisión de mera ejecución.
- No incorpora el flujo Docker oficial.

Descripción recomendada:

Use for designing, writing, diagnosing, or reviewing Laravel PHPUnit tests in this repository: coverage, assertions, data, isolation, HTTP/security boundaries, and suite performance. Use PHPUnit 12.5 and the repository’s Docker test workflow. Do not use Pest guidance, do not assume tenancy, and do not load design rules merely to run an already-selected test unless diagnosis is requested.

## 8. Duplicaciones y contradicciones

### Reglas duplicadas o mal ubicadas

- AGENTS.md contiene idioma, FormRequests, auditoría, permisos, capacidad, transacciones y comentarios de pruebas. Estas son reglas durables del repositorio, no defaults de una skill genérica.
- laravel-best-practices repite parcialmente FormRequests, autorización, transacciones, locks y arquitectura.
- testing-best-practices repite pruebas, fakes, seguridad y assertions que también aparecen como enlaces en Laravel best practices.
- Horizon y queue-jobs comparten Redis, timeouts y workers; la duplicación es tolerable porque una es operacional y la otra transversal.
- Pulse y Horizon se solapan solo en “monitoring”; deben permanecer separadas.

### Contradicciones principales

1. FormRequest obligatorio:

   - AGENTS.md:27 exige FormRequest para todo request de controlador.
   - validation.md:3-6 y routing.md:106 permiten validación inline.

2. Mayoría observada:

   - infer-conventions/SKILL.md:15 manda seguir la mayoría aunque contradiga FormRequests u otras reglas decididas.

3. Escritura manual:

   - infer-conventions/SKILL.md:67 y 100 permiten escribir reglas manualmente.
   - AGENTS.md:87 exige siempre record-rule.

4. Layout de pruebas:

   - testing-best-practices/rules/naming.md:6 exige reflejar exactamente la ruta de la clase.
   - architecture.md:121-123 organiza tests por tests/Feature/<Modulo> y tests/Unit/<Modulo>.

5. Tenancy:

   - endpoint-tests.md:13-24 y security.md:7 empujan casos cross-tenant.
   - AGENTS.md:33 establece acceso company-wide a unidades de producción y prohíbe filtros por unidad.

6. Comentarios:

   - AGENTS.md:143 exige comentarios en español antes de cada prueba y acción significativa.
   - assertions.md:5 sugiere que la separación AAA basta sin comentarios.

7. Idioma público:

   - AGENTS.md:25-26 exige contrato público en español.
   - varios ejemplos usan mensajes, rutas y contenido público en inglés.

## 9. Divulgación progresiva y contexto

Coste de almacenamiento de .agents/skills:

- infer-conventions: 24.455 B cargables; su SKILL.md ya ocupa 12.367 B y ordena abrir el checklist de 12.088 B.
- laravel-best-practices: 74.369 B; su índice es bueno, pero una tarea transversal puede abrir muchos rules.
- testing-best-practices: 28.578 B; índice correcto, pero sus nueve rules son voluminosas.
- pulse-development: 6.932 B monolíticos, sin referencias.
- Horizon: buena separación en cuatro referencias.
- Tailwind: 3.462 B, tamaño aceptable.

Activaciones simultáneas de riesgo:

- Laravel + testing: 8.827 B de bases antes de rules.
- Pulse + Tailwind para cards: 10.394 B sin divulgación intermedia.
- Infer: 24.455 B en una activación normal.

Recomendaciones:

- dividir infer entre auditoría read-only y persistencia explícita;
- mover instalación, recorders y custom cards de Pulse a references;
- acortar descriptions;
- mantener indexes, pero exigir carga selectiva.

## 10. Dependencias y comandos inválidos

Versiones:

- Laravel 13.29.0
- Horizon 5.48.3
- Pulse 1.8.1
- Boost 2.7.0
- PHPUnit 12.5.34
- Larastan 3.10.0
- Livewire 4.4.2
- Tailwind 4.x

Hallazgos críticos:

1. testing-best-practices usa documentación PHPUnit 13.3 en SKILL.md:11, assertions.md:14 y performance.md:5. El proyecto instala PHPUnit 12.5.34.
2. performance.md:28 prescribe php artisan test --parallel, pero brianium/paratest no aparece en composer.lock. Collision requiere ParaTest para esa opción.
3. assertions.md:29 dice que assertSee hace innecesaria la aserción de status. La API oficial define assertSee como comprobación de contenido, no de código HTTP.
4. security.md:22-23 exige que una prueba XSS contenga literalmente <script> mientras intenta probar escape. El ejemplo es defectuoso.
5. Horizon references/supervisors.md:21-23 afirma que balance false fija el número de workers. Horizon 13.x documenta que false aún escala entre minProcesses y maxProcesses; el número fijo usa balance simple y processes.
6. Pulse SKILL.md:15-23 comienza por composer require, vendor:publish y migrate aunque Pulse ya está declarado, configurado y migrado.
7. El MCP de .mcp.json no puede iniciar en el host observado porque no hay php en PATH ni vendor/autoload.php.
8. Infer conventions referencia pint.json, rector.php y phpstan.neon como si fueran archivos principales; en el repositorio solo existe .phpstan-lots.neon. La skill debe decir “si existe”.
9. Los comandos de testing de las skills no reflejan el flujo oficial de README.md, que ejecuta PHPUnit dentro del servicio api con DB_DATABASE=gam_test.

Validaciones positivas:

- config/horizon.php existe.
- config/pulse.php existe.
- routes/console.php existe.
- Compose tiene servicios api, horizon, scheduler y pulse.
- Pulse tiene migración, configuración y vista publicada.
- Tailwind 4 coincide con package.json y resources/css/app.css usa @import tailwindcss.
- Horizon documenta correctamente Redis, horizon:snapshot, viewHorizon, tags y waits salvo el error de balance false.

## 11. Matriz rol–skill

O = obligatoria; C = útil bajo condición; I = innecesaria; X = contraproducente/deshabilitar.

| Rol | Horizon | Infer conventions | Laravel best | Pulse | Tailwind | Testing |
|---|---:|---:|---:|---:|---:|---:|
| boundary_explorer | C | X | O | C | X | C |
| test_runner | C | X | X | C | X | O |
| reviewer | C | X | O | C | C | O |
| security_reviewer | C | X | O | C | I | O |
| docs_researcher | C | X | C | C | C | C |

Deshabilitación explícita:

- boundary_explorer: infer-conventions y Tailwind; Horizon/Pulse solo si están en la frontera.
- test_runner: infer-conventions, Laravel best practices y Tailwind; Horizon/Pulse solo si se prueba ese dominio.
- reviewer: infer-conventions; skills de producto solo si el diff las toca.
- security_reviewer: infer-conventions y Tailwind; Horizon/Pulse solo para dashboards, telemetría o acceso.
- docs_researcher: infer-conventions y skills de implementación salvo investigación puntual del dominio.

Infer conventions es especialmente contraproducente para agentes read-only porque su flujo termina en record-rule o fallback manual.

## 12. Skills para mantener

Ninguna skill completa debería permanecer sin cambios.

Partes que sí son reutilizables:

- índice de rules de Laravel;
- índice de rules de testing;
- referencias Horizon de métricas, tags y notificaciones, salvo actualización de versión;
- contenido técnico Tailwind 4;
- procedimientos Pulse posteriores al preflight.

## 13. Skills que necesitan correcciones

- configuring-horizon: estrategia de balance, Sail, Compose y preflight.
- infer-conventions: autoridad, escritura, concurrencia, detección de frontend y comandos Windows.
- laravel-best-practices: FormRequest, idioma, review read-only y autoridad de AGENTS.
- pulse-development: activación, instalación previa y referencias.
- tailwindcss-development: activación y v4 explícito.
- testing-best-practices: PHPUnit, ParaTest, assertions, XSS, layout modular, tenancy, comentarios y Docker.

## 14. Dividir, fusionar o deshabilitar

Dividir infer-conventions en:

1. convention-audit: read-only, evidencia y conflictos.
2. convention-recording: explícita, solo después de aprobación, dependiente de record-rule.

Deshabilitar implícitamente convention-recording.

No fusionar Laravel/testing, Horizon/Pulse ni Pulse/Tailwind.

Mantener Pulse como una skill mientras separe instalación, operaciones y custom cards mediante referencias. Solo dividir custom cards si se demuestra recurrencia.

No retirar ninguna skill ahora.

## 15. Carencias reales

No existe una skill con el flujo de ejecución de pruebas del repositorio. Esa información puede mantenerse en README.md, AGENTS.md o testing-best-practices; por sí sola no justifica una nueva skill.

Sí existe una carencia de revisión integrada de mutaciones críticas. Las reglas actuales están repartidas entre AGENTS.md, architecture.md, Laravel best practices y testing.

## 16. Evaluación de gam-critical-mutation-review

Recomendación: crearla en una fase posterior, no ahora.

Evidencia de recurrencia:

- 51 Actions de aplicación.
- 41 archivos con transacciones.
- 36 archivos con lockForUpdate.
- 44 archivos con AuditRecorder.
- 42 archivos con AuditEntryData.
- 80 archivos con señales de idempotencia u operation_id.

Debe aceptar como entrada un diff, Action o endpoint crítico y producir una matriz read-only:

- Action dueña;
- frontera transaccional;
- orden de locks;
- idempotencia y replay;
- auditoría síncrona y snapshot allowlisted;
- compensaciones;
- permisos;
- contratos intermodulares;
- OpenAPI;
- rollback;
- concurrencia;
- cobertura de éxito, conflicto y fallo.

No debe copiar AGENTS.md ni implementar cambios. Debe activarse solo para frases como “revisar mutación crítica”, “auditar concurrencia” o “validar operación transaccional”.

Disponibilidad recomendada:

- reviewer: obligatoria bajo condición;
- security_reviewer: útil bajo condición;
- boundary_explorer: útil bajo condición;
- test_runner: deshabilitada;
- docs_researcher: deshabilitada.

## 17. Plan priorizado

1. Corregir PHPUnit 12.5, ParaTest, assertSee y el ejemplo XSS.
2. Eliminar la contradicción FormRequest inline.
3. Resolver el runtime MCP de Boost.
4. Dividir infer-conventions y eliminar escritura manual.
5. Corregir Horizon balance false y adaptar comandos a Compose.
6. Reestructurar Pulse con preflight y references.
7. Limitar activación de Tailwind a cambios reales.
8. Definir la fuente de verdad entre .agents y .claude.
9. Sincronizar las reglas críticas ausentes de CLAUDE.md.
10. Diseñar gam-critical-mutation-review después de estabilizar las reglas existentes.

## 18. Preguntas pendientes

- ¿Cuál es la fuente oficial para regenerar .agents/skills y .claude/skills?
- ¿Boost MCP debe ejecutarse desde PHP local o desde el servicio api?
- ¿CLAUDE.md y .claude/skills siguen siendo consumidores activos?
- ¿resources/ contiene frontend mantenido o solo el esqueleto Laravel?
- ¿Los futuros reviewers serán estrictamente read-only?
- ¿gam-critical-mutation-review debe aceptar solo diffs o también Actions existentes?

## 19. Estado Git

Durante la auditoría original:

- estado inicial: limpio;
- estado final: limpio;
- archivos de skills modificados: ninguno.

Este archivo fue creado posteriormente por una solicitud explícita del usuario para poder transferir el informe a otro LLM. Por tanto, la creación del presente archivo es un cambio deliberado y separado de la auditoría read-only.

## Referencias locales principales

- AGENTS.md: C:/Users/Zar/Documents/Programacion/UTEC/ProyectoFinal/GAM-Backend/AGENTS.md
- CLAUDE.md: C:/Users/Zar/Documents/Programacion/UTEC/ProyectoFinal/GAM-Backend/CLAUDE.md
- architecture.md: C:/Users/Zar/Documents/Programacion/UTEC/ProyectoFinal/GAM-Backend/architecture.md
- README.md: C:/Users/Zar/Documents/Programacion/UTEC/ProyectoFinal/GAM-Backend/README.md
- Skills Codex: C:/Users/Zar/Documents/Programacion/UTEC/ProyectoFinal/GAM-Backend/.agents/skills
- Skills Claude: C:/Users/Zar/Documents/Programacion/UTEC/ProyectoFinal/GAM-Backend/.claude/skills
