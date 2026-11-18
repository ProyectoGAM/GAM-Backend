# Granja-Milpa: inventario de casos de uso y brechas

**Corte de fuentes:** 18 de noviembre de 2026<br>
**Repositorios contrastados:** GAM-Backend develop en 01919a6 y GAM-Frontend develop en ed5c52d.<br>
**Propósito:** consolidar los casos de uso que aparecen en el Kanban, las notas originales y parseadas, los pedidos añadidos en las bitácoras y el checkout actualizado. Este documento conserva las contradicciones para que el equipo decida cuál es la regla vigente.

## 1. Alcance y lectura

Se revisaron las 27 cards visibles del Kanban del proyecto, la página principal Notas y sus cinco páginas de notas/relevamiento, las dos bitácoras, y el archivo adjunto Reportes_de_SGA.docx de la card 21. La página de notas parseadas marca Notas 1, 2 y 3 como procesadas; también se consideró Notas 4, que contiene decisiones y correcciones posteriores.

El inventario enumera los CU funcionales expresados o implícitos en esas fuentes. Los CU añadidos por una card y todavía ausentes de las notas parseadas se incluyen como extensiones. Los nombres de CU se normalizaron para que sean comparables; no se afirma que Notion ya los tenga numerados con estos identificadores.

### Estados usados para contrastar

- **En código:** hay implementación actual descrita en la documentación del repo y/o archivos de backend/frontend.
- **Parcial:** hay una parte funcional, pero falta alcance de negocio, integración o una validación necesaria.
- **Pendiente / por definir:** no se encontró evidencia de implementación suficiente o falta una decisión del cliente.
- **Aceptación pendiente:** el código puede existir, pero Notion conserva Do tests o falta aceptación del negocio/dispositivo.

Los estados del Kanban se transcriben tal como estaban al consultar Notion. No se ejecutaron pruebas para esta recopilación; “en código” no equivale a aceptación funcional.

## 2. Inventario de CU por módulo

### M01 — Usuarios, roles y permisos

**Kanban:** Do tests · prioridad Urgente.

- Registrar, consultar, editar, desactivar y rehabilitar usuarios.
- Autenticar una cuenta personal con correo y contraseña; cerrar sesión y administrar sus credenciales.
- Autenticar en un dispositivo compartido mediante selección de usuario y PIN, con vínculo del dispositivo y sesión vigente.
- Administrar roles, permisos granulares y acceso a módulos/acciones; consultar permisos efectivos.
- Asignar una unidad productiva a una cuenta de dispositivo compartido y limitar sus datos a esa UP.
- Permitir un PIN operativo de confirmación para acciones sensibles en cuentas personales, distinto del PIN de acceso compartido.
- Atender los perfiles nombrados en las notas: Admin, Reparto, Gestionador de Postura, Manejo de Granja y Elaborador de raciones; determinar el alcance de cada uno.
- Usar la app en una tablet compartida y en celulares personales; mantener como mínimo Android 12.

**Estado actual:** identidad, acceso, gestión de roles/permisos y las modalidades personal/compartida están implementadas en backend y frontend. La regla actual permite **un rol por cuenta**. La card y las notas de reunión dicen que una persona puede tener varios roles: ver §3. La documentación reciente sí implementa alcance por UP para cuentas PIN; las cuentas personales conservan alcance empresarial según sus permisos.

### M02 — Auditoría y trazabilidad

**Kanban:** In progress · prioridad Media.

- Consultar el historial de una entidad y reconstruir quién realizó cada operación, cuándo, desde qué módulo y con qué resultado.
- Filtrar y paginar entradas por entidad, actor, módulo, operación y período.
- Conservar correcciones y anulaciones como movimientos compensatorios; no borrar ni sobrescribir la historia.
- Seguir una operación entre módulos mediante identificador de operación y traza.

**Estado actual:** existe infraestructura y consulta de auditoría, con escrituras transaccionales documentadas para varios módulos. La cobertura se amplía cuando cada operación se instrumenta; la card sigue In progress.

### M03 — Granjas, galpones y plantas de ración

**Kanban:** Do tests · prioridad Alta.

- Registrar y administrar unidades productivas (granjas), localidades y departamentos.
- Registrar y administrar galpones con capacidad máxima y estado.
- Consultar ocupación, disponibilidad y lotes alojados; consultar historial de entradas, salidas y movimientos.
- Registrar plantas de ración como ubicaciones separadas de los galpones con aves; admitir varias plantas por UP.
- Administrar mantenimiento de galpones e instalaciones desde su contexto.

**Estado actual:** Geografía, estructura, capacidad/estado y mantenimiento de galpones tienen implementación backend; hay pantallas frontend dentro de unidades productivas. La capacidad representa un máximo físico; la ocupación se deriva de Lotes.

