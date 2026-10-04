<?php

namespace Tests\Unit\FarmStructure;

use App\Services\FarmStructure\UruguayBoundary;
use PHPUnit\Framework\TestCase;

final class UruguayBoundaryTest extends TestCase
{
    // Flujo: valida puntos interiores, el borde oficial y coordenadas fuera del país.
    public function test_official_boundary_accepts_inland_and_edge_points_and_rejects_foreign_and_water_points(): void
    {
        // Preparación: carga el polígono oficial y comprueba su fuente y geometría completa.
        $boundary = new UruguayBoundary;
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'geography'.DIRECTORY_SEPARATOR.'uruguay-igm-boundary.json';
        $source = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $ring = $source['geometry']['rings'][0];
        $this->assertSame(30773, count($ring));
        $this->assertSame(4326, $source['geometry']['spatialReference']['wkid']);
        $this->assertStringContainsString('Instituto Geográfico Militar', $source['source']);

        // Acción: evalúa Montevideo, Durazno, un vértice fronterizo, Brasil y el Río de la Plata.
        $this->assertTrue($boundary->contains(-34.9011, -56.1645));
        $this->assertTrue($boundary->contains(-33.4131, -56.5000));
        $this->assertTrue($boundary->contains((float) $ring[0][1], (float) $ring[0][0]));
        $this->assertFalse($boundary->contains(-29.9, -56.0));
        $this->assertFalse($boundary->contains(-35.2, -55.0));
        $this->assertFalse($boundary->contains(90.1, -56.0));
        $this->assertFalse($boundary->contains(INF, -56.0));
    }
}
