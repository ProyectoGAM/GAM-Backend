<?php

namespace App\Services\CustomersAndAccounts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CustomerLocationGeocoder
{
    private const ENDPOINT = 'https://api.mapbox.com/search/geocode/v6/reverse';

    private const UNAVAILABLE = 'No se pudo consultar la dirección en Mapbox. Inténtalo nuevamente más tarde.';

    /**
     * Devuelve una sugerencia a partir del punto elegido, conservando sus coordenadas.
     *
     * @return array{latitude: float, longitude: float, address: ?string, locality: ?string, department: ?string, postcode: ?string, country_code: string}
     */
    public function reverse(float $latitude, float $longitude): array
    {
        $token = trim((string) config('services.mapbox.access_token'));
        abort_if($token === '', 503, self::UNAVAILABLE);

        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->get(self::ENDPOINT, [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'country' => 'UY',
                    'language' => 'es',
                    'permanent' => 'false',
                    'access_token' => $token,
                ]);
        } catch (ConnectionException) {
            abort(503, self::UNAVAILABLE);
        }

        $features = $response->successful() ? $response->json('features') : null;
        abort_unless(is_array($features) && array_is_list($features), 503, self::UNAVAILABLE);

        // Mapbox ordena los resultados inversos desde el más específico y adjunta su contexto geográfico.
        $properties = data_get($features, '0.properties', []);
        $type = data_get($properties, 'feature_type');
        $address = $type === 'address' ? data_get($properties, 'full_address') : null;
        if ($type === 'address' && (! is_string($address) || mb_strlen($address) > 240)) {
            $address = data_get($properties, 'name');
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => is_string($address) && mb_strlen($address) <= 240 ? $address : null,
            'locality' => data_get($properties, 'context.locality.name') ?? data_get($properties, 'context.place.name'),
            'department' => data_get($properties, 'context.region.name'),
            'postcode' => data_get($properties, 'context.postcode.name'),
            'country_code' => 'UY',
        ];
    }
}