### M04 — Proveedores y catálogos

**Kanban:** Do tests · prioridad Alta.

- Registrar, consultar, actualizar y desactivar proveedores, separados de los clientes.
- Administrar catálogos reutilizables de productos, materias primas, insumos, alimentos, medicamentos, vacunas y referencias comerciales de huevos.
- Consultar los ingresos vinculados al proveedor.
- Administrar clasificaciones de huevo de forma flexible, sin impedir una operación por una categoría comercial no normalizada.
- Gestionar operaciones asociadas a un proveedor y sus transacciones de ingreso.

**Estado actual:** proveedores y catálogos tienen implementación. Las fichas de medicamentos y vacunas existen; su registro no modifica inventario físico. El cuadro oficial de tamaños/pesos de huevos sigue pendiente del cliente.

### M05 — Lotes y cría

**Kanban:** Do tests · prioridad Alta.

- Registrar un lote con código, origen/proveedor, raza o línea genética, cantidad, fecha de ingreso, semana/edad y galpón.
- Mantener una sola raza/línea genética por lote y no mezclar líneas coloradas y blancas en la misma unidad de manejo.
- Consultar su estado, detalle e historial.
- Redistribuir aves entre galpones; dividir un lote; crear o incorporar aves a un lote receptor; fusionar o trasladar un lote completo.
- Consultar movimientos con origen, destino, fecha, cantidad y trazabilidad histórica.
- Identificar los sublotes derivados de una división y conservar fecha de ingreso, raza, semana y vínculo con el lote de origen.
- Registrar mortalidad y consultar sus datos históricos.
- Finalizar o poner en cuarentena un lote.

**Estado actual:** admisiones, movimientos, redistribuciones, mortalidad, recolección, capacidad y auditoría están implementadas en backend. Hay una regla vigente de **un solo lote abierto por galpón**, aunque un movimiento parcial puede crear otro lote independiente en un galpón distinto. Esta regla contradice el relevamiento que permite varios lotes por galpón; ver §3.

### M06 — Manejo productivo y sanidad

**Kanban:** Do tests · prioridad Media.

- Registrar y consultar manejos por fecha, lote/galpón, tipo, responsable y referencia a la pauta/manual.
- Administrar un plan maestro de manejo con actividades y fechas previstas.
- Administrar vacunas; programar, aplicar y consultar vacunas; avisar las próximas y vencidas según plan e historial.
- Registrar medicación y consultar su historial.
- Registrar y consultar mortalidad.
- Registrar pesajes grupales de aves pequeñas y pesajes individuales de muestras de aves mayores.
- Calcular promedios/estadísticas, comparar peso real con la curva teórica, advertir valores improbables y corregir un dato histórico con trazabilidad.
- Registrar por sublote la fecha, población muestreada y pesos; conservar el método de muestreo para cada registro.
- Registrar despique y otros manejos definidos por el cliente.
- Consultar tareas programadas, ejecutadas, vencidas y pendientes.

**Estado actual:** planes de manejo, vacunación, medicación como catálogo, pesajes, mortalidad, despique y alimentación diaria tienen implementación parcial/por bloques; existen avisos derivados de planes e historial. El CU completo de sanidad no debe declararse terminado por la existencia de un catálogo. El código actual vincula varias de estas operaciones a lotes; el relevamiento pide registrar algunas por galpón y que el personal no necesite identificar el lote. Ver §3.

### M07 — Mantenimiento de instalaciones

**Kanban:** Do tests · prioridad Media.

- Registrar un mantenimiento/reparación de galpón con fecha, descripción, costo/moneda y responsable.
- Consultar el historial, el último mantenimiento, los costos y las reparaciones repetidas por tipo.
- Corregir o cancelar un registro conservando la auditoría.
- Definir si se administrarán planes preventivos, vida útil estimada y alertas de próximo mantenimiento.

**Estado actual:** registro, histórico, último mantenimiento, corrección y cancelación están implementados. Los planes futuros, avisos y estimación de vida útil no forman parte de la entrega documentada; las notas originales los piden como preguntas o posibilidades.

### M08 — Inventario y movimientos de stock

**Kanban:** Do tests · prioridad Alta.

- Consultar saldos físicos, reservados y disponibles por producto, ubicación y UP.
- Registrar ingresos, salidas, pérdidas, transferencias, reservas, liberaciones, consumos, ajustes y reversas como movimientos auditables.
- Configurar stock mínimo y consultar saldos bajo mínimo.
- Consultar el historial y corregir una operación mediante compensación.
- Mantener la cuenta corriente de huevos por UP y registrar movimientos de carga, preparación de reparto, devolución, donación, ingreso manual y pérdida.
- Descontar insumos de ración al fabricar y registrar la salida/retorno de huevos en repartos.
- Registrar y consultar donaciones de huevos como transacciones separadas de las ventas, con cantidad y nota de destinatario/motivo.

