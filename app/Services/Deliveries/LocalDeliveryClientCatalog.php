<?php

namespace App\Services\Deliveries;

use App\Exceptions\Deliveries\DeliveryConflict;

final class LocalDeliveryClientCatalog
{
    /** @return list<array{client_reference:string, client_name:string, address:string, latitude:string, longitude:string}> */
    public function clients(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DeliveryConflict('La agenda de clientes todavía no está integrada. No se puede iniciar un reparto en este entorno.');
        }

        // TODO(M13): reemplazar este catálogo local por la consulta real de clientes, sin asignarlos al reparto.
        return [
            ['client_reference' => 'demo-001', 'client_name' => 'Almacén El Puente', 'address' => 'Av. Artigas 1200', 'latitude' => '-34.7301000', 'longitude' => '-56.2181000'],
            ['client_reference' => 'demo-002', 'client_name' => 'Comercio La Plaza', 'address' => 'Wilson Ferreira 845', 'latitude' => '-34.7313000', 'longitude' => '-56.2169000'],
            ['client_reference' => 'demo-003', 'client_name' => 'Despensa Santa Rita', 'address' => 'Calle Rivera 410', 'latitude' => '-34.7288000', 'longitude' => '-56.2206000'],
            ['client_reference' => 'demo-004', 'client_name' => 'Mercado del Barrio', 'address' => 'Camino de las Flores 88', 'latitude' => '-34.7330000', 'longitude' => '-56.2197000'],
            ['client_reference' => 'demo-005', 'client_name' => 'Autoservicio Norte', 'address' => 'Ruta 5 km 24', 'latitude' => '-34.7258000', 'longitude' => '-56.2231000'],
            ['client_reference' => 'demo-006', 'client_name' => 'Kiosco La Estación', 'address' => 'José Batlle 302', 'latitude' => '-34.7279000', 'longitude' => '-56.2148000'],
        ];
    }

    /** @return array{client_reference:string, client_name:string, address:string, latitude:string, longitude:string} */
    public function find(string $reference): array
    {
        foreach ($this->clients() as $client) {
            if ($client['client_reference'] === $reference) {
                return $client;
            }
        }

        throw new DeliveryConflict('El cliente indicado no está disponible.');
    }

    /** @return list<array{id:string, name:string, address:string, latitude:float, longitude:float}> */
    public function search(string $query, int $limit): array
    {
        $matches = [];
        foreach ($this->clients() as $client) {
            if ($query !== '' && ! str_contains(mb_strtolower($client['client_name'].' '.$client['address']), mb_strtolower($query))) {
                continue;
            }
            $matches[] = [
                'id' => $client['client_reference'],
                'name' => $client['client_name'],
                'address' => $client['address'],
                'latitude' => (float) $client['latitude'],
                'longitude' => (float) $client['longitude'],
            ];
            if (count($matches) === $limit) {
                break;
            }
        }

        return $matches;
    }

    public function vehicleReference(): string
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DeliveryConflict('Los vehículos todavía no están integrados. No se puede iniciar un reparto en este entorno.');
        }

        // TODO(M16): reemplazar por el vehículo disponible asignado desde Vehículos.
        return 'demo-vehicle-001';
    }
}
