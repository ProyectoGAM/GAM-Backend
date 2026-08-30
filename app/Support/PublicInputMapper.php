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
            'linea_reserva_id' => 'reservation_line_id',
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
            'direccion' => 'address',
            'agrupaciones' => 'groupings',
            'metricas' => 'metrics',
            'formato' => 'format',
            'clave_fuente' => 'source_key',
            'version_definicion' => 'definition_version',
            'configuracion' => 'configuration',
        ];

        if ($context === 'catalog') {
            $keys['tipo'] = 'kind';
        } elseif ($context === 'inventory') {
            $keys['tipo'] = 'type';
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
        }

        $mapped = [];
        foreach ($input as $key => $value) {
            $mapped[$keys[$key] ?? $key] = is_array($value)
                ? self::toInternal($value, $context)
                : $value;
        }

        return $mapped;
    }
}