**Estado actual:** existe ledger general de inventario con saldos, movimientos, idempotencia, reversas y mínimos. Huevos y alimento tienen operaciones especializadas. Medicamentos/vacunas no llevan stock físico en el alcance actual, aunque una descripción antigua de M04 habla de administrarlos junto con inventario; ver §3.

### M09 — Postura y stock de huevos

**Kanban:** Do tests · prioridad Alta.

- Registrar la recolección/cantidad de huevos y consultar el histórico por fecha, lote y galpón.
- Corregir o anular una recolección manteniendo trazabilidad.
- Consultar producción diaria, semanal y mensual; mostrar conversión a cajones/maples cuando se definan equivalencias.
- Ingresar el resultado al stock y consultar existencias de huevos.
- Registrar diferencias, pérdidas y ajustes explícitos.
- Consultar la clasificación/tamaño cuando esté informada, sin bloquear el cierre total por una diferencia de clasificación.

**Estado actual:** recolecciones por lote, métricas y stock genérico de huevos por UP están implementados. La API de recolección usa huevo genérico y cantidades enteras; no recibe tamaño, descartes, presentación ni cantidad fraccionaria. Esto deja fuera parte del detalle comercial y contrasta con las notas que ponen los registros “por galpón”; ver §3.

### M10 — Raciones y recetas

**Kanban:** Do tests · prioridad Alta.

- Crear, consultar, modificar y desactivar recetas de ración con ingredientes, cantidades/unidades y rendimiento.
- Calcular los ingredientes requeridos y la producción esperada a partir de una receta.
- Consultar la disponibilidad de materias primas en la planta de ración.
- Administrar pautas de alimentación por semana/edad, galpón/lote y receta, con su historial.

**Estado actual:** el catálogo de recetas y su edición tienen implementación. La configuración y registro diario de alimentación por lote están en otro bloque de Planes de manejo; no confundir fórmula, fabricación y alimento administrado.

### M11 — Elaboración de ración

**Kanban:** Do tests · prioridad Alta.

- Elaborar una tanda con receta, planta de ración, cantidad, fecha y responsable.
- Validar disponibilidad y descontar materias primas; generar alimento terminado.
- Consultar tandas, consumos, saldos e historial por receta/planta.
- Ajustar o revertir una elaboración conservando los movimientos y su trazabilidad.
- Informar diferencias/margen entre receta teórica e insumos realmente usados.

**Estado actual:** fabricación y saldo de alimento por receta/UP tienen implementación backend. La tolerancia de error/margen de elaboración mencionada en la nota inicial no aparece como regla confirmada. Algunas operaciones actuales exigen cantidades enteras en gramos y no modelan partidas/vencimientos.

### M12 — Previsión de ración y abastecimiento

**Kanban:** In progress · prioridad Alta.

- Estimar consumo/cobertura de ración con población, edad, pauta y consumo diario registrado.
- Proyectar cuándo faltará alimento o materia prima y cuánto reabastecer.
- Consultar capacidad/disponibilidad consolidada por planta de ración.
- Generar alertas priorizadas por stock bajo o cambio de ración.

**Estado actual:** alimentación diaria automática/manual, consulta de consumo, mínimos y avisos de cobertura existen. La proyección completa de compras, capacidad de almacenamiento y recomendación de cantidades de abastecimiento no se encontró cerrada.

### M13 — Clientes

**Kanban:** Do tests · prioridad Media.

- Registrar/consultar/editar/desactivar clientes con identificador interno y datos fiscales opcionales.
- Guardar contacto, dirección, coordenadas, frecuencia/condiciones, notas y estado.
- Consultar entregas, visitas, deuda, pagos e incidencias en la ficha.
- Entregar una lista móvil priorizada/paginada; consultar clientes en mapa con resultados acotados por área.
- Mantener cliente y proveedor como entidades separadas.

**Estado actual:** backend y frontend incluyen ficha, cuenta, historia y vistas para repartos. La documentación de implementación deja pendiente aceptación de negocio y recorrido real en móvil/tablet; no se importaron deudas antiguas sin fuente verificable.

### M14 — Pedidos y ventas

**Kanban:** Not started · prioridad Media. El título conserva el signo de pregunta.

- Confirmar si existe un pedido previo al reparto y, si existe, crear/consultar/editar/cancelar pedidos con cantidades, precios, fecha y estado.
- Registrar una venta durante la visita aunque no exista pedido ni reserva.
- Capturar varias líneas por tipo/tamaño/presentación, cantidades enteras o fraccionarias admitidas y precio editable.
- Separar valor de mercadería, cobro y fiado; vincular la venta al cliente y al reparto.
- Hacer idempotente la sincronización offline de la operación.

