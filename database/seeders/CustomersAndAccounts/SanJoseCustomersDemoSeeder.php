<?php

namespace Database\Seeders\CustomersAndAccounts;

use App\Actions\CustomersAndAccounts\AdjustCustomerAccountAction;
use App\Actions\CustomersAndAccounts\RecordCustomerPaymentAction;
use App\Actions\CustomersAndAccounts\SaveCustomerAction;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Geography\Locality;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SanJoseCustomersDemoSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: ?string, 4: ?string}> */
    private const CUSTOMERS = [
        // Nombre, latitud, longitud, deuda inicial y pago.
        1 => ['Almacén Demo Maragato', '-34.3387', '-56.7128', '1250.000', null],
        2 => ['Despensa Demo La Alameda', '-34.3368', '-56.7145', '780.500', null],
        3 => ['Mercado Demo Del Centro', '-34.3412', '-56.7130', '2450.750', null],
        4 => ['Kiosco Demo Del Teatro', '-34.3375', '-56.7118', null, '320.125'],
        5 => ['Autoservicio Demo La Esquina', '-34.3401', '-56.7161', null, '95.000'],
        6 => ['Panadería Demo El Trigal', '-34.3382', '-56.7169', null, '640.250'],
        7 => ['Comercio Demo Los Tilos', '-34.3366', '-56.7126', '900.000', '900.000'],
        8 => ['Almacén Demo Del Prado', '-34.3415', '-56.7151', null, null],
        9 => ['Provisión Demo La Estación', '-34.3395', '-56.7109', null, null],
    ];

    public function run(
        SaveCustomerAction $saveCustomer,
        AdjustCustomerAccountAction $adjustAccount,
        RecordCustomerPaymentAction $recordPayment,
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function () use ($saveCustomer, $adjustAccount, $recordPayment): void {
            $admin = User::query()->role('admin')->orderBy('id')->lockForUpdate()->first();
            if ($admin === null) {
                return;
            }

            $localityId = Locality::query()->where('normalized_name', 'san josé de mayo')
                ->whereRelation('department', 'normalized_name', 'san josé')->value('id');

            foreach (self::CUSTOMERS as $fixtureId => [$name, $latitude, $longitude, $debt, $payment]) {
                $suffix = sprintf('%012d', $fixtureId);
                $createKey = 'd0951208-c478-4a54-9b01-'.$suffix;
                if (CustomerOperation::query()->where('kind', 'customer_create')->where('idempotency_key', $createKey)->exists()) {
                    continue;
                }

                $operation = $saveCustomer->execute(null, $admin, [
                    'name' => $name,
                    'address' => 'Dirección ficticia '.$fixtureId.', San José de Mayo',
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'locality_id' => $localityId,
                    'visit_frequency' => 'Semanal',
                    'delivery_notes' => 'Cliente ficticio para demostración.',
                ], $createKey);

                $customer = Customer::query()->findOrFail($operation->customer_id);
                if ($debt !== null) {
                    $adjustAccount->execute($customer, $admin, [
                        'direction' => 'increase',
                        'amount' => $debt,
                        'reason' => 'Deuda inicial ficticia de demostración',
                        'expected_version' => 0,
                    ], 'd0951208-c478-4a54-9b02-'.$suffix);
                }

                if ($payment !== null) {
                    $recordPayment->execute($customer, $admin, [
                        'amount' => $payment,
                        'payment_method' => 'transfer',
                        'reason' => 'Pago ficticio de demostración',
                    ], 'd0951208-c478-4a54-9b03-'.$suffix);
                }
            }
        }, 3);
    }
}
