<?php

namespace App\Services\FarmStructure;

use JsonException;
use LogicException;

class UruguayBoundary
{
    private const EDGE_TOLERANCE = 1.0e-9;

    /** @var list<array{0: float, 1: float}>|null */
    private static ?array $ring = null;

    /** Indica si la coordenada latitud/longitud está dentro del polígono oficial. */
    public function contains(float $latitude, float $longitude): bool
    {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return false;
        }

        $ring = self::ring();
        $inside = false;
        $previous = $ring[array_key_last($ring)];

        foreach ($ring as $current) {
            [$currentLongitude, $currentLatitude] = $current;
            [$previousLongitude, $previousLatitude] = $previous;

            if ($this->isOnSegment(
                $longitude,
                $latitude,
                $previousLongitude,
                $previousLatitude,
                $currentLongitude,
                $currentLatitude,
            )) {
                return true;
            }

            if (($currentLatitude > $latitude) !== ($previousLatitude > $latitude)
                && $longitude < ($previousLongitude - $currentLongitude)
                * ($latitude - $currentLatitude)
                / ($previousLatitude - $currentLatitude) + $currentLongitude) {
                $inside = ! $inside;
            }

            $previous = $current;
        }

        return $inside;
    }

    /** @return list<array{0: float, 1: float}> */
    private static function ring(): array
    {
        if (self::$ring !== null) {
            return self::$ring;
        }

        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'
            .DIRECTORY_SEPARATOR.'geography'.DIRECTORY_SEPARATOR.'uruguay-igm-boundary.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new LogicException('No se pudo leer el polígono oficial de Uruguay.');
        }

        try {
            $boundary = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException('El polígono oficial de Uruguay no es válido.', previous: $exception);
        }

        $points = $boundary['geometry']['rings'][0] ?? null;

        if (! is_array($points) || count($points) < 4) {
            throw new LogicException('El polígono oficial de Uruguay no contiene un anillo válido.');
        }

        self::$ring = array_map(
            static fn (array $point): array => [(float) $point[0], (float) $point[1]],
            $points,
        );

        return self::$ring;
    }

    private function isOnSegment(
        float $longitude,
        float $latitude,
        float $startLongitude,
        float $startLatitude,
        float $endLongitude,
        float $endLatitude,
    ): bool {
        $cross = ($longitude - $startLongitude) * ($endLatitude - $startLatitude)
            - ($latitude - $startLatitude) * ($endLongitude - $startLongitude);

        return abs($cross) <= self::EDGE_TOLERANCE
            && $longitude >= min($startLongitude, $endLongitude) - self::EDGE_TOLERANCE
            && $longitude <= max($startLongitude, $endLongitude) + self::EDGE_TOLERANCE
            && $latitude >= min($startLatitude, $endLatitude) - self::EDGE_TOLERANCE
            && $latitude <= max($startLatitude, $endLatitude) + self::EDGE_TOLERANCE;
    }
}