**Estado actual:** las entregas y sus cargos se registran desde Repartos, con integración a Cuenta Corriente. No se encontró una bandeja separada de pedidos de mostrador o pre-pedidos. Las notas describen repartos proactivos, por lo que el pedido previo es una decisión pendiente, no un supuesto.

### CU transversal — Presentaciones y precios de huevos

- Administrar nombres de presentaciones y su equivalencia en huevos por unidad.
- Administrar un precio predeterminado por combinación comercial; mostrarlo como referencia y permitir ajustar el precio aplicado en cada entrega.
- Consultar las presentaciones y precios usados en el historial sin alterar ventas anteriores.

**Estado actual:** CRUD de presentaciones y precio predeterminado, con edición administrativa, existe en frontend/backend. La clave de precio actual se basa en presentación y equivalencia de huevos; las líneas de entrega no distinguen huevo blanco/colorado u otro tipo. Por eso está cubierta la edición por presentación, pero no la diferencia de precio por tipo de huevo solicitada en las notas.

### M15 — Cuenta corriente, pagos y cobranzas

**Kanban:** Do tests · prioridad Media.

- Consultar estado de cuenta, deuda/saldo, cargos, pagos, ajustes y compensaciones por cliente/período.
- Registrar pago de deuda, incluso sin entregar mercadería.
- Registrar fiado y vincularlo con cliente y reparto.
- Permitir varios cobros y medios de pago; registrar ajustes de deuda autorizados con motivo.
- Consultar la conciliación y resumen financiero al cierre del reparto.

**Estado actual:** libro de movimientos, pagos, cargos de entrega, ajustes y reversas están implementados y conectados a Repartos. Medios actuales: efectivo, transferencia y otro. La migración de deuda histórica no se realizó por falta de una fuente confiable.

### M16 — Vehículos y uso

**Kanban:** Not started · prioridad Media.

- Administrar vehículos, estado, matrícula, marca/modelo, capacidad y documentación.
- Registrar inicio/cierre de uso, conductor, reparto y lectura de odómetro.
- Registrar combustible y gastos asociados al uso.
- Asociar vehículo a un reparto y conservar la referencia histórica.

**Estado actual:** backend y frontend ya contienen administración de vehículos, usos, odómetro, combustible y asociación con repartos. El estado Not started está desactualizado respecto del checkout; la aceptación funcional sigue pendiente.

### M17 — Mantenimiento de vehículos

**Kanban:** Not started · prioridad Media.

- Definir planes preventivos por fecha/kilometraje y ciclos.
- Registrar mantenimiento/reparación, tipo, fecha, odómetro, taller, importe, moneda, notas y próximo servicio.
- Consultar documentos/estado y el histórico de mantenimiento.
- Generar alertas por vencimiento de documentos y servicios próximos/vencidos.

**Estado actual:** hay Actions, contratos y pantallas para planes/intervenciones, lecturas, combustible y alertas. El Kanban no refleja esta implementación reciente; validar aceptación y reglas de negocio.

### M18 — Repartos, rutas y entregas

**Kanban:** Not started · prioridad Media.

- Iniciar un reparto con repartidor, vehículo y carga; permitir una carga inicial vacía cuando se sale sólo a cobrar.
- Agregar varias cargas de huevo durante la jornada y reflejarlas en inventario.
- Consultar lista de clientes pendientes/visitados y mapa secundario con clientes relevantes.
- Registrar entrega total/parcial/no realizada, venta con líneas de presentaciones, precios editables, cobro, fiado, cobro de deuda sin mercadería y motivo/observaciones.
- Mostrar acciones ya realizadas, pendientes, última entrega e historia de cada cliente.
- Registrar fechas de recolección que componen la carga.
- Cerrar el reparto con carga, ventas, devoluciones, diferencias, resumen de dinero y comentario; corregir un reparto/entrega cerrada con auditoría.
- Permitir a administración monitorear repartos activos, consultar el historial, detalle y línea temporal de eventos/entregas/cobros.

**Estado actual:** frontend y backend ya tienen flujo móvil/admin, inicio/carga/visitas/cierre, corrección, cuenta corriente, idempotencia, GPS y cola offline. El backend permite iniciar sin carga y el frontend de administración arma una línea temporal con marcas de tiempo. La card Not started no está actualizada. Sólo se permite un reparto activo por persona; no se encontró una decisión explícita sobre cuántos repartos secuenciales puede abrir en un mismo día.

### M19 — Incidentes y excepciones de reparto

**Kanban:** Not started · prioridad Media.

- Registrar cliente omitido, dirección incorrecta, falta de mercadería, devolución, entrega rechazada, diferencia, cobro pendiente o revisita.
- Asignar seguimiento/responsable/estado y consultar historia de resolución.
- Convertir una excepción accionable en alerta o tarea pendiente.

