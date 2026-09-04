<?php

namespace App\Support;

/**
 * Traduce el contrato HTTP al vocabulario interno antes de entrar en la aplicación.
 *
 * Las tablas, modelos y servicios siguen usando sus nombres originales en inglés;
 * el español queda aislado en la frontera pública de la API.
 */
final class PublicInputMapper
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function toInternal(array $input, string $context = 'default'): array
    {
        $keys = [
            'nombre' => 'name',
            'correo_electronico' => 'email',
            'buscar' => 'search',
            'por_pagina' => 'per_page',
            'pagina' => 'page',
            'estado' => 'status',
            'departamento_id' => 'department_id',
            'localidad_id' => 'locality_id',
            'unidad_productiva_id' => 'production_unit_id',
            'capacidad_aves' => 'bird_capacity',
            'latitud' => 'latitude',
            'longitud' => 'longitude',
            'direccion' => 'address',
            'unidad_base' => 'base_unit',
            'controla_stock' => 'stock_tracked',
            'ubicacion_stock_id' => 'stock_location_id',
            'producto_id' => 'product_id',
            'proveedor_id' => 'supplier_id',
            'cantidad' => 'quantity',
            'cantidad_contada' => 'counted_quantity',
            'cantidad_minima' => 'minimum_quantity',
            'bajo_minimo' => 'below_minimum',
            'tipo_referencia' => 'reference_type',
            'referencia_id' => 'reference_id',
            'motivo' => 'reason',
            'ocurrido_en' => 'occurred_at',
            'lineas' => 'lines',
            'ubicacion_stock_origen_id' => 'from_stock_location_id',
            'ubicacion_stock_destino_id' => 'to_stock_location_id',
            'revierte_movimiento_id' => 'reverses_movement_id',
            'id_operacion' => 'operation_id',
            'columnas' => 'columns',
            'filtros' => 'filters',
            'campo' => 'field',
            'operador' => 'operator',
            'valor' => 'value',
            'desde' => 'from',
            'hasta' => 'to',
            'ordenamientos' => 'sorts',
            'orden' => 'sort',
            'agrupaciones' => 'groupings',
            'metricas' => 'metrics',
            'formato' => 'format',
            'clave_fuente' => 'source_key',
            'version_definicion' => 'definition_version',
            'configuracion' => 'configuration',
        ];

        if ($context === 'lots') {
            $keys = array_replace($keys, [
                'id' => 'public_id',
                'codigo' => 'code',
                'raza_id' => 'breed_id',
                'origen' => 'origin',
                'galpon_id' => 'poultry_house_id',
                'cantidad_inicial' => 'initial_quantity',
                'fecha_ingreso' => 'entry_date',
                'observaciones' => 'notes',
                'lote_id' => 'flock_id',
                'galpon_destino_id' => 'destination_poultry_house_id',
                'lote_destino_id' => 'destination_flock_id',
                'codigo_destino' => 'destination_code',
                'id_destino' => 'destination_public_id',
                'version_destino' => 'destination_version',
                'version_lote' => 'flock_version',
                'categoria_mortalidad_id' => 'mortality_category_id',
                'motivo_correccion' => 'correction_reason',
                'fecha_desde' => 'date_from',
                'fecha_hasta' => 'date_to',
                'tipo' => 'type',
            ]);
        } elseif ($context === 'catalog') {
            $keys['tipo'] = 'kind';
        } elseif ($context === 'inventory') {
            $keys['tipo'] = 'type';
        } elseif ($context === 'egg-stock') {
            $keys = array_replace($keys, [
                'tipo' => 'type',
                'observaciones' => 'notes',
                'motivo_correccion' => 'correction_reason',
                'fecha_desde' => 'date_from',
                'fecha_hasta' => 'date_to',
            ]);
        } elseif ($context === 'maintenance') {
            $keys += [
                'fecha_mantenimiento' => 'maintenance_date',
                'descripcion' => 'description',
                'costo_importe' => 'cost_amount',
                'costo_moneda' => 'cost_currency',
                'responsable_id' => 'responsible_user_id',
                'fecha_desde' => 'date_from',
                'fecha_hasta' => 'date_to',
            ];
        } elseif ($context === 'report') {
            // Las columnas y filtros son un contrato del dominio de reportes y
            // conservan sus claves en español hasta llegar a la fuente.
            $keys = [
                'buscar' => 'search',
                'por_pagina' => 'per_page',
                'pagina' => 'page',
                'estado' => 'status',
                'nombre' => 'name',
                'clave_fuente' => 'source_key',
                'version_definicion' => 'definition_version',
                'configuracion' => 'configuration',
                'formato' => 'format',
            ];
        } elseif ($context === 'report-query') {
            // La consulta se normaliza con el contrato público de reportes;
            // no traduzcas sus claves ni siquiera dentro de filtros.
            $keys = [];
        }

        $mapped = [];
        foreach ($input as $key => $value) {
            $nestedContext = $context === 'report' && $key === 'configuracion'
                ? 'report-query'
                : $context;
            $mapped[$keys[$key] ?? $key] = is_array($value)
                ? self::toInternal($value, $nestedContext)
                : $value;
        }

        return $mapped;
    }
}
