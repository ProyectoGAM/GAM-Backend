<?php

namespace App\Services\Lots;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class WeighingMath
{
    public function toGrams(string $value, string $unit): string
    {
        $decimal = BigDecimal::of($value);
        if ($unit === 'kg') {
            $decimal = $decimal->multipliedBy('1000');
        }

        return (string) $decimal->toScale(1, RoundingMode::Unnecessary);
    }

    public function fromGrams(string $value, string $unit): string
    {
        $decimal = BigDecimal::of($value);
        if ($unit === 'kg') {
            $decimal = $decimal->dividedBy('1000', 4, RoundingMode::Unnecessary);
        }

        return (string) $decimal;
    }

    public function sum(string ...$values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return (string) $total->toScale(1, RoundingMode::Unnecessary);
    }

    public function divide(string $value, int $divisor, int $scale = 6): string
    {
        if ($divisor < 1) {
            throw new InvalidArgumentException('El divisor debe ser positivo.');
        }

        return (string) BigDecimal::of($value)->dividedBy($divisor, $scale, RoundingMode::HalfUp);
    }

    public function format(?string $value, int $scale = 6): ?string
    {
        return $value === null
            ? null
            : (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public function compare(string $left, string $right): int
    {
        return BigDecimal::of($left)->compareTo($right);
    }

    public function isOutside(string $value, ?string $minimum, ?string $maximum): bool
    {
        return $minimum !== null && $maximum !== null
            && ($this->compare($value, $minimum) < 0 || $this->compare($value, $maximum) > 0);
    }

    /** @return array{mean: string, stddev: string|null, bins: list<array<string, mixed>>, curve: list<array{x: string, y: string, y_unit: string}>|null, reason: string|null} */
    public function distribution(array $weights, string $unit): array
    {
        $n = count($weights);
        if ($n === 0) {
            return [
                'mean' => $this->convertDistributionValue('0', $unit),
                'stddev' => null,
                'bins' => [],
                'curve' => null,
                'reason' => 'insufficient_sample',
            ];
        }

        $decimalWeights = array_map(static fn (string $weight): BigDecimal => BigDecimal::of($weight), $weights);
        $sum = BigDecimal::zero();
        foreach ($decimalWeights as $weight) {
            $sum = $sum->plus($weight);
        }
        $mean = $sum->dividedBy($n, 24, RoundingMode::HalfUp);

        if ($n < 2) {
            return [
                'mean' => $this->convertDistributionValue((string) $mean, $unit),
                'stddev' => null,
                'bins' => $this->bins($decimalWeights, $unit, 1),
                'curve' => null,
                'reason' => 'insufficient_sample',
            ];
        }

        $sumSquaredDifferences = BigDecimal::zero();
        foreach ($decimalWeights as $weight) {
            $difference = $weight->minus($mean);
            $sumSquaredDifferences = $sumSquaredDifferences->plus($difference->multipliedBy($difference));
        }
        $variance = $sumSquaredDifferences->dividedBy($n - 1, 24, RoundingMode::HalfUp);
        $stddev = $variance->sqrt(18, RoundingMode::HalfUp);
        $binCount = min(20, (int) ceil(log($n, 2) + 1));

        if ($stddev->isZero()) {
            return [
                'mean' => $this->convertDistributionValue((string) $mean, $unit),
                'stddev' => $this->convertDistributionValue((string) $stddev, $unit),
                'bins' => $this->bins($decimalWeights, $unit, 1),
                'curve' => null,
                'reason' => 'zero_variance',
            ];
        }

        $minimum = $decimalWeights[0];
        $maximum = $decimalWeights[0];
        foreach ($decimalWeights as $weight) {
            if ($weight->compareTo($minimum) < 0) {
                $minimum = $weight;
            }
            if ($weight->compareTo($maximum) > 0) {
                $maximum = $weight;
            }
        }

        $fourSigma = $stddev->multipliedBy('4');
        $domainMinimum = $mean->minus($fourSigma);
        $domainMaximum = $mean->plus($fourSigma);
        if ($minimum->compareTo($domainMinimum) < 0) {
            $domainMinimum = $minimum;
        }
        if ($maximum->compareTo($domainMaximum) > 0) {
            $domainMaximum = $maximum;
        }

        $step = $domainMaximum->minus($domainMinimum)->dividedBy(80, 24, RoundingMode::HalfUp);
        $curve = [];
        $stddevFloat = $this->distributionDecimal((string) $stddev, $unit)->toFloat();
        $densityUnit = '1/'.$unit;
        for ($index = 0; $index <= 80; $index++) {
            $x = $domainMinimum->plus($step->multipliedBy($index));
            $z = $x->minus($mean)->dividedBy($stddev, 24, RoundingMode::HalfUp)->toFloat();
            $density = exp(-0.5 * ($z ** 2)) / ($stddevFloat * sqrt(2 * M_PI));
            $curve[] = [
                'x' => $this->convertDistributionValue((string) $x, $unit),
                'y' => number_format($density, 6, '.', ''),
                'y_unit' => $densityUnit,
            ];
        }

        return [
            'mean' => $this->convertDistributionValue((string) $mean, $unit),
            'stddev' => $this->convertDistributionValue((string) $stddev, $unit),
            'bins' => $this->bins($decimalWeights, $unit, $binCount),
            'curve' => $curve,
            'reason' => null,
        ];
    }

    /** @param list<BigDecimal> $weights @return list<array<string, mixed>> */
    private function bins(array $weights, string $unit, int $count): array
    {
        if ($weights === []) {
            return [];
        }

        $minimum = $weights[0];
        $maximum = $weights[0];
        foreach ($weights as $weight) {
            if ($weight->compareTo($minimum) < 0) {
                $minimum = $weight;
            }
            if ($weight->compareTo($maximum) > 0) {
                $maximum = $weight;
            }
        }

        if ($count === 1 || $minimum->compareTo($maximum) === 0) {
            $value = $this->convertDistributionValue((string) $minimum, $unit);

            return [['lower' => $value, 'upper' => $value, 'center' => $value, 'count' => count($weights)]];
        }

        $width = $maximum->minus($minimum)->dividedBy($count, 24, RoundingMode::HalfUp);
        $bins = [];
        for ($index = 0; $index < $count; $index++) {
            $lower = $minimum->plus($width->multipliedBy($index));
            $upper = $index === $count - 1 ? $maximum : $lower->plus($width);
            $center = $lower->plus($upper)->dividedBy(2, 24, RoundingMode::HalfUp);
            $bins[$index] = [
                'lower' => $this->convertDistributionValue((string) $lower, $unit),
                'upper' => $this->convertDistributionValue((string) $upper, $unit),
                'center' => $this->convertDistributionValue((string) $center, $unit),
                'count' => 0,
            ];
        }

        foreach ($weights as $weight) {
            $offset = $weight->minus($minimum);
            $index = $offset->dividedBy($width, 24, RoundingMode::HalfUp)
                ->toScale(0, RoundingMode::Floor)
                ->toInt();
            $index = max(0, min($count - 1, $index));
            $bins[$index]['count']++;
        }

        return array_values($bins);
    }

    private function convertDistributionValue(string $grams, string $unit): string
    {
        return (string) $this->distributionDecimal($grams, $unit)->toScale(6, RoundingMode::HalfUp);
    }

    private function distributionDecimal(string $grams, string $unit): BigDecimal
    {
        $decimal = BigDecimal::of($grams);
        if ($unit === 'kg') {
            $decimal = $decimal->dividedBy('1000', 24, RoundingMode::HalfUp);
        }

        return $decimal;
    }
}
