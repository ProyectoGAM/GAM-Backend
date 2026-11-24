<?php

namespace Database\Seeders\Deliveries;

use App\Actions\CustomersAndAccounts\SaveCustomerAction;
use App\Actions\Deliveries\CloseDeliveryAction;
use App\Actions\Deliveries\RecordDeliveryStopAction;
use App\Actions\Deliveries\StartDeliveryAction;
use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\Actions\Vehicles\SaveVehicleAction;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type DemoCustomer array{name: string, address?: string, latitude?: string, longitude?: string}
 * @phpstan-type DemoPayment array{amount: string, payment_method?: string}
 * @phpstan-type DemoVisit array{customer: DemoCustomer, payment: DemoPayment|null}
 */
final class DeliveriesDemoSeeder extends Seeder
{
    public const string START_KEY = '28e1241b-882d-4136-a1f3-7af848354001';

    private const string SHORT_ROUTE_START_KEY = '28e1241b-882d-4136-a1f3-7af848355001';

    private const string LONG_ROUTE_START_KEY = '28e1241b-882d-4136-a1f3-7af848356001';

    private const array CASH = ['amount' => '300.000', 'payment_method' => 'cash'];

    private const array TRANSFER = ['amount' => '300.000', 'payment_method' => 'transfer'];

    private const array CREDIT = ['amount' => '0.000'];

    private const array EXISTING_CUSTOMERS = [
        'Almacén El Puente',
        'Comercio La Plaza',
        'Despensa Santa Rita',
        'Mercado del Barrio',
        'Autoservicio Norte',
        'Kiosco La Estación',
    ];

    private const array LONG_ROUTE_VISITS = [
        ['customer' => ['name' => 'Almacén Demo Los Aromos', 'latitude' => '-34.6370', 'longitude' => '-56.6217'], 'payment' => self::CASH],
        ['customer' => ['name' => 'Kiosco Demo La Cantera', 'latitude' => '-34.6399', 'longitude' => '-56.6268'], 'payment' => self::CREDIT],
        ['customer' => ['name' => 'Provisión Demo La Arboleda', 'latitude' => '-34.6452', 'longitude' => '-56.6277'], 'payment' => self::TRANSFER],
        ['customer' => ['name' => 'Mercado Demo Los Ceibos', 'latitude' => '-34.6487', 'longitude' => '-56.6235'], 'payment' => self::CASH],
        ['customer' => ['name' => 'Autoservicio Demo Del Camino', 'latitude' => '-34.6502', 'longitude' => '-56.6177'], 'payment' => self::CREDIT],
        ['customer' => ['name' => 'Despensa Demo La Ribera', 'latitude' => '-34.6520', 'longitude' => '-56.6116'], 'payment' => null],
        ['customer' => ['name' => 'Panadería Demo Los Ombúes', 'latitude' => '-34.6473', 'longitude' => '-56.6098'], 'payment' => self::CASH],
        ['customer' => ['name' => 'Almacén Demo Las Golondrinas', 'latitude' => '-34.6438', 'longitude' => '-56.6057'], 'payment' => self::TRANSFER],
        ['customer' => ['name' => 'Comercio Demo Del Horizonte', 'latitude' => '-34.6404', 'longitude' => '-56.6103'], 'payment' => self::CREDIT],
        ['customer' => ['name' => 'Kiosco Demo Del Molino', 'latitude' => '-34.6388', 'longitude' => '-56.6061'], 'payment' => null],
        ['customer' => ['name' => 'Mercado Demo Las Acacias', 'latitude' => '-34.6355', 'longitude' => '-56.6116'], 'payment' => self::TRANSFER],
        ['customer' => ['name' => 'Despensa Demo Santa Clara', 'latitude' => '-34.6321', 'longitude' => '-56.6154'], 'payment' => self::CASH],
    ];