**Estado actual:** Repartos contempla estados/motivos de visita, diferencias y alertas operativas. No se encontró evidencia equivalente a un módulo general de excepciones/tareas pendientes con todos los tipos y un ciclo de seguimiento separado.

### M20 — Alertas y notificaciones

**Kanban:** In progress · prioridad Alta.

- Generar avisos para acciones humanas requeridas y asignar prioridad, entidad, destinatario y fecha.
- Consultar bandeja, filtrar por prioridad/UP, marcar leído y seguir resolución/reapertura.
- Alertar por stock mínimo/cobertura, vacuna/tarea próxima, peso atípico, recolección faltante, capacidad, mantenimiento, reparto con diferencia y acción pendiente.
- Incorporar alertas provenientes de sensores cuando exista M23.

**Estado actual:** motor y bandeja con episodios de varias áreas existen; el origen actual publica condiciones y la bandeja conserva lectura/resolución. Alertas de sensores dependen de M23 y quedan pendientes.

### M21 — Reportes y exportación

**Kanban:** In progress · prioridad Urgente.

- Consultar reportes tabulares en la aplicación.
- Seleccionar filtros, columnas, rangos, ordenamientos, agrupaciones y métricas permitidos por cada fuente.
- Guardar, recuperar, editar y eliminar configuraciones frecuentes.
- Exportar cada reporte a un XLSX independiente y a PDF.
- Compartir una vista HTML mediante enlace temporal.
- Consultar volumen vendido con/sin donaciones.
- Consultar consumo de ración y huevos producidos durante la vida del galpón.

**Catálogo solicitado en Reportes_de_SGA.docx:**

1. Reparto: fecha, reparto, cliente, cantidad por cajones/teleras, tamaño, valor entregado, monto pagado, saldo/deuda y forma de pago. La nota condiciona incluir comentarios según exista ese dato en el sistema.
2. Manejo general: lote, galpón, fecha, manejo y fecha según manual.
3. Lotes: lote, galpón, fecha de ingreso, raza y tamaño/cantidad.
4. Vacunación: lote, galpón, fecha, vacuna y partida/lote de vacuna.
5. Pesadas: lote, galpón, fecha y pesos.
6. Alimentación: lote, galpón, fecha/período, alimento y cantidad.
7. Postura: lote, galpón, fecha y cantidad de huevos.

La lista definitiva de reportes todavía debe confirmarse. Las notas iniciales dicen “todo reportable” y piden formatos XLS/PDF/HTML; el alcance posterior concreta consultas estándar y presets, sin personalización arbitraria.

**Estado actual:** el backend documenta 13 fuentes: saldos y movimientos de inventario; postura; admisiones de lotes; mediciones de pesaje; vacunaciones; ejecuciones de manejo; suministros de alimento; entregas; uso, combustible y mantenimiento de vehículos; y egreso de huevos vendidos/donados. El reporte de alimento conserva suministro registrado y pauta estimada como cantidades distintas; ninguna equivale a ingesta medida. El motor, exportaciones/configuraciones y pantalla Angular ya existen. La descripción de la card de octubre que decía que sólo había dos fuentes y no había pantalla quedó obsoleta. Quedan aceptación, lista final del negocio y revisar campos reportables que no estén incluidos (por ejemplo monto cobrado, saldo y medio de pago en el reporte de reparto).

### M22 — Indicadores y dashboards

**Kanban:** In progress · prioridad Media.

- Ver huevos recolectados, aves vivas, mortalidad, evolución de pesaje, consumo/cobertura de alimento y stock.
- Ver entregas/ventas, donaciones, deuda y estado de repartos.
- Consultar gastos de mantenimiento/combustible y alertas operativas.
- Comparar postura real y teórica cuando exista una curva aprobada.

**Estado actual:** hay dashboard de inventario y resumen operativo con postura, aves, mortalidad, alertas, valor entregado y gastos. La descripción original que enumera todo como objetivo no significa que todos esos indicadores estén implementados como tablero.

### M23 — Integración de sensores IoT

**Kanban:** Not started · prioridad Baja.

- Registrar sensores y asociarlos a UP/galpón.
- Recibir mediciones de temperatura, humedad, CO₂/amoníaco, agua, electricidad, silos u otros dispositivos sin acoplarse a una marca.
- Consultar mediciones e histórico y exponerlas a alertas, monitoreo y reportes.

**Estado actual:** sin implementación encontrada.

### M24 — Monitoreo ambiental

**Kanban:** Not started · prioridad Baja.

- Consultar valores actuales e históricos por sensor/galpón.
- Graficar series y máximos/mínimos; configurar límites y detectar anomalías.
- Mostrar la alerta asociada y su estado.

**Estado actual:** depende de M23; sin implementación encontrada.

