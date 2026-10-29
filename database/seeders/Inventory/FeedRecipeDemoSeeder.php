<?php

namespace Database\Seeders\Inventory;

use App\Actions\Inventory\CreateFeedRecipeAction;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

final class FeedRecipeDemoSeeder extends Seeder
{
    public function run(CreateFeedRecipeAction $createRecipe): void
    {
        if (! app()->environment('local') && ! (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $corn = Product::query()->where('sku', 'MAIZ-025')->firstOrFail();
        $soy = Product::query()->where('sku', 'SOJA-025')->firstOrFail();
        $createRecipe->execute([
            'name' => 'Ración demo inicial',
            'notes' => 'Receta global ficticia para probar cálculos sin elaborar ni afectar stock.',
            'yield_grams' => 13000,
            'ingredients' => [
                ['product_id' => (int) $corn->getKey(), 'quantity_grams' => 5000],
                ['product_id' => (int) $soy->getKey(), 'quantity_grams' => 7000],
            ],
            'idempotency_key' => '01a133e2-061b-4a40-820a-93a472c6dc60',
        ], $actor, 'seeder');
    }
}