    public function run(
        SaveCustomerAction $saveCustomer,
        SaveVehicleAction $saveVehicle,
        RecordEggStockTransactionAction $stock,
        StartDeliveryAction $start,
        RecordDeliveryStopAction $recordStop,
        CloseDeliveryAction $close,
    ): void {
        if (! app()->environment(['local', 'testing']) && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }

        DB::transaction(function () use ($saveCustomer, $saveVehicle, $stock, $start, $recordStop, $close): void {
            $driver = User::query()->where('email', config('auth.demo_delivery.email'))->lockForUpdate()->firstOrFail();
            $admin = User::query()->where('email', config('auth.admin.email'))->firstOrFail();

            foreach ($this->routes() as $startKey => $route) {
                if (Delivery::query()->where('start_idempotency_key', $startKey)->exists()) {
                    continue;
                }

                $unit = ProductionUnit::query()->where('normalized_name', $route['unit'])
                    ->whereRelation('locality', 'normalized_name', $route['locality'])->sole();
                $vehicle = $saveVehicle->execute([
                    'plate' => $route['plate'],
                    'make' => 'Ford',
                    'model' => 'Transit',
                    'status' => 'active',
                    'habitual_driver_id' => $driver->id,
                    'notes' => 'Vehículo del reparto de demostración.',
                ], $admin);

                $stock->execute(
                    unit: $unit,
                    type: 'manual_receipt',
                    quantity: $route['loaded'],
                    operationId: $this->operationKey($startKey, 2),
                    actor: $admin,
                    reason: 'Existencias para el reparto de demostración.',
                    source: 'seeder',
                );

                $delivery = $start->execute($driver, [
                    'vehicle_id' => $vehicle->public_id,
                    'production_unit_id' => $unit->id,
                    'items' => [['unit' => 'huevo', 'amount' => (string) $route['loaded'], 'eggs_per_unit' => 1]],
                ], $startKey);

                $returned = $route['loaded'];
                foreach ($route['visits'] as $index => $visit) {
                    $customer = $this->resolveCustomer($saveCustomer, $admin, $unit, $visit['customer'], $this->operationKey($startKey, 100 + $index));
                    $key = $this->operationKey($startKey, 3 + $index);
                    $details = ['client_reference' => $customer->public_id];

                    if ($visit['payment'] === null) {
                        $details['status'] = DeliveryStopStatus::NotDelivered->value;
                        $details['visit_reason'] = 'Comercio cerrado.';
                    } else {
                        $details['status'] = DeliveryStopStatus::Delivered->value;
                        $details['accounting_version'] = 2;
                        $details['client_event_id'] = $key;
                        $details['items'] = [['unit' => 'huevo', 'amount' => '30', 'eggs_per_unit' => 1, 'unit_price' => 10]];
                        $details['payment'] = $visit['payment'];
                        $returned -= 30;
                    }

                    $recordStop->execute($delivery, $driver, $details, $key);
                }

                $close->execute($delivery, $driver, [
                    'returned_items' => $returned === 0 ? [] : [['unit' => 'huevo', 'amount' => (string) $returned, 'eggs_per_unit' => 1]],
                    'return_production_unit_id' => $returned === 0 ? null : $unit->id,
                    'notes' => 'Cierre del reparto de demostración en '.$unit->name.'.',
                ], $this->operationKey($startKey, $route['close_sequence']));
            }
        }, 3);
    }

    /** @return array<string, array{plate: string, unit: string, locality: string, loaded: int, visits: list<DemoVisit>, close_sequence: int}> */
    private function routes(): array
    {
        return [
            self::START_KEY => [
                'plate' => 'DEMO-2040', 'unit' => 'granja el ombú', 'locality' => 'las piedras', 'loaded' => 120,
                'visits' => $this->existingVisits([self::CASH, self::CREDIT, null]),
                'close_sequence' => 6,
            ],
            self::SHORT_ROUTE_START_KEY => [
                'plate' => 'DEMO-2041', 'unit' => 'granja el ombú', 'locality' => 'las piedras', 'loaded' => 180,
                'visits' => $this->existingVisits([self::TRANSFER, self::CASH, self::CREDIT, self::TRANSFER, null, self::CASH]),
                'close_sequence' => 90,
            ],
            self::LONG_ROUTE_START_KEY => [
                'plate' => 'DEMO-2042', 'unit' => 'granja santa clara', 'locality' => 'libertad', 'loaded' => 300,
                'visits' => self::LONG_ROUTE_VISITS,
                'close_sequence' => 90,
            ],
        ];
    }

    /**
     * @param  list<DemoPayment|null>  $payments
     * @return list<DemoVisit>
     */
    private function existingVisits(array $payments): array
    {
        $visits = [];
        foreach ($payments as $index => $payment) {
            $visits[] = [
                'customer' => ['name' => self::EXISTING_CUSTOMERS[$index], 'address' => 'Dirección de demostración '.($index + 1)],
                'payment' => $payment,
            ];
        }

        return $visits;
    }

    /** @param DemoCustomer $details */
    private function resolveCustomer(SaveCustomerAction $saveCustomer, User $admin, ProductionUnit $unit, array $details, string $key): Customer
    {
        if (isset($details['address'])) {
            return Customer::query()->where('name', $details['name'])->where('address', $details['address'])->sole();
        }

        $operation = $saveCustomer->execute(null, $admin, [
            ...$details,
            'address' => 'Ubicación ficticia de '.$details['name'].', Libertad',
            'locality_id' => $unit->locality_id,
            'delivery_notes' => 'Cliente de demostración para visualizar el recorrido.',
        ], $key);

        return Customer::query()->findOrFail($operation->customer_id);
    }

    private function operationKey(string $startKey, int $sequence): string
    {
        return substr($startKey, 0, -3).sprintf('%03d', $sequence);
    }
}