### M25 — Cámaras y detección de anomalías con IA

**Kanban:** Not started · prioridad Baja.

- Ingerir/consultar eventos de cámaras de galpones y detectar agrupamientos, zonas evitadas o movimientos/ausencias atípicos.
- Generar señales que puedan relacionarse con ambiente y alertas.
- Evaluar procesamiento en Edge o nube.

**Estado actual:** la propia card lo deja fuera del MVP y como investigación futura; sin implementación encontrada.

## 3. Extensiones y notas no parseadas

### M18A — Reparto móvil, GPS y sincronización offline

**Kanban:** Not started · prioridad Alta. Es una extensión de M18, no una card autónoma del modelo de negocio.

- Separar la pantalla operativa móvil del shell administrativo; sólo el rol Reparto puede operar.
- Capturar ubicación durante el reparto activo, mostrar su antigüedad en administración y detener el seguimiento al cerrar/cancelar.
- Guardar comandos en cola durable, indicar conexión/sincronización y reintentar sin duplicar cargas, entregas, cobros ni ubicaciones.
- Hacer que la lista de clientes sea principal y el mapa un apoyo acotado; continuar si el mapa falla.
- Mantener una vista administrativa distinta para seguimiento, históricos y detalle.

El código ya contiene servicios nativos de red/GPS, cola offline, mapa, ubicación de repartos y vista administrativa. La card sigue con estado Not started, por lo que su aceptación en dispositivo no debe darse por aprobada automáticamente. El alcance del plan limita GPS al primer plano; seguimiento en background, mapas offline y optimización de rutas quedan fuera del MVP.

La bitácora pregunta si se permiten varios repartos en un día y pide iniciar sin carga, cobrar sin entregar mercadería y mostrar una línea temporal administrativa de inicio a fin. Lo último y el inicio sin carga aparecen en el código actual. La regla documentada es un reparto activo por repartidor; falta confirmar si habrá límite diario.

### 21–22B — Frontend Angular + ECharts

**Kanban:** Not started · prioridad Urgente.

La card describe un constructor de reportes, vistas tabulares, presets, exportaciones, dashboard y estados de interfaz, consumiendo contratos Laravel. La pantalla y servicios frontend están ahora en el repo GAM-Frontend; su estado Not started es una fotografía desactualizada, aunque aceptación y cobertura completa de indicadores siguen pendientes.

### CU transversal — Aplicación móvil

- Usar la misma aplicación en modo personal o compartido, según el dispositivo y la modalidad de autenticación.
- Priorizar operaciones cómodas en móvil, con controles grandes y estados claros; usar la lista de clientes como pantalla principal del repartidor.
- Mantener compatibilidad mínima con Android 12 y operación en una tablet compartida.

La modalidad personal del repartidor se separó de la tablet compartida en M01/18A: las cuentas Reparto usan credenciales personales; el selector de usuario + PIN queda para la sesión comunitaria.

## 4. Contradicciones, cambios y definiciones abiertas

