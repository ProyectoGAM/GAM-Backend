<?php

namespace Tests\Unit\Lots;

use App\Services\Lots\WeighingMath;
use PHPUnit\Framework\TestCase;

final class WeighingMathTest extends TestCase
{
    // Flujo: convierte gramos y kilogramos sin perder la décima persistida.
    public function test_converts_units_with_exact_decimal_scale(): void
    {
        // Preparación: usa el servicio decimal sin base de datos.
        $math = new WeighingMath;

        // Consulta: convierte ambas unidades a gramos.
        $grams = $math->toGrams('1.2345', 'kg');
        $plainGrams = $math->toGrams('20.0', 'g');

        // Verificación: conserva la precisión exacta exigida por el contrato.
        $this->assertSame('1234.5', $grams);
        $this->assertSame('20.0', $plainGrams);
    }

    // Flujo: calcula el promedio ponderado de varias tandas grupales.
    public function test_divides_group_total_by_represented_birds(): void
    {
        // Preparación: configura un total decimal y una cantidad conocida.
        $math = new WeighingMath;

        // Consulta: divide 1340 gramos entre trece aves.
        $average = $math->divide($math->sum('500.0', '840.0'), 13);

        // Verificación: redondea el promedio a seis decimales.
        $this->assertSame('103.076923', $average);
    }

    // Flujo: reconoce límites inclusivos y marca sólo valores externos.
    public function test_expected_range_is_inclusive(): void
    {
        // Preparación: usa límites positivos de referencia.
        $math = new WeighingMath;

        // Consulta: compara los dos bordes y un valor inferior.
        $atMinimum = $math->isOutside('10.0', '10.0', '20.0');
        $atMaximum = $math->isOutside('20.0', '10.0', '20.0');
        $below = $math->isOutside('9.9', '10.0', '20.0');

        // Verificación: sólo el valor inferior queda fuera.
        $this->assertFalse($atMinimum);
        $this->assertFalse($atMaximum);
        $this->assertTrue($below);
    }

    // Flujo: calcula histograma y curva con las condiciones de muestra pequeñas.
    public function test_distribution_handles_single_value_zero_variance_and_curve_points(): void
    {
        // Preparación: crea una muestra única y otra variable.
        $math = new WeighingMath;

        // Consulta: procesa los tres escenarios de distribución.
        $single = $math->distribution(['20.0'], 'g');
        $zero = $math->distribution(['20.0', '20.0'], 'g');
        $variable = $math->distribution(['10.0', '20.0', '30.0'], 'g');

        // Verificación: respeta los motivos y los 81 puntos deterministas.
        $this->assertSame('insufficient_sample', $single['reason']);
        $this->assertNull($single['stddev']);
        $this->assertSame('zero_variance', $zero['reason']);
        $this->assertSame('0.000000', $zero['stddev']);
        $this->assertCount(81, $variable['curve']);
    }

    // Flujo: calcula media, desviación muestral y bins con valores conocidos.
    public function test_distribution_uses_sample_standard_deviation_and_exact_bins(): void
    {
        // Preparación: configura tres pesos cuya media y desviación son enteras.
        $math = new WeighingMath;

        // Consulta: calcula la distribución de la muestra conocida.
        $distribution = $math->distribution(['10.0', '20.0', '30.0'], 'g');

        // Verificación: conserva media, desviación y la asignación inclusiva de cada bin.
        $this->assertSame('20.000000', $distribution['mean']);
        $this->assertSame('10.000000', $distribution['stddev']);
        $this->assertSame([1, 1, 1], array_column($distribution['bins'], 'count'));
        $this->assertSame('10.000000', $distribution['bins'][0]['lower']);
        $this->assertSame('30.000000', $distribution['bins'][2]['upper']);
    }

    // Flujo: evita que magnitudes altas y cercanas colapsen al convertirlas a float.
    public function test_distribution_preserves_large_close_decimal_weights(): void
    {
        // Preparación: usa pesos grandes con diferencias decimales significativas.
        $math = new WeighingMath;

        // Consulta: calcula media, desviación y bins exclusivamente con decimales exactos.
        $distribution = $math->distribution([
            '100000000000.1',
            '100000000000.2',
            '100000000000.3',
        ], 'g');

        // Verificación: conserva las diferencias y distribuye una muestra en tres bins.
        $this->assertSame('100000000000.200000', $distribution['mean']);
        $this->assertSame('0.100000', $distribution['stddev']);
        $this->assertSame([1, 1, 1], array_column($distribution['bins'], 'count'));
        $this->assertCount(81, $distribution['curve']);
    }

    // Flujo: conserva la dimensionalidad al expresar la misma distribución en gramos y kilogramos.
    public function test_distribution_scales_curve_axis_deviation_bins_and_density_by_unit(): void
    {
        // Preparación: procesa la misma muestra en las dos unidades públicas.
        $math = new WeighingMath;
        $grams = $math->distribution(['10.0', '20.0', '30.0'], 'g');
        $kilograms = $math->distribution(['10.0', '20.0', '30.0'], 'kg');

        // Verificación: escala los valores lineales y declara la unidad inversa de la densidad.
        $this->assertSame('20.000000', $grams['mean']);
        $this->assertSame('0.020000', $kilograms['mean']);
        $this->assertSame('10.000000', $grams['stddev']);
        $this->assertSame('0.010000', $kilograms['stddev']);
        $this->assertSame('10.000000', $grams['bins'][0]['lower']);
        $this->assertSame('0.010000', $kilograms['bins'][0]['lower']);
        $this->assertSame('20.000000', $grams['curve'][40]['x']);
        $this->assertSame('0.020000', $kilograms['curve'][40]['x']);
        $this->assertSame('1/g', $grams['curve'][40]['y_unit']);
        $this->assertSame('1/kg', $kilograms['curve'][40]['y_unit']);
        $this->assertEqualsWithDelta((float) $grams['curve'][40]['y'] * 1000, (float) $kilograms['curve'][40]['y'], 0.001);
    }
}
