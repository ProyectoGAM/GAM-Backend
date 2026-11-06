<?php

return [
    'modules' => [
        'identity' => 'Identidad y acceso',
        'audit' => 'Auditoría',
        'customers' => 'Clientes',
        'customer_accounts' => 'Cuentas corrientes',
        'lots' => 'Lotes y razas',
        'management_plans' => 'Planes de manejo',
        'health' => 'Sanidad',
        'mortality' => 'Mortalidad',
        'eggs' => 'Producción de huevos',
        'egg_stock' => 'Stock de huevos',
        'weighings' => 'Pesajes',
        'geography' => 'Geografía',
        'production_units' => 'Unidades productivas',
        'poultry_houses' => 'Galpones y mantenimientos',
        'suppliers' => 'Proveedores',
        'products' => 'Productos',
        'inventory' => 'Inventario',
        'reports' => 'Reportes',
        'feed_recipes' => 'Recetas de alimento',
        'deliveries' => 'Repartos',
        'vehicles' => 'Vehículos',
        'feed_stock' => 'Alimento e ingredientes',
        'flock_feeding' => 'Alimentación de lotes',
        'notifications' => 'Notificaciones',
    ],
    'permissions' => [
        'can_view_notifications' => [
            'module' => 'notifications', 'label' => 'Consultar notificaciones',
            'description' => 'Ver y marcar como leídas las notificaciones propias.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_view_admin_dashboard' => [
            'module' => 'identity', 'label' => 'Ver inicio administrativo',
            'description' => 'Acceder al inicio del área de administración.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_view_audit' => [
            'module' => 'audit', 'label' => 'Consultar auditoría',
            'description' => 'Consultar el historial de operaciones y responsables.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_view_customers' => [
            'module' => 'customers', 'label' => 'Ver clientes',
            'description' => 'Consultar clientes y sus datos de contacto.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_customers' => [
            'module' => 'customers', 'label' => 'Gestionar clientes',
            'description' => 'Crear clientes, editar datos y cambiar su estado.',
            'requires' => ['can_view_customers'], 'admin_only' => false,
        ],
        'can_view_customer_accounts' => [
            'module' => 'customer_accounts', 'label' => 'Ver cuentas corrientes',
            'description' => 'Consultar saldos y movimientos de clientes.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_record_customer_payments' => [
            'module' => 'customer_accounts', 'label' => 'Registrar pagos',
            'description' => 'Registrar cobros y pagos de clientes.',
            'requires' => ['can_view_customer_accounts'], 'admin_only' => false,
        ],
        'can_adjust_customer_accounts' => [
            'module' => 'customer_accounts', 'label' => 'Ajustar cuentas corrientes',
            'description' => 'Registrar ajustes de cuenta corriente; exclusivo de Admin.',
            'requires' => ['can_view_customer_accounts'], 'admin_only' => true,
        ],
        'can_reverse_customer_accounts' => [
            'module' => 'customer_accounts', 'label' => 'Revertir movimientos de cuenta',
            'description' => 'Compensar movimientos de cuenta corriente; exclusivo de Admin.',
            'requires' => ['can_view_customer_accounts'], 'admin_only' => true,
        ],
        'can_view_flocks' => [
            'module' => 'lots', 'label' => 'Ver lotes',
            'description' => 'Consultar lotes y su información general.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_flocks' => [
            'module' => 'lots', 'label' => 'Gestionar lotes',
            'description' => 'Crear lotes, editar datos y cambiar estados permitidos.',
            'requires' => ['can_view_flocks'], 'admin_only' => false,
        ],
        'can_redistribute_flocks' => [
            'module' => 'lots', 'label' => 'Redistribuir aves',
            'description' => 'Registrar y rectificar redistribuciones entre lotes y galpones.',
            'requires' => ['can_view_flocks'], 'admin_only' => false,
        ],
        'can_finalize_flocks' => [
            'module' => 'lots', 'label' => 'Cerrar lotes',
            'description' => 'Finalizar lotes conforme a las validaciones del módulo.',
            'requires' => ['can_view_flocks'], 'admin_only' => false,
        ],
        'can_view_management_plans' => [
            'module' => 'management_plans', 'label' => 'Ver planes de manejo',
            'description' => 'Consultar plantillas, planes e historial de manejo.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_management_plans' => [
            'module' => 'management_plans', 'label' => 'Gestionar planes de manejo',
            'description' => 'Crear, editar, publicar y asignar planes de manejo.',
            'requires' => ['can_view_management_plans'], 'admin_only' => false,
        ],
        'can_execute_management_plans' => [
            'module' => 'management_plans', 'label' => 'Registrar manejos',
            'description' => 'Registrar y corregir aplicaciones sanitarias y prácticas de manejo.',
            'requires' => ['can_view_management_plans'], 'admin_only' => false,
        ],
        'can_manage_medicine_stock' => [
            'module' => 'health', 'label' => 'Ajustar contador de medicamentos',
            'description' => 'Ajustar el contador clínico global de medicamentos, sin stock físico por UP.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_view_breeds' => [
            'module' => 'lots', 'label' => 'Ver razas',
            'description' => 'Consultar el catálogo de razas.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_breeds' => [
            'module' => 'lots', 'label' => 'Gestionar razas',
            'description' => 'Crear razas, editar datos y cambiar estados permitidos.',
            'requires' => ['can_view_breeds'], 'admin_only' => false,
        ],
        'can_view_mortality' => [
            'module' => 'mortality', 'label' => 'Ver mortalidad',
            'description' => 'Consultar registros e indicadores de mortalidad.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_mortality' => [
            'module' => 'mortality', 'label' => 'Registrar mortalidad',
            'description' => 'Registrar, corregir y anular registros de mortalidad.',
            'requires' => ['can_view_mortality'], 'admin_only' => false,
        ],
        'can_view_egg_collections' => [
            'module' => 'eggs', 'label' => 'Ver recolecciones',
            'description' => 'Consultar recolecciones e indicadores de producción de huevos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_egg_collections' => [
            'module' => 'eggs', 'label' => 'Registrar recolecciones',
            'description' => 'Registrar, corregir y anular recolecciones de huevos.',
            'requires' => ['can_view_egg_collections'], 'admin_only' => false,
        ],
        'can_view_egg_stock' => [
            'module' => 'egg_stock', 'label' => 'Ver stock de huevos',
            'description' => 'Consultar saldos y movimientos de huevos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_move_egg_stock' => [
            'module' => 'egg_stock', 'label' => 'Mover huevos',
            'description' => 'Registrar ingresos, salidas, donaciones y movimientos permitidos de huevos.',
            'requires' => ['can_view_egg_stock'], 'admin_only' => false,
        ],
        'can_adjust_egg_stock' => [
            'module' => 'egg_stock', 'label' => 'Ajustar stock de huevos',
            'description' => 'Corregir saldos de huevos y compensar movimientos indicando el motivo.',
            'requires' => ['can_view_egg_stock'], 'admin_only' => false,
        ],
        'can_view_weighings' => [
            'module' => 'weighings', 'label' => 'Ver pesajes',
            'description' => 'Consultar pesajes, jornadas e indicadores de crecimiento.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_weighings' => [
            'module' => 'weighings', 'label' => 'Registrar pesajes',
            'description' => 'Registrar y corregir pesajes e ingresos diarios.',
            'requires' => ['can_view_weighings'], 'admin_only' => false,
        ],
        'can_manage_weighing_settings' => [
            'module' => 'weighings', 'label' => 'Configurar pesajes',
            'description' => 'Modificar valores de referencia y parámetros de pesajes.',
            'requires' => ['can_view_weighings'], 'admin_only' => false,
        ],
        'can_view_geography' => [
            'module' => 'geography', 'label' => 'Ver geografía',
            'description' => 'Consultar departamentos y localidades.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_geography' => [
            'module' => 'geography', 'label' => 'Gestionar geografía',
            'description' => 'Crear y editar departamentos y localidades y cambiar sus estados.',
            'requires' => ['can_view_geography'], 'admin_only' => false,
        ],
        'can_view_production_units' => [
            'module' => 'production_units', 'label' => 'Ver unidades productivas',
            'description' => 'Consultar todas las unidades productivas de la empresa.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_production_units' => [
            'module' => 'production_units', 'label' => 'Gestionar unidades productivas',
            'description' => 'Crear y editar unidades productivas y cambiar estados permitidos.',
            'requires' => ['can_view_production_units'], 'admin_only' => false,
        ],
        'can_view_poultry_houses' => [
            'module' => 'poultry_houses', 'label' => 'Ver galpones',
            'description' => 'Consultar galpones, capacidad, ocupación y mantenimientos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_poultry_houses' => [
            'module' => 'poultry_houses', 'label' => 'Gestionar galpones',
            'description' => 'Crear y editar galpones, cambiar estados y gestionar mantenimientos.',
            'requires' => ['can_view_poultry_houses'], 'admin_only' => false,
        ],
        'can_view_suppliers' => [
            'module' => 'suppliers', 'label' => 'Ver proveedores',
            'description' => 'Consultar proveedores y sus datos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_suppliers' => [
            'module' => 'suppliers', 'label' => 'Gestionar proveedores',
            'description' => 'Crear y editar proveedores y cambiar estados permitidos.',
            'requires' => ['can_view_suppliers'], 'admin_only' => false,
        ],
        'can_view_products' => [
            'module' => 'products', 'label' => 'Ver productos',
            'description' => 'Consultar el catálogo de productos y materias primas.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_products' => [
            'module' => 'products', 'label' => 'Gestionar productos',
            'description' => 'Crear y editar productos permitidos y cambiar sus estados.',
            'requires' => ['can_view_products'], 'admin_only' => false,
        ],
        'can_view_inventory' => [
            'module' => 'inventory', 'label' => 'Ver inventario',
            'description' => 'Consultar ubicaciones, saldos, mínimos e historial de inventario.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_move_inventory' => [
            'module' => 'inventory', 'label' => 'Registrar movimientos',
            'description' => 'Registrar ingresos, salidas, pérdidas y transferencias permitidas.',
            'requires' => ['can_view_inventory'], 'admin_only' => false,
        ],
        'can_adjust_inventory' => [
            'module' => 'inventory', 'label' => 'Ajustar inventario',
            'description' => 'Corregir saldos, regularizar existencias y revertir movimientos permitidos.',
            'requires' => ['can_view_inventory'], 'admin_only' => false,
        ],
        'can_manage_inventory' => [
            'module' => 'inventory', 'label' => 'Configurar inventario',
            'description' => 'Gestionar ubicaciones y configurar mínimos de inventario.',
            'requires' => ['can_view_inventory'], 'admin_only' => false,
        ],
        'can_view_reports' => [
            'module' => 'reports', 'label' => 'Ver reportes',
            'description' => 'Consultar fuentes y previsualizar reportes con acceso a sus datos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_export_reports' => [
            'module' => 'reports', 'label' => 'Exportar reportes',
            'description' => 'Generar y descargar exportaciones de reportes autorizados.',
            'requires' => ['can_view_reports'], 'admin_only' => false,
        ],
        'can_manage_report_presets' => [
            'module' => 'reports', 'label' => 'Gestionar configuraciones de reportes',
            'description' => 'Guardar, editar y eliminar configuraciones de reportes.',
            'requires' => ['can_view_reports'], 'admin_only' => false,
        ],
        'can_share_reports' => [
            'module' => 'reports', 'label' => 'Compartir reportes',
            'description' => 'Emitir enlaces temporales de exportaciones autorizadas.',
            'requires' => ['can_export_reports'], 'admin_only' => false,
        ],
        'can_view_feed_recipes' => [
            'module' => 'feed_recipes', 'label' => 'Ver recetas',
            'description' => 'Consultar recetas, ingredientes y cálculos de alimento.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_feed_recipes' => [
            'module' => 'feed_recipes', 'label' => 'Gestionar recetas',
            'description' => 'Crear recetas, editar cantidades y cambiar estados permitidos.',
            'requires' => ['can_view_feed_recipes'], 'admin_only' => false,
        ],
        'can_login_native' => [
            'module' => 'identity', 'label' => 'Acceder desde aplicación nativa',
            'description' => 'Iniciar y mantener una sesión personal nativa con token vigente.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_login_web' => [
            'module' => 'identity', 'label' => 'Acceder desde navegador',
            'description' => 'Iniciar y mantener una sesión personal web con cookies y CSRF.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_login_shared' => [
            'module' => 'identity', 'label' => 'Acceder desde dispositivo compartido',
            'description' => 'Iniciar y mantener una sesión compartida con PIN habilitado; excluye Admin.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_users' => [
            'module' => 'identity', 'label' => 'Gestionar usuarios',
            'description' => 'Consultar y gestionar cuentas no administradoras; no permite administrar roles.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_user_pins' => [
            'module' => 'identity', 'label' => 'Gestionar PIN',
            'description' => 'Configurar, deshabilitar y desbloquear PIN de usuarios no administradores.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_shared_devices' => [
            'module' => 'identity', 'label' => 'Gestionar dispositivos compartidos',
            'description' => 'Vincular y revocar dispositivos compartidos conforme a las reglas de identidad.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_user_sessions' => [
            'module' => 'identity', 'label' => 'Revocar sesiones',
            'description' => 'Revocar sesiones de usuarios no administradores.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_start_deliveries' => [
            'module' => 'deliveries', 'label' => 'Iniciar repartos',
            'description' => 'Iniciar repartos con un vehículo habilitado.',
            'requires' => ['can_view_own_deliveries'], 'admin_only' => false,
        ],
        'can_record_delivery_fuel' => [
            'module' => 'deliveries', 'label' => 'Registrar combustible de reparto',
            'description' => 'Registrar cargas de combustible durante repartos autorizados.',
            'requires' => ['can_view_own_deliveries'], 'admin_only' => false,
        ],
        'can_view_own_deliveries' => [
            'module' => 'deliveries', 'label' => 'Ver repartos propios',
            'description' => 'Consultar repartos asignados al propio usuario.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_update_own_deliveries' => [
            'module' => 'deliveries', 'label' => 'Registrar actividad de reparto',
            'description' => 'Registrar entregas, cobros, cargas y cierre de repartos propios.',
            'requires' => ['can_view_own_deliveries'], 'admin_only' => false,
        ],
        'can_publish_delivery_location' => [
            'module' => 'deliveries', 'label' => 'Publicar ubicación de reparto',
            'description' => 'Enviar ubicaciones durante un reparto propio activo.',
            'requires' => ['can_view_own_deliveries'], 'admin_only' => false,
        ],
        'can_monitor_deliveries' => [
            'module' => 'deliveries', 'label' => 'Monitorear repartos',
            'description' => 'Consultar repartos y ubicaciones de los conductores.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_view_delivery_history' => [
            'module' => 'deliveries', 'label' => 'Ver historial de repartos',
            'description' => 'Consultar el historial general de repartos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_view_vehicles' => [
            'module' => 'vehicles', 'label' => 'Ver vehículos',
            'description' => 'Consultar vehículos y su disponibilidad.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_vehicles' => [
            'module' => 'vehicles', 'label' => 'Gestionar vehículos',
            'description' => 'Crear y editar vehículos, asignar conductores y cambiar estados.',
            'requires' => ['can_view_vehicles'], 'admin_only' => false,
        ],
        'can_use_vehicles' => [
            'module' => 'vehicles', 'label' => 'Utilizar vehículos',
            'description' => 'Operar vehículos habilitados conforme a sus asignaciones.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_vehicle_readings' => [
            'module' => 'vehicles', 'label' => 'Registrar lecturas',
            'description' => 'Registrar lecturas de odómetro y horómetro.',
            'requires' => ['can_view_vehicles'], 'admin_only' => false,
        ],
        'can_view_vehicle_fuel' => [
            'module' => 'vehicles', 'label' => 'Ver combustible',
            'description' => 'Consultar cargas y comprobantes de combustible.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_vehicle_fuel' => [
            'module' => 'vehicles', 'label' => 'Gestionar combustible',
            'description' => 'Registrar cargas de combustible de vehículos autorizados.',
            'requires' => ['can_view_vehicle_fuel'], 'admin_only' => false,
        ],
        'can_view_vehicle_maintenance' => [
            'module' => 'vehicles', 'label' => 'Ver mantenimiento de vehículos',
            'description' => 'Consultar tareas y planes de mantenimiento de vehículos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_vehicle_maintenance' => [
            'module' => 'vehicles', 'label' => 'Gestionar mantenimiento de vehículos',
            'description' => 'Registrar, iniciar, finalizar y anular mantenimientos de vehículos.',
            'requires' => ['can_view_vehicle_maintenance'], 'admin_only' => false,
        ],
        'can_view_vehicle_documents' => [
            'module' => 'vehicles', 'label' => 'Ver documentos de vehículos',
            'description' => 'Consultar documentos y vencimientos de vehículos.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_vehicle_documents' => [
            'module' => 'vehicles', 'label' => 'Gestionar documentos de vehículos',
            'description' => 'Registrar y actualizar documentos de vehículos.',
            'requires' => ['can_view_vehicle_documents'], 'admin_only' => false,
        ],
        'can_view_feed_stock' => [
            'module' => 'feed_stock', 'label' => 'Ver stock de alimento',
            'description' => 'Consultar saldos de alimento por receta y UP e historial de elaboraciones y ajustes.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manufacture_feed' => [
            'module' => 'feed_stock', 'label' => 'Elaborar alimento',
            'description' => 'Registrar elaboraciones con consumo de ingredientes.',
            'requires' => ['can_view_feed_stock'], 'admin_only' => false,
        ],
        'can_adjust_feed_stock' => [
            'module' => 'feed_stock', 'label' => 'Ajustar stock de alimento',
            'description' => 'Corregir manualmente el saldo de alimento indicando el motivo.',
            'requires' => ['can_view_feed_stock'], 'admin_only' => false,
        ],
        'can_adjust_feed_ingredients' => [
            'module' => 'feed_stock', 'label' => 'Ajustar ingredientes',
            'description' => 'Corregir existencias de ingredientes de una planta indicando el motivo.',
            'requires' => ['can_view_feed_stock'], 'admin_only' => false,
        ],
        'can_cancel_feed_manufacturing' => [
            'module' => 'feed_stock', 'label' => 'Anular elaboraciones',
            'description' => 'Compensar elaboraciones y devolver ingredientes conforme a las reglas de stock.',
            'requires' => ['can_view_feed_stock'], 'admin_only' => false,
        ],
        'can_manage_feed_minimums' => [
            'module' => 'feed_stock', 'label' => 'Configurar mínimos de alimento',
            'description' => 'Configurar mínimos de alimento por receta y UP sin modificar saldos.',
            'requires' => ['can_view_feed_stock'], 'admin_only' => false,
        ],
        'can_view_flock_feeding' => [
            'module' => 'flock_feeding', 'label' => 'Ver alimentación de lotes',
            'description' => 'Consultar configuración, historial y consumos de alimento de los lotes.',
            'requires' => [], 'admin_only' => false,
        ],
        'can_manage_flock_feeding' => [
            'module' => 'flock_feeding', 'label' => 'Configurar alimentación de lotes',
            'description' => 'Configurar alimentación manual y regresar al modo automático.',
            'requires' => ['can_view_flock_feeding'], 'admin_only' => false,
        ],
        'can_correct_feed_consumption' => [
            'module' => 'flock_feeding', 'label' => 'Rectificar consumos de alimento',
            'description' => 'Compensar consumos diarios históricos indicando el motivo.',
            'requires' => ['can_view_flock_feeding'], 'admin_only' => false,
        ],
        'can_manage_medicines' => [
            'module' => 'health', 'label' => 'Gestionar medicamentos',
            'description' => 'Crear y editar medicamentos y cambiar su estado; exclusivo de Admin.',
            'requires' => [], 'admin_only' => true,
        ],
        'can_manage_vaccines' => [
            'module' => 'health', 'label' => 'Gestionar vacunas',
            'description' => 'Crear y editar vacunas y cambiar su estado; exclusivo de Admin.',
            'requires' => [], 'admin_only' => true,
        ],
        'can_manage_egg_presentations' => [
            'module' => 'egg_stock', 'label' => 'Gestionar presentaciones de huevos',
            'description' => 'Consultar y gestionar presentaciones de huevos; exclusivo de Admin.',
            'requires' => [], 'admin_only' => true,
        ],
    ],
];