| Tema | Fuente anterior / nota | Card o implementación actual | Qué debe decidirse |
| --- | --- | --- | --- |
| Roles por persona | Notas MEET 2 y M01: una persona puede tener varios roles. | Backend actual: cada cuenta tiene exactamente un rol. | Confirmar si multi-rol es requisito o si se conserva un único rol y se asignan permisos granulares. |
| Permisos por persona | Notas 4 pregunta por permisos granulares y plantea permisos asignables también por usuario. | El backend actual resuelve permisos desde el único rol asignado; no se encontró override individual por usuario. | Confirmar si alcanza con roles configurables o si cada empleado necesita permisos propios. |
| Alcance por UP | Notas 4: empleados sólo deberían ver su UP. M03: se anotó que los usuarios no están relacionados con UP. | Cambio de identidad de noviembre: cuentas PIN pertenecen a una UP y sus consultas se filtran; cuentas con correo tienen alcance empresarial según permisos. | Aceptar si sólo las cuentas compartidas se restringen por UP o si también las cuentas personales deben asignarse a una unidad. |
| Lotes por galpón | Notas3 y nota parseada: puede haber varios lotes por galpón. | La regla de M05 y la base actual admiten un único lote abierto por galpón. | Validar si la regla operativa cambió o si hay que modelar varios lotes convivientes. |
| Granularidad de registros | Notas3/4: vacunación y mortalidad por galpón porque las aves conviven y los operarios no identifican lotes. | Varias Actions de manejo, pesaje, vacuna y recolección se vinculan a lote; el esquema actual limita la convivencia con la regla anterior. | Definir operación por galpón, lote o ambos; incluir quién selecciona el lote y cómo se reconstruye el contexto histórico. |
| Tamaños y clasificación de huevos | Notas MEET 2/Notas3: grados/tamaños, color, categorías y pesos; cuadro oficial pendiente. | Catálogo flexible, pero recolección de postura usa huevo genérico y no guarda presentación/tamaño. | Obtener el cuadro oficial y decidir qué dato es obligatorio en postura, inventario, entrega y reportes. |
| Precio de huevos | Notas3: precios pueden diferir por tipo/color y presentación, y el repartidor puede ajustarlos. | Catálogo actual administra precio por presentación/equivalencia; la venta no discrimina tipo/color. | Confirmar si se requieren tarifas por color/tipo además de por presentación. |
| Inventario sanitario | Descripción antigua de M04 propone productos de medicación/vacunas administrables y stock. | Documentación actual: catálogos sin cantidad/stock físico; la aplicación sanitaria no descuenta inventario. | Confirmar que el stock de medicamentos/vacunas queda fuera del alcance actual. |
| Ración | Notas iniciales mencionan receta, desvío de elaboración y alertas de insumos; notas posteriores distinguen pauta semanal y consumo. | El código separa catálogo/receta, fabricación, stock y suministro diario al lote; suministro registrado/estimado no es medición automática de consumo. | Definir tolerancias, unidades y qué significa “consumió”: fabricado, enviado al galpón o ingerido. |
| Repartos | Notas y Bitácora piden lista de clientes, cobro sin mercadería, inicio sin carga, varias cargas y timeline. | Código actual cubre inicio vacío, cobro separado, cargas, outbox y timeline; la card sigue Not started. | Aceptar en Android/tablet y confirmar cantidad de repartos permitida por día, fecha de recolección y reglas de cobro. |
| Reparto como pedido o venta | M14 tiene “Pedidos?” y describe pedidos previos; notas dicen que se sale sin reservas. | Las ventas se registran durante Repartos; no se encontró módulo independiente de pedidos. | Confirmar si el pedido previo existe o si M14 debe limitarse a ventas durante la visita. |
| Medio de pago | DOCX enumera efectivo, transferencia y cheque. | Cuenta corriente implementa efectivo, transferencia y otro; “cheque” no es una categoría explícita. | Decidir si “otro” basta o si cheque debe ser un medio identificable; incluirlo en reportes. |
| Reportes | Nota inicial pide todo reportable y customizable; DOCX lista siete; notas parseadas dicen que no se requiere personalización avanzada y cada reporte va separado. | El motor actual ofrece fuentes configuradas, filtros/presets, XLSX/PDF y enlace HTML temporal; ya hay 13 fuentes. | Validar la lista final, períodos históricos y campos comerciales/financieros faltantes. |
| Estado de Kanban | M16/M17/M18/18A y 21–22B aparecen Not started; M21/22 In progress. | Código actual ya contiene módulos de vehículos, repartos, GPS/offline, reportes y dashboards. | Actualizar estados sólo después de la aceptación correspondiente, no usar el estado anterior como descripción del checkout. |
| Varias unidades productivas | Nota parseada: contemplar varias UP a futuro. | Hay entidades y aislamiento por UP, con cuentas PIN asignadas a una unidad. | Confirmar si varias UP ya forman parte del despliegue actual y qué datos son globales o por unidad. |
| Línea genética | Notas3 menciona Hy-Line Brown y una línea “W” sin nombre completo. | La card de Lotes contempla razas/catálogos extensibles. | Confirmar el nombre de “W” antes de cargarla como raza oficial. |
| Identificador de sublote | Notas3 pide un identificador derivado y fácil de reconocer. | Las divisiones actuales crean un lote independiente con código/ULID propios. | Definir si el identificador propio satisface el requisito o si debe mantener una convención visible basada en el lote de origen. |
| Devoluciones | Notas 4: muy eventuales, manuales y de baja prioridad. | Repartos e inventario tienen movimientos de devolución/ajuste en el cierre. | Definir el flujo manual de devolución de cliente y si requiere CU independiente. |
| Bitácora de ingeniería | Bitácora IA .NET pide analizar y mejorar CI/CD en todos los repos. | Es una tarea técnica, no describe comportamiento funcional del sistema. | Mantenerla fuera del catálogo de CU; dar seguimiento en el trabajo de CI/CD. |

## 5. Fuentes y evidencia

### Notion: notas del proyecto

