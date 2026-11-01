<?php

namespace Database\Seeders\CustomersAndAccounts;

use App\Actions\CustomersAndAccounts\AdjustCustomerAccountAction;
use App\Actions\CustomersAndAccounts\RecordCustomerPaymentAction;
use App\Actions\CustomersAndAccounts\SaveCustomerAction;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

final class CustomersDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $admin = User::query()->role('admin')->orderBy('id')->first();
        if ($admin === null) {
            return;
        }
        $names = ['Almacén El Puente', 'Comercio La Plaza', 'Despensa Santa Rita', 'Mercado del Barrio', 'Autoservicio Norte', 'Kiosco La Estación'];
        $locations = [
            ['latitude' => '-34.7301000', 'longitude' => '-56.2181000'],
            ['latitude' => '-34.7242000', 'longitude' => '-56.2194000'],
            ['latitude' => '-34.7338000', 'longitude' => '-56.2115000'],
            ['latitude' => '-34.7371000', 'longitude' => '-56.2246000'],
            ['latitude' => '-34.7216000', 'longitude' => '-56.2293000'],
            ['latitude' => '-34.7159000', 'longitude' => '-56.2139000'],
        ];
        foreach ($names as $index => $name) {
            $address = 'Dirección de demostración '.($index + 1);
            $data = ['name' => $name, 'address' => $address, ...$locations[$index],
                'visit_frequency' => 'Semanal', 'delivery_notes' => 'Cliente ficticio para desarrollo.'];
            $customer = Customer::query()->where('name', $name)->where('address', $address)->first();
            if ($customer === null) {
                $operation = app(SaveCustomerAction::class)->execute(null, $admin, $data,
                    'b6c9548b-7b4c-4f65-8e21-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT));
                $customer = Customer::query()->findOrFail($operation->customer_id);
            } elseif ($customer->latitude !== $locations[$index]['latitude'] || $customer->longitude !== $locations[$index]['longitude']) {
                app(SaveCustomerAction::class)->execute($customer, $admin, $data,
                    'b6c9548b-7b4c-4f65-8e23-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT));
            }
            if ($index === 0) {
                app(AdjustCustomerAccountAction::class)->execute($customer, $admin, ['direction' => 'increase',
                    'amount' => '1000.000', 'reason' => 'Saldo inicial ficticio de demostración', 'expected_version' => 0],
                    'b6c9548b-7b4c-4f65-8e22-000000000001');
            }
            if ($index === 1) {
                app(RecordCustomerPaymentAction::class)->execute($customer, $admin, ['amount' => '150.125',
                    'payment_method' => 'transfer', 'reason' => 'Pago ficticio de demostración'], 'b6c9548b-7b4c-4f65-8e22-000000000002');
            }
        }
    }
}
