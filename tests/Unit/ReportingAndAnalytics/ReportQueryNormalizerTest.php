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
        $query = $normalizer->normalize('inventory.stock-balances', [
            'groupings' => ['product'],
            'metrics' => ['physical_stock'],
        ]);

        // Verificación: confirma que los resultados quedan separados por unidad.
        $this->assertSame(['product', 'base_unit'], $query->groupings);
        $this->assertSame(['physical_stock'], $query->metrics);
        $this->assertSame([['field' => 'product', 'direction' => 'asc']], $query->sorts);
    }

    // Flujo: normaliza filtros, fechas y ordenamiento usando solo valores permitidos.
    public function test_returns_a_canonical_query_for_allowlisted_values(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();

        // Acción: normaliza una consulta de movimientos con filtros acotados.
        $query = $normalizer->normalize('inventory.movements', [
            'filters' => [['field' => 'type', 'operator' => 'eq', 'value' => 'receipt']],
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'sorts' => [['field' => 'date', 'direction' => 'desc']],
            'page' => 2,
            'per_page' => 25,
        ]);

        // Verificación: confirma que la representación canónica es estable para presets y exportaciones.
        $this->assertSame('inventory.movements', $query->sourceKey);
        $this->assertSame('1.0', $query->definitionVersion);
        $this->assertSame([['field' => 'type', 'operator' => 'eq', 'value' => 'receipt']], $query->filters);
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
        $normalizer->normalize('inventory.stock-balances', [
            'columns' => ['products.secret_column'],
        ]);
    }

    // Flujo: intenta filtrar un enum con un valor inexistente y verifica el rechazo.
    public function test_rejects_values_outside_the_source_definition(): void
    {
        // Preparación: crea el normalizador con las fuentes públicas de inventario.
        $normalizer = $this->normalizer();
        $this->expectException(ReportQueryValidationException::class);

        // Acción: solicita un tipo de movimiento no declarado por la fuente.
        $normalizer->normalize('inventory.movements', [
            'filters' => [['field' => 'type', 'operator' => 'eq', 'value' => 'drop-table']],
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