- [Notas — contexto y problemas iniciales](https://app.notion.com/p/cd3032f1caff83a0a50e818f5b643c98)
- [NOTAS MEET](https://app.notion.com/p/3c1032f1caff805f9b53d7122f643647)
- [Notas MEET 2](https://app.notion.com/p/3ce032f1caff80c586cdcd3e9e358a3d)
- [Notas3](https://app.notion.com/p/3dd032f1caff8054af20ce260695bfdb)
- [Notas “resumidas/parseadas”](https://app.notion.com/p/3d1032f1caff800587f8e6d7b65d3554)
- [Notas 4](https://app.notion.com/p/3ed032f1caff80cf85add9945cc2eebb)
- [Bitácora IA .NET](https://app.notion.com/p/407032f1caff80c8aecbc1e5ff716bb2)
- [Bitácora IA Proyecto](https://app.notion.com/p/40a032f1caff8029b88bf71bb59b1f7a)

### Kanban: cards

- [01 Usuarios, roles y permisos](https://app.notion.com/p/3cb032f1caff80c89c3df052d96f6662) · [02 Auditoría y trazabilidad](https://app.notion.com/p/3cb032f1caff80ce90cad371f095863c) · [03 Granjas, galpones y plantas de ración](https://app.notion.com/p/3cb032f1caff8075add3e37e24e0e326)
- [04 Proveedores y Stocks](https://app.notion.com/p/3cb032f1caff805b8abfecd6355e32e3) · [05 Lotes y cría](https://app.notion.com/p/3cb032f1caff80b7b11ccbb3ac0d4df9) · [06 Manejo productivo y sanidad](https://app.notion.com/p/3cb032f1caff80c98bc6e88e335dc889)
- [07 Mantenimiento de instalaciones](https://app.notion.com/p/3cb032f1caff80799282d442cc1c7256) · [08 Inventario y movimientos de stock](https://app.notion.com/p/3cb032f1caff809fb6abf477734cb688) · [09 Postura y stock de huevos](https://app.notion.com/p/3cb032f1caff8014a2fecd5d1a0c8c55)
- [10 Raciones y recetas](https://app.notion.com/p/3cb032f1caff80b6bf68e7224279392c) · [11 Elaboración de ración](https://app.notion.com/p/3cb032f1caff809b916fcd0add89985c) · [12 Previsión de ración y abastecimiento](https://app.notion.com/p/3cb032f1caff80c291f7cc5300ad8654)
- [13 Clientes](https://app.notion.com/p/3cb032f1caff80bb8f1cc202974a8e7e) · [14 Pedidos? y ventas](https://app.notion.com/p/3cb032f1caff80159421eed17c2b911c) · [15 Cuenta corriente, pagos y cobranzas](https://app.notion.com/p/3cb032f1caff8067b28af8a3e083c9d7)
- [16 Vehículos y uso](https://app.notion.com/p/3cb032f1caff80c89e03e956bbde37b2) · [17 Mantenimiento de vehículos](https://app.notion.com/p/3cb032f1caff8083b36af4262ab9702f) · [18 Repartos, rutas y entregas](https://app.notion.com/p/3cb032f1caff8059b855c4834b930650)
- [19 Incidentes y excepciones](https://app.notion.com/p/3cb032f1caff8051a14edc3ca98083dd) · [20 Alertas y notificaciones](https://app.notion.com/p/3cb032f1caff8029bbb9e0bfbceaf17c) · [21 Reportes y exportación](https://app.notion.com/p/3cb032f1caff808dbd8ae2113693feb0)
- [22 Indicadores y dashboards](https://app.notion.com/p/3cb032f1caff807e9940c7f9a5ced08e) · [23 Integración de sensores IoT](https://app.notion.com/p/3cb032f1caff80c793bef98afe716141) · [24 Monitoreo ambiental](https://app.notion.com/p/3cb032f1caff80f3a0a8ca265013e978)
- [25 Cámaras e IA](https://app.notion.com/p/3cb032f1caff8028a563e263f8a3edd3) · [18A Reparto móvil, GPS y sincronización offline](https://app.notion.com/p/3ea032f1caff81389e11ddaabf89be71) · [21–22B Frontend Angular + ECharts](https://app.notion.com/p/3cb032f1caff813a8a48d7c76617b11b)
- Planes vinculados: [M03 Ubicaciones y estructura](https://app.notion.com/p/3cb032f1caff811f918bc891a7e0142e) · [M04/M08 Proveedores e inventario](https://app.notion.com/p/3cb032f1caff81138a28ef74f01e0b32) · [M21 Reportes](https://app.notion.com/p/3f7032f1caff81759f8bef1967b3534e).

### Repositorios consultados

- Backend: GAM-Backend/docs/implementations/, contracts/openapi/, app/Actions/, app/Services/ y routes/.
- Frontend: GAM-Frontend/src/app/features/ y src/app/core/native/.
- Evidencia de implementación especialmente relevante: identity-access-implementation.md, roles-and-permissions-implementation.md, access-methods-and-production-unit-implementation.md, lots-implementation.md, egg-production-implementation.md, feed-recipes-implementation.md, flock-feeding-implementation.md, vaccination-implementation.md, medication-implementation-plan.md, maintenance-implementation.md, customers-and-accounts-implementation.md, reporting-implementation.md y operational-summary-implementation.md.
