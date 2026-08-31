<?php

namespace Tests\Unit\ReportingAndAnalytics;

use App\Modules\Inventory\Application\PublicApi\Reporting\InventoryMovementsReportSource;
use App\Modules\Inventory\Application\PublicApi\Reporting\InventoryStockBalancesReportSource;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportQueryNormalizer;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Modules\ReportingAndAnalytics\Domain\Exceptions\ReportQueryValidationException;
use PHPUnit\Framework\TestCase;

final class ReportQueryNormalizerTest extends TestCase
{
    // Flujo: normaliza una métrica de cantidad y agrega la unidad base.
    public function test_adds_base_unit_when_a_quantity_metric_is_requested(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();

        // Acción: normaliza una agrupación y una métrica cuantitativa.
        $query = $normalizer->normalize('inventario.saldos-stock', [
            'agrupaciones' => ['producto'],
            'metricas' => ['stock_disponible'],
        ]);

        // Verificación: confirma que los resultados quedan separados por unidad.
        $this->assertSame(['producto', 'unidad_base'], $query->groupings);
        $this->assertSame(['stock_disponible'], $query->metrics);
        $this->assertSame([['field' => 'producto', 'direction' => 'asc']], $query->sorts);
    }

    // Flujo: agrupa sin medir; verifica que la fuente elija su métrica principal automáticamente.
    public function test_adds_default_metric_when_grouping_is_requested_without_metrics(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();

        // Acción: normaliza únicamente una dimensión.
        $query = $normalizer->normalize('inventario.saldos-stock', [
            'agrupaciones' => ['producto'],
        ]);

        // Verificación: conserva una agrupación útil y añade la medida publicada por defecto.
        $this->assertSame(['producto', 'unidad_base'], $query->groupings);
        $this->assertSame(['stock_disponible'], $query->metrics);
    }

    // Flujo: normaliza filtros, fechas y ordenamiento usando solo valores permitidos.
    public function test_returns_a_canonical_query_for_allowlisted_values(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();

        // Acción: normaliza una consulta de movimientos con filtros acotados.
        $query = $normalizer->normalize('inventario.movimientos', [
            'filtros' => [['campo' => 'tipo', 'operador' => 'eq', 'valor' => 'receipt']],
            'desde' => '2026-01-01',
            'hasta' => '2026-01-31',
            'ordenamientos' => [['campo' => 'fecha', 'direccion' => 'desc']],
            'pagina' => 2,
            'por_pagina' => 25,
        ]);

        // Verificación: confirma que la representación canónica es estable para presets y exportaciones.
        $this->assertSame('inventario.movimientos', $query->sourceKey);
        $this->assertSame('1.0', $query->definitionVersion);
        $this->assertSame([['field' => 'tipo', 'operator' => 'eq', 'value' => 'receipt']], $query->filters);
        $this->assertSame(2, $query->page);
        $this->assertSame(25, $query->perPage);
    }

    // Flujo: intenta inyectar una columna y verifica el rechazo de la consulta.
    public function test_rejects_unknown_query_parts_before_building_sql(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();
        $this->expectException(ReportQueryValidationException::class);

        // Acción: envía una columna que no pertenece al contrato de la fuente.
        $normalizer->normalize('inventario.saldos-stock', [
            'columnas' => ['productos.secret_column'],
        ]);
    }

    // Flujo: intenta filtrar un enum con un valor inexistente y verifica el rechazo.
    public function test_rejects_values_outside_the_source_definition(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();
        $this->expectException(ReportQueryValidationException::class);

        // Acción: solicita un tipo de movimiento no declarado por la fuente.
        $normalizer->normalize('inventario.movimientos', [
            'filtros' => [['campo' => 'tipo', 'operador' => 'eq', 'valor' => 'drop-table']],
        ]);
    }

    private function normalizer(): ReportQueryNormalizer
    {
        return new ReportQueryNormalizer(new ReportSourceRegistry([
            new InventoryStockBalancesReportSource,
            new InventoryMovementsReportSource,
        ]));
    }
}
